<?php

namespace App\Services\InventoryCount;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use TCPDF;

/**
 * 棚卸指示書PDF描画サービス
 *
 * TCPDF座標描画のみ使用（HTML禁止）
 * PickingListPdfService と同じパターン
 *
 * サンプル: storage/specifications/20260523/棚卸指示書（H）.pdf
 * レイアウト: A4横 / ロケーション順 / フロア改ページ / 3行ブロック
 */
class InventoryInstructionPdfService
{
    // フォントサイズ（pt）
    private const FONT_SIZE_TITLE = 18;

    private const FONT_SIZE_HEADER = 9;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_SMALL = 7;

    private const FONT_SIZE_COL_HEADER = 7;

    // 行高さ（mm）
    private const LINE_HEIGHT = 5;

    // ブロック内の行高さ
    private const BLOCK_ROW_HEIGHT = 5.5;

    // 罫線幅（mm）
    private const LINE_WIDTH = 0.2;

    // マージン（mm）
    private const MARGIN_LEFT = 10;

    private const MARGIN_TOP = 8;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_BOTTOM = 12;

    // A4横
    private const PAGE_WIDTH = 297;

    private const PAGE_HEIGHT = 210;

    private const CONTENT_WIDTH = 277; // 297 - 10 - 10

    // 各列幅（mm）— サンプルPDFから実測
    // Row1: item_code | location_no | manufacturer | system_quantity
    // Row2: item_name | lot_no | volume_spec | cost_price | barcode
    // Row3: (empty) | received_at | expiration_date | total_amount
    //
    // 列配分（左→右）:
    // col1: アイテムコード/アイテム名称 = 90mm
    // col2: ロケーションNO/ロットNO/入庫日 = 60mm
    // col3: メーカー/容量+規格/賞味期限 = 55mm
    // col4: 理論在庫数量/仕入原価/合計金額 = 35mm
    // col5: バーコード = 37mm
    private const COL_W1 = 90;  // item_code / item_name / (blank)

    private const COL_W2 = 60;  // location_no / lot_no / received_at

    private const COL_W3 = 55;  // manufacturer / volume+spec / expiration_date

    private const COL_W4 = 35;  // system_quantity / cost_price / total_amount

    private const COL_W5 = 37;  // barcode (3 rows)

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    /**
     * 棚卸指示書PDF生成
     */
    public function generate(WmsInventoryCount $inventoryCount): string
    {
        $items = $this->queryItems($inventoryCount);

        $this->initPdf();

        $header = $this->buildHeader($inventoryCount);
        $currentFloor = null;
        $isFirstPage = true;

        foreach ($items as $item) {
            $itemFloor = $item->floor_name ?? '';

            // フロア変更 → 改ページ
            if ($currentFloor !== null && $currentFloor !== $itemFloor) {
                $this->addNewPage($header);
                $isFirstPage = false;
            } elseif ($isFirstPage && $currentFloor === null) {
                // 最初のページ
                $this->addNewPage($header);
                $isFirstPage = false;
            }

            $currentFloor = $itemFloor;

            // ブロック高さ = 3行分
            $blockHeight = self::BLOCK_ROW_HEIGHT * 3;

            // 改ページチェック
            if ($this->currentY + $blockHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM) {
                $this->addNewPage($header);
            }

            $this->renderItemBlock($item, $inventoryCount);
        }

        // ページが無い場合（items空）
        if ($this->pdf->getNumPages() === 0) {
            $this->addNewPage($header);
            $this->pdf->SetFont('kozgopromedium', '', 12);
            $this->pdf->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->pdf->Cell(self::CONTENT_WIDTH, 10, '対象データなし', 0, 0, 'C');
        }

        // ページ番号を全ページに描画
        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    // ========================================
    // データ取得
    // ========================================

    /**
     * @return \Illuminate\Database\Eloquent\Collection<WmsInventoryCountItem>
     */
    private function queryItems(WmsInventoryCount $inventoryCount): \Illuminate\Database\Eloquent\Collection
    {
        return WmsInventoryCountItem::where('inventory_count_id', $inventoryCount->id)
            ->with(['item' => function ($q) {
                $q->with(['manufacturer', 'container_type']);
            }])
            ->orderBy('floor_name')
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->get();
    }

    private function buildHeader(WmsInventoryCount $inventoryCount): array
    {
        return [
            'count_date' => $inventoryCount->count_date?->format('Y/m/d') ?? '',
            'warehouse_code' => $inventoryCount->warehouse_code ?? '',
            'warehouse_name' => $inventoryCount->warehouse_name ?? '',
        ];
    }

    // ========================================
    // PDF初期化
    // ========================================

    private function initPdf(): void
    {
        $this->pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle('棚卸指示書');
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
    }

    // ========================================
    // ページ追加
    // ========================================

    private function addNewPage(array $header): void
    {
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->renderPageHeader($header);
        $this->renderColumnHeaders();
    }

    // ========================================
    // ヘッダー描画
    // ========================================

    private function renderPageHeader(array $header): void
    {
        $leftX = self::MARGIN_LEFT;
        $contentW = self::CONTENT_WIDTH;

        // Row 1: タイトル（左）/ 棚卸日・倉庫コード・倉庫名称（中央）/ 印刷日時（右）
        // タイトル「棚卸指示書」
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY($leftX, $this->currentY);
        $this->pdf->Cell(60, 10, '棚卸指示書', 0, 0, 'L');

        // 棚卸日（タイトル右横）
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY($leftX + 62, $this->currentY + 2);
        $this->pdf->Cell(40, 5, '棚卸日 ' . $header['count_date'], 0, 0, 'L');

        // 倉庫コード・倉庫名称（中央付近）
        $this->pdf->SetXY($leftX + 108, $this->currentY + 2);
        $this->pdf->Cell(30, 5, '倉庫コード ' . $header['warehouse_code'], 0, 0, 'L');

        $this->pdf->SetXY($leftX + 145, $this->currentY + 2);
        $this->pdf->Cell(60, 5, '倉庫名称 ' . $header['warehouse_name'], 0, 0, 'L');

        // 印刷日時（右）
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

        // Row1 headers
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell(self::COL_W1, $rowH, 'アイテムコード', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y);
        $this->pdf->Cell(self::COL_W2, $rowH, 'ロケーションNO', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y);
        $this->pdf->Cell(self::COL_W3, $rowH, 'メーカー', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y);
        $this->pdf->Cell(self::COL_W4, $rowH, '理論在庫数量', 0, 0, 'R');

        // Row2 headers
        $y2 = $y + $rowH;
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1, $rowH, 'アイテム名称', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y2);
        $this->pdf->Cell(self::COL_W2, $rowH, 'ロットNO', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y2);
        $this->pdf->Cell(self::COL_W3 / 2, $rowH, '容量', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 / 2, $y2);
        $this->pdf->Cell(self::COL_W3 / 2, $rowH, '規格', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y2);
        $this->pdf->Cell(self::COL_W4, $rowH, '仕入原価', 0, 0, 'R');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4, $y2);
        $this->pdf->Cell(self::COL_W5, $rowH, 'バーコード', 0, 0, 'L');

        // Row3 headers
        $y3 = $y + $rowH * 2;
        $this->pdf->SetXY($x, $y3);
        $this->pdf->Cell(self::COL_W1, $rowH, '', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1, $y3);
        $this->pdf->Cell(self::COL_W2, $rowH, '入庫日', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y3);
        $this->pdf->Cell(self::COL_W3, $rowH, '賞味期限', 0, 0, 'L');

        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y3);
        $this->pdf->Cell(self::COL_W4, $rowH, '合計金額', 0, 0, 'R');

        $this->currentY = $y3 + $rowH;
    }

    // ========================================
    // アイテムブロック描画（3行1セット）
    // ========================================

    private function renderItemBlock(WmsInventoryCountItem $countItem, WmsInventoryCount $inventoryCount): void
    {
        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowH = self::BLOCK_ROW_HEIGHT;

        // 区切り線（ブロック上端）— 点線
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetLineStyle(['dash' => '2,1']);
        $this->pdf->Line($x, $y, $x + self::CONTENT_WIDTH, $y);
        $this->pdf->SetLineStyle(['dash' => '']);

        // データ準備
        $item = $countItem->item;
        $manufacturerName = $item?->manufacturer?->name ?? '';
        $volume = '';
        $spec = '';

        if ($item) {
            // 容量（volume + volume_unit）
            if ($item->volume && $item->volume_unit) {
                try {
                    $volumeUnit = \App\Enums\EVolumeUnit::from($item->volume_unit);
                    $volume = $item->volume . $volumeUnit->name();
                } catch (\Throwable) {
                    $volume = (string) $item->volume;
                }
            }

            // 規格（container_type名称）
            if ($item->container_type) {
                $spec = $item->container_type->name ?? '';
            }
        }

        $systemQty = $this->formatQuantity($countItem->system_quantity);
        $costPrice = $this->formatMoney($countItem->cost_price);
        $totalAmount = $this->formatMoney(
            (float) $countItem->system_quantity * (float) $countItem->cost_price
        );

        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);

        // === Row 1: item_code | location_no | manufacturer | system_quantity ===
        $y1 = $y;

        // item_code（太字）
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x, $y1);
        $this->pdf->Cell(self::COL_W1, $rowH, $countItem->item_code ?? '', 0, 0, 'L');

        // location_no（太字）
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + self::COL_W1, $y1);
        $this->pdf->Cell(self::COL_W2, $rowH, $countItem->location_no ?? '', 0, 0, 'L');

        // manufacturer
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y1);
        $this->pdf->Cell(self::COL_W3, $rowH, $this->truncateText($manufacturerName, self::COL_W3 - 2), 0, 0, 'L');

        // system_quantity（右寄せ）
        $this->pdf->SetFont('kozgopromedium', 'B', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y1);
        $this->pdf->Cell(self::COL_W4, $rowH, $systemQty, 0, 0, 'R');

        // === Row 2: item_name | lot_no | volume | spec | cost_price | barcode ===
        $y2 = $y + $rowH;

        // item_name
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x, $y2);
        $this->pdf->Cell(self::COL_W1, $rowH, $this->truncateText($countItem->item_name ?? '', self::COL_W1 - 2), 0, 0, 'L');

        // lot_no
        $this->pdf->SetXY($x + self::COL_W1, $y2);
        $this->pdf->Cell(self::COL_W2, $rowH, $countItem->lot_no ?? '', 0, 0, 'L');

        // volume（COL_W3の前半）
        $halfW3 = self::COL_W3 / 2;
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y2);
        $this->pdf->Cell($halfW3, $rowH, $volume, 0, 0, 'L');

        // spec（COL_W3の後半）
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + $halfW3, $y2);
        $this->pdf->Cell($halfW3, $rowH, $this->truncateText($spec, $halfW3 - 2), 0, 0, 'L');

        // cost_price（右寄せ）
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y2);
        $this->pdf->Cell(self::COL_W4, $rowH, $costPrice, 0, 0, 'R');

        // === Row 3: (empty) | received_at | expiration_date | total_amount ===
        $y3 = $y + $rowH * 2;

        // received_at
        $receivedAt = $countItem->received_at ? $countItem->received_at->format('Y/m/d') : '';
        $this->pdf->SetXY($x + self::COL_W1, $y3);
        $this->pdf->Cell(self::COL_W2, $rowH, $receivedAt, 0, 0, 'L');

        // expiration_date
        $expirationDate = $countItem->expiration_date ? $countItem->expiration_date->format('Y/m/d') : '';
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2, $y3);
        $this->pdf->Cell(self::COL_W3, $rowH, $expirationDate, 0, 0, 'L');

        // total_amount（右寄せ）
        $this->pdf->SetXY($x + self::COL_W1 + self::COL_W2 + self::COL_W3, $y3);
        $this->pdf->Cell(self::COL_W4, $rowH, $totalAmount, 0, 0, 'R');

        // === バーコード（Row1-3の右端、3行またぎ）===
        $barcodeX = $x + self::COL_W1 + self::COL_W2 + self::COL_W3 + self::COL_W4 + 1;
        $barcodeW = self::COL_W5 - 2;
        $barcodeH = ($rowH * 3 - 1) / 2;
        $barcode = $countItem->barcode ?? $countItem->item_code ?? '';

        if ($barcode !== '') {
            $this->pdf->write1DBarcode(
                $barcode,
                'C128',
                $barcodeX,
                $y1 + 0.5,
                $barcodeW,
                $barcodeH,
                0.3,
                ['position' => '', 'border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => false, 'font' => 'kozgopromedium', 'fontsize' => 0, 'stretchtext' => 0],
                'N'
            );
        }

        $this->currentY = $y3 + $rowH;
    }

    // ========================================
    // ページ番号描画
    // ========================================

    private function renderPageNumbers(): void
    {
        $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_HEADER);

        for ($i = 1; $i <= $this->totalPages; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} ／ {$this->totalPages}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::PAGE_WIDTH - self::MARGIN_RIGHT - $textWidth;
            $y = self::MARGIN_TOP;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, 5, $pageText, 0, 0, 'R');
        }
    }

    // ========================================
    // ユーティリティ
    // ========================================

    private function formatQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $floatVal = (float) $value;

        // 整数の場合はカンマ区切り
        if ($floatVal == (int) $floatVal) {
            return number_format((int) $floatVal);
        }

        return number_format($floatVal, 3);
    }

    private function formatMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $floatVal = (float) $value;

        if ($floatVal == 0) {
            return '';
        }

        return '¥' . number_format($floatVal, 2);
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

        return $result . $ellipsis;
    }
}
