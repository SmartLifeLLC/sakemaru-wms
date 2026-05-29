<?php

namespace App\Services\InventoryCount;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Support\Collection;
use TCPDF;

class InventoryDiffListPdfService
{
    private const FONT_SIZE_TITLE = 18;

    private const FONT_SIZE_HEADER = 9;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_COL_HEADER = 7;

    private const BLOCK_ROW_HEIGHT = 5.5;

    private const LINE_WIDTH = 0.2;

    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    // A4 Portrait
    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    private const CONTENT_WIDTH = 190; // 210 - 10 - 10

    // Column widths (mm) — based on sample PDF layout
    // Row1: item_code (wide span) ... cost_price (right)
    // Row2: item_name | input_count | system_qty | actual_qty | diff_qty | diff_amount
    private const COL_W1 = 70;  // item_code / item_name

    private const COL_W2 = 20;  // location_no / input_count

    private const COL_W3 = 25;  // lot_no / system_qty

    private const COL_W4 = 20;  // expiration / actual_qty

    private const COL_W5 = 25;  // (blank) / diff_qty

    private const COL_W6 = 30;  // cost_price / diff_amount

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    public function generate(WmsInventoryCount $inventoryCount): string
    {
        $items = $this->queryItems($inventoryCount);

        $this->initPdf();

        $header = $this->buildHeader($inventoryCount);

        if ($items->isEmpty()) {
            $this->addNewPage($header);
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, '差異データなし', 0, 0, 'C');
        } else {
            $this->addNewPage($header);

            foreach ($items as $item) {
                $blockHeight = self::BLOCK_ROW_HEIGHT * 2;

                if ($this->currentY + $blockHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                    $this->addNewPage($header);
                }

                $this->renderItemBlock($item);
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
        return WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->where(function ($query) {
                $query->whereNotNull('difference_quantity')
                    ->orWhereNotNull('final_count_quantity')
                    ->orWhereNotNull('second_count_quantity')
                    ->orWhereNotNull('first_count_quantity');
            })
            ->orderBy('item_code')
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->get()
            ->map(function (WmsInventoryCountItem $item): WmsInventoryCountItem {
                if ($item->difference_quantity !== null) {
                    return $item;
                }

                $actualQty = $item->final_count_quantity
                    ?? $item->second_count_quantity
                    ?? $item->first_count_quantity;

                if ($actualQty === null) {
                    return $item;
                }

                $differenceQuantity = (float) $actualQty - (float) $item->system_quantity;
                $item->difference_quantity = $differenceQuantity;
                $item->difference_amount = $differenceQuantity * (float) $item->cost_price;

                return $item;
            })
            ->filter(fn (WmsInventoryCountItem $item): bool => (float) ($item->difference_quantity ?? 0) !== 0.0)
            ->values();
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
        $this->pdf->SetTitle('棚卸差異リスト');
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
    }

    private function addNewPage(array $header): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->renderPageHeader($header);
        $this->renderColumnHeaders();
    }

    private function renderPageHeader(array $header): void
    {
        $x = self::MARGIN_LEFT;

        // Row 1: title + 棚卸日 + print datetime
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY($x, $this->currentY);
        $this->pdf->Cell(55, 10, '棚卸差異リスト', 0, 0, 'L');

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
        $this->pdf->Cell(self::COL_W1, $rowH, 'アイテムコード', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y);
        $this->pdf->Cell(self::COL_W2, $rowH, 'ロケーションNO', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y);
        $this->pdf->Cell(self::COL_W3, $rowH, 'ロットNO', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y);
        $this->pdf->Cell(self::COL_W4, $rowH, '賞味期限', 0, 0, 'L');

        $rightX = $x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4 + self::COL_W5;
        $this->pdf->SetXY($rightX, $y);
        $this->pdf->Cell(self::COL_W6, $rowH, '仕入原価', 0, 0, 'R');

        // Row 2 headers
        $y2 = $y + $rowH;
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1, $rowH, 'アイテム名称', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y2);
        $this->pdf->Cell(self::COL_W2, $rowH, '入力回数', 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y2);
        $this->pdf->Cell(self::COL_W3, $rowH, '理論数量', 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y2);
        $this->pdf->Cell(self::COL_W4, $rowH, '実数量', 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y2);
        $this->pdf->Cell(self::COL_W5, $rowH, '差異数量', 0, 0, 'R');

        $this->pdf->SetXY($rightX, $y2);
        $this->pdf->Cell(self::COL_W6, $rowH, '差異金額', 0, 0, 'R');

        // Separator line below headers
        $sepY = $y2 + $rowH;
        $this->pdf->Line($x, $sepY, $x + self::CONTENT_WIDTH, $sepY);

        $this->currentY = $sepY + 0.5;
    }

    private function renderItemBlock(WmsInventoryCountItem $countItem): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        // Separator line (dashed)
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetLineStyle(['dash' => '2,1']);
        $this->pdf->Line($x, $y, $x + self::CONTENT_WIDTH, $y);
        $this->pdf->SetLineStyle(['dash' => '']);

        $costPrice = $this->formatMoney($countItem->cost_price);
        $diffAmount = $this->formatDiffMoney($countItem->difference_amount);

        $rightX = $x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4 + self::COL_W5;

        // === Row 1: item_code ... cost_price ===
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell(self::COL_W1, $rowH, $countItem->item_code ?? '', 0, 0, 'L');

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + self::COL_W1, $y);
        $this->pdf->Cell(self::COL_W2, $rowH, $countItem->location_no ?? '', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y);
        $this->pdf->Cell(self::COL_W3, $rowH, $countItem->lot_no ?? '', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y);
        $this->pdf->Cell(self::COL_W4, $rowH, $countItem->expiration_date?->format('Y/m/d') ?? '', 0, 0, 'L');

        $this->pdf->SetXY($rightX, $y);
        $this->pdf->Cell(self::COL_W6, $rowH, $costPrice, 0, 0, 'R');

        // === Row 2: item_name | input_count | system_qty | actual_qty | diff_qty | diff_amount ===
        $y2 = $y + $rowH;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1, $rowH, $this->truncateText($countItem->item_name ?? '', self::COL_W1 - 2), 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y2);
        $this->pdf->Cell(self::COL_W2, $rowH, (string) ($countItem->input_count ?? 0), 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y2);
        $this->pdf->Cell(self::COL_W3, $rowH, $this->formatQuantity($countItem->system_quantity), 0, 0, 'R');

        $actualQty = $countItem->final_count_quantity ?? $countItem->second_count_quantity ?? $countItem->first_count_quantity;
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y2);
        $this->pdf->Cell(self::COL_W4, $rowH, $this->formatQuantity($actualQty), 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y2);
        $this->pdf->Cell(self::COL_W5, $rowH, $this->formatQuantity($countItem->difference_quantity), 0, 0, 'R');

        $this->pdf->SetXY($rightX, $y2);
        $this->pdf->Cell(self::COL_W6, $rowH, $diffAmount, 0, 0, 'R');

        $this->currentY = $y2 + $rowH;
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

    private function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '¥0';
        }

        $floatVal = (float) $value;

        if ($floatVal == 0) {
            return '¥0';
        }

        return '¥'.number_format($floatVal);
    }

    private function formatDiffMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '¥0';
        }

        $floatVal = (float) $value;

        if ($floatVal == 0) {
            return '¥0';
        }

        $prefix = $floatVal < 0 ? '-¥' : '¥';

        return $prefix.number_format(abs($floatVal));
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
