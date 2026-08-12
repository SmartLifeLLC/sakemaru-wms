<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderChannel;
use App\Enums\AutoOrder\OrderDataFileChannel;
use App\Enums\EVolumeUnit;
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
    // A4横サイズ（mm）
    private const PAGE_WIDTH = 297;

    private const PAGE_HEIGHT = 210;

    // マージン（mm）
    private const MARGIN_LEFT = 10;

    private const MARGIN_RIGHT = 10;

    private const MARGIN_TOP = 10;

    private const MARGIN_BOTTOM = 6;

    // 描画エリア
    private const CONTENT_WIDTH = 277; // PAGE_WIDTH - MARGIN_LEFT - MARGIN_RIGHT

    // フォントサイズ（pt）
    private const FONT_SIZE_TITLE = 22;

    private const FONT_SIZE_LARGE = 16;

    private const FONT_SIZE_NORMAL = 11.5;

    private const FONT_SIZE_SMALL = 10.5;

    // 行高さ（mm）
    private const LINE_HEIGHT_NORMAL = 6;

    private const LINE_HEIGHT_TABLE = 6.2;

    private const COMMUNICATION_TOP_GAP = 3;

    private const COMMUNICATION_CONTENT_HEIGHT = 12;

    // 罫線幅（mm）
    private const LINE_WIDTH = 0.2;

    private const LINE_WIDTH_THICK = 0.4;

    // テーブル列幅（mm）
    private const COL_WIDTHS = [
        'ordering_code' => 46,     // 発注CD（JANコード）- 省略禁止
        'item_code' => 25,         // 自社CD
        'volume' => 18,            // 容量
        'capacity_case' => 16,     // 入数
        'item_name' => 128,        // 商品名（省略なし）
        'case_qty' => 15,          // ケース
        'piece_qty' => 14,         // バラ
        'total_piece_qty' => 15,    // 総バラ
    ];

    private TCPDF $pdf;

    private float $currentY;

    private int $itemRowCount = 0;

    private int $currentPageDetailRowCount = 0;

    private int $currentPage = 1;

    private int $totalPages = 0;

    private ?string $communicationNotes = null;

    private string $generatedAtText = '';

    // ヘッダー情報を保持（全ページで使用）
    private WmsOrderDataFile $dataFile;

    private $contractor;

    private ?Client $client;

    private $warehouse;

    private OrderOutputQuantityResolver $quantityResolver;

    private array $logoImageSourceCache = [];

    private array $temporaryLogoImagePaths = [];

    public function __destruct()
    {
        foreach ($this->temporaryLogoImagePaths as $path) {
            if (is_string($path) && @file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * WmsOrderDataFileからPDFを生成
     */
    public function generateFromDataFile(WmsOrderDataFile $dataFile, ?string $communicationNotes = null): string
    {
        $candidates = $this->resolveCandidatesForDataFile($dataFile);

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
            $candidates = $this->resolveCandidatesForDataFile($dataFile);

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

        return $this->storePdfBinary($pdfBinary, $dataFile);
    }

    /**
     * 指定済み候補からPDFを生成しS3に保存
     */
    public function generateAndStoreFromCandidates(Collection $candidates, WmsOrderDataFile $dataFile, ?string $communicationNotes = null): string
    {
        if ($candidates->isEmpty()) {
            throw new \RuntimeException('生成対象の発注候補がありません');
        }

        $pdfBinary = $this->generate($candidates, $dataFile, $communicationNotes);

        return $this->storePdfBinary($pdfBinary, $dataFile);
    }

    private function storePdfBinary(string $pdfBinary, WmsOrderDataFile $dataFile): string
    {
        // S3パス生成
        $date = now()->format('Y-m-d');
        $warehouseCode = $dataFile->warehouse?->code ?? $dataFile->warehouse_id;
        $contractorCode = $dataFile->contractor?->code ?? $dataFile->contractor_id;
        $filename = "{$dataFile->batch_code}_{$warehouseCode}_{$contractorCode}_df{$dataFile->id}.pdf";
        $filePath = "order-data-files/{$date}/{$filename}";

        Storage::disk('s3')->put($filePath, $pdfBinary);

        // DBに記録
        $dataFile->update(['fax_file_path' => $filePath]);

        return $filePath;
    }

    private function resolveCandidatesForDataFile(WmsOrderDataFile $dataFile): Collection
    {
        $query = WmsOrderCandidate::query()
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor'])
            ->orderBy('expected_arrival_date');

        $candidateIds = collect($dataFile->candidate_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if (! empty($candidateIds)) {
            return $query
                ->whereIn('id', $candidateIds)
                ->get()
                ->sortBy(fn (WmsOrderCandidate $candidate) => array_search($candidate->id, $candidateIds, true))
                ->values();
        }

        $candidatesFromCsv = $this->resolveCandidatesFromCsv($dataFile);
        if ($candidatesFromCsv->isNotEmpty()) {
            return $candidatesFromCsv;
        }

        return $query
            ->where('batch_code', $dataFile->batch_code)
            ->where('warehouse_id', $dataFile->warehouse_id)
            ->where('contractor_id', $dataFile->contractor_id)
            ->get();
    }

    private function resolveCandidatesFromCsv(WmsOrderDataFile $dataFile): Collection
    {
        if (! $dataFile->file_path) {
            return collect();
        }

        try {
            $csvContent = Storage::disk('s3')->get($dataFile->file_path);
        } catch (\Throwable) {
            return collect();
        }

        if (! is_string($csvContent) || $csvContent === '') {
            return collect();
        }

        $rows = $this->parseCsvRows($csvContent);
        if (empty($rows)) {
            return collect();
        }

        $candidatePool = WmsOrderCandidate::where('batch_code', $dataFile->batch_code)
            ->where('warehouse_id', $dataFile->warehouse_id)
            ->where('contractor_id', $dataFile->contractor_id)
            ->where('status', CandidateStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        if ($candidatePool->isEmpty()) {
            return collect();
        }

        $quantityResolver = app(OrderOutputQuantityResolver::class);
        $matched = collect();
        $usedCandidateIds = [];

        foreach ($rows as $row) {
            $itemCode = trim((string) ($row['商品コード'] ?? ''));
            $arrivalDate = trim((string) ($row['入荷予定日'] ?? ''));
            $orderQuantity = trim((string) ($row['発注数量'] ?? ''));

            $candidate = $candidatePool->first(function (WmsOrderCandidate $candidate) use ($itemCode, $arrivalDate, $orderQuantity, $quantityResolver, $usedCandidateIds): bool {
                if (in_array($candidate->id, $usedCandidateIds, true)) {
                    return false;
                }

                if ($itemCode !== '' && (string) $candidate->item?->code !== $itemCode) {
                    return false;
                }

                if ($arrivalDate !== '' && $candidate->expected_arrival_date?->format('Y-m-d') !== $arrivalDate) {
                    return false;
                }

                if ($orderQuantity !== '') {
                    $resolved = $quantityResolver->resolve($candidate);
                    if ((string) ($resolved['order_quantity'] ?? '') !== $orderQuantity) {
                        return false;
                    }
                }

                return true;
            });

            if ($candidate) {
                $matched->push($candidate);
                $usedCandidateIds[] = $candidate->id;
            }
        }

        return $matched->values();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsvRows(string $csvContent): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        $headers = fgetcsv($stream);
        if ($headers === false) {
            fclose($stream);

            return [];
        }

        $headers = array_map(
            fn ($header) => preg_replace('/^\xEF\xBB\xBF/', '', (string) $header),
            $headers
        );

        $rows = [];
        while (($values = fgetcsv($stream)) !== false) {
            if ($values === [null] || $values === false) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = (string) ($values[$index] ?? '');
            }
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /**
     * PDFを初期化
     */
    private function initPdf(): void
    {
        $this->pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

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
        $this->generatedAtText = now()->format('Y年m月d H時i分s秒');
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
        $this->quantityResolver = app(OrderOutputQuantityResolver::class);
        $this->communicationNotes = $communicationNotes;

        // 最初のページ
        $this->pdf->AddPage();
        $this->currentY = self::MARGIN_TOP;
        $this->currentPage = 1;

        // ヘッダー描画
        $this->renderHeader();

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
        $this->renderOrderTitleBlock();
        $this->renderCompanyHeader();

        if ($this->shouldRenderEosStamp()) {
            $this->renderEosStamp();
        }

        $contractorBottomY = $this->renderContractorCard();
        $this->renderApprovalBoxes();
        $deliveryBottomY = $this->renderDeliverySummary(max($contractorBottomY + 4, 50));

        $this->currentY = max($deliveryBottomY + 3, 76);
    }

    private function renderOrderTitleBlock(): void
    {
        $this->pdf->SetTextColor(15, 23, 42);
        $this->pdf->SetFont('kozminproregular', '', 10);
        $this->pdf->SetXY(self::MARGIN_LEFT, self::MARGIN_TOP - 3);
        $this->pdf->Cell(95, 5, '生成日時: '.$this->generatedAtText, 0, 1, 'L');

        $this->pdf->SetFont('kozminproregular', 'B', 20);
        $this->pdf->SetXY(self::MARGIN_LEFT, self::MARGIN_TOP + 3);
        $this->pdf->Cell(80, 8, '発注書', 0, 1, 'L');

        $this->pdf->SetFont('kozminproregular', 'B', 10.5);
        $this->pdf->SetXY(self::MARGIN_LEFT, self::MARGIN_TOP + 11.5);
        $this->pdf->Cell(95, 5, '発注番号: '.$this->dataFile->batch_code, 0, 1, 'L');
        $this->pdf->SetTextColor(0, 0, 0);
    }

    private function renderCompanyHeader(): void
    {
        if (! $this->client) {
            return;
        }

        $startX = self::PAGE_WIDTH - self::MARGIN_RIGHT - 80;
        $startY = self::MARGIN_TOP - 1;
        $width = 80;
        $height = 38;
        $lineY = $startY + 4;
        $logoSource = $this->client?->setting?->logo_image_url;
        $logoPath = $this->resolveLogoImageSource($logoSource);
        $stampPath = $this->resolveLogoImageSource($this->client?->setting?->stamp_image_url);

        $this->pdf->SetDrawColor(203, 213, 225);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->Rect($startX, $startY, $width, $height);
        $this->pdf->SetDrawColor(0, 0, 0);

        if ($logoPath) {
            try {
                $this->pdf->Image(
                    $logoPath,
                    $startX + 12,
                    $lineY,
                    56,
                    0,
                    '',
                    '',
                    '',
                    false,
                    300,
                    '',
                    false,
                    false,
                    0,
                );
                $lineY += 15;
            } catch (\Throwable) {
                $logoPath = null;
            }
        }

        if (! $logoPath) {
            $this->pdf->SetFont('kozminproregular', 'B', self::FONT_SIZE_NORMAL);
            $this->pdf->SetXY($startX + 2, $lineY);
            $this->pdf->Cell($width - 4, self::LINE_HEIGHT_NORMAL, $this->client->name ?? '', 0, 1, 'C');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        if ($stampPath) {
            try {
                $this->pdf->SetAlpha(0.25);
                $this->pdf->Image($stampPath, $startX + 65, $lineY - 0.5, 11, 11);
                $this->pdf->SetAlpha(1);
            } catch (\Throwable) {
                $this->pdf->SetAlpha(1);
            }
        }

        $textLineHeight = 3.9;

        $address = $this->companyHeaderAddress();
        if ($address !== '') {
            $this->pdf->SetXY($startX + 2, $lineY);
            $this->setFittingFont('kozminproregular', '', 9, 7.2, $address, $width - 4);
            $this->pdf->Cell($width - 4, $textLineHeight, $address, 0, 1, 'C');
            $lineY += $textLineHeight;
        }

        $contactLines = $this->companyHeaderContactLines();
        if ($contactLines !== []) {
            $contactText = implode('  ', $contactLines);
            $this->pdf->SetXY($startX + 2, $lineY);
            $this->setFittingFont('kozminproregular', '', 9, 7.2, $contactText, $width - 4);
            $this->pdf->Cell($width - 4, $textLineHeight, $contactText, 0, 1, 'C');
            $lineY += $textLineHeight;
        }

        $registrationLine = $this->companyHeaderRegistrationLine();
        if ($registrationLine !== '') {
            $this->pdf->SetXY($startX + 2, $lineY);
            $this->setFittingFont('kozminproregular', '', 9, 7.2, $registrationLine, $width - 4);
            $this->pdf->Cell($width - 4, $textLineHeight, $registrationLine, 0, 1, 'R');
            $lineY += $textLineHeight;
        }

        $creatorName = $this->dataFile->created_by_name ?? '';
        if ($creatorName) {
            $creatorText = '発注担当: '.$creatorName;
            $this->pdf->SetXY($startX + 2, $lineY);
            $this->setFittingFont('kozminproregular', '', 9, 7.2, $creatorText, $width - 4);
            $this->pdf->Cell($width - 4, $textLineHeight, $creatorText, 0, 1, 'R');
        }

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
    }

    private function companyHeaderAddress(): string
    {
        $address = trim(($this->warehouse?->address1 ?? '').($this->warehouse?->address2 ?? ''));
        $postalCode = (string) ($this->warehouse?->postal_code ?? '');

        if ($address === '') {
            $address = trim(($this->client->order_form_address1 ?? $this->client->address1 ?? '').($this->client->order_form_address2 ?? $this->client->address2 ?? ''));
            $postalCode = (string) ($this->client->postal_code ?? '');
        }

        if ($address === '') {
            return '';
        }

        return $postalCode !== '' ? '〒'.$postalCode.' '.$address : $address;
    }

    /**
     * @return array<int, string>
     */
    private function companyHeaderContactLines(): array
    {
        $tel = $this->warehouse?->tel ?: $this->client->tel;
        $fax = $this->warehouse?->fax ?: $this->client->fax;
        $lines = [];

        if ($tel) {
            $lines[] = 'TEL: '.$tel;
        }

        if ($fax) {
            $lines[] = 'FAX: '.$fax;
        }

        return $lines;
    }

    private function companyHeaderRegistrationLine(): string
    {
        $businessNumber = trim((string) ($this->client->business_number ?? ''));
        if ($businessNumber === '') {
            return '';
        }

        return '登録番号: T'.ltrim($businessNumber, 'Tt');
    }

    private function setFittingFont(string $family, string $style, float $preferredSize, float $minimumSize, string $text, float $maxWidth): void
    {
        for ($size = $preferredSize; $size >= $minimumSize; $size -= 0.3) {
            $this->pdf->SetFont($family, $style, $size);
            if ($this->pdf->GetStringWidth($text) <= $maxWidth) {
                return;
            }
        }

        $this->pdf->SetFont($family, $style, $minimumSize);
    }

    private function renderContractorCard(): float
    {
        $x = self::MARGIN_LEFT;
        $y = 26;
        $width = 140;
        $height = 20;

        $this->pdf->SetDrawColor(203, 213, 225);
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->Rect($x, $y, $width, $height);
        $this->pdf->SetFillColor(51, 65, 85);
        $this->pdf->Rect($x, $y, 1.8, $height, 'F');

        $this->pdf->SetTextColor(15, 23, 42);
        $contractorName = $this->contractor?->name ?? '（発注先名）';
        $contractorTitle = $contractorName.' 御中';
        $this->setFittingFont('kozminproregular', 'B', 14, 10.5, $contractorTitle, $width - 8);
        $this->pdf->SetXY($x + 5, $y + 3);
        $this->pdf->Cell($width - 8, 6, $contractorTitle, 0, 1, 'L');

        $this->pdf->SetDrawColor(203, 213, 225);
        $this->pdf->Line($x + 5, $y + 10.2, $x + $width - 4, $y + 10.2);

        $contactParts = [];
        if ($this->contractor?->tel) {
            $contactParts[] = 'TEL: '.$this->contractor->tel;
        }
        if ($this->contractor?->fax) {
            $contactParts[] = 'FAX: '.$this->contractor->fax;
        }

        $contactText = implode('   ', $contactParts);
        $this->setFittingFont('kozminproregular', '', 11, 8.5, $contactText, $width - 8);
        $this->pdf->SetXY($x + 5, $y + 11.6);
        $this->pdf->Cell($width - 8, 5, $contactText, 0, 1, 'L');

        $this->pdf->SetFont('kozminproregular', '', 10);
        $this->pdf->SetTextColor(71, 85, 105);
        $this->pdf->SetXY($x + 5, $y + 16.2);
        $this->pdf->Cell($width - 8, 4, '下記内容にて、発注をお願いいたします。', 0, 1, 'L');

        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetDrawColor(0, 0, 0);

        return $y + $height;
    }

    private function renderApprovalBoxes(): void
    {
        $x = self::PAGE_WIDTH - self::MARGIN_RIGHT - 64;
        $y = 50;
        $width = 64;
        $headerHeight = 6;
        $bodyHeight = 11;
        $colWidth = $width / 3;
        $labels = ['確認', '処理', '承認'];

        $this->pdf->SetDrawColor(203, 213, 225);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetFillColor(248, 250, 252);
        $this->pdf->Rect($x, $y, $width, $headerHeight, 'DF');
        $this->pdf->Rect($x, $y + $headerHeight, $width, $bodyHeight);

        $this->pdf->SetFont('kozminproregular', 'B', 9);
        foreach ($labels as $index => $label) {
            $colX = $x + ($colWidth * $index);
            if ($index > 0) {
                $this->pdf->Line($colX, $y, $colX, $y + $headerHeight + $bodyHeight);
            }
            $this->pdf->SetXY($colX, $y + 0.5);
            $this->pdf->Cell($colWidth, $headerHeight - 1, $label, 0, 0, 'C');
        }

        $this->pdf->Line($x, $y + $headerHeight, $x + $width, $y + $headerHeight);
        $this->pdf->SetDrawColor(0, 0, 0);
    }

    private function renderDeliverySummary(float $startY): float
    {
        $deliveryWarehouse = $this->warehouse;
        if ($this->warehouse?->is_virtual && $this->warehouse?->stock_warehouse_id) {
            $deliveryWarehouse = \App\Models\Sakemaru\Warehouse::find($this->warehouse->stock_warehouse_id);
        }

        $designatedCode = WmsContractorWarehouseSetting::getDesignatedCode(
            $this->warehouse?->id ?? 0,
            $this->contractor?->id ?? 0,
        );

        $rows = [
            ['納入場所:', $deliveryWarehouse?->name ?? ''],
            ['仕入先コード:', (string) ($this->contractor?->code ?: ' - ')],
            ['納入先指定コード:', (string) ($designatedCode ?? ' - ')],
            ['発注日:', $this->dataFile->order_date?->format('Y-m-d') ?? ''],
            ['納品予定日:', $this->dataFile->expected_arrival_date?->format('Y-m-d') ?? ''],
        ];

        $labelWidth = 30;
        $lineHeight = 4.6;
        $y = $startY;

        foreach ($rows as [$label, $value]) {
            $this->pdf->SetFont('kozminproregular', 'B', 11);
            $this->pdf->SetXY(self::MARGIN_LEFT, $y);
            $this->pdf->Cell($labelWidth, $lineHeight, $label, 0, 0, 'L');

            $this->pdf->SetFont('kozminproregular', '', 11);
            $this->pdf->SetXY(self::MARGIN_LEFT + $labelWidth, $y);
            $this->pdf->Cell(95, $lineHeight, $value, 0, 1, 'L');
            $y += $lineHeight;
        }

        return $y;
    }

    private function resolveLogoImageSource(?string $source): ?string
    {
        if (blank($source)) {
            return null;
        }

        $source = trim((string) $source);
        if (array_key_exists($source, $this->logoImageSourceCache)) {
            return $this->logoImageSourceCache[$source];
        }

        if (str_starts_with($source, 'data:image/')) {
            $base64 = preg_replace('/^data:image\/[^;]+;base64,/', '', $source);
            $binary = base64_decode((string) $base64, true);

            return $this->logoImageSourceCache[$source] = $binary !== false && $binary !== ''
                ? $this->writeLogoImageBinaryToTemporaryFile($binary, $source)
                : null;
        }

        if (@file_exists($source)) {
            return $this->logoImageSourceCache[$source] = $this->prepareLogoImagePath($source);
        }

        if (! filter_var($source, FILTER_VALIDATE_URL)) {
            return null;
        }

        $binary = $this->resolveLogoImageBinaryFromUrl($source);

        return $this->logoImageSourceCache[$source] = $binary !== null && $binary !== ''
            ? $this->writeLogoImageBinaryToTemporaryFile($binary, $source)
            : null;
    }

    private function resolveLogoImageBinaryFromUrl(string $url): ?string
    {
        try {
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            $isS3Url = $host !== '' && (str_contains($host, '.s3.') || str_contains($host, '.s3-'));

            if ($isS3Url) {
                $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
                if ($path !== '') {
                    $content = $this->resolveLogoImageBinaryFromS3Path($path);
                    if ($content !== null && $content !== '') {
                        return $content;
                    }
                }
            }
        } catch (\Throwable) {
            // S3取得失敗時はURL直取得へフォールバックする。
        }

        $content = @file_get_contents($url);

        return is_string($content) && $content !== '' ? $content : null;
    }

    private function resolveLogoImageBinaryFromS3Path(string $path): ?string
    {
        $logicalPaths = array_values(array_unique(array_filter([
            $path,
            $this->stripConfiguredS3Prefix($path),
        ])));

        foreach ($logicalPaths as $logicalPath) {
            try {
                $content = Storage::disk('s3')->get($logicalPath);
                if (is_string($content) && $content !== '') {
                    return $content;
                }
            } catch (\Throwable) {
                // 次の解決方法へフォールバックする。
            }
        }

        try {
            $config = config('filesystems.disks.s3', []);
            unset($config['prefix']);

            $content = Storage::build($config)->get($path);

            return is_string($content) && $content !== '' ? $content : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function stripConfiguredS3Prefix(string $path): string
    {
        $prefix = trim((string) config('filesystems.disks.s3.prefix', ''), '/');
        if ($prefix === '') {
            return $path;
        }

        return str_starts_with($path, $prefix.'/')
            ? substr($path, strlen($prefix) + 1)
            : $path;
    }

    private function writeLogoImageBinaryToTemporaryFile(string $binary, string $source): ?string
    {
        $extension = pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';
        if (str_starts_with($source, 'data:image/jpeg')) {
            $extension = 'jpg';
        } elseif (str_starts_with($source, 'data:image/png')) {
            $extension = 'png';
        }

        $path = tempnam(sys_get_temp_dir(), 'wms_po_logo_').'.'.strtolower($extension);
        if (@file_put_contents($path, $binary) === false) {
            return null;
        }

        $this->temporaryLogoImagePaths[] = $path;

        return $this->prepareLogoImagePath($path);
    }

    private function prepareLogoImagePath(string $path): string
    {
        if (! str_ends_with(strtolower($path), '.png') || ! function_exists('imagecreatefrompng')) {
            return $path;
        }

        $image = @imagecreatefrompng($path);
        if (! $image) {
            return $path;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $jpeg = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($jpeg, 255, 255, 255);
        imagefill($jpeg, 0, 0, $white);
        imagealphablending($jpeg, true);
        imagecopy($jpeg, $image, 0, 0, 0, 0, $width, $height);

        $jpegPath = tempnam(sys_get_temp_dir(), 'wms_po_logo_').'.jpg';
        if (@imagejpeg($jpeg, $jpegPath, 95)) {
            $this->temporaryLogoImagePaths[] = $jpegPath;
            imagedestroy($image);
            imagedestroy($jpeg);

            return $jpegPath;
        }

        imagedestroy($image);
        imagedestroy($jpeg);

        return $path;
    }

    private function shouldRenderEosStamp(): bool
    {
        $dataFileChannel = $this->dataFile->order_channel instanceof OrderDataFileChannel
            ? $this->dataFile->order_channel
            : OrderDataFileChannel::tryFrom((string) $this->dataFile->order_channel);

        if ($this->dataFile->show_eos_stamp || $dataFileChannel === OrderDataFileChannel::EOS) {
            return true;
        }

        $candidateIds = collect($this->dataFile->candidate_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($candidateIds === []) {
            return false;
        }

        return WmsOrderCandidate::query()
            ->whereIn('id', $candidateIds)
            ->where('order_channel', OrderChannel::EOS->value)
            ->exists();
    }

    private function renderEosStamp(): void
    {
        $width = 42;
        $height = 11;
        $x = self::MARGIN_LEFT + 148;
        $y = self::MARGIN_TOP + 20;

        $this->pdf->SetDrawColor(30, 64, 175);
        $this->pdf->SetTextColor(30, 64, 175);
        $this->pdf->SetLineWidth(self::LINE_WIDTH_THICK);
        $this->pdf->SetFont('kozminproregular', 'B', self::FONT_SIZE_SMALL);
        $this->pdf->Rect($x, $y, $width, $height);
        $this->pdf->SetXY($x, $y + 1.5);
        $this->pdf->Cell($width, 7, 'EOS発注控え', 0, 0, 'C');
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetDrawColor(0, 0, 0);
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
    }

    /**
     * 発注先情報描画
     */
    private function renderContractorInfo(float $startY): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_LARGE);
        $this->pdf->SetXY(self::MARGIN_LEFT, $startY);

        $contractorName = $this->contractor?->name ?? '（発注先名）';
        $this->pdf->Cell(110, 10, $contractorName.' 御中', 0, 1, 'L');

        // 下線
        $this->pdf->Line(self::MARGIN_LEFT, $startY + 10, self::MARGIN_LEFT + 110, $startY + 10);

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
        $lineY = $startY + 13;

        if ($this->contractor?->tel) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, 'TEL: '.$this->contractor->tel, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }
        if ($this->contractor?->fax) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, 'FAX: '.$this->contractor->fax, 0, 1, 'L');
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
            $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, '納入場所: '.$warehouseName, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // 仕入先コード（FAX宛先の発注先マスタコード）
        $contractorCode = $this->contractor?->code;
        $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
        $this->pdf->Cell(58, self::LINE_HEIGHT_NORMAL, '仕入先コード: '.($contractorCode ?: ' - '), 0, 0, 'L');

        // 納入先指定コード
        $designatedCode = WmsContractorWarehouseSetting::getDesignatedCode(
            $this->warehouse?->id ?? 0,
            $this->contractor?->id ?? 0,
        );
        $this->pdf->SetXY(self::MARGIN_LEFT + 58, $lineY);
        $this->pdf->Cell(90, self::LINE_HEIGHT_NORMAL, '納入先指定コード: '.($designatedCode ?? ' - '), 0, 1, 'L');
        $lineY += self::LINE_HEIGHT_NORMAL;

        // 納入予定日（入荷日）
        $expectedDate = $this->dataFile->expected_arrival_date?->format('Y年m月d日') ?? '';
        if ($expectedDate) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, '納入予定日: '.$expectedDate, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        $this->currentY = max($this->currentY, $lineY + 2);
    }

    /**
     * 発注元情報描画（発注先と同じ高さから開始）
     * 倉庫住所を優先、未設定の場合はClientにフォールバック
     */
    private function renderClientInfo(float $startY): void
    {
        $startX = self::PAGE_WIDTH - self::MARGIN_RIGHT - 100;
        $wh = $this->warehouse;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);

        $lineY = $startY;

        // 会社名（Clientから）
        $this->pdf->SetXY($startX, $lineY);
        $this->pdf->Cell(100, self::LINE_HEIGHT_NORMAL, $this->client->name ?? '', 0, 1, 'L');
        $lineY += self::LINE_HEIGHT_NORMAL;

        // 倉庫名
        if ($wh?->name) {
            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell(100, self::LINE_HEIGHT_NORMAL, $wh->name, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // 住所（倉庫優先 → Clientフォールバック）
        $address = trim(($wh?->address1 ?? '').($wh?->address2 ?? ''));
        if (! $address) {
            $address = trim(($this->client->order_form_address1 ?? $this->client->address1 ?? '').($this->client->order_form_address2 ?? $this->client->address2 ?? ''));
        }
        if ($address) {
            $postalCode = $wh?->postal_code ?? '';
            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
            $this->pdf->SetXY($startX, $lineY);
            $displayAddress = $postalCode ? '〒'.$postalCode.' '.$address : $address;
            $this->pdf->MultiCell(100, self::LINE_HEIGHT_NORMAL, $displayAddress, 0, 'L');
            $lineY = $this->pdf->GetY();
        }

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        // TEL（倉庫優先 → Clientフォールバック）
        $tel = $wh?->tel ?: $this->client->tel;
        if ($tel) {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell(100, self::LINE_HEIGHT_NORMAL, 'TEL: '.$tel, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // FAX（倉庫優先 → Clientフォールバック）
        $fax = $wh?->fax ?: $this->client->fax;
        if ($fax) {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell(100, self::LINE_HEIGHT_NORMAL, 'FAX: '.$fax, 0, 1, 'L');
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
        $this->currentPageDetailRowCount = 0;
        foreach ($candidates as $candidate) {
            $this->renderTableRow($candidate);
        }

        // テーブル下線
        $this->renderTableBottomLine();
        $this->renderCommunicationAreaForPage();
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
            '自社CD',      // 商品コード
            '容量',        // volume + volume_unit
            '入数',        // capacity_case
            '商品名',      // 省略なし
            'ケース',      // ケース数
            'バラ',        // バラ数
            '総バラ',      // 総バラ数
        ];

        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $rowHeight = self::LINE_HEIGHT_TABLE;

        $tableWidth = array_sum(self::COL_WIDTHS);
        $this->pdf->SetDrawColor(203, 213, 225);
        $this->pdf->SetFillColor(248, 250, 252);
        $this->pdf->Rect($x, $y, $tableWidth, $rowHeight, 'F');

        // 上線
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
        $this->pdf->SetDrawColor(0, 0, 0);
    }

    /**
     * テーブル行描画
     */
    private function renderTableRow($candidate): void
    {
        $rowHeight = self::LINE_HEIGHT_TABLE;

        // 行データ準備
        $item = $candidate->item;

        $outputQuantity = $this->quantityResolver->resolve($candidate);
        $capacityCase = $outputQuantity['display_capacity'];
        $caseQty = $outputQuantity['case_quantity'];
        $pieceQty = $outputQuantity['piece_quantity'];
        $totalPieceQty = ($caseQty * max(1, (int) $capacityCase)) + $pieceQty;

        $volumeLabel = $this->formatVolume($item);

        $rowData = [
            $outputQuantity['ordering_code'] ?? '',                             // 発注CD（JANコード）- 省略禁止
            $this->truncateText($item?->code ?? '', 22),                     // 自社コード
            $volumeLabel,                                                    // 容量
            $capacityCase > 1 ? $capacityCase : '',                          // 入数（1は表示しない）
            $item?->name ?? '',                                              // 商品名（省略なし - 複数行対応）
            $caseQty !== 0 ? $caseQty : '',                                  // ケース
            $pieceQty !== 0 ? $pieceQty : '',                                // バラ
            $totalPieceQty !== 0 ? $totalPieceQty : '',                      // 総バラ
        ];

        // 入数・ケース・バラは中央揃え、商品名は左揃え
        $aligns = ['C', 'C', 'C', 'C', 'L', 'C', 'C', 'C'];
        $widths = array_values(self::COL_WIDTHS);

        // 商品名の高さを計算（複数行対応）- index 4
        $itemName = $rowData[4];
        $itemNameWidth = $widths[4] - 2; // パディング分引く
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $itemNameLines = $this->pdf->getNumLines($itemName, $itemNameWidth);
        $actualRowHeight = max($rowHeight, $itemNameLines * self::LINE_HEIGHT_NORMAL);

        // ページ残高チェック（明細表の下に通信欄を必ず残す）
        if (
            $this->currentPageDetailRowCount > 0
            && $this->currentY + $actualRowHeight + $this->communicationAreaReservedHeight() > self::PAGE_HEIGHT - self::MARGIN_BOTTOM
        ) {
            $this->renderTableBottomLine();
            $this->renderCommunicationAreaForPage();

            $this->pdf->AddPage();
            $this->currentY = self::MARGIN_TOP;
            $this->currentPage++;

            // 全ページにヘッダーとテーブルヘッダーを表示
            $this->renderHeader();
            $this->renderTableHeader();
            $this->currentPageDetailRowCount = 0;
        }

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        $x = self::MARGIN_LEFT;
        $y = $this->currentY;
        $this->pdf->SetDrawColor(203, 213, 225);

        // 各セルを描画
        foreach ($rowData as $i => $value) {
            if ($i === 4) {
                // 商品名は複数行対応 - 垂直中央揃え (index 4)
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
        $this->currentPageDetailRowCount++;
        $this->pdf->SetDrawColor(0, 0, 0);
    }

    /**
     * テーブル下線描画
     */
    private function renderTableBottomLine(): void
    {
        $tableWidth = array_sum(self::COL_WIDTHS);
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->SetDrawColor(203, 213, 225);
        $this->pdf->Line(self::MARGIN_LEFT, $this->currentY, self::MARGIN_LEFT + $tableWidth, $this->currentY);
        $this->pdf->SetDrawColor(0, 0, 0);
    }

    /**
     * 通信欄描画（全幅）
     */
    private function renderCommunicationArea(?string $notes = null): void
    {
        $boxX = self::MARGIN_LEFT;
        $boxY = $this->currentY;
        $boxWidth = self::CONTENT_WIDTH;
        $lineHeight = 5;
        $padding = 2;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        $contentHeight = $this->communicationContentHeight($notes);
        $boxHeight = self::LINE_HEIGHT_NORMAL + $contentHeight;

        $this->pdf->SetXY($boxX, $boxY);
        $this->pdf->Cell($boxWidth, self::LINE_HEIGHT_NORMAL, '【通信欄】', 0, 1, 'L');

        // 枠線
        $this->pdf->SetLineWidth(self::LINE_WIDTH);
        $this->pdf->Rect($boxX, $boxY + self::LINE_HEIGHT_NORMAL, $boxWidth, $contentHeight);

        // 枠内にテキストを描画
        if ($notes) {
            $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
            $this->pdf->SetXY($boxX + $padding, $boxY + self::LINE_HEIGHT_NORMAL + 1);
            $this->pdf->MultiCell($boxWidth - ($padding * 2), $lineHeight, $notes, 0, 'L');
        }

        // Y座標を通信欄の下へ進める
        $this->currentY = $boxY + $boxHeight;
    }

    private function renderCommunicationAreaForPage(): void
    {
        $this->currentY += self::COMMUNICATION_TOP_GAP;
        $this->renderCommunicationArea($this->communicationNotes);
    }

    private function communicationAreaReservedHeight(): float
    {
        return self::COMMUNICATION_TOP_GAP + self::LINE_HEIGHT_NORMAL + $this->communicationContentHeight($this->communicationNotes);
    }

    private function communicationContentHeight(?string $notes = null): float
    {
        $contentHeight = self::COMMUNICATION_CONTENT_HEIGHT;
        if (! $notes) {
            return $contentHeight;
        }

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $numLines = $this->pdf->getNumLines($notes, self::CONTENT_WIDTH - 4);

        return $numLines >= 4
            ? ($numLines * 5) + 4
            : $contentHeight;
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
            $pageText = "{$i} / {$totalPages}頁";
            $textWidth = $this->pdf->GetStringWidth($pageText);
            $x = self::PAGE_WIDTH - self::MARGIN_RIGHT - $textWidth;
            $y = self::MARGIN_TOP - 6;
            $this->pdf->SetXY($x, $y);
            $this->pdf->Cell($textWidth, self::LINE_HEIGHT_NORMAL, $pageText, 0, 0, 'R');
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
     * 容量表示フォーマット（例: 1000ml, 500g）
     */
    private function formatVolume($item): string
    {
        if (! $item || ! $item->volume || ! $item->volume_unit) {
            return '';
        }

        $unit = EVolumeUnit::tryFrom($item->volume_unit);
        if (! $unit) {
            return '';
        }

        return $item->volume.$unit->name();
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
