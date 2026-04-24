<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\Sakemaru\Client;
use App\Models\WmsContractorWarehouseSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use TCPDF;

/**
 * 発注書PDF生成サービス（FAX用）
 *
 * TCPDF座標描画のみ使用（HTML禁止）
 * A4縦、白黒FAX前提
 */
class PurchaseOrderPdfService
{
    // A4サイズ（mm）
    private const PAGE_WIDTH = 210;

    private const PAGE_HEIGHT = 297;

    // マージン（mm）
    private const MARGIN_LEFT = 10;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_TOP = 10;

    private const MARGIN_BOTTOM = 15;

    // 描画エリア
    private const CONTENT_WIDTH = 190; // PAGE_WIDTH - MARGIN_LEFT - MARGIN_RIGHT

    // フォントサイズ（pt）
    private const FONT_SIZE_TITLE = 16;

    private const FONT_SIZE_LARGE = 12;

    private const FONT_SIZE_NORMAL = 9;

    private const FONT_SIZE_SMALL = 8;

    // 行高さ（mm）
    private const LINE_HEIGHT_NORMAL = 5;

    private const LINE_HEIGHT_TABLE = 6;

    // 罫線幅（mm）
    private const LINE_WIDTH = 0.2;

    private const LINE_WIDTH_THICK = 0.4;

    // テーブル列幅（mm）
    private const COL_WIDTHS = [
        'ordering_code' => 28,     // 発注CD（JANコード）
        'item_code' => 22,         // 自社コード
        'capacity_case' => 12,     // 入数
        'item_name' => 108,        // 商品名（省略なし）- 規格削除分広く
        'case_qty' => 10,          // ケース
        'piece_qty' => 10,         // バラ
    ];

    private TCPDF $pdf;

    private float $currentY;

    private int $itemRowCount = 0;

    private int $currentPage = 1;

    private int $totalPages = 0;

    // ヘッダー情報を保持（全ページで使用）
    private WmsOrderDataFile $dataFile;

    private $contractor;

    private ?Client $client;

    private $warehouse;

    /**
     * WmsOrderDataFileからPDFを生成
     */
    public function generateFromDataFile(WmsOrderDataFile $dataFile, ?string $communicationNotes = null): string
    {
        // CONFIRMED状態の発注候補を取得
        $candidates = WmsOrderCandidate::where('batch_code', $dataFile->batch_code)
            ->where('warehouse_id', $dataFile->warehouse_id)
            ->where('contractor_id', $dataFile->contractor_id)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor'])
            ->orderBy('expected_arrival_date')
            ->get();

        if ($candidates->isEmpty()) {
            throw new \RuntimeException('生成対象の発注候補がありません');
        }

        return $this->generate($candidates, $dataFile, $communicationNotes);
    }

    /**
     * 発注データからPDFを生成しバイナリを返す
     */
    public function generate(Collection $candidates, WmsOrderDataFile $dataFile, ?string $communicationNotes = null): string
    {
        $this->initPdf();
        $this->renderDocument($candidates, $dataFile, $communicationNotes);

        // 全ページにページ番号を描画
        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    /**
     * 複数DataFileからPDFを一括生成しバイナリを返す
     */
    public function generateBulk(Collection $dataFiles): string
    {
        $this->initPdf();

        foreach ($dataFiles as $dataFile) {
            $candidates = WmsOrderCandidate::where('batch_code', $dataFile->batch_code)
                ->where('warehouse_id', $dataFile->warehouse_id)
                ->where('contractor_id', $dataFile->contractor_id)
                ->where('status', CandidateStatus::CONFIRMED)
                ->with(['warehouse', 'item', 'contractor'])
                ->orderBy('expected_arrival_date')
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            $this->renderDocument($candidates, $dataFile, null);
        }

        $this->renderPageNumbers();

        return $this->pdf->Output('', 'S');
    }

    /**
     * PDFを生成しS3に保存
     */
    public function generateAndStore(WmsOrderDataFile $dataFile, ?string $communicationNotes = null): string
    {
        $pdfBinary = $this->generateFromDataFile($dataFile, $communicationNotes);

        // S3パス生成
        $date = now()->format('Y-m-d');
        $warehouseCode = $dataFile->warehouse?->code ?? $dataFile->warehouse_id;
        $contractorCode = $dataFile->contractor?->code ?? $dataFile->contractor_id;
        $filename = "{$dataFile->batch_code}_{$warehouseCode}_{$contractorCode}.pdf";
        $filePath = "order-data-files/{$date}/{$filename}";

        Storage::disk('s3')->put($filePath, $pdfBinary);

        // DBに記録
        $dataFile->update(['fax_file_path' => $filePath]);

        return $filePath;
    }

    /**
     * PDFを初期化
     */
    private function initPdf(): void
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // メタデータ
        $this->pdf->SetCreator('Smart WMS');
        $this->pdf->SetAuthor('Smart WMS');
        $this->pdf->SetTitle('発注書');

        // マージン設定
        $this->pdf->SetMargins(self::MARGIN_LEFT, self::MARGIN_TOP, self::MARGIN_RIGHT);
        $this->pdf->SetAutoPageBreak(false); // 手動改ページ制御

        // ヘッダー・フッター無効（座標制御のため）
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);

        // 日本語フォント設定（TCPDF内蔵CIDフォント）
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
    }

    /**
     * ドキュメント全体を描画
     */
    private function renderDocument(Collection $candidates, WmsOrderDataFile $dataFile, ?string $communicationNotes = null): void
    {
        $firstCandidate = $candidates->first();

        // ヘッダー情報を保持（全ページで使用）
        $this->dataFile = $dataFile;
        $this->warehouse = $firstCandidate->warehouse;
        $this->contractor = $firstCandidate->contractor;
        $this->client = $this->getClientInfo($this->warehouse);

        // 最初のページ
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->currentPage = 1;

        // ヘッダー描画
        $this->renderHeader();

        // 通信欄描画（商品リストの前）
        $this->renderCommunicationArea($communicationNotes);
        $this->currentY += 5;

        // 明細テーブル描画
        $this->renderDetailTable($candidates);

        // 総ページ数を記録
        $this->totalPages = $this->pdf->getNumPages();
    }

    /**
     * ヘッダー部描画（全ページ共通）
     */
    private function renderHeader(): void
    {
        // タイトル「発注書」
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
        $titleWidth = $this->pdf->GetStringWidth('発注書');
        $this->pdf->SetXY((self::PAGE_WIDTH - $titleWidth) / 2, $this->currentY);
        $this->pdf->Cell($titleWidth, 10, '発注書', 0, 0, 'C');
        $this->currentY += 12;

        // 発注日・発注番号（右上）
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
        $this->pdf->SetXY(self::PAGE_WIDTH - self::MARGIN_RIGHT - 60, self::MARGIN_TOP);
        $this->pdf->Cell(60, self::LINE_HEIGHT_NORMAL, '発注日: '.$this->dataFile->order_date->format('Y年m月d日'), 0, 1, 'R');
        $this->pdf->SetX(self::PAGE_WIDTH - self::MARGIN_RIGHT - 60);
        $this->pdf->Cell(60, self::LINE_HEIGHT_NORMAL, '発注番号: '.$this->dataFile->batch_code, 0, 1, 'R');

        // 発注先・発注元情報を同じ高さから描画
        $infoStartY = $this->currentY;

        // 発注先情報（左側）
        $this->renderContractorInfo($infoStartY);

        // 発注元情報（右側）- 同じ高さから開始
        if ($this->client) {
            $this->renderClientInfo($infoStartY);
        }

        $this->currentY += 5;
    }

    /**
     * 発注先情報描画
     */
    private function renderContractorInfo(float $startY): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_LARGE);
        $this->pdf->SetXY(self::MARGIN_LEFT, $startY);

        $contractorName = $this->contractor?->name ?? '（発注先名）';
        $this->pdf->Cell(80, 8, $contractorName.' 御中', 0, 1, 'L');

        // 下線
        $this->pdf->Line(self::MARGIN_LEFT, $startY + 8, self::MARGIN_LEFT + 80, $startY + 8);

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $lineY = $startY + 10;

        if ($this->contractor?->tel) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(80, self::LINE_HEIGHT_NORMAL, 'TEL: '.$this->contractor->tel, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }
        if ($this->contractor?->fax) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(80, self::LINE_HEIGHT_NORMAL, 'FAX: '.$this->contractor->fax, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // 納入場所（発注倉庫名）- 仮想倉庫の場合は実倉庫を表示
        $lineY += 2; // 少し間隔を空ける
        $deliveryWarehouse = $this->warehouse;
        if ($this->warehouse?->is_virtual && $this->warehouse?->stock_warehouse_id) {
            $deliveryWarehouse = \App\Models\Sakemaru\Warehouse::find($this->warehouse->stock_warehouse_id);
        }
        $warehouseName = $deliveryWarehouse?->name ?? '';
        if ($warehouseName) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(80, self::LINE_HEIGHT_NORMAL, '納入場所: '.$warehouseName, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // 納入先指定コード
        $designatedCode = WmsContractorWarehouseSetting::getDesignatedCode(
            $this->warehouse?->id ?? 0,
            $this->contractor?->id ?? 0,
        );
        $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
        $this->pdf->Cell(80, self::LINE_HEIGHT_NORMAL, '納入先指定コード: '.($designatedCode ?? ' - '), 0, 1, 'L');
        $lineY += self::LINE_HEIGHT_NORMAL;

        // 納入予定日（入荷日）
        $expectedDate = $this->dataFile->expected_arrival_date?->format('Y年m月d日') ?? '';
        if ($expectedDate) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(80, self::LINE_HEIGHT_NORMAL, '納入予定日: '.$expectedDate, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // 発注担当（発注元倉庫）
        $orderingWarehouseName = $this->warehouse?->name ?? '';
        if ($orderingWarehouseName) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(80, self::LINE_HEIGHT_NORMAL, '発注担当: '.$orderingWarehouseName, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        $this->currentY = max($this->currentY, $lineY + 3);
    }

    /**
     * 発注元情報描画（発注先と同じ高さから開始）
     */
    private function renderClientInfo(float $startY): void
    {
        $startX = self::PAGE_WIDTH - self::MARGIN_RIGHT - 75;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);

        $lineY = $startY;

        // 会社名
        $this->pdf->SetXY($startX, $lineY);
        $this->pdf->Cell(75, self::LINE_HEIGHT_NORMAL, $this->client->name ?? '', 0, 1, 'L');
        $lineY += self::LINE_HEIGHT_NORMAL;

        // 住所
        $address = trim(($this->client->order_form_address1 ?? $this->client->address1 ?? '').($this->client->order_form_address2 ?? $this->client->address2 ?? ''));
        if ($address) {
            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
            $this->pdf->SetXY($startX, $lineY);
            // 住所が長い場合は折り返し
            $this->pdf->MultiCell(75, self::LINE_HEIGHT_NORMAL, $address, 0, 'L');
            $lineY = $this->pdf->GetY();
        }

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        // TEL
        if ($this->client->tel) {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell(75, self::LINE_HEIGHT_NORMAL, 'TEL: '.$this->client->tel, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // FAX
        if ($this->client->fax) {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell(75, self::LINE_HEIGHT_NORMAL, 'FAX: '.$this->client->fax, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        $this->currentY = max($this->currentY, $lineY + 3);
    }

    /**
     * 明細テーブル描画
     */
    private function renderDetailTable(Collection $candidates): void
    {
        // テーブルヘッダー
        $this->renderTableHeader();

        // 明細行
        $this->itemRowCount = 0;
        foreach ($candidates as $candidate) {
            $this->renderTableRow($candidate);
        }

        // テーブル下線
        $this->renderTableBottomLine();
    }

    /**
     * テーブルヘッダー描画
     */
    private function renderTableHeader(): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);

        $headers = [
            '発注CD',      // JANコード（ordering_code）
            '自社コード',   // 商品コード
            '入数',        // capacity_case
            '商品名',      // 省略なし
            'ケース',      // ケース数
            'バラ',        // バラ数
        ];

        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowHeight = self::LINE_HEIGHT_TABLE;

        // 上線
        $tableWidth = array_sum(self::COL_WIDTHS);
        $this->pdf->Line($x, $y, $x + $tableWidth, $y);

        // セル描画
        $widths = array_values(self::COL_WIDTHS);
        foreach ($headers as $i => $header) {
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($widths[$i], $rowHeight, $header, 0, 0, 'C');
            // 縦線
            $this->pdf->Line($x, $y, $x, $y + $rowHeight);
            $x += $widths[$i];
        }
        // 右端の縦線
        $this->pdf->Line($x, $y, $x, $y + $rowHeight);

        // 下線
        $this->pdf->Line(self::MARGIN_LEFT, $y + $rowHeight, self::MARGIN_LEFT + $tableWidth, $y + $rowHeight);

        $this->currentY = $y + $rowHeight;
    }

    /**
     * テーブル行描画
     */
    private function renderTableRow($candidate): void
    {
        $rowHeight = self::LINE_HEIGHT_TABLE;

        // ページ残高チェック
        $footerHeight = 5; // 下マージン余白
        if ($this->currentY + $rowHeight > self::PAGE_HEIGHT - self::MARGIN_BOTTOM - $footerHeight) {
            // 改ページ
            $this->renderTableBottomLine();
            $this->pdf->AddPage();
            $this->currentY = self::MARGIN_TOP;
            $this->currentPage++;

            // 全ページにヘッダーを表示
            $this->renderHeader();

            // テーブルヘッダー再描画
            $this->renderTableHeader();
        }

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        $x = self::MARGIN_LEFT;
        $y = $this->currentY;

        // 行データ準備
        $item = $candidate->item;

        // 入り数（ケース入り数）
        $capacityCase = $item?->capacity_case ?? 1;
        $orderQty = $candidate->order_quantity ?? 0;

        // ケース数・バラ数の計算
        // 入り数が1以外の場合は両方表示（発注数を入り数で割る）
        if ($capacityCase > 1) {
            $caseQty = floor($orderQty / $capacityCase);
            $pieceQty = $orderQty % $capacityCase;
        } else {
            // 入り数が1の場合は元のロジック
            $caseQty = $candidate->quantity_type?->value === 'CASE' ? $orderQty : '';
            $pieceQty = $candidate->quantity_type?->value === 'PIECE' ? $orderQty : '';
        }

        $rowData = [
            $this->truncateText($candidate->ordering_code ?? '', 28),  // 発注CD（JANコード）
            $this->truncateText($item?->code ?? '', 22),               // 自社コード
            $capacityCase > 1 ? $capacityCase : '',                    // 入数（1は表示しない）
            $item?->name ?? '',                                        // 商品名（省略なし - 複数行対応）
            $caseQty !== '' && $caseQty !== 0 ? $caseQty : '',         // ケース
            $pieceQty !== '' && $pieceQty !== 0 ? $pieceQty : '',      // バラ
        ];

        // 入数・ケース・バラは中央揃え、商品名は左揃え
        $aligns = ['C', 'C', 'C', 'L', 'C', 'C'];
        $widths = array_values(self::COL_WIDTHS);

        // 商品名の高さを計算（複数行対応）- index 3
        $itemName = $rowData[3];
        $itemNameWidth = $widths[3] - 2; // パディング分引く
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $itemNameLines = $this->pdf->getNumLines($itemName, $itemNameWidth);
        $actualRowHeight = max($rowHeight, $itemNameLines * self::LINE_HEIGHT_NORMAL);

        // 各セルを描画
        foreach ($rowData as $i => $value) {
            if ($i === 3) {
                // 商品名は複数行対応 - 垂直中央揃え (index 3)
                $textHeight = $itemNameLines * self::LINE_HEIGHT_NORMAL;
                $cellY = $y + ($actualRowHeight - $textHeight) / 2;
                $this->pdf->SetXY($x, $cellY);
                $this->pdf->MultiCell($widths[$i], self::LINE_HEIGHT_NORMAL, $value, 0, $aligns[$i]);
            } else {
                // 中央揃え（縦方向）
                $cellY = $y + ($actualRowHeight - $rowHeight) / 2;
                $this->pdf->SetXY($x, $cellY);
                $this->pdf->Cell($widths[$i], $rowHeight, $value, 0, 0, $aligns[$i]);
            }

            // 縦線
            $this->pdf->Line($x, $y, $x, $y + $actualRowHeight);
            $x += $widths[$i];
        }
        // 右端の縦線
        $this->pdf->Line($x, $y, $x, $y + $actualRowHeight);

        // 行の下に実線を描画
        $tableWidth = array_sum(self::COL_WIDTHS);
        $this->pdf->Line(self::MARGIN_LEFT, $y + $actualRowHeight, self::MARGIN_LEFT + $tableWidth, $y + $actualRowHeight);

        $this->currentY = $y + $actualRowHeight;
        $this->itemRowCount++;
    }

    /**
     * テーブル下線描画
     */
    private function renderTableBottomLine(): void
    {
        $tableWidth = array_sum(self::COL_WIDTHS);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->Line(self::MARGIN_LEFT, $this->currentY, self::MARGIN_LEFT + $tableWidth, $this->currentY);
    }

    /**
     * 通信欄描画（全幅）
     */
    private function renderCommunicationArea(?string $notes = null): void
    {
        $boxX = self::MARGIN_LEFT;
        $boxY = $this->currentY;
        $boxWidth = self::CONTENT_WIDTH; // 全幅
        $boxHeight = 25;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $this->pdf->SetXY($boxX, $boxY);
        $this->pdf->Cell($boxWidth, self::LINE_HEIGHT_NORMAL, '【通信欄】', 0, 1, 'L');

        // 枠線
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->Rect($boxX, $boxY + self::LINE_HEIGHT_NORMAL, $boxWidth, $boxHeight - self::LINE_HEIGHT_NORMAL);

        // 枠内にテキストを描画
        if ($notes) {
            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
            $this->pdf->SetXY($boxX + 2, $boxY + self::LINE_HEIGHT_NORMAL + 1);
            $this->pdf->MultiCell($boxWidth - 4, 4, $notes, 0, 'L');
        }

        // Y座標を通信欄の下へ進める
        $this->currentY = $boxY + $boxHeight;
    }

    /**
     * 全ページにページ番号を描画（レンダリング後に呼び出し）
     */
    private function renderPageNumbers(): void
    {
        $totalPages = $this->totalPages;
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        for ($i = 1; $i <= $totalPages; $i++) {
            $this->pdf->setPage($i);
            $pageText = "{$i} / {$totalPages}";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = (self::PAGE_WIDTH - $textWidth) / 2;
            $y = self::PAGE_HEIGHT - self::MARGIN_BOTTOM + 3;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, self::LINE_HEIGHT_NORMAL, $pageText, 0, 0, 'C');
        }
    }

    /**
     * テキスト切り詰め（はみ出し防止）
     */
    private function truncateText(string $text, float $maxWidth): string
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $currentWidth = $this->pdf->GetStringWidth($text);

        if ($currentWidth <= $maxWidth) {
            return $text;
        }

        // 1文字ずつ減らして収まるようにする
        $ellipsis = '…';
        $ellipsisWidth = $this->pdf->GetStringWidth($ellipsis);
        $targetWidth = $maxWidth - $ellipsisWidth;

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

    /**
     * クライアント情報取得
     */
    private function getClientInfo($warehouse): ?Client
    {
        if (! $warehouse || ! $warehouse->client_id) {
            return Client::first();
        }

        return Client::find($warehouse->client_id) ?? Client::first();
    }
}
