<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use TCPDF;

class InventoryDiffListPdfService
{
    private const UNCOUNTED_TARGET_CATEGORY_CODES = [1001, 1002, 1003, 1006];

    private const FONT_SIZE_TITLE = 18;

    private const FONT_SIZE_HEADER = 9;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_COL_HEADER = 7;

    private const BLOCK_ROW_HEIGHT = 6.5;

    private const CATEGORY_HEADER_HEIGHT = 6;

    private const LINE_WIDTH = 0.2;

    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    // A4 Portrait
    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const CONTENT_WIDTH = 190; // 210 - 10 - 10

    private const COL_W_JAN = 36;

    private const COL_W_ITEM = 100;

    private const COL_W_LOCATION = 12;

    private const COL_W_LOT = 20;

    private const COL_W_EXPIRATION = 22;

    private const COL_W_INPUT = 10;

    private const COL_W_SYSTEM = 16;

    private const COL_W_ACTUAL = 14;

    private const COL_W_DIFF = 14;

    private const BARCODE_WIDTH = 34;

    private const BARCODE_HEIGHT = 7.5;

    private const BARCODE_TEXT_HEIGHT = 3;

    private const FONT_SIZE_JAN = 7;

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    private string $pdfTitle = '棚卸差異リスト';

    private string $emptyMessage = '差異データなし';

    private ?int $uncountedRound = null;

    public function generate(WmsInventoryCount $inventoryCount): string
    {
        $this->pdfTitle = '棚卸差異リスト';
        $this->emptyMessage = '差異データなし';
        $this->uncountedRound = null;

        return $this->generatePdf($inventoryCount);
    }

    public function generateUncounted(WmsInventoryCount $inventoryCount, int $round): string
    {
        $this->pdfTitle = "{$round}回目未カウントリスト";
        $this->emptyMessage = "{$round}回目未カウントデータなし";
        $this->uncountedRound = min(max($round, 1), 3);

        return $this->generatePdf($inventoryCount);
    }

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     */
    public function generateUncountedForCounts(Collection $inventoryCounts, int $round): string
    {
        $inventoryCounts = $inventoryCounts
            ->filter(fn ($inventoryCount): bool => $inventoryCount instanceof WmsInventoryCount && $inventoryCount->exists)
            ->values();
        $round = min(max($round, 1), 3);

        $this->pdfTitle = "{$round}回目未カウントリスト";
        $this->emptyMessage = "{$round}回目未カウントデータなし";
        $this->uncountedRound = $round;

        return $this->generatePdfForItems(
            $this->queryMultiCountUncountedItems($inventoryCounts, $round),
            $this->buildMultiHeader($inventoryCounts),
        );
    }

    private function generatePdf(WmsInventoryCount $inventoryCount): string
    {
        return $this->generatePdfForItems(
            $this->queryItems($inventoryCount),
            $this->buildHeader($inventoryCount),
        );
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @param  array{count_date: string, warehouse_code: string, warehouse_name: string}  $header
     */
    private function generatePdfForItems(Collection $items, array $header): string
    {
        $this->initPdf();
        $janCodes = $items->isEmpty()
            ? []
            : (new InventoryJanCodeResolver)->forItems($items);

        $currentShelfPrefix = null;
        $currentCategoryKey = null;
        $isFirstPage = true;

        if ($items->isEmpty()) {
            $this->addNewPage($header, null);
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, $this->emptyMessage, 0, 0, 'C');
        } else {
            foreach ($items as $item) {
                $shelfPrefix = $this->shelfPagePrefix($item);
                $categoryKey = $this->middleCategoryKey($item);
                $categoryName = $this->middleCategoryName($item);

                if ($currentShelfPrefix !== null && $currentShelfPrefix !== $shelfPrefix) {
                    $this->addNewPage($header, $shelfPrefix);
                    $currentCategoryKey = null;
                    $isFirstPage = false;
                } elseif ($isFirstPage && $currentShelfPrefix === null) {
                    $this->addNewPage($header, $shelfPrefix);
                    $currentCategoryKey = null;
                    $isFirstPage = false;
                }

                $currentShelfPrefix = $shelfPrefix;
                $categoryChanged = $currentCategoryKey !== $categoryKey;
                $blockHeight = self::BLOCK_ROW_HEIGHT * $this->itemBlockRowCount();
                $requiredHeight = $blockHeight + ($categoryChanged ? self::CATEGORY_HEADER_HEIGHT : 0);

                if ($this->currentY + $requiredHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                    $this->addNewPage($header, $currentShelfPrefix);
                    $currentCategoryKey = null;
                    $categoryChanged = true;
                }

                if ($categoryChanged) {
                    $this->renderCategoryHeader($categoryName);
                    $currentCategoryKey = $categoryKey;
                }

                $this->renderItemBlock($item, $janCodes[(int) $item->item_id] ?? '');
            }
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    /**
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function queryItems(WmsInventoryCount $inventoryCount): Collection
    {
        $query = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->with(['item.item_category2']);

        if ($this->uncountedRound !== null) {
            $query->whereNull($this->roundColumn($this->uncountedRound));
            $this->applyUncountedTargetFilters($query);
        } else {
            $query
                ->whereNotNull('ending_system_quantity')
                ->where(function ($query) {
                    $query->whereNotNull('final_count_quantity')
                        ->orWhereNotNull('second_count_quantity')
                        ->orWhereNotNull('first_count_quantity');
                });
        }

        $items = $query
            ->orderByRaw("
                CASE
                    WHEN location_id IS NULL
                        OR COALESCE(location_no, '') = ''
                        OR COALESCE(location_code1, '') = ''
                    THEN 1
                    ELSE 0
                END
            ")
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->orderBy('item_code')
            ->get();

        if ($this->uncountedRound !== null) {
            return $items
                ->sort($this->inventoryItemSorter(...))
                ->values();
        }

        return $items
            ->map(fn (WmsInventoryCountItem $item): WmsInventoryCountItem => $this->attachDiffListValues($item))
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->hasPrintableDifference($item))
            ->sort($this->inventoryItemSorter(...))
            ->values();
    }

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @return Collection<int, WmsInventoryCountItem>
     */
    private function queryMultiCountUncountedItems(Collection $inventoryCounts, int $round): Collection
    {
        $inventoryCountIds = $inventoryCounts
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($inventoryCountIds->isEmpty()) {
            return collect();
        }

        $roundColumn = $this->roundColumn($round);

        return WmsInventoryCountItem::with(['inventoryCount', 'item.item_category2'])
            ->whereIn('inventory_count_id', $inventoryCountIds)
            ->tap(fn (Builder $query) => $this->applyUncountedTargetFilters($query))
            ->get()
            ->groupBy(fn (WmsInventoryCountItem $item): string => $this->inventoryItemKey($item))
            ->filter(fn (Collection $items): bool => $items->every(
                fn (WmsInventoryCountItem $item): bool => $item->{$roundColumn} === null,
            ))
            ->map(fn (Collection $items): WmsInventoryCountItem => $this->latestRepresentativeItem($items))
            ->values()
            ->sort($this->inventoryItemSorter(...))
            ->values();
    }

    private function applyUncountedTargetFilters(Builder $query): void
    {
        $query
            ->whereHas('item.item_category1', fn (Builder $query) => $query->whereIn('code', self::UNCOUNTED_TARGET_CATEGORY_CODES))
            ->where(function (Builder $query): void {
                $query
                    ->where('system_quantity', '!=', 0)
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->whereNotNull('difference_quantity')
                            ->where('difference_quantity', '!=', 0);
                    });
            });
    }

    private function inventoryItemKey(WmsInventoryCountItem $item): string
    {
        if ($item->real_stock_id !== null) {
            return 'real_stock:'.$item->real_stock_id;
        }

        return implode('|', [
            'item',
            $item->item_id ?? '',
            $item->lot_id ?? '',
            $item->lot_no ?? '',
            $item->expiration_date?->format('Y-m-d') ?? '',
            $item->location_id ?? '',
            $item->location_code1 ?? '',
            $item->location_code2 ?? '',
            $item->location_code3 ?? '',
            $item->location_no ?? '',
        ]);
    }

    /**
     * @param  Collection<int, WmsInventoryCountItem>  $items
     */
    private function latestRepresentativeItem(Collection $items): WmsInventoryCountItem
    {
        return $items
            ->sort(fn (WmsInventoryCountItem $a, WmsInventoryCountItem $b): int => $this->representativeSortValues($a) <=> $this->representativeSortValues($b))
            ->last();
    }

    /**
     * @return array<int, int>
     */
    private function representativeSortValues(WmsInventoryCountItem $item): array
    {
        return [
            $item->inventoryCount?->count_date?->getTimestamp() ?? 0,
            (int) $item->inventory_count_id,
            (int) $item->id,
        ];
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
        return [
            $item->location_id === null
                || trim((string) ($item->location_no ?? '')) === ''
                || trim((string) ($item->location_code1 ?? '')) === '' ? 1 : 0,
            $this->shelfPagePrefix($item) ?? '',
            ...$this->middleCategorySortValues($item),
            (string) ($item->location_code1 ?? ''),
            (string) ($item->location_code2 ?? ''),
            (string) ($item->location_code3 ?? ''),
            (string) ($item->item_code ?? ''),
            (int) $item->inventory_count_id,
            (int) $item->id,
        ];
    }

    private function attachDiffListValues(WmsInventoryCountItem $item): WmsInventoryCountItem
    {
        $actualQty = $this->actualQuantity($item);

        if ($actualQty !== null) {
            $item->setAttribute('pdf_actual_quantity', $actualQty);
        }

        if ($actualQty !== null && $item->ending_system_quantity !== null) {
            $endDifferenceQuantity = (float) $actualQty - (float) $item->ending_system_quantity;

            $item->setAttribute('pdf_end_difference_quantity', $endDifferenceQuantity);
        }

        return $item;
    }

    private function hasPrintableDifference(WmsInventoryCountItem $item): bool
    {
        return (float) ($item->getAttribute('pdf_end_difference_quantity') ?? 0) !== 0.0;
    }

    private function actualQuantity(WmsInventoryCountItem $item): mixed
    {
        return $item->final_count_quantity
            ?? $item->second_count_quantity
            ?? $item->first_count_quantity;
    }

    private function buildHeader(WmsInventoryCount $inventoryCount): array
    {
        return [
            'count_date' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            'warehouse_code' => $inventoryCount->warehouse_code ?? '',
            'warehouse_name' => $inventoryCount->warehouse_name ?? '',
        ];
    }

    /**
     * @param  Collection<int, WmsInventoryCount>  $inventoryCounts
     * @return array{count_date: string, warehouse_code: string, warehouse_name: string}
     */
    private function buildMultiHeader(Collection $inventoryCounts): array
    {
        $dates = $inventoryCounts
            ->pluck('count_date')
            ->filter()
            ->sortBy(fn ($date): string => $date->format('Y-m-d'))
            ->values();

        $warehouseCodes = $inventoryCounts
            ->pluck('warehouse_code')
            ->filter(fn ($value): bool => filled($value))
            ->unique()
            ->values();

        $warehouseNames = $inventoryCounts
            ->pluck('warehouse_name')
            ->filter(fn ($value): bool => filled($value))
            ->unique()
            ->values();

        return [
            'count_date' => $this->formatMultiDateLabel($dates),
            'warehouse_code' => $warehouseCodes->count() === 1 ? (string) $warehouseCodes->first() : '複数',
            'warehouse_name' => $warehouseNames->count() === 1 ? (string) $warehouseNames->first() : '複数倉庫',
        ];
    }

    /**
     * @param  Collection<int, mixed>  $dates
     */
    private function formatMultiDateLabel(Collection $dates): string
    {
        if ($dates->isEmpty()) {
            return '';
        }

        $first = $dates->first()?->format('Y/m/d');
        $last = $dates->last()?->format('Y/m/d');

        if ($first === $last) {
            return $first;
        }

        return "{$first}-{$last}";
    }

    private function shelfPagePrefix(WmsInventoryCountItem $item): ?string
    {
        $locationNo = trim((string) ($item->location_no ?? ''));

        if ($locationNo === '') {
            return null;
        }

        return mb_substr($locationNo, 0, 2);
    }

    private function middleCategory(WmsInventoryCountItem $item): ?ItemCategory
    {
        $category = $item->item?->item_category2;

        if ($category === null || (int) ($category->depth ?? 0) !== 2) {
            return null;
        }

        return $category;
    }

    private function middleCategoryKey(WmsInventoryCountItem $item): string
    {
        $category = $this->middleCategory($item);

        return $category?->id !== null
            ? (string) $category->id
            : 'no_middle_category';
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

    private function roundColumn(int $round): string
    {
        return match ($round) {
            1 => 'first_count_quantity',
            2 => 'second_count_quantity',
            3 => 'final_count_quantity',
            default => 'first_count_quantity',
        };
    }

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle($this->pdfTitle);
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
    }

    private function addNewPage(array $header, ?string $shelfPrefix): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->renderPageHeader($header, $shelfPrefix);
        $this->renderColumnHeaders();
    }

    private function renderPageHeader(array $header, ?string $shelfPrefix): void
    {
        $x = self::MARGIN_LEFT;

        // Row 1: title + 棚卸日 + print datetime
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY($x, $this->currentY);
        $this->pdf->Cell(55, 10, '棚番：'.($shelfPrefix ?? ''), 0, 0, 'L');

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($x + 57, $this->currentY + 2);
        $this->pdf->Cell(40, 5, '棚卸日 '.$header['count_date'], 0, 0, 'L');

        $printTimestamp = now()->format('Y/m/d H:i:s');
        $this->pdf->SetXY($x + self::CONTENT_WIDTH - 45, $this->currentY);
        $this->pdf->Cell(45, 5, $printTimestamp, 0, 0, 'R');

        // Row 2: 倉庫コード + 倉庫名称
        $row2Y = $this->currentY + 7;
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($x + 57, $row2Y);
        $this->pdf->Cell(35, 5, '倉庫コード '.$header['warehouse_code'], 0, 0, 'L');

        $this->pdf->SetXY($x + 95, $row2Y);
        $this->pdf->Cell(60, 5, '倉庫名称 '.$header['warehouse_name'], 0, 0, 'L');

        $this->currentY = $row2Y + 7;
    }

    private function renderColumnHeaders(): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_COL_HEADER);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        // Row 1 headers
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell(self::COL_W_JAN, $rowH * 2, 'JANコード', 0, 0, 'C');

        $row1X = $x + self::COL_W_JAN;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_ITEM, $rowH, 'アイテムコード', 0, 0, 'L');

        $row1X += self::COL_W_ITEM;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOCATION, $rowH, 'ロケ', 0, 0, 'C');

        $row1X += self::COL_W_LOCATION;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOT, $rowH, 'ロットNO', 0, 0, 'C');

        $row1X += self::COL_W_LOT;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_EXPIRATION, $rowH, '賞味期限', 0, 0, 'C');

        // Row 2 headers
        $y2 = $y + $rowH;
        $row2X = $x + self::COL_W_JAN;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_ITEM, $rowH, 'アイテム名称', 0, 0, 'L');

        $row2X += self::COL_W_ITEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_INPUT, $rowH, '入力', 0, 0, 'C');

        $row2X += self::COL_W_INPUT;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_SYSTEM, $rowH, $this->uncountedRound === null ? '終了理論' : '理論数量', 0, 0, 'C');

        $row2X += self::COL_W_SYSTEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_ACTUAL, $rowH, '実数量', 0, 0, 'C');

        $row2X += self::COL_W_ACTUAL;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_DIFF, $rowH, $this->uncountedRound === null ? '終了差異' : '差異数量', 0, 0, 'C');

        // Separator line below headers
        $sepY = $y + ($rowH * $this->itemBlockRowCount());
        $this->pdf->Line($x, $sepY, $x + self::CONTENT_WIDTH, $sepY);

        $this->currentY = $sepY + 0.5;
    }

    private function renderCategoryHeader(string $categoryName): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;

        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetDrawColor(180, 190, 200);
        $this->pdf->SetFillColor(245, 247, 250);
        $this->pdf->Rect($x, $y, self::CONTENT_WIDTH, self::CATEGORY_HEADER_HEIGHT, 'DF');
        $this->pdf->SetDrawColor(0, 0, 0);

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + 2, $y + 0.8);
        $this->pdf->Cell(
            self::CONTENT_WIDTH - 4,
            self::CATEGORY_HEADER_HEIGHT - 1,
            $this->truncateText('中分類：'.$categoryName, self::CONTENT_WIDTH - 4),
            0,
            0,
            'L'
        );

        $this->currentY = $y + self::CATEGORY_HEADER_HEIGHT + 0.5;
    }

    private function renderItemBlock(WmsInventoryCountItem $countItem, string $janCode): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        // Separator line (dashed)
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetLineStyle(['dash' => '2,1']);
        $this->pdf->Line($x, $y, $x + self::CONTENT_WIDTH, $y);
        $this->pdf->SetLineStyle(['dash' => '']);

        $endDifferenceQuantity = $this->uncountedRound !== null
            ? null
            : $countItem->getAttribute('pdf_end_difference_quantity');

        $this->renderBarcodeCell($x, $y, self::COL_W_JAN, $janCode);
        $contentX = $x + self::COL_W_JAN;

        // === Row 1: item_code | location | lot | expiration ===
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($contentX, $y);
        $this->pdf->Cell(self::COL_W_ITEM, $rowH, $countItem->item_code ?? '', 0, 0, 'L');

        $row1X = $contentX + self::COL_W_ITEM;
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOCATION, $rowH, $countItem->location_no ?? '', 0, 0, 'C');

        $row1X += self::COL_W_LOCATION;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_LOT, $rowH, $countItem->lot_no ?? '', 0, 0, 'C');

        $row1X += self::COL_W_LOT;
        $this->pdf->SetXY($row1X, $y);
        $this->pdf->Cell(self::COL_W_EXPIRATION, $rowH, $countItem->expiration_date?->format('Y/m/d') ?? '', 0, 0, 'C');

        // === Row 2: item_name | input_count | system_qty | actual_qty | diff_qty ===
        $y2 = $y + $rowH;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($contentX, $y2);
        $this->pdf->Cell(self::COL_W_ITEM, $rowH, $this->truncateText($countItem->item_name ?? '', self::COL_W_ITEM - 2), 0, 0, 'L');

        $row2X = $contentX + self::COL_W_ITEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_INPUT, $rowH, (string) ($countItem->input_count ?? 0), 0, 0, 'C');

        $row2X += self::COL_W_INPUT;
        $this->pdf->SetXY($row2X, $y2);
        $systemQty = $this->uncountedRound === null
            ? $countItem->ending_system_quantity
            : $countItem->system_quantity;
        $this->pdf->Cell(
            self::COL_W_SYSTEM,
            $rowH,
            $this->uncountedRound === null ? $this->formatOptionalQuantity($systemQty) : $this->formatQuantity($systemQty),
            0,
            0,
            'C'
        );

        $actualQty = $this->uncountedRound !== null
            ? $countItem->{$this->roundColumn($this->uncountedRound)}
            : $countItem->getAttribute('pdf_actual_quantity');
        $row2X += self::COL_W_SYSTEM;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_ACTUAL, $rowH, $this->formatQuantity($actualQty), 0, 0, 'C');

        $row2X += self::COL_W_ACTUAL;
        $this->pdf->SetXY($row2X, $y2);
        $this->pdf->Cell(self::COL_W_DIFF, $rowH, $this->uncountedRound !== null ? '0' : $this->formatOptionalQuantity($endDifferenceQuantity), 0, 0, 'C');

        $this->currentY = $y + ($rowH * $this->itemBlockRowCount());
    }

    private function renderBarcodeCell(float $x, float $y, float $width, string $janCode): void
    {
        if ($janCode === '') {
            return;
        }

        $barcodeW = min(self::BARCODE_WIDTH, $width - 4);
        $barcodeX = $x + (($width - $barcodeW) / 2);
        $barcodeY = $y + 1;

        $this->pdf->write1DBarcode(
            $janCode,
            'C128',
            $barcodeX,
            $barcodeY,
            $barcodeW,
            self::BARCODE_HEIGHT,
            0.35,
            ['position' => '', 'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false, 'font' => 'kozgopromedium', 'fontsize' => 0, 'stretchtext' => 0],
            'N'
        );

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_JAN);
        $this->pdf->SetXY($x, $barcodeY + self::BARCODE_HEIGHT + 0.8);
        $this->pdf->Cell($width, self::BARCODE_TEXT_HEIGHT, $this->truncateText($janCode, $width - 2), 0, 0, 'C');
    }

    private function itemBlockRowCount(): int
    {
        return 2;
    }

    private function renderPageNumbers(): void
    {
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);

        for ($i = 1; $i <= $this->totalPages; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} ／ {$this->totalPages}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::PAGE_WIDTH - self::MARGIN_RIGHT - $textWidth;
            $y = self::MARGIN_TOP + 7;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, 5, $pageText, 0, 0, 'R');
        }
    }

    private function formatQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $floatVal = (float) $value;

        if ($floatVal == (int) $floatVal) {
            return number_format((int) $floatVal);
        }

        return number_format($floatVal, 3);
    }

    private function formatOptionalQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $this->formatQuantity($value);
    }

    private function truncateText(string $text, float $maxWidthMm): string
    {
        if ($text === '') {
            return '';
        }

        $currentWidth = $this->pdf->GetStringWidth($text);
        if ($currentWidth <= $maxWidthMm) {
            return $text;
        }

        $ellipsis = '…';
        $ellipsisWidth = $this->pdf->GetStringWidth($ellipsis);
        $targetWidth = $maxWidthMm - $ellipsisWidth;

        $chars = mb_str_split($text);
        $result = '';
        $width = 0;

        foreach ($chars as $char) {
            $charWidth = $this->pdf->GetStringWidth($char);
            if ($width + $charWidth > $targetWidth) {
                break;
            }
            $result .= $char;
            $width += $charWidth;
        }

        return $result.$ellipsis;
    }
}
