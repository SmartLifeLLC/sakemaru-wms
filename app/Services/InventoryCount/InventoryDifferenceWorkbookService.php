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
    private const UNCOUNTED_TARGET_CATEGORY_CODES = [1001, 1002, 1003, 1006];

    /**
     * @return non-empty-string
     */
    public function generate(WmsInventoryCount $inventoryCount): string
    {
        $items = $this->queryItems($inventoryCount);
        $costPrices = $this->costPricesByItem($items, $inventoryCount);
        $spreadsheet = new Spreadsheet;

        $diffSheet = $spreadsheet->getActiveSheet();
        $diffSheet->setTitle('差異');
        $this->writeRows($diffSheet, $this->diffColumns(), $this->diffRows($inventoryCount, $items, $costPrices));

        $uncountedSheet = $spreadsheet->createSheet();
        $uncountedSheet->setTitle('未棚');
        $this->writeRows($uncountedSheet, $this->uncountedColumns(), $this->uncountedRows($inventoryCount, $items, $costPrices));

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
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->isUncountedPdfTarget($item))
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
    private function roundColumns(): array
    {
        return [
            '1回目数量',
            '1回目±差異',
            '1回目絶対差異',
            '2回目数量',
            '2回目±差異',
            '2回目絶対差異',
            '3回目数量',
            '3回目±差異',
            '3回目絶対差異',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDiffRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices): array
    {
        $row = $this->baseRow($inventoryCount, $item, $costPrices);

        foreach ([1, 2, 3] as $round) {
            $values = $this->roundValues($inventoryCount, $item, $round);

            $row["{$round}回目数量"] = $values['quantity'];
            $row["{$round}回目±差異"] = $values['difference'];
            $row["{$round}回目絶対差異"] = $values['absolute_difference'];

        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUncountedRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices): array
    {
        $row = $this->baseRow($inventoryCount, $item, $costPrices);
        $uncountedRounds = [];

        foreach ([1, 2, 3] as $round) {
            $values = $this->roundValues($inventoryCount, $item, $round);

            $row["{$round}回目数量"] = $values['quantity'];
            $row["{$round}回目±差異"] = $values['difference'];
            $row["{$round}回目絶対差異"] = $values['absolute_difference'];

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
    private function baseRow(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, Collection $costPrices): array
    {
        $systemQuantity = $this->baseSystemQuantity($item);
        $costPrice = $this->costPrice($item, $costPrices);

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
     * @return array{quantity: ?int, difference: ?int, absolute_difference: ?int}
     */
    private function roundValues(WmsInventoryCount $inventoryCount, WmsInventoryCountItem $item, int $round): array
    {
        if (! $this->shouldExportRound($inventoryCount, $round)) {
            return ['quantity' => null, 'difference' => null, 'absolute_difference' => null];
        }

        $quantity = $this->roundQuantity($item, $round);

        if ($quantity === null) {
            return ['quantity' => null, 'difference' => null, 'absolute_difference' => null];
        }

        $systemQuantity = $this->systemQuantityForRound($inventoryCount, $item, $round);

        if ($systemQuantity === null) {
            return ['quantity' => $quantity, 'difference' => null, 'absolute_difference' => null];
        }

        $difference = $this->isRoundConfirmed($inventoryCount, $round)
            ? $item->confirmedRoundDifference($round)
            : null;
        $difference ??= $quantity - $systemQuantity;
        $difference = (int) $difference;

        return [
            'quantity' => $quantity,
            'difference' => $difference,
            'absolute_difference' => abs($difference),
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

        $priceDate = CarbonImmutable::parse($inventoryCount->count_date)->toDateString();
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

    private function isUncountedPdfTarget(WmsInventoryCountItem $item): bool
    {
        $majorCategoryCode = $item->item?->item_category1?->code;

        if (! in_array((int) $majorCategoryCode, self::UNCOUNTED_TARGET_CATEGORY_CODES, true)) {
            return false;
        }

        return (int) ($item->system_quantity ?? 0) !== 0
            || (float) ($item->difference_quantity ?? 0) !== 0.0;
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

            if (str_contains($label, '±差異')) {
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
            || $label === 'CP在庫金額'
            || $label === '入力回数'
            || str_contains($label, '数量')
            || str_contains($label, '絶対差異');
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
            '原価', 'CP在庫金額' => 14,
            default => str_contains($label, '絶対差異') ? 13 : 11,
        };
    }
}
