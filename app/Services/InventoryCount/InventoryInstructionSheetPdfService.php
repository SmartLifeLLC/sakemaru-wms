<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\ItemCategory;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use TCPDF;

class InventoryInstructionSheetPdfService
{
    public const ITEM_SCOPE_ALL = 'all';

    public const ITEM_SCOPE_TOP_50 = 'top_50';

    private const TOP_SYSTEM_QUANTITY_LIMIT = 50;

    private const FONT_SIZE_TITLE = 18;

    private const FONT_SIZE_HEADER = 9;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_COL_HEADER = 7;

    private const FONT_SIZE_PRODUCT = 9;

    private const FONT_SIZE_PRODUCT_CODE = 10;

    private const FONT_SIZE_SHELF = 10;

    private const FONT_SIZE_STOCK = 11;

    private const FONT_SIZE_JAN = 7;

    private const BLOCK_ROW_HEIGHT = 5.5;

    private const LINE_WIDTH = 0.2;

    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const CONTENT_WIDTH = 190;

    private const COL_W1 = 75; // 商品コード / 商品名

    private const COL_W2 = 25; // 棚番

    private const COL_W3 = 30; // 規格

    private const COL_W4 = 25; // 現在庫

    private const COL_W5 = 35; // 場所（記入欄）

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    public function generate(WmsInventoryCount $inventoryCount, ?array $categoryIds = null, string $itemScope = self::ITEM_SCOPE_ALL): string
    {
        $items = $this->queryItems($inventoryCount, $categoryIds, $itemScope);
        $janCodes = (new InventoryJanCodeResolver)->forItems($items);

        $this->initPdf();

        $header = $this->buildHeader($inventoryCount);
        $currentShelfPrefix = null;
        $isFirstPage = true;

        foreach ($items as $item) {
            $shelfPrefix = $this->shelfPagePrefix($item);

            if ($currentShelfPrefix !== null && $currentShelfPrefix !== $shelfPrefix) {
                $this->addNewPage($header, $shelfPrefix);
                $isFirstPage = false;
            } elseif ($isFirstPage && $currentShelfPrefix === null) {
                $this->addNewPage($header, $shelfPrefix);
                $isFirstPage = false;
            }

            $currentShelfPrefix = $shelfPrefix;

            $blockHeight = self::BLOCK_ROW_HEIGHT * 3;

            if ($this->currentY + $blockHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                $this->addNewPage($header, $currentShelfPrefix);
            }

            $this->renderItemBlock($item, $janCodes[(int) $item->item_id] ?? '');
        }

        if ($this->pdf->getNumPages() === 0) {
            $this->addNewPage($header, null);
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, '対象データなし', 0, 0, 'C');
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    public function getCategoryOptions(WmsInventoryCount $inventoryCount): array
    {
        $itemIds = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->whereNotNull('item_id')
            ->distinct()
            ->pluck('item_id');

        if ($itemIds->isEmpty()) {
            return [];
        }

        return ItemCategory::query()
            ->where('is_active', true)
            ->whereExists(function ($query) use ($itemIds) {
                $query->selectRaw('1')
                    ->from('items')
                    ->whereColumn('items.item_category2_id', 'item_categories.id')
                    ->whereIn('items.id', $itemIds);
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (ItemCategory $cat) => [$cat->id => "[{$cat->code}]{$cat->name}"])
            ->toArray();
    }

    public static function itemScopeOptions(): array
    {
        return [
            self::ITEM_SCOPE_ALL => '全件',
            self::ITEM_SCOPE_TOP_50 => '在庫数上位50',
        ];
    }

    private function queryItems(WmsInventoryCount $inventoryCount, ?array $categoryIds = null, string $itemScope = self::ITEM_SCOPE_ALL): Collection
    {
        $query = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->with(['item']);

        if ($categoryIds !== null && $categoryIds !== []) {
            $query->whereHas('item', function ($q) use ($categoryIds) {
                $q->whereIn('item_category2_id', $categoryIds);
            });
        }

        if ($this->normalizeItemScope($itemScope) === self::ITEM_SCOPE_TOP_50) {
            $topItemIds = (clone $query)
                ->reorder()
                ->orderByDesc('system_quantity')
                ->orderBy('id')
                ->limit(self::TOP_SYSTEM_QUANTITY_LIMIT)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($topItemIds === []) {
                return $query->whereRaw('1 = 0')->get();
            }

            $query->whereKey($topItemIds);
        }

        return $this->applyInstructionOrder($query)->get();
    }

    private function applyInstructionOrder(Builder $query): Builder
    {
        return $query
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
            ->orderBy('item_code');
    }

    private function normalizeItemScope(?string $itemScope): string
    {
        return $itemScope === self::ITEM_SCOPE_TOP_50
            ? self::ITEM_SCOPE_TOP_50
            : self::ITEM_SCOPE_ALL;
    }

    private function buildHeader(WmsInventoryCount $inventoryCount): array
    {
        return [
            'count_date' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            'warehouse_code' => $inventoryCount->warehouse_code ?? '',
            'warehouse_name' => $inventoryCount->warehouse_name ?? '',
        ];
    }

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle('棚卸し指示書');
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
        $leftX = self::MARGIN_LEFT;
        $contentW = self::CONTENT_WIDTH;

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY($leftX, $this->currentY);
        $this->pdf->Cell(52, 10, '棚番：'.($shelfPrefix ?? ''), 0, 0, 'L');

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($leftX + 54, $this->currentY + 2);
        $this->pdf->Cell(38, 5, '棚卸日 '.$header['count_date'], 0, 0, 'L');

        $this->pdf->SetXY($leftX + 94, $this->currentY + 2);
        $this->pdf->Cell(80, 5, $this->truncateText($header['warehouse_name'], 78), 0, 0, 'L');

        $printTimestamp = now()->format('Y/m/d H:i:s');
        $this->pdf->SetXY($leftX + $contentW - 50, $this->currentY);
        $this->pdf->Cell(50, 5, $printTimestamp, 0, 0, 'R');

        $this->currentY += 12;
    }

    private function renderColumnHeaders(): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_COL_HEADER);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell(self::COL_W1, $rowH, '商品コード', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y);
        $this->pdf->Cell(self::COL_W2, $rowH, '棚番', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y);
        $this->pdf->Cell(self::COL_W3, $rowH, '規格', 0, 0, 'L');

        $col4X = $x + self::COL_W1 + self::COL_W2 + self::COL_W3;
        $this->pdf->SetXY($col4X, $y);
        $this->pdf->Cell(self::COL_W4, $rowH, '現在庫', 0, 0, 'C');

        $col5X = $col4X + self::COL_W4;
        $this->pdf->SetXY($col5X, $y);
        $this->pdf->Cell(self::COL_W5, $rowH, '場所', 0, 0, 'C');

        $y2 = $y + $rowH;
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1, $rowH, '商品名', 0, 0, 'L');

        $y3 = $y + $rowH * 2;
        $this->pdf->SetXY($x, $y3);
        $this->pdf->Cell(self::COL_W1, $rowH, 'JANコード', 0, 0, 'L');

        $this->currentY = $y3 + $rowH;
    }

    private function renderItemBlock(WmsInventoryCountItem $countItem, string $janCode): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetLineStyle(['dash' => '2,1']);
        $this->pdf->Line($x, $y, $x + self::CONTENT_WIDTH, $y);
        $this->pdf->SetLineStyle(['dash' => '']);

        $item = $countItem->item;
        $spec = (string) ($item?->packaging ?? '');

        // === Row 1 ===
        $y1 = $y;
        $shelfNo = $this->shelfCode($countItem);

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_PRODUCT_CODE);
        $this->pdf->SetXY($x, $y1);
        $this->pdf->Cell(self::COL_W1, $rowH, $countItem->item_code ?? '', 0, 0, 'L');

        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_SHELF);
        $this->pdf->SetXY($x + self::COL_W1, $y1);
        $this->pdf->Cell(self::COL_W2, $rowH, $shelfNo, 0, 0, 'L');

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y1);
        $this->pdf->Cell(self::COL_W3, $rowH, $this->truncateText($spec, self::COL_W3 - 2), 0, 0, 'L');

        // 現在庫（3行分のセル中央に配置）
        $col4X = $x + self::COL_W1 + self::COL_W2 + self::COL_W3;
        $stockQty = (int) $countItem->system_quantity;
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_STOCK);
        $stockCellY = $y + ($rowH * 3 - $rowH) / 2;
        $this->pdf->SetXY($col4X, $stockCellY);
        $this->pdf->Cell(self::COL_W4, $rowH, number_format($stockQty), 0, 0, 'R');

        // 場所（空の罫線ボックス）
        $col5X = $col4X + self::COL_W4;
        $boxMargin = 2;
        $boxX = $col5X + $boxMargin;
        $boxY = $y + 1;
        $boxW = self::COL_W5 - ($boxMargin * 2);
        $boxH = ($rowH * 3) - 2;
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->SetLineStyle(['dash' => '']);
        $this->pdf->Rect($boxX, $boxY, $boxW, $boxH);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        // === Row 2 ===
        $y2 = $y + $rowH;
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_PRODUCT);
        $this->pdf->SetXY($x, $y2);
        $nameWidth = self::COL_W1 + self::COL_W2 + self::COL_W3 - 2;
        $this->pdf->Cell($nameWidth, $rowH, $this->truncateText((string) ($countItem->item_name ?? ''), $nameWidth - 2), 0, 0, 'L');

        // === Row 3 ===
        $y3 = $y + $rowH * 2;
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_JAN);
        $this->pdf->SetXY($x, $y3);
        $this->pdf->Cell($nameWidth, $rowH, $this->truncateText($janCode, $nameWidth - 2), 0, 0, 'L');

        $this->currentY = $y + $rowH * 3;
    }

    private function renderPageNumbers(): void
    {
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);

        for ($i = 1; $i <= $this->totalPages; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} ／ {$this->totalPages}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::PAGE_WIDTH - self::MARGIN_RIGHT - $textWidth;
            $y = self::MARGIN_TOP + 5;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, 5, $pageText, 0, 0, 'R');
        }
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

    private function shelfCode(WmsInventoryCountItem $countItem): string
    {
        $code = \App\Models\Sakemaru\Location::formatCode(
            $countItem->location_code1,
            $countItem->location_code2,
            $countItem->location_code3,
            ''
        );

        return $code !== '' ? $code : (string) ($countItem->location_no ?? '');
    }

    private function shelfPagePrefix(WmsInventoryCountItem $countItem): string
    {
        $shelfCode = $this->shelfCode($countItem);

        return mb_substr($shelfCode, 0, 2);
    }
}
