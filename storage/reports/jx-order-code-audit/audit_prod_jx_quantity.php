<?php

require '/var/www/hana/sakemaru-wms/vendor/autoload.php';

$app = require '/var/www/hana/sakemaru-wms/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$date = $argv[1] ?? '2026-05-06';
$docs = DB::connection('sakemaru')->table('wms_order_jx_documents')
    ->select('id', 'contractor_id', 'warehouse_id', 'batch_code', 'file_path', 'file_size', 'record_count', 'order_count', 'status', 'created_at', 'transmitted_at')
    ->where('status', 'TRANSMITTED')
    ->whereNotNull('file_path')
    ->whereBetween('created_at', [$date.' 00:00:00', $date.' 23:59:59'])
    ->orderBy('id')
    ->get();

$out = fopen('php://output', 'w');
fputcsv($out, [
    'doc_id', 'contractor_id', 'warehouse_id', 'batch_code', 'file_path', 'created_at', 'transmitted_at',
    'db_file_size', 'actual_size', 'size_match', 'db_record_count', 'actual_records', 'record_count_match',
    'db_order_count', 'actual_d_records', 'order_count_match', 'db_candidate_count', 'db_case_rows', 'db_piece_rows',
    'db_case_qty_sum', 'file_case_qty_sum', 'case_qty_sum_match', 'db_piece_qty_sum', 'file_piece_qty_sum', 'piece_qty_sum_match',
    'invalid_record_length', 'read_error', 'issues',
]);

foreach ($docs as $doc) {
    $agg = DB::connection('sakemaru')->table('wms_order_candidates')
        ->selectRaw('COUNT(*) AS candidate_count')
        ->selectRaw("SUM(CASE WHEN quantity_type = 'CASE' THEN 1 ELSE 0 END) AS case_rows")
        ->selectRaw("SUM(CASE WHEN quantity_type = 'PIECE' THEN 1 ELSE 0 END) AS piece_rows")
        ->selectRaw("COALESCE(SUM(CASE WHEN quantity_type = 'CASE' THEN order_quantity ELSE 0 END), 0) AS case_qty_sum")
        ->selectRaw("COALESCE(SUM(CASE WHEN quantity_type = 'PIECE' THEN order_quantity ELSE 0 END), 0) AS piece_qty_sum")
        ->where('wms_order_jx_document_id', $doc->id)
        ->first();

    $readError = '';
    $actualSize = 0;
    $actualRecords = 0;
    $actualDRecords = 0;
    $fileCaseQtySum = 0;
    $filePieceQtySum = 0;
    $invalidRecordLength = 0;
    $issues = [];

    try {
        $content = Storage::disk('s3')->get($doc->file_path);
        $actualSize = strlen($content);

        if ($actualSize % 128 !== 0) {
            $invalidRecordLength = 1;
            $issues[] = 'size_not_multiple_of_128';
        }

        $actualRecords = intdiv($actualSize, 128);
        for ($offset = 0; $offset + 128 <= $actualSize; $offset += 128) {
            $record = substr($content, $offset, 128);

            if (($record[0] ?? '') !== 'D') {
                continue;
            }

            $actualDRecords++;
            $caseQty = (int) substr($record, 94, 7);
            $pieceQty = (int) substr($record, 101, 7);
            $fileCaseQtySum += $caseQty;
            $filePieceQtySum += $pieceQty;

            if ($caseQty > 0 && $pieceQty > 0) {
                $issues[] = 'both_case_and_piece_qty_on_d_record';
            }
        }
    } catch (Throwable $e) {
        $readError = get_class($e).': '.$e->getMessage();
        $issues[] = 's3_read_error';
    }

    $sizeMatch = ((int) $doc->file_size === $actualSize) ? 1 : 0;
    $recordCountMatch = ((int) $doc->record_count === $actualRecords) ? 1 : 0;
    $orderCountMatch = ((int) $doc->order_count === $actualDRecords && (int) $agg->candidate_count === $actualDRecords) ? 1 : 0;
    $caseQtyMatch = ((int) $agg->case_qty_sum === $fileCaseQtySum) ? 1 : 0;
    $pieceQtyMatch = ((int) $agg->piece_qty_sum === $filePieceQtySum) ? 1 : 0;

    if (! $sizeMatch) {
        $issues[] = 'file_size_mismatch';
    }
    if (! $recordCountMatch) {
        $issues[] = 'record_count_mismatch';
    }
    if (! $orderCountMatch) {
        $issues[] = 'd_record_count_mismatch';
    }
    if (! $caseQtyMatch) {
        $issues[] = 'case_qty_sum_mismatch';
    }
    if (! $pieceQtyMatch) {
        $issues[] = 'piece_qty_sum_mismatch';
    }

    fputcsv($out, [
        $doc->id, $doc->contractor_id, $doc->warehouse_id, $doc->batch_code, $doc->file_path, $doc->created_at, $doc->transmitted_at,
        $doc->file_size, $actualSize, $sizeMatch, $doc->record_count, $actualRecords, $recordCountMatch,
        $doc->order_count, $actualDRecords, $orderCountMatch, (int) $agg->candidate_count, (int) $agg->case_rows, (int) $agg->piece_rows,
        (int) $agg->case_qty_sum, $fileCaseQtySum, $caseQtyMatch, (int) $agg->piece_qty_sum, $filePieceQtySum, $pieceQtyMatch,
        $invalidRecordLength, $readError, implode(';', array_values(array_unique($issues))),
    ]);
}
