<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class InventoryDifferenceWorkbookService
{
    /**
     * @return non-empty-string
     */
    public function generate(WmsInventoryCount $inventoryCount): string
    {
        $items = $this->queryItems($inventoryCount);
        $costPrices = $this->costPricesByItem($items, $inventoryCount);
        $spreadsheet = new Spreadsheet;
        $allRows = $this->allRows($inventoryCount, $items, $costPrices);
        $diffRows = $this->diffRows($inventoryCount, $items, $costPrices);
        $uncountedRows = $this->uncountedRows($inventoryCount, $items, $costPrices);

        $departmentSheet = $spreadsheet->getActiveSheet();
        $departmentSheet->setTitle('部門別');
        $this->writeRows($departmentSheet, $this->departmentColumns(), $this->departmentRows($allRows));

        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('集計');
        $this->writeRows($summarySheet, $this->summaryColumns(), $this->summaryRows($allRows, $diffRows, $uncountedRows));

        $diffSheet = $spreadsheet->createSheet();
        $diffSheet->setTitle('差異');
        $this->writeRows($diffSheet, $this->diffColumns(), $diffRows);

        $uncountedSheet = $spreadsheet->createSheet();
        $uncountedSheet->setTitle('未棚');
        $this->writeRows($uncountedSheet, $this->uncountedColumns(), $uncountedRows);

        $spreadsheet->setActiveSheetIndex(0);

        $tempPath = tempnam(sys_get_temp_dir(), 'wms-inventory-diff-');
        if ($tempPath === false) {
            throw new RuntimeException('一時ファイルを作成できません。');
        }

        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);

            return (string) file_get_contents($tempPath);
        } finally {
            $spreadsheet->disconnectWorksheets();

            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function queryItems(WmsInventoryCount $inventoryCount): Collection
    {
        return WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->withoutOwnedSetItems()
            ->with(['inventoryCount', 'item.item_category1', 'item.item_category2'])
            ->get()
            ->sort($this->inventoryItemSorter(...))
            ->values();
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @return array<int, array<string, mixed>>
     */
    private function allRows(WmsInventoryCount $inventoryCount, Collection $items, Collection $costPrices): array
    {
        return $items
            ->map(fn (WmsInventoryCountItem $item): array => $this->buildDiffRow($inventoryCount, $item, $costPrices))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @return array<int, array<string, mixed>>
     */
    private function diffRows(WmsInventoryCount $inventoryCount, Collection $items, Collection $costPrices): array
    {
        return $items
            ->filter(fn (WmsInventoryCountItem $item): bool => $item->ending_system_quantity !== null)
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->hasRoundDifference($inventoryCount, $item))
            ->map(fn (WmsInventoryCountItem $item): array => $this->buildDiffRow($inventoryCount, $item, $costPrices))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  Collection<int, float>  $costPrices
     * @return array<int, array<string, mixed>>
     */
    private function uncountedRows(WmsInventoryCount $inventoryCount, Collection $items, Collection $costPrices): array
    {
        return $items
            ->map(fn (WmsInventoryCountItem $item): array => $this->buildUncountedRow($inventoryCount, $item, $costPrices))
            ->filter(fn (array $row): bool => filled($row['未入力回']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function diffColumns(): array
    {
        return array_merge(
            $this->baseColumns(),
            $this->roundColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function uncountedColumns(): array
    {
        return array_merge(
            ['未入力回'],
            $this->baseColumns(),
            $this->roundColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function baseColumns(): array
    {
        return [
            '棚卸しNo',
            '棚卸日',
            '倉庫CD',
            '倉庫名',
            '商品CD',
            '商品名',
            '大分類CD',
            '大分類名',
            '理論在庫',
            '原価',
            'CP在庫金額',
            '入力回数',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function summaryColumns(): array
    {
        return array_merge(
            ['区分', '件数', 'CP在庫金額'],
            $this->roundAmountColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function departmentColumns(): array
    {
        return array_merge(
            ['部門CD', '部門名', '総数', 'CP在庫金額'],
            $this->departmentRoundColumns(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function departmentRoundColumns(): array
    {
        $columns = [];

        foreach ([1, 2, 3] as $round) {
            array_push(
                $columns,
                "{$round}回目差異数",
                "{$round}回目差異率",
                "{$round}回目±不明差異金額",
                "{$round}回目±在庫差異率",
                "{$round}回目絶対値不明差異金額",
                "{$round}回目絶対値在庫差異率",
            );
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    private function roundColumns(): array
    {
        return [
            '1回目数量',
            '1回目±差異',
            '1回目絶対差異',
            '1回目±差異金額',
            '1回目絶対差異金額',
            '2回目数量',
            '2回目±差異',
            '2回目絶対差異',
            '2回目±差異金額',
            '2回目絶対差異金額',
            '3回目数量',
            '3回目±差異',
            '3回目絶対差異',
            '3回目±差異金額',
            '3回目絶対差異金額',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function roundAmountColumns(): array
    {
        return [
            '1回目±差異金額',
            '1回目絶対差異金額',
            '2回目±差異金額',
            '2回目絶対差異金額',
            '3回目±差異金額',
            '3回目絶対差異金額',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $diffRows
     * @param  array<int, array<string, mixed>>  $uncountedRows
     * @return array<int, array<string, mixed>>
     */
    private function summaryRows(
        array $allRows,
        array $diffRows,
        array $uncountedRows,
    ): array {
        return [
            $this->summaryRow('全体', $allRows),
            $this->summaryRow('差異あり', $diffRows),
            $this->summaryRow('未棚', $uncountedRows),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summaryRow(string $label, array $rows): array
    {
        $row = [
            '区分' => $label,
            '件数' => count($rows),
            'CP在庫金額' => $this->sumRows($rows, 'CP在庫金額'),
        ];

        foreach ($this->roundAmountColumns() as $column) {
            $row[$column] = $this->sumRows($rows, $column);
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function departmentRows(array $rows): array
    {
        $departmentRows = collect($rows)
            ->groupBy(fn (array $row): string => trim((string) ($row['大分類CD'] ?? '')))
            ->sortKeysUsing(fn (string $a, string $b): int => $this->categoryCodeSortValue($a) <=> $this->categoryCodeSortValue($b))
            ->map(fn (Collection $departmentRows): array => $this->departmentRow($departmentRows->all()))
            ->values()
            ->all();

        $departmentRows[] = $this->departmentRow($rows, '合計', '合計');

        return $departmentRows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function departmentRow(array $rows, ?string $departmentCode = null, ?string $departmentName = null): array
    {
        $stockAmount = $this->sumRows($rows, 'CP在庫金額') ?? 0;
        $totalCount = count($rows);
        $row = [
            '部門CD' => $departmentCode ?? (string) ($rows[0]['大分類CD'] ?? ''),
            '部門名' => $departmentName ?? (string) ($rows[0]['大分類名'] ?? '分類なし'),
            '総数' => $totalCount,
            'CP在庫金額' => $stockAmount,
        ];

        foreach ([1, 2, 3] as $round) {
            $differenceColumn = "{$round}回目±差異";
            $signedAmountColumn = "{$round}回目±差異金額";
            $absoluteAmountColumn = "{$round}回目絶対差異金額";
            $signedAmount = $this->sumRows($rows, $signedAmountColumn);
            $absoluteAmount = $this->sumRows($rows, $absoluteAmountColumn);
            $differenceCount = collect($rows)
                ->filter(fn (array $row): bool => ($row[$differenceColumn] ?? null) !== null && (int) $row[$differenceColumn] !== 0)
                ->count();

            $row["{$round}回目差異数"] = $differenceCount;
            $row["{$round}回目差異率"] = $totalCount === 0 ? 0 : $differenceCount / $totalCount;
            $row["{$round}回目±不明差異金額"] = $signedAmount;
            $row["{$round}回目±在庫差異率"] = $this->amountRate($signedAmount, $stockAmount);
            $row["{$round}回目絶対値不明差異金額"] = $absoluteAmount;
            $row["{$round}回目絶対値在庫差異率"] = $this->amountRate($absoluteAmount, $stockAmount);
        }

        return $row;
    }

    private function categoryCodeSortValue(string $code): array
    {
        if ($code === '') {
            return [1, ''];
        }

        return [0, str_pad($code, 20, '0', STR_PAD_LEFT)];
    }

    private function amountRate(float|int|null $amount, float|int|null $stockAmount): ?float
    {
        if ($amount === null) {
            return null;
        }

        if ((float) $stockAmount === 0.0) {
            return 0.0;
        }

        return (float) $amount / (float) $stockAmount;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sumRows(array $rows, string $column): float|int|null
    {
        $values = collect($rows)
            ->pluck($column)
            ->filter(fn ($value): bool => $value !== null && $value !== '');

        if ($values->isEmpty()) {
            return null;
        }

        return $values->sum(fn ($value): float => (float) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDiffRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices): array
    {
        $costPrice = $this->costPrice($item, $costPrices);
        $row = $this->baseRow($inventoryCount, $item, $costPrices, $costPrice);

        foreach ([1, 2, 3] as $round) {
            $values = $this->roundValues($inventoryCount, $item, $round, $costPrice);

            $row["{$round}回目数量"] = $values['quantity'];
            $row["{$round}回目±差異"] = $values['difference'];
            $row["{$round}回目絶対差異"] = $values['absolute_difference'];
            $row["{$round}回目±差異金額"] = $values['difference_amount'];
            $row["{$round}回目絶対差異金額"] = $values['absolute_difference_amount'];

        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUncountedRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices): array
    {
        $costPrice = $this->costPrice($item, $costPrices);
        $row = $this->baseRow($inventoryCount, $item, $costPrices, $costPrice);
        $uncountedRounds = [];

        foreach ([1, 2, 3] as $round) {
            $values = $this->roundValues($inventoryCount, $item, $round, $costPrice);

            $row["{$round}回目数量"] = $values['quantity'];
            $row["{$round}回目±差異"] = $values['difference'];
            $row["{$round}回目絶対差異"] = $values['absolute_difference'];
            $row["{$round}回目±差異金額"] = $values['difference_amount'];
            $row["{$round}回目絶対差異金額"] = $values['absolute_difference_amount'];

            if ($this->shouldExportRound($inventoryCount, $round) && $this->physicalRoundQuantity($item, $round) === null) {
                $uncountedRounds[] = "{$round}回目";
            }
        }

        return array_merge([
            '未入力回' => implode(',', $uncountedRounds),
        ], $row);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices, ?float $costPrice = null): array
    {
        $systemQuantity = $this->baseSystemQuantity($item);
        $costPrice ??= $this->costPrice($item, $costPrices);

        return [
            '棚卸しNo' => $inventoryCount->count_no ?? '',
            '棚卸日' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            '倉庫CD' => $inventoryCount->warehouse_code ?? '',
            '倉庫名' => $inventoryCount->warehouse_name ?? '',
            '商品CD' => $item->item_code ?? '',
            '商品名' => $item->item_name ?? '',
            '大分類CD' => $this->majorCategoryCode($item),
            '大分類名' => $this->majorCategoryName($item),
            '理論在庫' => $systemQuantity,
            '原価' => $costPrice,
            'CP在庫金額' => $systemQuantity !== null && $costPrice !== null ? $systemQuantity * $costPrice : null,
            '入力回数' => $item->input_count ?? 0,
        ];
    }

    private function hasRoundDifference(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item): bool
    {
        foreach ([1, 2, 3] as $round) {
            $difference = $this->roundValues($inventoryCount, $item, $round)['difference'];

            if ($difference !== null && (int) $difference !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{quantity: ?int, difference: ?int, absolute_difference: ?int, difference_amount: ?float, absolute_difference_amount: ?float}
     */
    private function roundValues(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, int $round, ?float $costPrice = null): array
    {
        if (! $this->shouldExportRound($inventoryCount, $round)) {
            return ['quantity' => null, 'difference' => null, 'absolute_difference' => null, 'difference_amount' => null, 'absolute_difference_amount' => null];
        }

        $quantity = $this->roundQuantity($item, $round);

        if ($quantity === null) {
            return ['quantity' => null, 'difference' => null, 'absolute_difference' => null, 'difference_amount' => null, 'absolute_difference_amount' => null];
        }

        $systemQuantity = $this->systemQuantityForRound($inventoryCount, $item, $round);

        if ($systemQuantity === null) {
            return ['quantity' => $quantity, 'difference' => null, 'absolute_difference' => null, 'difference_amount' => null, 'absolute_difference_amount' => null];
        }

        $difference = $this->isRoundConfirmed($inventoryCount, $round)
            ? $item->confirmedRoundDifference($round)
            : null;
        $difference ??= $quantity - $systemQuantity;
        $difference = (int) $difference;
        $differenceAmount = $costPrice === null ? null : $difference * $costPrice;

        return [
            'quantity' => $quantity,
            'difference' => $difference,
            'absolute_difference' => abs($difference),
            'difference_amount' => $differenceAmount,
            'absolute_difference_amount' => $differenceAmount === null ? null : abs($differenceAmount),
        ];
    }

    private function roundQuantity(WmsInventoryCountItem $item, int $round): ?int
    {
        return match ($round) {
            1 => $item->first_count_quantity,
            2 => $item->second_count_quantity ?? $item->first_count_quantity,
            3 => $item->final_count_quantity,
            default => null,
        };
    }

    private function physicalRoundQuantity(WmsInventoryCountItem $item, int $round): ?int
    {
        return match ($round) {
            1 => $item->first_count_quantity,
            2 => $item->second_count_quantity,
            3 => $item->final_count_quantity,
            default => null,
        };
    }

    private function systemQuantityForRound(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, int $round): ?int
    {
        $confirmedSystemQuantity = $this->isRoundConfirmed($inventoryCount, $round)
            ? $item->confirmedRoundSystemQuantity($round)
            : null;

        if ($confirmedSystemQuantity !== null) {
            return (int) $confirmedSystemQuantity;
        }

        return $this->baseSystemQuantity($item);
    }

    private function baseSystemQuantity(WmsInventoryCountItem $item): ?int
    {
        $quantity = $item->ending_system_quantity ?? $item->system_quantity;

        return $quantity === null ? null : (int) $quantity;
    }

    private function costPrice(WmsInventoryCountItem $item, Collection $costPrices): ?float
    {
        if ($item->item_id === null) {
            return null;
        }

        return (float) ($costPrices->get((int) $item->item_id) ?? 0);
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @return Collection<int, float>
     */
    private function costPricesByItem(Collection $items, WmsInventoryCount $inventoryCount): Collection
    {
        $itemIds = $items
            ->pluck('item_id')
            ->filter(fn ($itemId): bool => $itemId !== null)
            ->map(fn ($itemId): int => (int) $itemId)
            ->unique()
            ->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        $priceDate = CarbonImmutable::today()->toDateString();
        $costPrices = collect();

        foreach ($itemIds->chunk(1000) as $chunkItemIds) {
            $rankedPrices = DB::connection('sakemaru')
                ->table('item_prices as ip')
                ->select([
                    'ip.item_id',
                    'ip.cost_unit_price',
                    DB::raw('ROW_NUMBER() OVER (PARTITION BY ip.item_id ORDER BY ip.start_date DESC, ip.id DESC) as price_rank'),
                ])
                ->whereIn('ip.item_id', $chunkItemIds->all())
                ->where('ip.client_id', (int) $inventoryCount->client_id)
                ->where('ip.is_active', true)
                ->where('ip.start_date', '<=', $priceDate);

            DB::connection('sakemaru')
                ->query()
                ->fromSub($rankedPrices, 'ranked_prices')
                ->where('ranked_prices.price_rank', 1)
                ->get(['ranked_prices.item_id', 'ranked_prices.cost_unit_price'])
                ->each(function ($price) use ($costPrices): void {
                    $costPrices->put((int) $price->item_id, (float) $price->cost_unit_price);
                });
        }

        return $costPrices;
    }

    private function shouldExportRound(WmsInventoryCount $inventoryCount, int $round): bool
    {
        return $this->isRoundConfirmed($inventoryCount, $round);
    }

    private function isRoundConfirmed(WmsInventoryCount $inventoryCount, int $round): bool
    {
        return $inventoryCount->{$this->roundConfirmedAtColumn($round)} !== null;
    }

    private function roundConfirmedAtColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_confirmed_at',
            2 => 'second_count_confirmed_at',
            3 => 'final_count_confirmed_at',
            default => 'first_count_confirmed_at',
        };
    }

    private function inventoryItemSorter(WmsInventoryCountItem $a, WmsInventoryCountItem $b): int
    {
        return $this->inventoryItemSortValues($a) <=> $this->inventoryItemSortValues($b);
    }

    /**
     * @return array<int, int|string>
     */
    private function inventoryItemSortValues(WmsInventoryCountItem $item): array
    {
        $inventoryCount = $item->inventoryCount;
        $locationMissing = $item->location_id === null
            || trim((string) ($item->location_no ?? '')) === ''
            || trim((string) ($item->location_code1 ?? '')) === '' ? 1 : 0;

        if ($inventoryCount instanceof WmsInventoryCount && $this->isWarehouse91($inventoryCount)) {
            return [
                $locationMissing,
                $this->shelfPrefix($item) ?? '',
                (string) ($item->location_code1 ?? ''),
                (string) ($item->location_code2 ?? ''),
                (string) ($item->location_code3 ?? ''),
                (string) ($item->item_code ?? ''),
                (int) $item->id,
            ];
        }

        return [
            ...$this->middleCategorySortValues($item),
            $locationMissing,
            (string) ($item->location_code1 ?? ''),
            (string) ($item->location_code2 ?? ''),
            (string) ($item->location_code3 ?? ''),
            (string) ($item->item_code ?? ''),
            (int) $item->id,
        ];
    }

    private function isWarehouse91(WmsInventoryCount $inventoryCount): bool
    {
        return trim((string) $inventoryCount->warehouse_code) === '91'
            || (int) $inventoryCount->warehouse_id === 91;
    }

    private function shelfPrefix(WmsInventoryCountItem $item): ?string
    {
        $locationNo = trim((string) ($item->location_no ?? ''));

        return $locationNo === '' ? null : mb_substr($locationNo, 0, 2);
    }

    private function middleCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category2;

        if ($category === null || (int) ($category->depth ?? 0) !== 2) {
            return null;
        }

        return $category;
    }

    private function majorCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category1;

        if ($category === null || (int) ($category->depth ?? 0) !== 1) {
            return null;
        }

        return $category;
    }

    private function majorCategoryCode(WmsInventoryCountItem $item): string
    {
        $code = $this->majorCategory($item)?->code;

        return $code === null ? '' : (string) $code;
    }

    private function majorCategoryName(WmsInventoryCountItem $item): string
    {
        $category = $this->majorCategory($item);

        return $category === null ? '' : (string) ($category->name ?? '');
    }

    private function middleCategoryName(WmsInventoryCountItem $item): string
    {
        $category = $this->middleCategory($item);

        if ($category === null) {
            return '中分類なし';
        }

        $name = trim((string) ($category->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $code = trim((string) ($category->code ?? ''));

        return $code !== '' ? $code : '中分類なし';
    }

    /**
     * @return array<int, int|string>
     */
    private function middleCategorySortValues(WmsInventoryCountItem $item): array
    {
        $category = $this->middleCategory($item);

        return [
            $category === null ? 1 : 0,
            (string) ($category?->code ?? ''),
            (int) ($category?->id ?? 0),
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeRows(Worksheet $sheet, array $columns, array $rows): void
    {
        foreach ($columns as $index => $label) {
            $sheet->setCellValue([$index + 1, 1], $label);
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            foreach ($columns as $columnIndex => $label) {
                $value = $row[$label] ?? null;
                $excelColumn = $columnIndex + 1;

                if ($value === null || $value === '') {
                    $sheet->setCellValue([$excelColumn, $rowIndex], null);
                } elseif ($this->isStringColumn($label)) {
                    $sheet->setCellValueExplicit([$excelColumn, $rowIndex], (string) $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue([$excelColumn, $rowIndex], $value);
                }
            }

            $rowIndex++;
        }

        $this->styleSheet($sheet, $columns, count($rows));
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function styleSheet(Worksheet $sheet, array $columns, int $rowCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $lastRow = max($rowCount + 1, 1);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E5EEF8'],
            ],
        ]);
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP);

        foreach ($columns as $index => $label) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($column)->setWidth($this->columnWidth($label));

            if ($rowCount === 0) {
                continue;
            }

            if (str_contains($label, '±差異金額')) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('+#,##0;-#,##0;0');
            } elseif (str_contains($label, '金額')) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0');
            } elseif ($this->isPercentColumn($label)) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('0.00%');
            } elseif (str_contains($label, '±差異')) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('+0;-0;0');
            } elseif ($this->isDecimalColumn($label)) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');
            } elseif ($this->isIntegerColumn($label)) {
                $sheet->getStyle("{$column}2:{$column}{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('0');
            }
        }
    }

    private function isStringColumn(string $label): bool
    {
        return in_array($label, [
            '未入力回',
            '区分',
            '部門CD',
            '部門名',
            '棚卸しNo',
            '棚卸日',
            '倉庫CD',
            '倉庫名',
            '商品CD',
            '商品名',
            '大分類CD',
            '大分類名',
        ], true);
    }

    private function isIntegerColumn(string $label): bool
    {
        return $label === '理論在庫'
            || $label === '件数'
            || $label === '総数'
            || str_contains($label, '差異数')
            || str_contains($label, '金額')
            || $label === '入力回数'
            || str_contains($label, '数量')
            || str_contains($label, '絶対差異');
    }

    private function isPercentColumn(string $label): bool
    {
        return str_contains($label, '差異率');
    }

    private function isDecimalColumn(string $label): bool
    {
        return $label === '原価';
    }

    private function columnWidth(string $label): int
    {
        return match ($label) {
            '商品名' => 46,
            '大分類名' => 20,
            '棚卸しNo' => 24,
            '倉庫名' => 18,
            '未入力回' => 16,
            '区分' => 14,
            '部門名' => 20,
            '原価', 'CP在庫金額' => 14,
            default => str_contains($label, '金額') ? 18 : (str_contains($label, '差異率') ? 14 : (str_contains($label, '絶対差異') ? 13 : 11)),
        };
    }
}
