<?php

namespace App\Services\PickingList;

use TCPDF;

/**
 * ピッキングリストPDF描画サービス
 *
 * TCPDF座標描画のみ使用（HTML禁止）
 * PurchaseOrderPdfService と同じパターン
 */
class PickingListPdfService
{
    // フォントサイズ（pt）
    private const FONT_SIZE_TITLE = 14;

    private const FONT_SIZE_HEADER = 10;

    private const FONT_SIZE_NORMAL = 8;

    private const FONT_SIZE_SMALL = 7;

    // 行高さ（mm）
    private const LINE_HEIGHT = 5;

    private const TABLE_ROW_HEIGHT = 6;

    // 罫線幅（mm）
    private const LINE_WIDTH = 0.2;

    private const LINE_WIDTH_THICK = 0.4;

    // マージン（mm）
    private const MARGIN = 10;

    private const MARGIN_BOTTOM = 15;

    private TCPDF $pdf;

    private float $currentY;

    private int $totalPages = 0;

    // ========================================
    // 1次リスト（A4横）
    // ========================================

    private const PRIMARY_PAGE_WIDTH = 210;

    private const PRIMARY_PAGE_HEIGHT = 297;

    private const PRIMARY_CONTENT_WIDTH = 190; // 210 - 10 - 10

    private const PRIMARY_TABLE_ROW_HEIGHT = 7;

    private const PRIMARY_COL_WIDTHS = [
        'no' => 7,
        'location' => 20,
        'item_code' => 22,
        'item_name' => 68,
        'packaging' => 18,
        'case_qty' => 12,
        'piece_qty' => 12,
        'shortage_qty' => 14,
        'total_piece_qty' => 17,
    ];

    /**
     * 1次ピッキングリストPDF描画
     */
    public function renderPrimaryPdf(array $data): string
    {
        $this->initPdf('P', 'ピッキングリスト（1次）');
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN;

        $this->renderPrimaryHeader($data['header']);
        $this->renderPrimaryTable($data['items'], $data['header']);
        $this->renderPrimarySummary($data['summary']);

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::PRIMARY_PAGE_WIDTH, self::PRIMARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    private function renderPrimaryHeader(array $header): void
    {
        // タイトル
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $title = $header['list_title'] ?? '1次ピッキングリスト';
        if (! empty($header['floor_name'])) {
            $title .= ' / '.$header['floor_name'];
        }
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, 8, $title, 0, 0, 'C');
        $this->currentY += 10;

        // ヘッダー情報（左右に配置）
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);

        // 左側
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '波動番号:'.($header['wave_no'] ?? ''), 0, 0, 'L');

        // 右側
        $this->pdf->SetXY(self::MARGIN + 95, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '印刷日時: '.now()->format('Y-m-d H:i'), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT;

        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '出荷日: '.($header['shipping_date'] ?? ''), 0, 0, 'L');

        $this->pdf->SetXY(self::MARGIN + 95, $this->currentY);
        $warehouseText = '倉庫: '.($header['warehouse_name'] ?? '');
        if (! empty($header['floor_name'])) {
            $warehouseText .= ' / フロア: '.$header['floor_name'];
        }

        $this->pdf->Cell(95, self::LINE_HEIGHT, $warehouseText, 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT + 3;
    }

    private function renderPrimaryTableHeader(): void
    {
        $headers = ['No', '棚番', '商品CD', '商品名', '荷姿', 'ケース', 'バラ', '欠品数', '総バラ数'];
        $widths = array_values(self::PRIMARY_COL_WIDTHS);
        $rowH = self::PRIMARY_TABLE_ROW_HEIGHT;

        $this->pdf->SetFont('kozminproregular', 'B', 9);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $x = self::MARGIN;
        $y = $this->currentY;

        $tableWidth = array_sum($widths);
        $this->pdf->Line($x, $y, $x + $tableWidth, $y);

        foreach ($headers as $i => $header) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($widths[$i], $rowH, $header, 0, 0, 'C');
            $this->pdf->Line($x, $y, $x, $y + $rowH);
            $x += $widths[$i];
        }
        $this->pdf->Line($x, $y, $x, $y + $rowH);

        $this->pdf->Line(self::MARGIN, $y + $rowH, self::MARGIN + $tableWidth, $y + $rowH);
        $this->currentY = $y + $rowH;
    }

    private function renderPrimaryTable(array $items, array $header): void
    {
        $this->renderPrimaryTableHeader();

        $widths = array_values(self::PRIMARY_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $minRowH = self::PRIMARY_TABLE_ROW_HEIGHT;
        $bodyFontSize = 10;

        foreach ($items as $index => $item) {
            $this->pdf->SetFont('kozminproregular', 'B', $bodyFontSize);
            $nameH = $this->pdf->getStringHeight($widths[3] - 2, $item['item_name']);
            $rowH = max($minRowH, $nameH);

            if ($this->currentY + $rowH > self::PRIMARY_PAGE_HEIGHT - self::MARGIN_BOTTOM - 10) {
                $this->pdf->AddPage();
                $this->currentY = self::MARGIN;
                $this->renderPrimaryHeader($header);
                $this->renderPrimaryTableHeader();
            }

            $x = self::MARGIN;
            $y = $this->currentY;

            $rowData = [
                $index + 1,
                $item['location_code'] ?? '',
                $item['item_code'],
                $item['item_name'],
                $item['packaging'] ?? '',
                $this->formatPdfQuantity($item['case_qty'] ?? null),
                $this->formatPdfQuantity($item['piece_qty'] ?? null),
                $this->formatPdfQuantity($item['shortage_qty'] ?? null),
                $this->formatPdfQuantity($item['total_piece_qty'] ?? null),
            ];

            $aligns = ['R', 'C', 'C', 'L', 'C', 'C', 'C', 'C', 'C'];

            foreach ($rowData as $i => $value) {
                $cellX = $x + ($aligns[$i] === 'L' ? 1 : 0);
                $cellW = $widths[$i] - ($aligns[$i] === 'L' ? 1 : 0);

                if ($i === 3) {
                    $this->pdf->SetFont('kozminproregular', 'B', $bodyFontSize);
                    $this->pdf->MultiCell($cellW, $minRowH, $value, 0, 'L', false, 0, $cellX, $y);
                    $this->pdf->SetFont('kozminproregular', '', $bodyFontSize);
                } else {
                    $this->pdf->SetFont('kozminproregular', '', $bodyFontSize);
                    $this->pdf->SetXY($cellX, $y);
                    $this->pdf->Cell($cellW, $rowH, $value, 0, 0, $aligns[$i]);
                }

                $this->pdf->Line($x, $y, $x, $y + $rowH);
                $x += $widths[$i];
            }
            $this->pdf->Line($x, $y, $x, $y + $rowH);
            $this->pdf->Line(self::MARGIN, $y + $rowH, self::MARGIN + $tableWidth, $y + $rowH);

            $this->currentY = $y + $rowH;
        }
    }

    private function formatPdfQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return (string) $value;
    }

    private function renderPrimarySummary(array $summary): void
    {
        $this->currentY += 3;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, self::LINE_HEIGHT,
            sprintf('合計  SKU数: %d  /  総数量: %d  /  ケース計: %d  /  バラ計: %d  /  欠品数: %d  /  総バラ数: %d',
                $summary['sku_count'], $summary['total_qty'], $summary['total_case'], $summary['total_piece'], $summary['total_shortage'] ?? 0, $summary['total_piece_qty'] ?? 0
            ), 0, 0, 'L');
    }

    // ========================================
    // 1次欠品リスト（A4縦）
    // ========================================

    private const SHORTAGE_COL_WIDTHS = [
        'no' => 7,
        'serial_id' => 14,
        'partner_name' => 26,
        'salesman' => 16,
        'location' => 14,
        'item_code' => 18,
        'item_name' => 34,
        'packaging' => 12,
        'qty_label' => 10,
        'planned_qty' => 13,
        'allocated_qty' => 13,
        'shortage_qty' => 13,
    ];

    public function renderShortagePdf(array $data): string
    {
        $this->initPdf('P', '欠品リスト（1次）');
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN;

        $this->renderShortageHeader($data['header']);
        $this->renderShortageTable($data['items'], $data['header']);
        $this->renderShortageSummary($data['summary']);

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::PRIMARY_PAGE_WIDTH, self::PRIMARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    private function renderShortageHeader(array $header): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, 8, '1次欠品リスト', 0, 0, 'C');
        $this->currentY += 10;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);

        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '波動番号:'.($header['wave_no'] ?? ''), 0, 0, 'L');

        $this->pdf->SetXY(self::MARGIN + 95, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '印刷日時: '.now()->format('Y-m-d H:i'), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT;

        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '出荷日: '.($header['shipping_date'] ?? ''), 0, 0, 'L');

        $this->pdf->SetXY(self::MARGIN + 95, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '倉庫: '.($header['warehouse_name'] ?? ''), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT + 3;
    }

    private function renderShortageTableHeader(): void
    {
        $headers = ['No', '伝票番号', '得意先名', '担当営業', '棚番', '商品CD', '商品名', '荷姿', '単位', '受注数', '引当数', '欠品数'];
        $widths = array_values(self::SHORTAGE_COL_WIDTHS);
        $rowH = self::PRIMARY_TABLE_ROW_HEIGHT;

        $this->pdf->SetFont('kozminproregular', 'B', 7);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $x = self::MARGIN;
        $y = $this->currentY;

        $tableWidth = array_sum($widths);
        $this->pdf->Line($x, $y, $x + $tableWidth, $y);

        foreach ($headers as $i => $header) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($widths[$i], $rowH, $header, 0, 0, 'C');
            $this->pdf->Line($x, $y, $x, $y + $rowH);
            $x += $widths[$i];
        }
        $this->pdf->Line($x, $y, $x, $y + $rowH);

        $this->pdf->Line(self::MARGIN, $y + $rowH, self::MARGIN + $tableWidth, $y + $rowH);
        $this->currentY = $y + $rowH;
    }

    private function renderShortageTable(array $items, array $header): void
    {
        $this->renderShortageTableHeader();

        $widths = array_values(self::SHORTAGE_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $minRowH = self::PRIMARY_TABLE_ROW_HEIGHT;
        $bodyFontSize = 7;
        $itemNameColIndex = 6;

        foreach ($items as $index => $item) {
            $this->pdf->SetFont('kozminproregular', 'B', $bodyFontSize);
            $nameH = $this->pdf->getStringHeight($widths[$itemNameColIndex] - 2, $item['item_name']);
            $rowH = max($minRowH, $nameH);

            if ($this->currentY + $rowH > self::PRIMARY_PAGE_HEIGHT - self::MARGIN_BOTTOM - 10) {
                $this->pdf->AddPage();
                $this->currentY = self::MARGIN;
                $this->renderShortageHeader($header);
                $this->renderShortageTableHeader();
            }

            $x = self::MARGIN;
            $y = $this->currentY;

            $rowData = [
                $index + 1,
                $item['serial_id'] ?? '',
                $item['partner_name'] ?? '',
                $item['salesman_name'] ?? '',
                $item['location_code'] ?? '',
                $item['item_code'],
                $item['item_name'],
                $item['packaging'] ?? '',
                $item['qty_label'],
                $item['planned_qty'],
                $item['allocated_qty'],
                $item['shortage_qty'],
            ];

            $aligns = ['R', 'C', 'L', 'L', 'C', 'C', 'L', 'C', 'C', 'C', 'C', 'C'];

            foreach ($rowData as $i => $value) {
                $cellX = $x + ($aligns[$i] === 'L' ? 1 : 0);
                $cellW = $widths[$i] - ($aligns[$i] === 'L' ? 1 : 0);

                if ($i === $itemNameColIndex) {
                    $this->pdf->SetFont('kozminproregular', 'B', $bodyFontSize);
                    $this->pdf->MultiCell($cellW, $minRowH, $value, 0, 'L', false, 0, $cellX, $y);
                    $this->pdf->SetFont('kozminproregular', '', $bodyFontSize);
                } else {
                    $this->pdf->SetFont('kozminproregular', '', $bodyFontSize);
                    $this->pdf->SetXY($cellX, $y);
                    $this->pdf->Cell($cellW, $rowH, $value, 0, 0, $aligns[$i]);
                }

                $this->pdf->Line($x, $y, $x, $y + $rowH);
                $x += $widths[$i];
            }
            $this->pdf->Line($x, $y, $x, $y + $rowH);
            $this->pdf->Line(self::MARGIN, $y + $rowH, self::MARGIN + $tableWidth, $y + $rowH);

            $this->currentY = $y + $rowH;
        }
    }

    private function renderShortageSummary(array $summary): void
    {
        $this->currentY += 3;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, self::LINE_HEIGHT,
            sprintf('合計  件数: %d  /  欠品総数: %d',
                $summary['sku_count'], $summary['total_shortage']
            ), 0, 0, 'L');
    }

    // ========================================
    // 2次リスト（A4縦）
    // ========================================

    private const SECONDARY_PAGE_WIDTH = 210;

    private const SECONDARY_PAGE_HEIGHT = 297;

    private const SECONDARY_CONTENT_WIDTH = 190;

    private const SECONDARY_COL_WIDTHS = [
        'location' => 30,
        'item_code' => 28,
        'item_name' => 72,
        'qty' => 25,
        'check' => 15,
        'qty_type' => 20,
    ];

    /**
     * 2次ピッキングリストPDF描画
     */
    public function renderSecondaryPdf(array $data): string
    {
        $this->initPdf('P', 'ピッキングリスト（2次）');
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN;

        $this->renderSecondaryHeader($data['header']);
        $this->renderSecondaryTable($data['items'], $data['header']);
        $this->renderSecondarySummary($data['summary']);

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::SECONDARY_PAGE_WIDTH, self::SECONDARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    private function renderSecondaryHeader(array $header): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::SECONDARY_CONTENT_WIDTH, 8, '2次ピッキングリスト（作業者別）', 0, 0, 'C');
        $this->currentY += 10;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);

        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '波動: '.($header['wave_no'] ?? ''), 0, 0, 'L');
        $this->pdf->SetXY(self::MARGIN + 95, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '出荷日: '.($header['shipping_date'] ?? ''), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT;

        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, 'コース: '.($header['course_name'] ?? ''), 0, 0, 'L');
        $this->pdf->SetXY(self::MARGIN + 95, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, 'ピッカー: '.($header['picker_name'] ?? ''), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT;

        if (! empty($header['area_name'])) {
            $this->pdf->SetXY(self::MARGIN, $this->currentY);
            $this->pdf->Cell(95, self::LINE_HEIGHT, 'エリア: '.$header['area_name'], 0, 0, 'L');
            $this->currentY += self::LINE_HEIGHT;
        }

        $this->currentY += 3;
    }

    private function renderSecondaryTableHeader(): void
    {
        $headers = ['棚番', '商品CD', '商品名', '数量', 'チェック', '区分'];
        $widths = array_values(self::SECONDARY_COL_WIDTHS);

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $x = self::MARGIN;
        $y = $this->currentY;
        $tableWidth = array_sum($widths);

        $this->pdf->Line($x, $y, $x + $tableWidth, $y);

        foreach ($headers as $i => $header) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($widths[$i], self::TABLE_ROW_HEIGHT, $header, 0, 0, 'C');
            $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
            $x += $widths[$i];
        }
        $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
        $this->pdf->Line(self::MARGIN, $y + self::TABLE_ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::TABLE_ROW_HEIGHT);

        $this->currentY = $y + self::TABLE_ROW_HEIGHT;
    }

    private function renderSecondaryTable(array $items, array $header, ?callable $pageHeaderRenderer = null): void
    {
        $renderPageHeader = $pageHeaderRenderer ?? fn (array $h) => $this->renderSecondaryHeader($h);

        $this->renderSecondaryTableHeader();

        $widths = array_values(self::SECONDARY_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $childRowHeight = 5;

        foreach ($items as $item) {
            // 子行数を計算して改ページチェック
            $destCount = count($item['destinations']);
            $totalHeight = self::TABLE_ROW_HEIGHT + ($destCount * $childRowHeight);

            if ($this->currentY + $totalHeight > self::SECONDARY_PAGE_HEIGHT - self::MARGIN_BOTTOM - 10) {
                $this->pdf->AddPage();
                $this->currentY = self::MARGIN;
                $renderPageHeader($header);
                $this->renderSecondaryTableHeader();
            }

            // 親行
            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
            $x = self::MARGIN;
            $y = $this->currentY;

            $rowData = [
                $item['location_code'],
                $item['item_code'],
                $this->truncateText($item['item_name'], $widths[2] - 2),
                $item['total_pick_qty'],
                '□',
                $item['qty_type'],
            ];
            $aligns = ['C', 'C', 'L', 'R', 'C', 'L'];

            foreach ($rowData as $i => $value) {
                $cellX = $x + ($aligns[$i] === 'L' ? 1 : 0);
                $cellW = $widths[$i] - ($aligns[$i] === 'L' ? 1 : 0);
                $this->pdf->SetXY($cellX, $y);
                $this->pdf->Cell($cellW, self::TABLE_ROW_HEIGHT, $value, 0, 0, $aligns[$i]);
                $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
                $x += $widths[$i];
            }
            $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
            $this->pdf->Line(self::MARGIN, $y + self::TABLE_ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::TABLE_ROW_HEIGHT);

            $this->currentY = $y + self::TABLE_ROW_HEIGHT;

            // 子行（納品先内訳）
            if (! empty($item['destinations'])) {
                $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
                $this->pdf->SetLineWidth(self::LINE_WIDTH);

                foreach ($item['destinations'] as $dest) {
                    $childY = $this->currentY;

                    // 棚番列はスキップ、商品名列にインデント付き納品先名
                    $destX = self::MARGIN + $widths[0]; // 棚番スキップ
                    // 商品CD列 + 商品名列にまたがって納品先名
                    $nameWidth = $widths[1] + $widths[2];
                    $this->pdf->SetXY($destX + 2, $childY);
                    $this->pdf->Cell($nameWidth - 2, $childRowHeight, '├ '.$this->truncateText($dest['name'], $nameWidth - 10), 0, 0, 'L');

                    // 数量
                    $qtyX = self::MARGIN + $widths[0] + $widths[1] + $widths[2];
                    $this->pdf->SetXY($qtyX, $childY);
                    $this->pdf->Cell($widths[3], $childRowHeight, $dest['qty'], 0, 0, 'R');

                    // 区分
                    $typeX = $qtyX + $widths[3] + $widths[4];
                    $this->pdf->SetXY($typeX + 1, $childY);
                    $this->pdf->Cell($widths[5] - 1, $childRowHeight, $dest['qty_type'], 0, 0, 'L');

                    // 左右の縦線
                    $this->pdf->Line(self::MARGIN, $childY, self::MARGIN, $childY + $childRowHeight);
                    $this->pdf->Line(self::MARGIN + $tableWidth, $childY, self::MARGIN + $tableWidth, $childY + $childRowHeight);

                    $this->currentY = $childY + $childRowHeight;
                }

                // 子行グループの下線
                $this->pdf->SetLineWidth(self::LINE_WIDTH);
                $this->pdf->Line(self::MARGIN, $this->currentY, self::MARGIN + $tableWidth, $this->currentY);
            }
        }
    }

    private function renderSecondarySummary(array $summary): void
    {
        $this->currentY += 3;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::SECONDARY_CONTENT_WIDTH, self::LINE_HEIGHT,
            sprintf('合計  品目数: %d  /  棚数: %d  /  総数量: %d',
                $summary['item_count'], $summary['location_count'], $summary['total_qty']
            ), 0, 0, 'L');
    }

    // ========================================
    // 3次リスト（A4縦 - 配送コース別、2次と同じレイアウト）
    // ========================================

    private const TERTIARY_COL_WIDTHS = [
        'location' => 28,
        'item_code' => 28,
        'item_name' => 72,
        'case_qty' => 20,
        'piece_qty' => 20,
        'check' => 22,
    ];

    /**
     * 3次ピッキングリストPDF描画（配送コース別）
     *
     * @param  array  $dataList  配送コースごとの {header, items, summary} 配列
     */
    public function renderTertiaryPdf(array $dataList): string
    {
        $this->initPdf('P', 'ピッキングリスト（3次）');

        foreach ($dataList as $data) {
            if (empty($data['items'])) {
                continue;
            }

            $this->pdf->AddPage();
            $this->currentY = self::MARGIN;

            $this->renderTertiaryPageHeader($data['header']);
            $this->renderTertiaryTable($data['items'], $data['header']);
            $this->renderTertiarySummary($data['summary']);
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::SECONDARY_PAGE_WIDTH, self::SECONDARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    private function renderTertiaryPageHeader(array $header): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::SECONDARY_CONTENT_WIDTH, 8, '3次ピッキングリスト（配送コース別）', 0, 0, 'C');
        $this->currentY += 10;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);

        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '波動: '.($header['wave_no'] ?? ''), 0, 0, 'L');
        $this->pdf->SetXY(self::MARGIN + 95, $this->currentY);
        $this->pdf->Cell(95, self::LINE_HEIGHT, '出荷日: '.($header['shipping_date'] ?? ''), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT;

        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(190, self::LINE_HEIGHT, 'コース: '.($header['course_name'] ?? ''), 0, 0, 'L');
        $this->currentY += self::LINE_HEIGHT + 3;
    }

    private function renderTertiaryTableHeader(): void
    {
        $headers = ['棚番', '商品CD', '商品名', 'ケース', 'バラ', 'チェック'];
        $widths = array_values(self::TERTIARY_COL_WIDTHS);

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $x = self::MARGIN;
        $y = $this->currentY;
        $tableWidth = array_sum($widths);

        $this->pdf->Line($x, $y, $x + $tableWidth, $y);

        foreach ($headers as $i => $header) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($widths[$i], self::TABLE_ROW_HEIGHT, $header, 0, 0, 'C');
            $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
            $x += $widths[$i];
        }
        $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
        $this->pdf->Line(self::MARGIN, $y + self::TABLE_ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::TABLE_ROW_HEIGHT);

        $this->currentY = $y + self::TABLE_ROW_HEIGHT;
    }

    private function renderTertiaryTable(array $items, array $header): void
    {
        $this->renderTertiaryTableHeader();

        $widths = array_values(self::TERTIARY_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $childRowHeight = 5;

        foreach ($items as $item) {
            $destCount = count($item['destinations']);
            $totalHeight = self::TABLE_ROW_HEIGHT + ($destCount * $childRowHeight);

            if ($this->currentY + $totalHeight > self::SECONDARY_PAGE_HEIGHT - self::MARGIN_BOTTOM - 10) {
                $this->pdf->AddPage();
                $this->currentY = self::MARGIN;
                $this->renderTertiaryPageHeader($header);
                $this->renderTertiaryTableHeader();
            }

            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
            $x = self::MARGIN;
            $y = $this->currentY;

            $rowData = [
                $item['location_code'],
                $item['item_code'],
                $this->truncateText($item['item_name'], $widths[2] - 2),
                $item['case_qty'] ?: '',
                $item['piece_qty'] ?: '',
                '□',
            ];
            $aligns = ['C', 'C', 'L', 'R', 'R', 'C'];

            foreach ($rowData as $i => $value) {
                $cellX = $x + ($aligns[$i] === 'L' ? 1 : 0);
                $cellW = $widths[$i] - ($aligns[$i] === 'L' ? 1 : 0);
                $this->pdf->SetXY($cellX, $y);
                $this->pdf->Cell($cellW, self::TABLE_ROW_HEIGHT, $value, 0, 0, $aligns[$i]);
                $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
                $x += $widths[$i];
            }
            $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
            $this->pdf->Line(self::MARGIN, $y + self::TABLE_ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::TABLE_ROW_HEIGHT);

            $this->currentY = $y + self::TABLE_ROW_HEIGHT;

            if (! empty($item['destinations'])) {
                $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
                $this->pdf->SetLineWidth(self::LINE_WIDTH);

                foreach ($item['destinations'] as $dest) {
                    $childY = $this->currentY;
                    $destX = self::MARGIN + $widths[0];
                    $nameWidth = $widths[1] + $widths[2];

                    $this->pdf->SetXY($destX + 2, $childY);
                    $this->pdf->Cell($nameWidth - 2, $childRowHeight, '├ '.$this->truncateText($dest['name'], $nameWidth - 10), 0, 0, 'L');

                    $caseX = self::MARGIN + $widths[0] + $widths[1] + $widths[2];
                    $this->pdf->SetXY($caseX, $childY);
                    $this->pdf->Cell($widths[3], $childRowHeight, $dest['case_qty'] ?: '', 0, 0, 'R');

                    $pieceX = $caseX + $widths[3];
                    $this->pdf->SetXY($pieceX, $childY);
                    $this->pdf->Cell($widths[4], $childRowHeight, $dest['piece_qty'] ?: '', 0, 0, 'R');

                    $this->pdf->Line(self::MARGIN, $childY, self::MARGIN, $childY + $childRowHeight);
                    $this->pdf->Line(self::MARGIN + $tableWidth, $childY, self::MARGIN + $tableWidth, $childY + $childRowHeight);

                    $this->currentY = $childY + $childRowHeight;
                }

                $this->pdf->SetLineWidth(self::LINE_WIDTH);
                $this->pdf->Line(self::MARGIN, $this->currentY, self::MARGIN + $tableWidth, $this->currentY);
            }
        }

        $this->renderTertiaryTotalRow($items, $header);
    }

    private function renderTertiaryTotalRow(array $items, array $header): void
    {
        if ($this->currentY + self::TABLE_ROW_HEIGHT > self::SECONDARY_PAGE_HEIGHT - self::MARGIN_BOTTOM - 10) {
            $this->pdf->AddPage();
            $this->currentY = self::MARGIN;
            $this->renderTertiaryPageHeader($header);
            $this->renderTertiaryTableHeader();
        }

        $widths = array_values(self::TERTIARY_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $totalCase = array_sum(array_column($items, 'case_qty'));
        $totalPiece = array_sum(array_column($items, 'piece_qty'));
        $x = self::MARGIN;
        $y = $this->currentY;

        $this->pdf->SetFont('kozminproregular', 'B', self::FONT_SIZE_NORMAL);

        $rowData = ['', '', '合計', $totalCase ?: '', $totalPiece ?: '', ''];
        $aligns = ['C', 'C', 'R', 'C', 'C', 'C'];

        foreach ($rowData as $i => $value) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($widths[$i], self::TABLE_ROW_HEIGHT, $value, 0, 0, $aligns[$i]);
            $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
            $x += $widths[$i];
        }

        $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
        $this->pdf->Line(self::MARGIN, $y + self::TABLE_ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::TABLE_ROW_HEIGHT);
        $this->currentY = $y + self::TABLE_ROW_HEIGHT;
    }

    private function renderTertiarySummary(array $summary): void
    {
        $this->currentY += 3;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::SECONDARY_CONTENT_WIDTH, self::LINE_HEIGHT,
            sprintf('合計  品目数: %d  /  棚数: %d  /  ケース: %d  /  バラ: %d',
                $summary['item_count'], $summary['location_count'], $summary['total_case'] ?? 0, $summary['total_piece'] ?? 0
            ), 0, 0, 'L');
    }

    // ========================================
    // 横持ち出荷ピッキングリスト（A4横）
    // ========================================

    // 横持ち出荷: No/得意先/商品CD/JAN CD/商品名/規格/棚番/区分/数量/出荷数
    private const PROXY_COL_WIDTHS = [
        'no' => 10,
        'customer' => 42,
        'item_code' => 25,
        'jan_code' => 32,
        'item_name' => 62,
        'packaging' => 22,
        'location' => 28,
        'qty_type' => 15,
        'qty' => 16,
        'ship_qty' => 25,
    ];

    /**
     * 横持ち出荷ピッキングリストPDF描画（配送コース別ページ分割）
     */
    public function renderProxyShipmentPdf(array $data): string
    {
        if (empty($data['courses'])) {
            return '';
        }

        $this->initPdf('L', '横持ち出荷ピッキングリスト');

        foreach ($data['courses'] as $courseData) {
            if (empty($courseData['items'])) {
                continue;
            }

            $this->pdf->AddPage();
            $this->currentY = self::MARGIN;

            $this->renderProxyHeader($courseData['header']);
            $this->renderProxyTable($courseData['items'], $courseData['header']);
            $this->renderProxySummary($courseData['summary']);
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::PRIMARY_PAGE_WIDTH, self::PRIMARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    private function renderProxyHeader(array $header): void
    {
        // 左上: 配��コース���（大きめ）
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH / 2, 8, $header['course_name'] ?? '', 0, 0, 'L');

        // 右上: タイトル
        $this->pdf->SetXY(self::MARGIN + self::PRIMARY_CONTENT_WIDTH / 2, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH / 2, 8, '横持ち出荷ピッキングリス��', 0, 0, 'R');
        $this->currentY += 10;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);

        // 左側: 倉庫
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(140, self::LINE_HEIGHT, 'ピッキング倉庫: '.($header['warehouse_name'] ?? ''), 0, 0, 'L');

        // 右側: ��刷日時
        $this->pdf->SetXY(self::MARGIN + 140, $this->currentY);
        $this->pdf->Cell(137, self::LINE_HEIGHT, '印刷日時: '.now()->format('Y-m-d H:i'), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT;

        // 左側: 出荷���
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(140, self::LINE_HEIGHT, '出荷日: '.($header['shipment_date'] ?? ''), 0, 0, 'L');

        // 右側: 担当者名
        $this->pdf->SetXY(self::MARGIN + 140, $this->currentY);
        $this->pdf->Cell(137, self::LINE_HEIGHT, '担当者名: '.($header['operator_name'] ?? ''), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT + 3;
    }

    private function renderProxyTableHeader(): void
    {
        $headers = ['No', '得意先', '商品CD', 'JAN CD', '商品名', '規格', '棚番', '区分', '数量', '出荷数'];
        $aligns = ['C', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C', 'C'];
        $widths = array_values(self::PROXY_COL_WIDTHS);

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $x = self::MARGIN;
        $y = $this->currentY;
        $tableWidth = array_sum($widths);

        // 上線
        $this->pdf->Line($x, $y, $x + $tableWidth, $y);

        foreach ($headers as $i => $header) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($widths[$i], self::TABLE_ROW_HEIGHT, $header, 0, 0, $aligns[$i]);
            $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
            $x += $widths[$i];
        }
        $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);

        // 下線
        $this->pdf->Line(self::MARGIN, $y + self::TABLE_ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::TABLE_ROW_HEIGHT);
        $this->currentY = $y + self::TABLE_ROW_HEIGHT;
    }

    private function renderProxyTable(array $items, array $header): void
    {
        $this->renderProxyTableHeader();

        $widths = array_values(self::PROXY_COL_WIDTHS);
        $tableWidth = array_sum($widths);

        foreach ($items as $index => $item) {
            // 改ページチェック
            if ($this->currentY + self::TABLE_ROW_HEIGHT > self::PRIMARY_PAGE_HEIGHT - self::MARGIN_BOTTOM - 10) {
                $this->pdf->AddPage();
                $this->currentY = self::MARGIN;
                $this->renderProxyHeader($header);
                $this->renderProxyTableHeader();
            }

            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
            $x = self::MARGIN;
            $y = $this->currentY;

            $rowData = [
                $index + 1,
                $this->truncateText($item['customer_name'], $widths[1] - 2),
                $item['item_code'],
                $item['jan_code'],
                $this->truncateText($item['item_name'], $widths[4] - 2),
                $this->truncateText($item['packaging'] ?? '', $widths[5] - 2),
                $item['location_code'],
                $item['qty_type'],
                $item['assign_qty'],
                '',
            ];

            $aligns = ['R', 'L', 'C', 'L', 'L', 'C', 'C', 'C', 'R', 'R'];

            foreach ($rowData as $i => $value) {
                $cellX = $x + ($aligns[$i] === 'L' ? 1 : 0);
                $cellW = $widths[$i] - ($aligns[$i] === 'L' ? 1 : 0);
                $this->pdf->SetXY($cellX, $y);
                $this->pdf->Cell($cellW, self::TABLE_ROW_HEIGHT, $value, 0, 0, $aligns[$i]);
                $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
                $x += $widths[$i];
            }
            $this->pdf->Line($x, $y, $x, $y + self::TABLE_ROW_HEIGHT);
            $this->pdf->Line(self::MARGIN, $y + self::TABLE_ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::TABLE_ROW_HEIGHT);

            $this->currentY = $y + self::TABLE_ROW_HEIGHT;
        }
    }

    private function renderProxySummary(array $summary): void
    {
        $this->currentY += 3;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, self::LINE_HEIGHT,
            sprintf('合計  明細数: %d',
                $summary['total_items']
            ), 0, 0, 'L');
    }

    // ========================================
    // バッチレンダリング（複数Wave/タスク一括）
    // ========================================

    /**
     * 複数Waveの1次リストを1つのPDFにまとめる
     */
    public function renderBatchPrimaryPdf(array $dataList): string
    {
        $this->initPdf('P', 'ピッキングリスト（1次）一括');

        foreach ($dataList as $data) {
            if (empty($data['items'])) {
                continue;
            }

            $this->pdf->AddPage();
            $this->currentY = self::MARGIN;

            $this->renderPrimaryHeader($data['header']);
            $this->renderPrimaryTable($data['items'], $data['header']);
            $this->renderPrimarySummary($data['summary']);
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::PRIMARY_PAGE_WIDTH, self::PRIMARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    public function renderBatchShortagePdf(array $dataList): string
    {
        $this->initPdf('P', '欠品リスト（1次）一括');

        foreach ($dataList as $data) {
            if (empty($data['items'])) {
                continue;
            }

            $this->pdf->AddPage();
            $this->currentY = self::MARGIN;

            $this->renderShortageHeader($data['header']);
            $this->renderShortageTable($data['items'], $data['header']);
            $this->renderShortageSummary($data['summary']);
        }

        if ($this->pdf->getNumPages() === 0) {
            $this->pdf->AddPage();
            $this->currentY = self::MARGIN;
            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
            $this->pdf->SetXY(self::MARGIN, $this->currentY);
            $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, 8, '欠品なし', 0, 0, 'C');
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::PRIMARY_PAGE_WIDTH, self::PRIMARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    /**
     * 複数ピッカーの2次リストを1つのPDFにまとめる
     */
    public function renderBatchSecondaryPdf(array $dataList): string
    {
        $this->initPdf('P', 'ピッキングリスト（2次）一括');

        foreach ($dataList as $data) {
            if (empty($data['items'])) {
                continue;
            }

            $this->pdf->AddPage();
            $this->currentY = self::MARGIN;

            $this->renderSecondaryHeader($data['header']);
            $this->renderSecondaryTable($data['items'], $data['header']);
            $this->renderSecondarySummary($data['summary']);
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderPageNumbers(self::SECONDARY_PAGE_WIDTH, self::SECONDARY_PAGE_HEIGHT);

        return $this->pdf->Output('', 'S');
    }

    /**
     * 複数コースの3次リストを1つのPDFにまとめる
     *
     * dataList は配送コースごとの {header, items, summary} の配列
     */
    public function renderBatchTertiaryPdf(array $dataList): string
    {
        return $this->renderTertiaryPdf($dataList);
    }

    // ========================================
    // 共通メソッド
    // ========================================

    private function initPdf(string $orientation, string $title): void
    {
        $this->pdf = new TCPDF($orientation, 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle($title);
        $this->pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
    }

    private function renderPageNumbers(float $pageWidth, float $pageHeight): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        for ($i = 1; $i <= $this->totalPages; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} / {$this->totalPages}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = ($pageWidth - $textWidth) / 2;
            $y = $pageHeight - self::MARGIN_BOTTOM + 3;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, self::LINE_HEIGHT, $pageText, 0, 0, 'C');
        }
    }

    private function truncateText(string $text, float $maxWidthMm, ?int $fontSize = null): string
    {
        $this->pdf->SetFont('kozminproregular', '', $fontSize ?? self::FONT_SIZE_NORMAL);
        $currentWidth = $this->pdf->GetStringWidth($text);

        if ($currentWidth <= $maxWidthMm) {
            return $text;
        }

        $this->pdf->SetFont('kozminproregular', '', $fontSize ?? self::FONT_SIZE_NORMAL);
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

    // ========================================
    // 配送コース別ピッキングリスト（仮ピッキングリスト出力の2次として使用）
    // 仕様: storage/specifications/配送コース別ピッキングリスト.pdf
    // ========================================

    // 仕様: storage/specifications/配送コース別ピッキングリスト.pdf からの実測値
    private const COURSE_GROUPED_MARGIN = 6;       // 左右マージン

    private const COURSE_GROUPED_CONTENT_WIDTH = 198; // 210 - 6 - 6

    private const COURSE_GROUPED_COL_WIDTHS = [
        'no' => 12,
        'location' => 22,
        'item_code' => 28,
        'item_name' => 68,
        'capacity' => 12,
        'case_qty' => 14,
        'piece_qty' => 14,
        'total_piece' => 14,
        'shortage' => 14,
    ];

    private const COURSE_GROUPED_HEADERS = [
        'no' => 'No',
        'location' => '棚番',
        'item_code' => "商品CD\nJAN",
        'item_name' => '商品名',
        'capacity' => '入数',
        'case_qty' => 'ケース',
        'piece_qty' => 'バラ',
        'total_piece' => '総バラ',
        'shortage' => '欠品',
    ];

    /**
     * 配送コース別ピッキングリスト一括描画
     *
     * 1ページ = 1売上伝票。$dataList は generateCourseGroupedListByWaveIds の戻り値。
     */
    public function renderCourseGroupedPdf(array $dataList): string
    {
        $this->initPdf('P', '配送コース別ピッキングリスト');

        if (empty($dataList)) {
            $this->pdf->AddPage();
            $this->currentY = self::COURSE_GROUPED_MARGIN;
            $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_TITLE);
            $this->pdf->SetXY(self::COURSE_GROUPED_MARGIN, $this->currentY);
            $this->pdf->Cell(self::COURSE_GROUPED_CONTENT_WIDTH, 8, '対象データなし', 0, 0, 'C');
            $this->totalPages = $this->pdf->getNumPages();
            $this->renderCourseGroupedPageNumbers();

            return $this->pdf->Output('', 'S');
        }

        foreach ($dataList as $data) {
            $this->pdf->AddPage();
            $this->currentY = self::COURSE_GROUPED_MARGIN;

            $this->renderCourseGroupedHeader($data['header']);
            $this->renderCourseGroupedTable($data['items'] ?? [], $data['header']);
            $this->renderCourseGroupedFooter();
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderCourseGroupedPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    private function renderCourseGroupedHeader(array $header): void
    {
        $margin = self::COURSE_GROUPED_MARGIN;
        $contentWidth = self::COURSE_GROUPED_CONTENT_WIDTH;

        // タイトル（中央、20pt太字 — 仕様PDFのYuGothic-Bold相当）
        $this->pdf->SetFont('kozgopromedium', 'B', 20);
        $this->pdf->SetXY($margin, $this->currentY);
        $this->pdf->Cell($contentWidth, 8, '配送コース別ピッキングリスト', 0, 0, 'C');
        $this->currentY += 9;

        // 配送者（=配送コース名）— 中央、18pt太字
        $this->pdf->SetFont('kozgopromedium', 'B', 18);
        $this->pdf->SetXY($margin, $this->currentY);
        $courseName = $header['course_name'] ?? '';
        $this->pdf->Cell($contentWidth, 8, '配送者：'.$courseName, 0, 0, 'C');
        $this->currentY += 9;

        // 出力日（右側、10pt）— 区切り線と被らないよう0.5mm上げる
        $this->pdf->SetFont('kozgopromedium', '', 10);
        $printDate = '出力日：'.now()->format('Y年m月d日');
        $printDateWidth = $this->pdf->GetStringWidth($printDate);
        $this->pdf->SetXY(self::PRIMARY_PAGE_WIDTH - $margin - $printDateWidth, $this->currentY - 0.5);
        $this->pdf->Cell($printDateWidth, 5, $printDate, 0, 0, 'R');

        $this->currentY += 4;

        // 区切り線（細め、テーブル幅と同じ）
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->Line($margin, $this->currentY, $margin + $contentWidth, $this->currentY);
        $this->currentY += 2;

        // 伝票情報（左側ブロック、12pt）
        $this->pdf->SetFont('kozgopromedium', '', 12);
        $infoX = $margin + 12;
        $labelW = 20;
        $valueW = 100;
        $infoLineH = 6;

        $isCourseGrouped = array_key_exists('slip_count', $header);
        $this->pdf->SetXY($infoX, $this->currentY);
        $this->pdf->Cell($labelW, $infoLineH, $isCourseGrouped ? '伝票数：' : '伝票No：', 0, 0, 'L');
        $this->pdf->SetXY($infoX + $labelW, $this->currentY);
        $this->pdf->Cell($valueW, $infoLineH, $isCourseGrouped ? ((string) $header['slip_count']).'件' : (string) ($header['slip_no'] ?? ''), 0, 0, 'L');
        $this->currentY += $infoLineH;

        $shippingDate = $header['shipping_date'] ?? '';
        if ($shippingDate) {
            try {
                $shippingDate = \Carbon\Carbon::parse($shippingDate)->format('Y/m/d');
            } catch (\Throwable) {
                // keep as is
            }
        }

        $this->pdf->SetXY($infoX, $this->currentY);
        $this->pdf->Cell($labelW, $infoLineH, '出荷日：', 0, 0, 'L');
        $this->pdf->SetXY($infoX + $labelW, $this->currentY);
        $this->pdf->Cell($valueW, $infoLineH, (string) $shippingDate, 0, 0, 'L');
        $this->currentY += $infoLineH;

        $this->pdf->SetXY($infoX, $this->currentY);
        $this->pdf->Cell($labelW, $infoLineH, '倉庫：', 0, 0, 'L');
        $this->pdf->SetXY($infoX + $labelW, $this->currentY);
        // floors.name には倉庫名が既に含まれているため、フロア名がある場合はそちらのみ表示
        $warehouseLine = ! empty($header['floor_name'])
            ? (string) $header['floor_name']
            : (string) ($header['warehouse_name'] ?? '');
        $this->pdf->Cell($valueW, $infoLineH, $warehouseLine, 0, 0, 'L');
        $this->currentY += $infoLineH + 1;
    }

    private function renderCourseGroupedTableHeader(): void
    {
        $widths = array_values(self::COURSE_GROUPED_COL_WIDTHS);
        $headerLabels = array_values(self::COURSE_GROUPED_HEADERS);
        $rowH = 11;

        $this->pdf->SetFont('kozgopromedium', 'B', 10);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetFillColor(232, 232, 232);

        $x = self::COURSE_GROUPED_MARGIN;
        $y = $this->currentY;

        // 背景塗りつぶし + 罫線
        foreach ($headerLabels as $i => $label) {
            $this->pdf->Rect($x, $y, $widths[$i], $rowH, 'DF');
            $this->pdf->MultiCell($widths[$i], $rowH, $label, 0, 'C', false, 0, $x, $y, true, 0, false, true, $rowH, 'M');
            $x += $widths[$i];
        }

        $this->currentY = $y + $rowH;
    }

    private function renderCourseGroupedTable(array $items, array $header): void
    {
        $this->renderCourseGroupedTableHeader();

        $widths = array_values(self::COURSE_GROUPED_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $margin = self::COURSE_GROUPED_MARGIN;
        $minRowH = 8;
        // 仕様PDF実測: 本文 ~12pt YuGothic-Bold / 棚番 11pt B / 商品CD 9pt B / JAN 7pt
        $bodyFontSize = 12;
        $boldFontSize = 12;
        $shelfFontSize = 11;
        $noFontSize = 9;
        $codeFontSize = 9;
        $janFontSize = 7;

        foreach ($items as $item) {
            $itemName = (string) ($item['item_name'] ?? '');

            $this->pdf->SetFont('kozgopromedium', 'B', $bodyFontSize);
            $nameH = $this->pdf->getStringHeight($widths[3] - 2, $itemName);

            $rowH = max($minRowH, $nameH);

            // ページ送り判定（摘要枠と余白を確保）
            if ($this->currentY + $rowH > self::PRIMARY_PAGE_HEIGHT - self::MARGIN_BOTTOM - 25) {
                $this->pdf->AddPage();
                $this->currentY = self::COURSE_GROUPED_MARGIN;
                $this->renderCourseGroupedHeader($header);
                $this->renderCourseGroupedTableHeader();
            }

            $x = $margin;
            $y = $this->currentY;

            // セル枠（縦線）
            foreach ($widths as $w) {
                $this->pdf->Line($x, $y, $x, $y + $rowH);
                $x += $w;
            }
            $this->pdf->Line($x, $y, $x, $y + $rowH);
            // 行下線
            $this->pdf->Line($margin, $y + $rowH, $margin + $tableWidth, $y + $rowH);

            // No（通常）
            $colX = $margin;
            $this->pdf->SetFont('kozgopromedium', '', $noFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[0], $rowH, (string) ($item['no'] ?? ''), 0, 0, 'C');
            $colX += $widths[0];

            // 棚番（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $shelfFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[1], $rowH, (string) ($item['location_code'] ?? ''), 0, 0, 'C');
            $colX += $widths[1];

            // 商品CD（上、太字）+ JAN（直下、小）— 行高に関わらず上端に密着配置
            $this->pdf->SetFont('kozgopromedium', 'B', $codeFontSize);
            $this->pdf->SetXY($colX, $y + 0.5);
            $this->pdf->Cell($widths[2], 4, (string) ($item['item_code'] ?? ''), 0, 0, 'C');
            $this->pdf->SetFont('kozgopromedium', '', $janFontSize);
            $this->pdf->SetXY($colX, $y + 4);
            $this->pdf->Cell($widths[2], 3.5, (string) ($item['jan_code'] ?? ''), 0, 0, 'C');
            $colX += $widths[2];

            // 商品名（左寄せ・太字、長い場合は折返し、上寄せ）
            $this->pdf->SetFont('kozgopromedium', 'B', $bodyFontSize);
            $lineH = $this->pdf->getStringHeight($widths[3] - 2, 'あ');
            $this->pdf->MultiCell($widths[3] - 2, $lineH, $itemName, 0, 'L', false, 0, $colX + 1, $y + 0.5, true, 0, false, true, 0, 'T');
            $colX += $widths[3];

            // 入数
            $this->pdf->SetFont('kozgopromedium', '', 10);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[4], $rowH, (string) ($item['capacity_case'] ?? ''), 0, 0, 'C');
            $colX += $widths[4];

            // ケース（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $boldFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[5], $rowH, (string) ($item['case_qty'] ?? '0'), 0, 0, 'C');
            $colX += $widths[5];

            // バラ（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $boldFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[6], $rowH, (string) ($item['piece_qty'] ?? '0'), 0, 0, 'C');
            $colX += $widths[6];

            // 総バラ（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $boldFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[7], $rowH, (string) ($item['total_pieces'] ?? '0'), 0, 0, 'C');
            $colX += $widths[7];

            // 欠品（数値があるときのみ表示）
            $shortageQty = (int) ($item['shortage_qty'] ?? 0);
            $this->pdf->SetFont('kozgopromedium', '', 10);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[8], $rowH, $shortageQty > 0 ? (string) $shortageQty : '', 0, 0, 'C');

            $this->currentY = $y + $rowH;
        }
    }

    private function renderCourseGroupedFooter(): void
    {
        $margin = self::COURSE_GROUPED_MARGIN;
        $contentWidth = self::COURSE_GROUPED_CONTENT_WIDTH;
        $boxH = 11;

        $y = $this->currentY;
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->Rect($margin, $y, $contentWidth, $boxH);

        $this->pdf->SetFont('kozgopromedium', '', 11);
        $this->pdf->SetXY($margin + 2, $y + 1);
        $this->pdf->Cell($contentWidth - 4, 6, '摘要：', 0, 0, 'L');

        $this->currentY = $y + $boxH;
    }

    /**
     * 配送コース別リスト用ページ番号（右上に表示）
     */
    private function renderCourseGroupedPageNumbers(): void
    {
        $this->pdf->SetFont('kozgopromedium', '', 10);
        $total = $this->totalPages;

        for ($i = 1; $i <= $total; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} / {$total}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::PRIMARY_PAGE_WIDTH - self::COURSE_GROUPED_MARGIN - $textWidth;
            $y = self::COURSE_GROUPED_MARGIN;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, 5, $pageText, 0, 0, 'R');
        }
    }

    // ========================================
    // 得意先別ピッキングリスト V2（仮ピッキングリスト出力の3次として使用）
    // 仕様: storage/specifications/得意先別ピッキングリスト V2.pdf
    // ⚠ 仕様PDFは A4 横向き (297×210mm)
    // ========================================

    private const BUYER_GROUPED_MARGIN = 6;

    // A4 横向き: 297mm 幅
    private const BUYER_GROUPED_PAGE_WIDTH = 297;

    private const BUYER_GROUPED_PAGE_HEIGHT = 210;

    private const BUYER_GROUPED_CONTENT_WIDTH = 285; // 297 - 6 - 6

    // 合計285mm。得意先名が最大2行で収まるよう得意先列を広げ、商品名列を縮小。
    private const BUYER_GROUPED_COL_WIDTHS = [
        'no' => 12,
        'location' => 22,
        'buyer' => 42,
        'item_code' => 28,
        'item_name' => 110,
        'capacity' => 12,
        'case_qty' => 14,
        'piece_qty' => 14,
        'total_piece' => 14,
        'shortage' => 17,
    ];

    private const BUYER_GROUPED_HEADERS = [
        'no' => 'No',
        'location' => '棚番',
        'buyer' => '得意先',
        'item_code' => "商品CD\nJAN",
        'item_name' => '商品名',
        'capacity' => '入数',
        'case_qty' => 'ケース',
        'piece_qty' => 'バラ',
        'total_piece' => '総バラ',
        'shortage' => '欠品',
    ];

    /**
     * 得意先別ピッキングリスト V2 一括描画
     *
     * 1ページ = 1配送コース。$dataList は generateBuyerGroupedListByWaveIds の戻り値。
     */
    public function renderBuyerGroupedPdf(array $dataList): string
    {
        // A4 横向きで初期化
        $this->initPdf('L', '得意先別ピッキングリスト');

        if (empty($dataList)) {
            $this->pdf->AddPage();
            $this->currentY = self::BUYER_GROUPED_MARGIN;
            $this->pdf->SetFont('kozgopromedium', '', self::FONT_SIZE_TITLE);
            $this->pdf->SetXY(self::BUYER_GROUPED_MARGIN, $this->currentY);
            $this->pdf->Cell(self::BUYER_GROUPED_CONTENT_WIDTH, 8, '対象データなし', 0, 0, 'C');
            $this->totalPages = $this->pdf->getNumPages();
            $this->renderBuyerGroupedPageNumbers();

            return $this->pdf->Output('', 'S');
        }

        foreach ($dataList as $data) {
            $this->pdf->AddPage();
            $this->currentY = self::BUYER_GROUPED_MARGIN;

            $this->renderBuyerGroupedHeader($data['header']);
            $this->renderBuyerGroupedTable($data['items'] ?? [], $data['header']);
            $this->renderBuyerGroupedSummary($data['summary'] ?? []);
        }

        $this->totalPages = $this->pdf->getNumPages();
        $this->renderBuyerGroupedPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    private function renderBuyerGroupedHeader(array $header): void
    {
        $margin = self::BUYER_GROUPED_MARGIN;
        $contentWidth = self::BUYER_GROUPED_CONTENT_WIDTH;

        // タイトル（中央、20pt太字）
        $this->pdf->SetFont('kozgopromedium', 'B', 20);
        $this->pdf->SetXY($margin, $this->currentY);
        $this->pdf->Cell($contentWidth, 8, '得意先別ピッキングリスト', 0, 0, 'C');
        $this->currentY += 9;

        // 配送者（=配送コース名、中央、18pt太字）
        $this->pdf->SetFont('kozgopromedium', 'B', 18);
        $this->pdf->SetXY($margin, $this->currentY);
        $courseName = $header['course_name'] ?? '';
        $this->pdf->Cell($contentWidth, 8, '配送者：'.$courseName, 0, 0, 'C');
        $this->currentY += 9;

        // 出力日（右側、10pt）— 区切り線と被らないよう0.5mm上げる
        $this->pdf->SetFont('kozgopromedium', '', 10);
        $printDate = '出力日：'.now()->format('Y年m月d日');
        $printDateWidth = $this->pdf->GetStringWidth($printDate);
        $this->pdf->SetXY(self::BUYER_GROUPED_PAGE_WIDTH - $margin - $printDateWidth, $this->currentY - 0.5);
        $this->pdf->Cell($printDateWidth, 5, $printDate, 0, 0, 'R');
        $this->currentY += 4;

        // 区切り線
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->Line($margin, $this->currentY, $margin + $contentWidth, $this->currentY);
        $this->currentY += 2;

        // 伝票情報（伝票No・出荷日のみ、倉庫なし）
        $this->pdf->SetFont('kozgopromedium', '', 12);
        $infoX = $margin + 12;
        $labelW = 20;
        $valueW = 100;
        $infoLineH = 6;

        $this->pdf->SetXY($infoX, $this->currentY);
        $this->pdf->Cell($labelW, $infoLineH, '伝票No：', 0, 0, 'L');
        $this->pdf->SetXY($infoX + $labelW, $this->currentY);
        $this->pdf->Cell($valueW, $infoLineH, (string) ($header['wave_no'] ?? ''), 0, 0, 'L');
        $this->currentY += $infoLineH;

        $shippingDate = $header['shipping_date'] ?? '';
        if ($shippingDate) {
            try {
                $shippingDate = \Carbon\Carbon::parse($shippingDate)->format('Y/m/d');
            } catch (\Throwable) {
                // keep as is
            }
        }
        $this->pdf->SetXY($infoX, $this->currentY);
        $this->pdf->Cell($labelW, $infoLineH, '出荷日：', 0, 0, 'L');
        $this->pdf->SetXY($infoX + $labelW, $this->currentY);
        $this->pdf->Cell($valueW, $infoLineH, (string) $shippingDate, 0, 0, 'L');
        $this->currentY += $infoLineH + 1;
    }

    private function renderBuyerGroupedTableHeader(): void
    {
        $widths = array_values(self::BUYER_GROUPED_COL_WIDTHS);
        $headerLabels = array_values(self::BUYER_GROUPED_HEADERS);
        $rowH = 11;

        $this->pdf->SetFont('kozgopromedium', 'B', 10);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetFillColor(232, 232, 232);

        $x = self::BUYER_GROUPED_MARGIN;
        $y = $this->currentY;

        foreach ($headerLabels as $i => $label) {
            $this->pdf->Rect($x, $y, $widths[$i], $rowH, 'DF');
            $this->pdf->MultiCell($widths[$i], $rowH, $label, 0, 'C', false, 0, $x, $y, true, 0, false, true, $rowH, 'M');
            $x += $widths[$i];
        }

        $this->currentY = $y + $rowH;
    }

    private function renderBuyerGroupedTable(array $items, array $header): void
    {
        $this->renderBuyerGroupedTableHeader();

        $widths = array_values(self::BUYER_GROUPED_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $margin = self::BUYER_GROUPED_MARGIN;
        $minRowH = 8;
        // 仕様PDF実測: 本文 ~12pt YuGothic-Bold / 棚番 11pt B / 商品CD 9pt B / JAN 7pt / 得意先 11pt
        $bodyFontSize = 12;
        $boldFontSize = 12;
        $shelfFontSize = 11;
        $noFontSize = 9;
        $codeFontSize = 9;
        $janFontSize = 7;
        $buyerFontSize = 11;

        foreach ($items as $item) {
            $itemName = (string) ($item['item_name'] ?? '');
            $buyerLabel = (string) ($item['buyer_name'] ?? '');

            $this->pdf->SetFont('kozgopromedium', 'B', $bodyFontSize);
            $nameH = $this->pdf->getStringHeight($widths[4] - 2, $itemName);

            $this->pdf->SetFont('kozgopromedium', '', $buyerFontSize);
            $buyerH = $this->pdf->getStringHeight($widths[2] - 2, $buyerLabel);

            $rowH = max($minRowH, $nameH, $buyerH);

            // ページ送り（横向き: 高さ210mm）
            if ($this->currentY + $rowH > self::BUYER_GROUPED_PAGE_HEIGHT - self::MARGIN_BOTTOM - 15) {
                $this->pdf->AddPage();
                $this->currentY = self::BUYER_GROUPED_MARGIN;
                $this->renderBuyerGroupedHeader($header);
                $this->renderBuyerGroupedTableHeader();
            }

            $x = $margin;
            $y = $this->currentY;

            // セル枠
            foreach ($widths as $w) {
                $this->pdf->Line($x, $y, $x, $y + $rowH);
                $x += $w;
            }
            $this->pdf->Line($x, $y, $x, $y + $rowH);
            $this->pdf->Line($margin, $y + $rowH, $margin + $tableWidth, $y + $rowH);

            $colX = $margin;

            // No
            $this->pdf->SetFont('kozgopromedium', '', $noFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[0], $rowH, (string) ($item['no'] ?? ''), 0, 0, 'C');
            $colX += $widths[0];

            // 棚番（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $shelfFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[1], $rowH, (string) ($item['location_code'] ?? ''), 0, 0, 'C');
            $colX += $widths[1];

            // 得意先（左寄せ折返し、上寄せ）
            $this->pdf->SetFont('kozgopromedium', '', $buyerFontSize);
            $buyerLineH = $this->pdf->getStringHeight($widths[2] - 2, 'あ');
            $this->pdf->MultiCell($widths[2] - 2, $buyerLineH, $buyerLabel, 0, 'L', false, 0, $colX + 1, $y + 0.5, true, 0, false, true, 0, 'T');
            $colX += $widths[2];

            // 商品CD（上、太字）+ JAN（直下、小）— 行高に関わらず上端に密着配置
            $this->pdf->SetFont('kozgopromedium', 'B', $codeFontSize);
            $this->pdf->SetXY($colX, $y + 0.5);
            $this->pdf->Cell($widths[3], 4, (string) ($item['item_code'] ?? ''), 0, 0, 'C');
            $this->pdf->SetFont('kozgopromedium', '', $janFontSize);
            $this->pdf->SetXY($colX, $y + 4);
            $this->pdf->Cell($widths[3], 3.5, (string) ($item['jan_code'] ?? ''), 0, 0, 'C');
            $colX += $widths[3];

            // 商品名（左寄せ・太字、長い場合は折返し、上寄せ）
            $this->pdf->SetFont('kozgopromedium', 'B', $bodyFontSize);
            $lineH = $this->pdf->getStringHeight($widths[4] - 2, 'あ');
            $this->pdf->MultiCell($widths[4] - 2, $lineH, $itemName, 0, 'L', false, 0, $colX + 1, $y + 0.5, true, 0, false, true, 0, 'T');
            $colX += $widths[4];

            // 入数
            $this->pdf->SetFont('kozgopromedium', '', 10);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[5], $rowH, (string) ($item['capacity_case'] ?? ''), 0, 0, 'C');
            $colX += $widths[5];

            // ケース（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $boldFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[6], $rowH, (string) ($item['case_qty'] ?? '0'), 0, 0, 'C');
            $colX += $widths[6];

            // バラ（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $boldFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[7], $rowH, (string) ($item['piece_qty'] ?? '0'), 0, 0, 'C');
            $colX += $widths[7];

            // 総バラ（太字）
            $this->pdf->SetFont('kozgopromedium', 'B', $boldFontSize);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[8], $rowH, (string) ($item['total_pieces'] ?? '0'), 0, 0, 'C');
            $colX += $widths[8];

            // 欠品
            $shortageQty = (int) ($item['shortage_qty'] ?? 0);
            $this->pdf->SetFont('kozgopromedium', '', 10);
            $this->pdf->SetXY($colX, $y);
            $this->pdf->Cell($widths[9], $rowH, $shortageQty > 0 ? (string) $shortageQty : '', 0, 0, 'C');

            $this->currentY = $y + $rowH;
        }
    }

    private function renderBuyerGroupedSummary(array $summary): void
    {
        $widths = array_values(self::BUYER_GROUPED_COL_WIDTHS);
        $tableWidth = array_sum($widths);
        $margin = self::BUYER_GROUPED_MARGIN;
        $rowH = 9;

        $rowCount = (int) ($summary['row_count'] ?? 0);
        $totalCase = (int) ($summary['total_case'] ?? 0);
        $totalPiece = (int) ($summary['total_piece'] ?? 0);
        $totalAll = (int) ($summary['total_pieces_all'] ?? 0);

        $y = $this->currentY;
        $x = $margin;

        // ラベル領域（No〜入数までを結合）幅
        $labelWidth = $widths[0] + $widths[1] + $widths[2] + $widths[3] + $widths[4] + $widths[5];

        // 縦罫線（外側）
        $this->pdf->Line($margin, $y, $margin, $y + $rowH);
        // ラベル右の縦線（入数まで結合）
        $this->pdf->Line($margin + $labelWidth, $y, $margin + $labelWidth, $y + $rowH);
        // 各数値セルの縦線
        $valX = $margin + $labelWidth;
        foreach ([$widths[6], $widths[7], $widths[8], $widths[9]] as $w) {
            $valX += $w;
            $this->pdf->Line($valX, $y, $valX, $y + $rowH);
        }
        // 行下線
        $this->pdf->Line($margin, $y + $rowH, $margin + $tableWidth, $y + $rowH);

        // ラベル「合計（N行）」
        $this->pdf->SetFont('kozgopromedium', '', 11);
        $this->pdf->SetXY($margin, $y);
        $this->pdf->Cell($labelWidth - 2, $rowH, "合計（{$rowCount}行）", 0, 0, 'R');

        // 数値
        $vx = $margin + $labelWidth;
        $this->pdf->SetFont('kozgopromedium', '', 12);

        $this->pdf->SetXY($vx, $y);
        $this->pdf->Cell($widths[6], $rowH, (string) $totalCase, 0, 0, 'C');
        $vx += $widths[6];
        $this->pdf->SetXY($vx, $y);
        $this->pdf->Cell($widths[7], $rowH, (string) $totalPiece, 0, 0, 'C');
        $vx += $widths[7];
        $this->pdf->SetXY($vx, $y);
        $this->pdf->Cell($widths[8], $rowH, (string) $totalAll, 0, 0, 'C');
        // 欠品セルは空白

        $this->currentY = $y + $rowH;
    }

    private function renderBuyerGroupedPageNumbers(): void
    {
        $this->pdf->SetFont('kozgopromedium', '', 10);
        $total = $this->totalPages;
        for ($i = 1; $i <= $total; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} / {$total}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::BUYER_GROUPED_PAGE_WIDTH - self::BUYER_GROUPED_MARGIN - $textWidth;
            $y = self::BUYER_GROUPED_MARGIN;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, 5, $pageText, 0, 0, 'R');
        }
    }
}
