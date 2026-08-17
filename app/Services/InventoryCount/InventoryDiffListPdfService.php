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
    // Row2: item_name | input_count | start_system_qty | actual_qty | start_diff_qty | start_diff_amount
    // Row3 (diff list only): blank | blank | end_system_qty | actual_qty | end_diff_qty | end_diff_amount
    private const COL_W1 = 70;  // item_code / item_name

    private const COL_W2 = 20;  // location_no / input_count

    private const COL_W3 = 25;  // lot_no / system_qty

    private const COL_W4 = 20;  // expiration / actual_qty

    private const COL_W5 = 25;  // (blank) / diff_qty

    private const COL_W6 = 30;  // cost_price / diff_amount

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

    private function generatePdf(WmsInventoryCount $inventoryCount): string
    {
        $items = $this->queryItems($inventoryCount);

        $this->initPdf();

        $header = $this->buildHeader($inventoryCount);
        $currentShelfPrefix = null;
        $isFirstPage = true;

        if ($items->isEmpty()) {
            $this->addNewPage($header, null);
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, $this->emptyMessage, 0, 0, 'C');
        } else {
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
                $blockHeight = self::BLOCK_ROW_HEIGHT * $this->itemBlockRowCount();

                if ($this->currentY + $blockHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                    $this->addNewPage($header, $currentShelfPrefix);
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
        $query = WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id);

        if ($this->uncountedRound !== null) {
            $query->whereNull($this->roundColumn($this->uncountedRound));
        } else {
            $query->where(function ($query) {
                $query->whereNotNull('difference_quantity')
                    ->orWhereNotNull('final_count_quantity')
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
            return $items;
        }

        return $items
            ->map(fn (WmsInventoryCountItem $item): WmsInventoryCountItem => $this->attachDiffListValues($item))
            ->filter(fn (WmsInventoryCountItem $item): bool => $this->hasPrintableDifference($item))
            ->values();
    }

    private function attachDiffListValues(WmsInventoryCountItem $item): WmsInventoryCountItem
    {
        $actualQty = $this->actualQuantity($item);

        $startDifferenceQuantity = $item->difference_quantity !== null
            ? (float) $item->difference_quantity
            : ($actualQty !== null ? (float) $actualQty - (float) $item->system_quantity : null);

        if ($actualQty !== null) {
            $item->setAttribute('pdf_actual_quantity', $actualQty);
        }

        if ($startDifferenceQuantity !== null) {
            $startDiffAmount = $item->difference_amount !== null
                ? (float) $item->difference_amount
                : $startDifferenceQuantity * (float) $item->cost_price;

            $item->setAttribute('pdf_start_difference_quantity', $startDifferenceQuantity);
            $item->setAttribute('pdf_start_difference_amount', $startDiffAmount);
        }

        if ($actualQty !== null && $item->ending_system_quantity !== null) {
            $endDifferenceQuantity = (float) $actualQty - (float) $item->ending_system_quantity;

            $item->setAttribute('pdf_end_difference_quantity', $endDifferenceQuantity);
            $item->setAttribute('pdf_end_difference_amount', $endDifferenceQuantity * (float) $item->cost_price);
        }

        return $item;
    }

    private function hasPrintableDifference(WmsInventoryCountItem $item): bool
    {
        return (float) ($item->getAttribute('pdf_start_difference_quantity') ?? 0) !== 0.0
            || (float) ($item->getAttribute('pdf_end_difference_quantity') ?? 0) !== 0.0;
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

    private function shelfPagePrefix(WmsInventoryCountItem $item): ?string
    {
        $locationNo = trim((string) ($item->location_no ?? ''));

        if ($locationNo === '') {
            return null;
        }

        return mb_substr($locationNo, 0, 2);
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
        $this->pdf->Cell(self::COL_W3, $rowH, $this->uncountedRound === null ? '理論在庫(開始)' : '理論数量', 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y2);
        $this->pdf->Cell(self::COL_W4, $rowH, '実数量', 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y2);
        $this->pdf->Cell(self::COL_W5, $rowH, $this->uncountedRound === null ? '開始差異' : '差異数量', 0, 0, 'R');

        $this->pdf->SetXY($rightX, $y2);
        $this->pdf->Cell(self::COL_W6, $rowH, $this->uncountedRound === null ? '開始差額' : '差異金額', 0, 0, 'R');

        if ($this->uncountedRound === null) {
            $y3 = $y2 + $rowH;

            $this->pdf->SetXY($x, $y3);
            $this->pdf->Cell(self::COL_W1, $rowH, '終了比較', 0, 0, 'L');

            $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y3);
            $this->pdf->Cell(self::COL_W3, $rowH, '理論在庫(終了)', 0, 0, 'R');

            $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y3);
            $this->pdf->Cell(self::COL_W4, $rowH, '実数量', 0, 0, 'R');

            $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y3);
            $this->pdf->Cell(self::COL_W5, $rowH, '終了差異', 0, 0, 'R');

            $this->pdf->SetXY($rightX, $y3);
            $this->pdf->Cell(self::COL_W6, $rowH, '終了差額', 0, 0, 'R');
        }

        // Separator line below headers
        $sepY = $y + ($rowH * $this->itemBlockRowCount());
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
        $startDifferenceQuantity = $this->uncountedRound !== null
            ? null
            : $countItem->getAttribute('pdf_start_difference_quantity');
        $startDiffAmount = $this->uncountedRound !== null
            ? null
            : $countItem->getAttribute('pdf_start_difference_amount');
        $endDifferenceQuantity = $this->uncountedRound !== null
            ? null
            : $countItem->getAttribute('pdf_end_difference_quantity');
        $endDiffAmount = $this->uncountedRound !== null
            ? null
            : $countItem->getAttribute('pdf_end_difference_amount');

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

        // === Row 2: item_name | input_count | start_system_qty | actual_qty | start_diff_qty | start_diff_amount ===
        $y2 = $y + $rowH;

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1, $rowH, $this->truncateText($countItem->item_name ?? '', self::COL_W1 - 2), 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y2);
        $this->pdf->Cell(self::COL_W2, $rowH, (string) ($countItem->input_count ?? 0), 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y2);
        $this->pdf->Cell(self::COL_W3, $rowH, $this->formatQuantity($countItem->system_quantity), 0, 0, 'R');

        $actualQty = $this->uncountedRound !== null
            ? $countItem->{$this->roundColumn($this->uncountedRound)}
            : $countItem->getAttribute('pdf_actual_quantity');
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y2);
        $this->pdf->Cell(self::COL_W4, $rowH, $this->formatQuantity($actualQty), 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y2);
        $this->pdf->Cell(self::COL_W5, $rowH, $this->formatQuantity($this->uncountedRound !== null ? null : $startDifferenceQuantity), 0, 0, 'R');

        $this->pdf->SetXY($rightX, $y2);
        $this->pdf->Cell(self::COL_W6, $rowH, $this->uncountedRound !== null ? '¥0' : $this->formatDiffMoney($startDiffAmount), 0, 0, 'R');

        if ($this->uncountedRound === null) {
            $y3 = $y2 + $rowH;

            $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y3);
            $this->pdf->Cell(self::COL_W3, $rowH, $this->formatOptionalQuantity($countItem->ending_system_quantity), 0, 0, 'R');

            $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y3);
            $this->pdf->Cell(self::COL_W4, $rowH, $this->formatQuantity($actualQty), 0, 0, 'R');

            $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y3);
            $this->pdf->Cell(self::COL_W5, $rowH, $this->formatOptionalQuantity($endDifferenceQuantity), 0, 0, 'R');

            $this->pdf->SetXY($rightX, $y3);
            $this->pdf->Cell(self::COL_W6, $rowH, $this->formatOptionalDiffMoney($endDiffAmount), 0, 0, 'R');
        }

        $this->currentY = $y + ($rowH * $this->itemBlockRowCount());
    }

    private function itemBlockRowCount(): int
    {
        return $this->uncountedRound === null ? 3 : 2;
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

    private function formatOptionalDiffMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return $this->formatDiffMoney($value);
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
