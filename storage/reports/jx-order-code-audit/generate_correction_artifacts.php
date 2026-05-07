<?php

require __DIR__ . '/../../../vendor/autoload.php';

$baseDir = __DIR__;
$sourcePath = "{$baseDir}/jx_correction_source_20260506.json";
$payload = json_decode(file_get_contents($sourcePath), true, flags: JSON_THROW_ON_ERROR);

$outDir = "{$baseDir}/corrected_20260506";
$datDir = "{$outDir}/dat";
$csvDir = "{$outDir}/csv";
$faxDir = "{$outDir}/fax";
$ticketDir = "{$outDir}/tickets";
foreach ([$outDir, $datDir, $csvDir, $faxDir, $ticketDir] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function sjisByteLen(string $value): int
{
    return strlen(mb_convert_encoding($value, 'SJIS-win', 'UTF-8'));
}

function toHalfWidthKana(string $value): string
{
    return mb_convert_kana($value, 'askh', 'UTF-8');
}

function padToByteLength(string $value, int $length, string $pad = ' ', int $padType = STR_PAD_RIGHT, bool $kana = true): string
{
    $value = $kana ? toHalfWidthKana($value) : $value;
    $result = '';
    $bytes = 0;
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $char) {
        $charBytes = sjisByteLen($char);
        if ($bytes + $charBytes > $length) {
            break;
        }
        $result .= $char;
        $bytes += $charBytes;
    }
    $padding = str_repeat($pad, max(0, $length - $bytes));

    return $padType === STR_PAD_LEFT ? $padding . $result : $result . $padding;
}

function ensureRecordLength(string $record): string
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

function padNumber($value, int $length): string
{
    return str_pad((string) ((int) ($value ?? 0)), $length, '0', STR_PAD_LEFT);
}

function padRightAscii(?string $value, int $length): string
{
    $value = (string) ($value ?? '');

    return substr($value . str_repeat(' ', $length), 0, $length);
}

function padLeftAscii(?string $value, int $length, string $pad = '0'): string
{
    $value = (string) ($value ?? '');

    return substr(str_repeat($pad, $length) . $value, -$length);
}

function writeCsvRow($fp, array $row): void
{
    fputcsv($fp, $row, ',', '"', '\\');
}

function correctedOrderCd(array $row): string
{
    foreach (['master_ordering_code', 'fallback_jan_cd', 'fallback_other_piece_cd', 'search_code'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return str_pad($value, 13, '0', STR_PAD_LEFT);
        }
    }

    return '';
}

function generateWrapperHeader(array $header, int $recordCount): string
{
    $now = new DateTimeImmutable('now');
    $fields = [
        padRightAscii('1', 1),
        padLeftAscii('0000001', 7),
        padRightAscii($header['send_document_type'] ?? '91', 2),
        $now->format('ymd'),
        $now->format('His'),
        padRightAscii('00', 2),
        $now->format('ymd'),
        padRightAscii($header['receiver_trading_code'] ?? '', 12),
        padRightAscii($header['sender_station_code'] ?? '', 6),
        padRightAscii('', 2),
        padRightAscii($header['receiver_station_code'] ?? '', 6),
        padRightAscii('', 2),
        padRightAscii('810501', 6),
        padRightAscii('', 2),
        padRightAscii($header['sender_trading_code'] ?? '', 12),
        padRightAscii($header['sender_trading_code'] ?? '', 12),
        padRightAscii($header['sender_name'] ?? '', 15),
        padRightAscii($header['sender_office_name'] ?? '', 10),
        padLeftAscii((string) $recordCount, 6),
        padLeftAscii('128', 3),
        padRightAscii(' ', 1),
        padRightAscii('', 1),
        padRightAscii('', 2),
    ];

    return padRightAscii(implode('', $fields), 128);
}

function wrapJxData(string $inner, array $header): string
{
    $innerRecordCount = strlen(mb_convert_encoding($inner, 'SJIS-win', 'UTF-8')) / 128;
    $totalRecords = (int) $innerRecordCount + 2;

    return generateWrapperHeader($header, $totalRecords) . $inner . '8' . str_repeat(' ', 127);
}

function generateARecord(array $header, int $totalRecordCount, int $slipCount): string
{
    $now = new DateTimeImmutable('now');
    $record = '';
    $record .= 'A';
    $record .= '01';
    $record .= $now->format('Ymd');
    $record .= $now->format('His');
    $record .= str_pad((string) ($header['sender_station_code'] ?? '01451019'), 8, '0', STR_PAD_LEFT);
    $record .= str_pad((string) ($header['receiver_station_code'] ?? $header['document_contractor_code'] ?? ''), 8, '0', STR_PAD_LEFT);
    $record .= padNumber($totalRecordCount, 6);
    $record .= padNumber($slipCount, 6);
    $record .= padToByteLength('ﾘｶｰﾜｰﾙﾄﾞ ﾊﾅ', 15);
    $record .= str_repeat(' ', 68);

    return ensureRecordLength($record);
}

function generateBRecord(array $row, int $seq): string
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
    $record .= padToByteLength($row['warehouse_kana_name'] ?? $row['warehouse_name'] ?? '', 15, ' ', STR_PAD_LEFT);
    $record .= padToByteLength($row['warehouse_kana_name'] ?? $row['warehouse_name'] ?? '', 10, ' ', STR_PAD_LEFT);
    $record .= str_repeat(' ', 25);
    $record .= '00';
    $record .= str_repeat(' ', 34);

    return ensureRecordLength($record);
}

function generateDRecord(array $row, int $seq): string
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

    $record = '';
    $record .= 'D';
    $record .= '01';
    $record .= padNumber($seq, 2);
    $record .= padToByteLength($row['item_name_main'] ?? $row['item_name'] ?? '', 62, ' ', STR_PAD_RIGHT, false) . '  ';
    $record .= str_pad(correctedOrderCd($row), 13);
    $record .= str_pad(substr((string) ($row['item_code'] ?? ''), 0, 6), 6);
    $record .= padNumber($capacityCase, 6);
    $record .= padNumber($caseQty, 7);
    $record .= padNumber($pieceQty, 7);
    $record .= padNumber($priceFormatted, 10);
    $record .= str_repeat(' ', 10);

    return ensureRecordLength($record);
}

function generateDat(array $doc): string
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
    $innerRecords[] = generateARecord($doc['header'], 1 + $bCount + $dCount, $bCount);
    $bSeq = 1;
    foreach ($chunks as $chunk) {
        $innerRecords[] = generateBRecord($chunk[0], $bSeq);
        $dSeq = 1;
        foreach ($chunk as $row) {
            $innerRecords[] = generateDRecord($row, $dSeq);
            $dSeq++;
        }
        $bSeq++;
    }

    $utf8 = wrapJxData(implode('', $innerRecords), $doc['header']);

    return mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
}

function writeCsv(array $doc, string $path): void
{
    $fp = fopen($path, 'wb');
    fwrite($fp, "\xEF\xBB\xBF");
    writeCsvRow($fp, ['発注先コード', '発注先名', '倉庫コード', '倉庫名', '商品コード', '商品名', '元発注CD', '正発注CD', '発注数量', '入荷予定日', '発注日', '候補ID', '元メッセージID']);
    foreach ($doc['rows'] as $row) {
        writeCsvRow($fp, [
            $row['candidate_contractor_code'] ?? '',
            $row['candidate_contractor_name'] ?? '',
            $row['warehouse_code'] ?? '',
            $row['warehouse_name'] ?? '',
            $row['item_code'] ?? '',
            $row['item_name'] ?? '',
            $row['original_ordering_code'] ?? '',
            correctedOrderCd($row),
            $row['order_quantity'] ?? '',
            $row['expected_arrival_date'] ?? '',
            $doc['header']['order_date'] ?? '',
            $row['candidate_id'] ?? '',
            $doc['header']['jx_message_id'] ?? '',
        ]);
    }
    fclose($fp);
}

function initPdf(string $title): TCPDF
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

function pdfCell(TCPDF $pdf, float $w, float $h, string $txt, string $align = 'L'): void
{
    $pdf->Cell($w, $h, $txt, 1, 0, $align);
}

function writeFaxPdf(array $doc, string $path): void
{
    $h = $doc['header'];
    $pdf = initPdf('訂正版発注書');
    $pdf->AddPage();
    $pdf->SetFont('kozminproregular', '', 16);
    $pdf->Cell(0, 9, '発注書（訂正版）', 0, 1, 'C');
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->Cell(0, 5, '元メッセージID: ' . ($h['jx_message_id'] ?? ''), 0, 1, 'R');
    $pdf->Cell(0, 5, '発注番号: ' . ($h['batch_code'] ?? '') . ' / 発注日: ' . substr((string) ($h['order_date'] ?? ''), 0, 10), 0, 1, 'R');
    $pdf->Ln(2);
    $pdf->SetFont('kozminproregular', '', 12);
    $pdf->Cell(95, 6, ($h['document_contractor_name'] ?? '') . ' 御中', 0, 0, 'L');
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->Cell(95, 6, '株式会社華 / Smart WMS', 0, 1, 'R');
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->MultiCell(0, 5, "通信欄: 先に送付した発注データの発注CDに誤りがありました。下記の訂正版をご確認ください。", 1, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('kozminproregular', '', 8);
    foreach (['発注CD' => 28, '自社CD' => 18, '入数' => 10, '商品名' => 96, 'ケース' => 12, 'バラ' => 12] as $label => $width) {
        pdfCell($pdf, $width, 6, $label, 'C');
    }
    $pdf->Ln();
    foreach ($doc['rows'] as $row) {
        $caseQty = ($row['quantity_type'] ?? '') === 'CASE' ? (string) ($row['order_quantity'] ?? '') : '';
        $pieceQty = ($row['quantity_type'] ?? '') === 'PIECE' ? (string) ($row['order_quantity'] ?? '') : '';
        $values = [
            [correctedOrderCd($row), 28, 'C'],
            [(string) ($row['item_code'] ?? ''), 18, 'C'],
            [(int) ($row['capacity_case'] ?? 1) > 1 ? (string) $row['capacity_case'] : '', 10, 'C'],
            [(string) ($row['item_name'] ?? ''), 96, 'L'],
            [$caseQty, 12, 'C'],
            [$pieceQty, 12, 'C'],
        ];
        $startY = $pdf->GetY();
        if ($startY > 270) {
            $pdf->AddPage();
            $startY = $pdf->GetY();
        }
        foreach ($values as [$txt, $w, $align]) {
            pdfCell($pdf, $w, 6, mb_strimwidth($txt, 0, 52, '', 'UTF-8'), $align);
        }
        $pdf->Ln();
    }
    $pdf->Output($path, 'F');
}

function writeTicketPdf(array $doc, string $path): void
{
    $h = $doc['header'];
    $badRows = array_values(array_filter($doc['rows'], fn ($row) => trim((string) ($row['original_ordering_code'] ?? '')) !== correctedOrderCd($row)));
    $pdf = initPdf('訂正・お詫び票');
    $pdf->AddPage();
    $pdf->SetFont('kozminproregular', '', 15);
    $pdf->Cell(0, 9, '発注CD訂正・お詫び票', 0, 1, 'C');
    $pdf->SetFont('kozminproregular', '', 9);
    $pdf->MultiCell(0, 5, "このたび送付済み発注データの一部明細で発注CDが空欄または誤った値となっていました。ご迷惑をおかけし申し訳ございません。下記の通り訂正いたします。", 0, 'L');
    $pdf->Ln(2);
    $pdf->Cell(0, 5, '発注先: ' . ($h['document_contractor_code'] ?? '') . ' ' . ($h['document_contractor_name'] ?? ''), 0, 1);
    $pdf->Cell(0, 5, '元メッセージID: ' . ($h['jx_message_id'] ?? ''), 0, 1);
    $pdf->Cell(0, 5, '元ファイル: ' . ($h['file_path'] ?? ''), 0, 1);
    $pdf->Ln(3);
    $pdf->SetFont('kozminproregular', '', 8);
    foreach (['商品CD' => 18, '商品名' => 82, '誤' => 35, '正' => 35, '数量' => 14] as $label => $width) {
        pdfCell($pdf, $width, 6, $label, 'C');
    }
    $pdf->Ln();
    foreach ($badRows as $row) {
        $values = [
            [(string) ($row['item_code'] ?? ''), 18, 'C'],
            [(string) ($row['item_name'] ?? ''), 82, 'L'],
            [trim((string) ($row['original_ordering_code'] ?? '')) ?: '空欄', 35, 'C'],
            [correctedOrderCd($row), 35, 'C'],
            [(string) ($row['order_quantity'] ?? ''), 14, 'C'],
        ];
        foreach ($values as [$txt, $w, $align]) {
            pdfCell($pdf, $w, 6, mb_strimwidth($txt, 0, 44, '', 'UTF-8'), $align);
        }
        $pdf->Ln();
    }
    $pdf->Output($path, 'F');
}

$summaryRows = [];
$allBadRows = [];
foreach ($payload['documents'] as $documentId => $doc) {
    $h = $doc['header'];
    $contractorCode = $h['document_contractor_code'] ?? $h['document_contractor_id'] ?? 'unknown';
    $baseName = sprintf('corrected_doc_%03d_%s_%s', (int) $documentId, $contractorCode, $h['batch_code'] ?? 'batch');

    $datPath = "{$datDir}/{$baseName}.dat";
    file_put_contents($datPath, generateDat($doc));
    writeCsv($doc, "{$csvDir}/{$baseName}.csv");
    writeFaxPdf($doc, "{$faxDir}/{$baseName}_fax.pdf");
    writeTicketPdf($doc, "{$ticketDir}/{$baseName}_ticket.pdf");

    $badRows = 0;
    foreach ($doc['rows'] as $row) {
        if (trim((string) ($row['original_ordering_code'] ?? '')) !== correctedOrderCd($row)) {
            $badRows++;
            $allBadRows[] = [$doc, $row];
        }
    }
    $summaryRows[] = [
        $documentId,
        $h['batch_code'] ?? '',
        $contractorCode,
        $h['document_contractor_name'] ?? '',
        $h['jx_message_id'] ?? '',
        count($doc['rows']),
        $badRows,
        $datPath,
        "{$csvDir}/{$baseName}.csv",
        "{$faxDir}/{$baseName}_fax.pdf",
        "{$ticketDir}/{$baseName}_ticket.pdf",
    ];
}

$summaryPath = "{$outDir}/corrected_artifacts_summary.csv";
$fp = fopen($summaryPath, 'wb');
fwrite($fp, "\xEF\xBB\xBF");
writeCsvRow($fp, ['document_id', 'batch_code', 'contractor_code', 'contractor_name', 'message_id', 'detail_rows', 'corrected_rows', 'dat_path', 'csv_path', 'fax_pdf_path', 'ticket_pdf_path']);
foreach ($summaryRows as $row) {
    writeCsvRow($fp, $row);
}
fclose($fp);

$consolidatedTicketCsv = "{$outDir}/consolidated_correction_ticket.csv";
$fp = fopen($consolidatedTicketCsv, 'wb');
fwrite($fp, "\xEF\xBB\xBF");
writeCsvRow($fp, ['発注先CD', '発注先名', '元メッセージID', '候補ID', '商品CD', '商品名', '誤発注CD', '正発注CD', '数量', '元ファイル']);
foreach ($allBadRows as [$doc, $row]) {
    $h = $doc['header'];
    writeCsvRow($fp, [
        $h['document_contractor_code'] ?? '',
        $h['document_contractor_name'] ?? '',
        $h['jx_message_id'] ?? '',
        $row['candidate_id'] ?? '',
        $row['item_code'] ?? '',
        $row['item_name'] ?? '',
        trim((string) ($row['original_ordering_code'] ?? '')) ?: '空欄',
        correctedOrderCd($row),
        $row['order_quantity'] ?? '',
        $h['file_path'] ?? '',
    ]);
}
fclose($fp);

$consolidatedTicketPdf = "{$outDir}/consolidated_correction_ticket.pdf";
$pdf = initPdf('発注CD訂正・お詫び票');
$pdf->AddPage();
$pdf->SetFont('kozminproregular', '', 15);
$pdf->Cell(0, 9, '発注CD訂正・お詫び票', 0, 1, 'C');
$pdf->SetFont('kozminproregular', '', 9);
$pdf->MultiCell(0, 5, '送付済みJX発注データの一部明細で発注CDが空欄または誤った値となっていました。ご迷惑をおかけし申し訳ございません。下記の通り訂正いたします。', 0, 'L');
$pdf->Ln(2);
$pdf->Cell(0, 5, '対象ファイル数: ' . count($payload['documents']) . ' / 訂正明細数: ' . count($allBadRows), 0, 1);
$pdf->Ln(2);
$pdf->SetFont('kozminproregular', '', 7);
foreach (['発注先' => 28, '商品CD' => 17, '商品名' => 62, '誤' => 30, '正' => 30, '数' => 8] as $label => $width) {
    pdfCell($pdf, $width, 5, $label, 'C');
}
$pdf->Ln();
foreach ($allBadRows as [$doc, $row]) {
    $h = $doc['header'];
    if ($pdf->GetY() > 275) {
        $pdf->AddPage();
    }
    $values = [
        [(string) ($h['document_contractor_code'] ?? ''), 28, 'C'],
        [(string) ($row['item_code'] ?? ''), 17, 'C'],
        [(string) ($row['item_name'] ?? ''), 62, 'L'],
        [trim((string) ($row['original_ordering_code'] ?? '')) ?: '空欄', 30, 'C'],
        [correctedOrderCd($row), 30, 'C'],
        [(string) ($row['order_quantity'] ?? ''), 8, 'C'],
    ];
    foreach ($values as [$txt, $w, $align]) {
        pdfCell($pdf, $w, 5, mb_strimwidth($txt, 0, 34, '', 'UTF-8'), $align);
    }
    $pdf->Ln();
}
$pdf->Output($consolidatedTicketPdf, 'F');

$zipPath = "{$baseDir}/jx_corrected_order_package_20260506.zip";
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
    'documents' => count($payload['documents']),
    'summary' => $summaryPath,
    'consolidated_ticket_pdf' => $consolidatedTicketPdf,
    'consolidated_ticket_csv' => $consolidatedTicketCsv,
    'zip' => file_exists($zipPath) ? $zipPath : null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
