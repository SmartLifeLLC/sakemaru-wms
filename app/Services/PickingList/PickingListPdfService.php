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
        'no' => 8,
        'location' => 22,
        'item_code' => 24,
        'item_name' => 88,
        'packaging' => 20,
        'case_qty' => 14,
        'piece_qty' => 14,
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
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, 8, '1次ピッキングリスト', 0, 0, 'C');
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
        $this->pdf->Cell(95, self::LINE_HEIGHT, '倉庫: '.($header['warehouse_name'] ?? ''), 0, 0, 'R');
        $this->currentY += self::LINE_HEIGHT + 3;
    }

    private function renderPrimaryTableHeader(): void
    {
        $headers = ['No', '棚番', '商品CD', '商品名', '荷姿', 'ケース', 'バラ'];
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
                $item['case_qty'] ?: '',
                $item['piece_qty'] ?: '',
            ];

            $aligns = ['R', 'C', 'C', 'L', 'C', 'C', 'C'];

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

    private function renderPrimarySummary(array $summary): void
    {
        $this->currentY += 3;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, self::LINE_HEIGHT,
            sprintf('合計  SKU数: %d  /  総数量: %d  /  ケース計: %d  /  バラ計: %d',
                $summary['sku_count'], $summary['total_qty'], $summary['total_case'], $summary['total_piece']
            ), 0, 0, 'L');
    }

    // ========================================
    // 1次欠品リスト（A4縦）
    // ========================================

    private const SHORTAGE_COL_WIDTHS = [
        'no' => 8,
        'location' => 22,
        'item_code' => 24,
        'item_name' => 50,
        'packaging' => 18,
        'qty_label' => 12,
        'planned_qty' => 18,
        'allocated_qty' => 18,
        'shortage_qty' => 20,
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
        $headers = ['No', '棚番', '商品CD', '商品名', '荷姿', '単位', '受注数', '引当数', '欠品数'];
        $widths = array_values(self::SHORTAGE_COL_WIDTHS);
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

    private function renderShortageTable(array $items, array $header): void
    {
        $this->renderShortageTableHeader();

        $widths = array_values(self::SHORTAGE_COL_WIDTHS);
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
                $this->renderShortageHeader($header);
                $this->renderShortageTableHeader();
            }

            $x = self::MARGIN;
            $y = $this->currentY;

            $rowData = [
                $index + 1,
                $item['location_code'] ?? '',
                $item['item_code'],
                $item['item_name'],
                $item['packaging'] ?? '',
                $item['qty_label'],
                $item['planned_qty'],
                $item['allocated_qty'],
                $item['shortage_qty'],
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

    private function renderShortageSummary(array $summary): void
    {
        $this->currentY += 3;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_HEADER);
        $this->pdf->SetXY(self::MARGIN, $this->currentY);
        $this->pdf->Cell(self::PRIMARY_CONTENT_WIDTH, self::LINE_HEIGHT,
            sprintf('合計  SKU数: %d  /  欠品総数: %d',
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

    /**
     * 3次ピッキングリストPDF描画（配送コース別）
     *
     * generateTertiaryList が返す配列は2次リストと同じ構造のため、
     * ヘッダータイトルを「3次」に変えて2次のレイアウトを再利用する。
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
            $this->renderSecondaryTable(
                $data['items'],
                $data['header'],
                fn (array $h) => $this->renderTertiaryPageHeader($h)
            );
            $this->renderSecondarySummary($data['summary']);
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
}
