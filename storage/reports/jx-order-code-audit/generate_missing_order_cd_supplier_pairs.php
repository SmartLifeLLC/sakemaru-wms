<?php

require __DIR__ . '/../../../vendor/autoload.php';

$baseDir = __DIR__;
$sourcePath = "{$baseDir}/jx_correction_source_20260506.json";
$payload = json_decode(file_get_contents($sourcePath), true, flags: JSON_THROW_ON_ERROR);

$outDir = "{$baseDir}/missing_order_cd_supplier_pairs_20260506";
$supplierDir = "{$outDir}/suppliers";
foreach ([$outDir, $supplierDir] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function pairSjisByteLen(string $value): int
{
    return strlen(mb_convert_encoding($value, 'SJIS-win', 'UTF-8'));
}

function pairToHalfWidthKana(string $value): string
{
    return mb_convert_kana($value, 'askh', 'UTF-8');
}

function pairPadToByteLength(string $value, int $length, string $pad = ' ', int $padType = STR_PAD_RIGHT, bool $kana = true): string
{
    $value = $kana ? pairToHalfWidthKana($value) : $value;
    $result = '';
    $bytes = 0;
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $char) {
        $charBytes = pairSjisByteLen($char);
        if ($bytes + $charBytes > $length) {
            break;
        }
        $result .= $char;
        $bytes += $charBytes;
    }
    $padding = str_repeat($pad, max(0, $length - $bytes));

    return $padType === STR_PAD_LEFT ? $padding . $result : $result . $padding;
}

function pairEnsureRecordLength(string $record): string
{
    $sjis = mb_convert_encoding($record, 'SJIS-win', 'UTF-8');
    $len = strlen($sjis);
    if ($len < 128) {
        $sjis .= str_repeat(' ', 128 - $len);
    } elseif ($len > 128) {
        $sjis = substr($sjis, 0, 128);
    }

    return mb_convert_encoding($sjis, 'UTF-8', 'SJIS-win');
}

function pairPadNumber($value, int $length): string
{
    return str_pad((string) ((int) ($value ?? 0)), $length, '0', STR_PAD_LEFT);
}

function pairPadRightAscii(?string $value, int $length): string
{
    $value = (string) ($value ?? '');

    return substr($value . str_repeat(' ', $length), 0, $length);
}

function pairPadLeftAscii(?string $value, int $length, string $pad = '0'): string
{
    $value = (string) ($value ?? '');

    return substr(str_repeat($pad, $length) . $value, -$length);
}

function pairWriteCsvRow($fp, array $row): void
{
    fputcsv($fp, $row, ',', '"', '\\');
}

function pairIsMissingOrderCd(?string $value): bool
{
    $value = trim((string) $value);

    return $value === '' || preg_match('/^0+$/', $value) === 1;
}

function pairCorrectedOrderCd(array $row): string
{
    foreach (['master_ordering_code', 'fallback_jan_cd', 'fallback_other_piece_cd', 'search_code'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '' && ! pairIsMissingOrderCd($value)) {
            return str_pad($value, 13, '0', STR_PAD_LEFT);
        }
    }

    return '';
}

function pairWrongOrderCd(array $row): string
{
    $value = trim((string) ($row['original_ordering_code'] ?? ''));

    return $value === '' ? '' : str_pad($value, 13, '0', STR_PAD_LEFT);
}

function pairGenerateWrapperHeader(array $header, int $recordCount): string
{
    $now = new DateTimeImmutable('now');
    $fields = [
        pairPadRightAscii('1', 1),
        pairPadLeftAscii('0000001', 7),
        pairPadRightAscii($header['send_document_type'] ?? '91', 2),
        $now->format('ymd'),
        $now->format('His'),
        pairPadRightAscii('00', 2),
        $now->format('ymd'),
        pairPadRightAscii($header['receiver_trading_code'] ?? '', 12),
        pairPadRightAscii($header['sender_station_code'] ?? '', 6),
        pairPadRightAscii('', 2),
        pairPadRightAscii($header['receiver_station_code'] ?? '', 6),
        pairPadRightAscii('', 2),
        pairPadRightAscii('810501', 6),
        pairPadRightAscii('', 2),
        pairPadRightAscii($header['sender_trading_code'] ?? '', 12),
        pairPadRightAscii($header['sender_trading_code'] ?? '', 12),
        pairPadRightAscii($header['sender_name'] ?? '', 15),
        pairPadRightAscii($header['sender_office_name'] ?? '', 10),
        pairPadLeftAscii((string) $recordCount, 6),
        pairPadLeftAscii('128', 3),
        pairPadRightAscii(' ', 1),
        pairPadRightAscii('', 1),
        pairPadRightAscii('', 2),
    ];

    return pairPadRightAscii(implode('', $fields), 128);
}

function pairWrapJxData(string $inner, array $header): string
{
    $innerRecordCount = strlen(mb_convert_encoding($inner, 'SJIS-win', 'UTF-8')) / 128;
    $totalRecords = (int) $innerRecordCount + 2;

    return pairGenerateWrapperHeader($header, $totalRecords) . $inner . '8' . str_repeat(' ', 127);
}

function pairGenerateARecord(array $header, int $totalRecordCount, int $slipCount): string
{
    $now = new DateTimeImmutable('now');
    $record = '';
    $record .= 'A';
    $record .= '01';
    $record .= $now->format('Ymd');
    $record .= $now->format('His');
    $record .= str_pad((string) ($header['sender_station_code'] ?? '01451019'), 8, '0', STR_PAD_LEFT);
    $record .= str_pad((string) ($header['receiver_station_code'] ?? $header['document_contractor_code'] ?? ''), 8, '0', STR_PAD_LEFT);
    $record .= pairPadNumber($totalRecordCount, 6);
    $record .= pairPadNumber($slipCount, 6);
    $record .= pairPadToByteLength('ﾘｶｰﾜｰﾙﾄﾞ ﾊﾅ', 15);
    $record .= str_repeat(' ', 68);

    return pairEnsureRecordLength($record);
}

function pairGenerateBRecord(array $row, int $seq): string
{
    $orderDate = new DateTimeImmutable('now');
    $deliveryDate = ! empty($row['expected_arrival_date'])
        ? new DateTimeImmutable($row['expected_arrival_date'])
        : $orderDate->modify('+2 days');
    $slipNumber = trim((string) ($row['slip_number'] ?? ''));
    $slipNumber = $slipNumber !== '' ? substr($slipNumber, 0, 11) : $orderDate->format('Ymd') . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

    $record = '';
    $record .= 'B';
    $record .= '01';
    $record .= $slipNumber;
    $record .= str_pad((string) ($row['warehouse_code'] ?? ''), 4, '0', STR_PAD_LEFT);
    $record .= '999';
    $record .= '01';
    $record .= $orderDate->format('ymd');
    $record .= $deliveryDate->format('ymd');
    $record .= str_repeat(' ', 3);
    $record .= str_pad(substr((string) ($row['candidate_contractor_code'] ?? ''), 0, 4), 4);
    $record .= pairPadToByteLength($row['warehouse_kana_name'] ?? $row['warehouse_name'] ?? '', 15, ' ', STR_PAD_LEFT);
    $record .= pairPadToByteLength($row['warehouse_kana_name'] ?? $row['warehouse_name'] ?? '', 10, ' ', STR_PAD_LEFT);
    $record .= str_repeat(' ', 25);
    $record .= '00';
    $record .= str_repeat(' ', 34);

    return pairEnsureRecordLength($record);
}

function pairGenerateDRecord(array $row, int $seq, bool $corrected): string
{
    $capacityCase = (int) ($row['capacity_case'] ?? 1);
    $orderQty = (int) ($row['order_quantity'] ?? 0);
    $quantityType = (string) ($row['quantity_type'] ?? '');
    $caseQty = $quantityType === 'CASE' ? $orderQty : 0;
    $pieceQty = $quantityType === 'PIECE' ? $orderQty : 0;
    $price = $quantityType === 'PIECE'
        ? (float) ($row['cost_unit_price'] ?? 0)
        : (float) ($row['cost_case_price'] ?? 0);
    $priceFormatted = (int) round($price * 100);
    $orderCd = $corrected ? pairCorrectedOrderCd($row) : pairWrongOrderCd($row);

    $record = '';
    $record .= 'D';
    $record .= '01';
    $record .= pairPadNumber($seq, 2);
    $record .= pairPadToByteLength($row['item_name_main'] ?? $row['item_name'] ?? '', 62, ' ', STR_PAD_RIGHT, false) . '  ';
    $record .= str_pad($orderCd, 13);
    $record .= str_pad(substr((string) ($row['item_code'] ?? ''), 0, 6), 6);
    $record .= pairPadNumber($capacityCase, 6);
    $record .= pairPadNumber($caseQty, 7);
    $record .= pairPadNumber($pieceQty, 7);
    $record .= pairPadNumber($priceFormatted, 10);
    $record .= str_repeat(' ', 10);

    return pairEnsureRecordLength($record);
}

function pairGenerateDat(array $doc, bool $corrected): string
{
    $rows = $doc['rows'];
    $groups = [];
    foreach ($rows as $row) {
        $groups[$row['candidate_contractor_id'] . '_' . $row['warehouse_id']][] = $row;
    }

    $chunks = [];
    foreach ($groups as $groupRows) {
        foreach (array_chunk($groupRows, 6) as $chunk) {
            $chunks[] = $chunk;
        }
    }

    $bCount = count($chunks);
    $dCount = count($rows);
    $innerRecords = [];
    $innerRecords[] = pairGenerateARecord($doc['header'], 1 + $bCount + $dCount, $bCount);
    $bSeq = 1;
    foreach ($chunks as $chunk) {
        $innerRecords[] = pairGenerateBRecord($chunk[0], $bSeq);
        $dSeq = 1;
        foreach ($chunk as $row) {
            $innerRecords[] = pairGenerateDRecord($row, $dSeq, $corrected);
            $dSeq++;
        }
        $bSeq++;
    }

    $utf8 = pairWrapJxData(implode('', $innerRecords), $doc['header']);

    return mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
}

function pairInitPdf(string $title): TCPDF
{
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Smart WMS');
    $pdf->SetAuthor('Smart WMS');
    $pdf->SetTitle($title);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetFont('kozminproregular', '', 9);

    return $pdf;
}

function pairPdfCell(TCPDF $pdf, float $w, float $h, string $txt, string $align = 'L'): void
{
    $pdf->Cell($w, $h, $txt, 1, 0, $align);
}

function pairWriteSlipPage(TCPDF $pdf, array $doc, string $title, bool $corrected): void
{
    $h = $doc['header'];
    $first = $doc['rows'][0] ?? [];
    $pdf->AddPage();
    $pdf->SetFont('kozminproregular', '', 16);
    $pdf->Cell(0, 9, $title, 0, 1, 'C');
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->Cell(0, 5, '元メッセージID: ' . ($h['jx_message_id'] ?? ''), 0, 1, 'R');
    $pdf->Cell(0, 5, '元発注番号: ' . ($h['batch_code'] ?? '') . ' / 元送信日時: ' . ($h['transmitted_at'] ?? ''), 0, 1, 'R');
    $pdf->Ln(2);
    $pdf->SetFont('kozminproregular', '', 12);
    $pdf->Cell(110, 6, ($first['candidate_contractor_code'] ?? '') . ' ' . ($first['candidate_contractor_name'] ?? '') . ' 御中', 0, 0, 'L');
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->Cell(80, 6, '株式会社華 / Smart WMS', 0, 1, 'R');
    $pdf->SetFont('kozminproregular', '', 9);
    $message = $corrected
        ? '発注CDが空欄またはオールゼロだった明細のみを、正しい発注CDで再作成した修正伝票です。'
        : '先に送信された伝票のうち、発注CDが空欄またはオールゼロだった明細のみを抜粋した誤送信内容です。';
    $pdf->MultiCell(0, 5, $message, 1, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('kozminproregular', '', 8);
    foreach (['発注CD' => 30, '商品CD' => 18, '商品名' => 88, '入数' => 10, 'ケース' => 12, 'バラ' => 12] as $label => $width) {
        pairPdfCell($pdf, $width, 6, $label, 'C');
    }
    $pdf->Ln();
    foreach ($doc['rows'] as $row) {
        if ($pdf->GetY() > 270) {
            $pdf->AddPage();
        }
        $caseQty = ($row['quantity_type'] ?? '') === 'CASE' ? (string) ($row['order_quantity'] ?? '') : '';
        $pieceQty = ($row['quantity_type'] ?? '') === 'PIECE' ? (string) ($row['order_quantity'] ?? '') : '';
        $orderCd = $corrected ? pairCorrectedOrderCd($row) : (pairWrongOrderCd($row) ?: '空欄');
        $values = [
            [$orderCd, 30, 'C'],
            [(string) ($row['item_code'] ?? ''), 18, 'C'],
            [(string) ($row['item_name'] ?? ''), 88, 'L'],
            [(int) ($row['capacity_case'] ?? 1) > 1 ? (string) $row['capacity_case'] : '', 10, 'C'],
            [$caseQty, 12, 'C'],
            [$pieceQty, 12, 'C'],
        ];
        foreach ($values as [$txt, $w, $align]) {
            pairPdfCell($pdf, $w, 6, mb_strimwidth($txt, 0, 48, '', 'UTF-8'), $align);
        }
        $pdf->Ln();
    }
}

function pairWriteComparisonPdf(array $docs, string $path, array $supplier): void
{
    $pdf = pairInitPdf('発注CD空欄訂正 伝票対比');
    $pdf->AddPage();
    $pdf->SetFont('kozminproregular', '', 15);
    $pdf->Cell(0, 9, '発注CD空欄訂正 伝票対比', 0, 1, 'C');
    $pdf->SetFont('kozminproregular', '', 10);
    $pdf->Cell(0, 6, ($supplier['code'] ?? '') . ' ' . ($supplier['name'] ?? '') . ' 御中', 0, 1);
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->MultiCell(0, 5, '発注CDが空欄またはオールゼロだった明細のみを対象に、誤った伝票と修正伝票を対で作成しました。空欄以外の発注CD差異はこの帳票には含めていません。', 0, 'L');
    $pdf->Ln(2);
    $totalRows = array_sum(array_map(fn ($doc) => count($doc['rows']), $docs));
    $pdf->Cell(0, 5, '対象伝票数: ' . count($docs) . ' / 対象明細数: ' . $totalRows, 0, 1);
    foreach ($docs as $doc) {
        $h = $doc['header'];
        $pdf->Cell(0, 5, '元発注番号: ' . ($h['batch_code'] ?? '') . ' / 元メッセージID: ' . ($h['jx_message_id'] ?? ''), 0, 1);
    }

    foreach ($docs as $doc) {
        pairWriteSlipPage($pdf, $doc, '誤った伝票（発注CD空欄のみ）', false);
        pairWriteSlipPage($pdf, $doc, '修正伝票（発注CD補正済み）', true);
    }
    $pdf->Output($path, 'F');
}

function pairWriteSlipPdf(array $doc, string $path, string $title, bool $corrected): void
{
    $pdf = pairInitPdf($title);
    pairWriteSlipPage($pdf, $doc, $title, $corrected);
    $pdf->Output($path, 'F');
}

$suppliers = [];
foreach ($payload['documents'] as $documentId => $doc) {
    $missingRows = array_values(array_filter(
        $doc['rows'],
        fn ($row) => pairIsMissingOrderCd($row['original_ordering_code'] ?? null) && pairCorrectedOrderCd($row) !== ''
    ));

    foreach ($missingRows as $row) {
        $supplierCode = (string) ($row['candidate_contractor_code'] ?? 'unknown');
        $supplierName = (string) ($row['candidate_contractor_name'] ?? '');
        $key = $supplierCode;
        $suppliers[$key]['code'] = $supplierCode;
        $suppliers[$key]['name'] = $supplierName;
        $suppliers[$key]['rows'][] = [$documentId, $doc, $row];
    }
}

ksort($suppliers);

$summaryPath = "{$outDir}/supplier_missing_order_cd_pair_summary.csv";
$summaryFp = fopen($summaryPath, 'wb');
fwrite($summaryFp, "\xEF\xBB\xBF");
pairWriteCsvRow($summaryFp, ['仕入先CD', '仕入先名', '対象伝票数', '対象明細数', '対比PDF', '対比CSV', 'ZIP内フォルダ']);

$allRowsPath = "{$outDir}/all_missing_order_cd_pairs.csv";
$allFp = fopen($allRowsPath, 'wb');
fwrite($allFp, "\xEF\xBB\xBF");
pairWriteCsvRow($allFp, ['仕入先CD', '仕入先名', 'document_id', 'batch_code', '元メッセージID', '倉庫CD', '倉庫名', '商品CD', '商品名', '誤発注CD', '正発注CD', '数量', '元ファイル']);

foreach ($suppliers as $supplier) {
    $safeCode = preg_replace('/[^0-9A-Za-z_-]/', '_', $supplier['code']);
    $dir = "{$supplierDir}/supplier_{$safeCode}";
    foreach ([$dir, "{$dir}/wrong_dat", "{$dir}/corrected_dat", "{$dir}/wrong_pdf", "{$dir}/corrected_pdf"] as $subDir) {
        if (! is_dir($subDir)) {
            mkdir($subDir, 0775, true);
        }
    }

    $docs = [];
    foreach ($supplier['rows'] as [$documentId, $originalDoc, $row]) {
        if (! isset($docs[$documentId])) {
            $docs[$documentId] = [
                'header' => $originalDoc['header'],
                'rows' => [],
            ];
        }
        $docs[$documentId]['rows'][] = $row;
    }

    $comparisonCsv = "{$dir}/supplier_{$safeCode}_wrong_to_corrected.csv";
    $fp = fopen($comparisonCsv, 'wb');
    fwrite($fp, "\xEF\xBB\xBF");
    pairWriteCsvRow($fp, ['document_id', 'batch_code', '元メッセージID', '倉庫CD', '倉庫名', '商品CD', '商品名', '誤発注CD', '正発注CD', '数量', '元ファイル', '誤伝票PDF', '修正伝票PDF']);

    foreach ($docs as $documentId => $doc) {
        $h = $doc['header'];
        $baseName = sprintf('doc_%03d_%s_%s', (int) $documentId, $safeCode, $h['batch_code'] ?? 'batch');
        $wrongDat = "{$dir}/wrong_dat/wrong_{$baseName}.dat";
        $correctedDat = "{$dir}/corrected_dat/corrected_{$baseName}.dat";
        $wrongPdf = "{$dir}/wrong_pdf/wrong_{$baseName}.pdf";
        $correctedPdf = "{$dir}/corrected_pdf/corrected_{$baseName}.pdf";
        file_put_contents($wrongDat, pairGenerateDat($doc, false));
        file_put_contents($correctedDat, pairGenerateDat($doc, true));
        pairWriteSlipPdf($doc, $wrongPdf, '誤った伝票（発注CD空欄のみ）', false);
        pairWriteSlipPdf($doc, $correctedPdf, '修正伝票（発注CD補正済み）', true);

        foreach ($doc['rows'] as $row) {
            $line = [
                $supplier['code'],
                $supplier['name'],
                $documentId,
                $h['batch_code'] ?? '',
                $h['jx_message_id'] ?? '',
                $row['warehouse_code'] ?? '',
                $row['warehouse_name'] ?? '',
                $row['item_code'] ?? '',
                $row['item_name'] ?? '',
                pairWrongOrderCd($row) ?: '空欄',
                pairCorrectedOrderCd($row),
                $row['order_quantity'] ?? '',
                $h['file_path'] ?? '',
                $wrongPdf,
                $correctedPdf,
            ];
            pairWriteCsvRow($fp, array_slice($line, 2));
            pairWriteCsvRow($allFp, array_slice($line, 0, 13));
        }
    }
    fclose($fp);

    $comparisonPdf = "{$dir}/supplier_{$safeCode}_wrong_to_corrected.pdf";
    pairWriteComparisonPdf(array_values($docs), $comparisonPdf, $supplier);

    pairWriteCsvRow($summaryFp, [
        $supplier['code'],
        $supplier['name'],
        count($docs),
        count($supplier['rows']),
        $comparisonPdf,
        $comparisonCsv,
        $dir,
    ]);
}
fclose($summaryFp);
fclose($allFp);

$zipPath = "{$baseDir}/missing_order_cd_supplier_pairs_20260506.zip";
if (class_exists(ZipArchive::class)) {
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($outDir) + 1));
    }
    $zip->close();
}

echo json_encode([
    'out_dir' => $outDir,
    'supplier_count' => count($suppliers),
    'document_count' => count(array_unique(array_merge(...array_map(
        fn ($supplier) => array_map(fn ($row) => $row[0], $supplier['rows']),
        $suppliers
    )))),
    'missing_order_cd_rows' => array_sum(array_map(fn ($supplier) => count($supplier['rows']), $suppliers)),
    'summary' => $summaryPath,
    'all_pairs_csv' => $allRowsPath,
    'zip' => file_exists($zipPath) ? $zipPath : null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
