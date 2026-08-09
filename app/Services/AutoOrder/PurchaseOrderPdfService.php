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

    private const MARGIN_BOTTOM = 15;

    // 描画エリア
    private const CONTENT_WIDTH = 277; // PAGE_WIDTH - MARGIN_LEFT - MARGIN_RIGHT

    // フォントサイズ（pt）
    private const FONT_SIZE_TITLE = 22;

    private const FONT_SIZE_LARGE = 16;

    private const FONT_SIZE_NORMAL = 13;

    private const FONT_SIZE_SMALL = 12;

    // 行高さ（mm）
    private const LINE_HEIGHT_NORMAL = 7;

    private const LINE_HEIGHT_TABLE = 9;

    // 罫線幅（mm）
    private const LINE_WIDTH = 0.2;

    private const LINE_WIDTH_THICK = 0.4;

    // テーブル列幅（mm）
    private const COL_WIDTHS = [
        'ordering_code' => 55,     // 発注CD（JANコード）- 省略禁止
        'item_code' => 32,         // 自社コード
        'volume' => 22,            // 容量
        'capacity_case' => 18,     // 入数
        'item_name' => 121,        // 商品名（省略なし）
        'case_qty' => 15,          // ケース
        'piece_qty' => 14,         // バラ
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
        $this->renderOrderMeta();
        $this->renderCompanyHeader();

        // タイトル「発注書」
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_TITLE);
        $titleWidth = $this->pdf->GetStringWidth('発注書');
        $this->pdf->SetXY((self::PAGE_WIDTH - $titleWidth) / 2, $this->currentY);
        $this->pdf->Cell($titleWidth, 14, '発注書', 0, 0, 'C');
        $this->currentY += 16;

        if ($this->shouldRenderEosStamp()) {
            $this->renderEosStamp();
        }

        // 発注先・発注元情報を同じ高さから描画
        $infoStartY = $this->currentY;

        // 発注先情報（左側）
        $this->renderContractorInfo($infoStartY);

        $this->currentY += 5;
    }

    private function renderOrderMeta(): void
    {
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $this->pdf->SetXY(self::MARGIN_LEFT, self::MARGIN_TOP);
        $this->pdf->Cell(95, self::LINE_HEIGHT_NORMAL, '発注日: '.$this->dataFile->order_date->format('Y年m月d日'), 0, 1, 'L');
        $this->pdf->SetX(self::MARGIN_LEFT);
        $this->pdf->Cell(95, self::LINE_HEIGHT_NORMAL, '発注番号: '.$this->dataFile->batch_code, 0, 1, 'L');
    }

    private function renderCompanyHeader(): void
    {
        if (! $this->client) {
            return;
        }

        $startX = self::PAGE_WIDTH - self::MARGIN_RIGHT - 83;
        $startY = self::MARGIN_TOP - 1;
        $width = 83;
        $lineY = $startY;
        $logoSource = $this->client?->setting?->logo_image_url;
        $logoPath = $this->resolveLogoImageSource($logoSource);
        $stampPath = $this->resolveLogoImageSource($this->client?->setting?->stamp_image_url);

        if ($logoPath) {
            try {
                $this->pdf->Image(
                    $logoPath,
                    $startX + 8,
                    $lineY,
                    50,
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
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell($width, self::LINE_HEIGHT_NORMAL, $this->client->name ?? '', 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        if ($stampPath) {
            try {
                $this->pdf->SetAlpha(0.45);
                $this->pdf->Image($stampPath, $startX + 58, $lineY - 1, 15, 15);
                $this->pdf->SetAlpha(1);
            } catch (\Throwable) {
                $this->pdf->SetAlpha(1);
            }
        }

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_NORMAL);
        $textLineHeight = 5.8;

        if ($logoPath && filled($this->client->name ?? null)) {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell($width, $textLineHeight, (string) $this->client->name, 0, 1, 'L');
            $lineY += $textLineHeight;
        }

        if ($this->warehouse?->name) {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell($width, $textLineHeight, $this->warehouse->name, 0, 1, 'L');
            $lineY += $textLineHeight;
        }

        $address = $this->companyHeaderAddress();
        if ($address !== '') {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->MultiCell($width, $textLineHeight, $address, 0, 'L');
            $lineY = $this->pdf->GetY();
        }

        foreach ($this->companyHeaderContactLines() as $contactLine) {
            $this->pdf->SetXY($startX, $lineY);
            $this->pdf->Cell($width, $textLineHeight, $contactLine, 0, 1, 'L');
            $lineY += $textLineHeight;
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
        $x = (self::PAGE_WIDTH - $width) / 2;
        $y = self::MARGIN_TOP + 16;

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
        $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, '仕入先コード: '.($contractorCode ?: ' - '), 0, 1, 'L');
        $lineY += self::LINE_HEIGHT_NORMAL;

        // 納入先指定コード
        $designatedCode = WmsContractorWarehouseSetting::getDesignatedCode(
            $this->warehouse?->id ?? 0,
            $this->contractor?->id ?? 0,
        );
        $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
        $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, '納入先指定コード: '.($designatedCode ?? ' - '), 0, 1, 'L');
        $lineY += self::LINE_HEIGHT_NORMAL;

        // 納入予定日（入荷日）
        $expectedDate = $this->dataFile->expected_arrival_date?->format('Y年m月d日') ?? '';
        if ($expectedDate) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, '納入予定日: '.$expectedDate, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        // 発注担当（作成者名）
        $creatorName = $this->dataFile->created_by_name ?? '';
        if ($creatorName) {
            $this->pdf->SetXY(self::MARGIN_LEFT, $lineY);
            $this->pdf->Cell(110, self::LINE_HEIGHT_NORMAL, '発注担当: '.$creatorName, 0, 1, 'L');
            $lineY += self::LINE_HEIGHT_NORMAL;
        }

        $this->currentY = max($this->currentY, $lineY + 3);
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
            '容量',        // volume + volume_unit
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

        $outputQuantity = $this->quantityResolver->resolve($candidate);
        $capacityCase = $outputQuantity['display_capacity'];
        $caseQty = $outputQuantity['case_quantity'];
        $pieceQty = $outputQuantity['piece_quantity'];

        $volumeLabel = $this->formatVolume($item);

        $rowData = [
            $outputQuantity['ordering_code'] ?? '',                             // 発注CD（JANコード）- 省略禁止
            $this->truncateText($item?->code ?? '', 22),                     // 自社コード
            $volumeLabel,                                                    // 容量
            $capacityCase > 1 ? $capacityCase : '',                          // 入数（1は表示しない）
            $item?->name ?? '',                                              // 商品名（省略なし - 複数行対応）
            $caseQty !== 0 ? $caseQty : '',                                  // ケース
            $pieceQty !== 0 ? $pieceQty : '',                                // バラ
        ];

        // 入数・ケース・バラは中央揃え、商品名は左揃え
        $aligns = ['C', 'C', 'C', 'C', 'L', 'C', 'C'];
        $widths = array_values(self::COL_WIDTHS);

        // 商品名の高さを計算（複数行対応）- index 4
        $itemName = $rowData[4];
        $itemNameWidth = $widths[4] - 2; // パディング分引く
        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);
        $itemNameLines = $this->pdf->getNumLines($itemName, $itemNameWidth);
        $actualRowHeight = max($rowHeight, $itemNameLines * self::LINE_HEIGHT_NORMAL);

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
        $boxWidth = self::CONTENT_WIDTH;
        $defaultBoxHeight = 25;
        $lineHeight = 5;
        $padding = 2;

        $this->pdf->SetFont('kozminproregular', '', self::FONT_SIZE_SMALL);

        // テキスト行数に応じて高さを調整（4行以上で拡張）
        $contentHeight = $defaultBoxHeight - self::LINE_HEIGHT_NORMAL;
        if ($notes) {
            $numLines = $this->pdf->getNumLines($notes, $boxWidth - ($padding * 2));
            if ($numLines >= 4) {
                $contentHeight = ($numLines * $lineHeight) + ($padding * 2);
            }
        }
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
