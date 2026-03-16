<?php

/**
 * 発注サンプルファイル分析スクリプト
 *
 * 各発注先のサンプルファイルを分析し、JANコードと発注数を検証する
 */

$basePath = '/Users/jungsinyu/Projects/sakemaru-wms';
require_once $basePath . '/vendor/autoload.php';

$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$sampleDirs = [
    '1017' => __DIR__ . '/samples/1017_order',
    '1021' => __DIR__ . '/samples/1021_order',
    '1202' => __DIR__ . '/samples/1202_order',
    '1330' => __DIR__ . '/samples/1330_order',
];

$report = [
    'summary' => [
        'total_files' => 0,
        'total_records' => 0,
        'total_d_records' => 0,
        'jan_code_match' => 0,
        'jan_code_mismatch' => 0,
        'jan_code_not_found' => 0,
        'is_used_for_ordering_match' => 0,
    ],
    'by_contractor' => [],
    'errors' => [],
    'mismatches' => [],
];

foreach ($sampleDirs as $contractorCode => $dirPath) {
    if (!is_dir($dirPath)) {
        $report['errors'][] = "Directory not found: {$dirPath}";
        continue;
    }

    $files = glob($dirPath . '/*.txt');
    $contractorReport = [
        'contractor_code' => $contractorCode,
        'file_count' => count($files),
        'total_records' => 0,
        'd_records' => 0,
        'jan_code_match' => 0,
        'jan_code_mismatch' => 0,
        'jan_code_not_found' => 0,
        'is_used_for_ordering_match' => 0,
        'unique_items' => [],
        'files_analyzed' => [],
    ];

    echo "Analyzing contractor {$contractorCode}: " . count($files) . " files\n";

    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $content = file_get_contents($filePath);

        // Remove trailing newlines
        $content = rtrim($content, "\r\n");

        $fileReport = [
            'filename' => $filename,
            'records' => 0,
            'd_records' => 0,
            'items' => [],
        ];

        $pos = 0;
        $recordLength = 128;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, $recordLength);

            // Skip newline characters if present
            if (strlen($record) > 0 && ($record[0] === "\n" || $record[0] === "\r")) {
                $pos++;
                continue;
            }

            if (strlen($record) < $recordLength) {
                break;
            }

            $fileReport['records']++;
            $contractorReport['total_records']++;

            $recordType = $record[0];

            if ($recordType === 'D') {
                $fileReport['d_records']++;
                $contractorReport['d_records']++;

                // Extract JAN code (position 70-82, 0-indexed: 69-81)
                $janCode = trim(substr($record, 69, 13));

                // Extract item code (position 83-88, 0-indexed: 82-87)
                $itemCode = trim(substr($record, 82, 6));

                // Extract case quantity (position 95-101, 0-indexed: 94-100)
                $caseQty = intval(trim(substr($record, 94, 7)));

                // Extract piece quantity (position 102-108, 0-indexed: 101-107)
                $pieceQty = intval(trim(substr($record, 101, 7)));

                // Extract capacity (position 89-94, 0-indexed: 88-93)
                $capacity = intval(trim(substr($record, 88, 6)));

                if (!empty($janCode) && $janCode !== '0000000000000') {
                    $janWithoutZeros = ltrim($janCode, '0');
                    if ($janWithoutZeros === '') $janWithoutZeros = '0';

                    // Check if item exists in database
                    $itemId = intval($itemCode);

                    // Check for exact match in item_search_information
                    $exactMatch = DB::connection('sakemaru')
                        ->table('item_search_information')
                        ->where('item_id', $itemId)
                        ->where('is_active', true)
                        ->where(function($q) use ($janCode, $janWithoutZeros) {
                            $q->where('search_string', $janCode)
                              ->orWhere('search_string', $janWithoutZeros);
                        })
                        ->first();

                    // Check for is_used_for_ordering match
                    $orderingMatch = DB::connection('sakemaru')
                        ->table('item_search_information')
                        ->where('item_id', $itemId)
                        ->where('is_used_for_ordering', true)
                        ->where('is_active', true)
                        ->where(function($q) use ($janCode, $janWithoutZeros) {
                            $q->where('search_string', $janCode)
                              ->orWhere('search_string', $janWithoutZeros);
                        })
                        ->exists();

                    $status = 'not_found';
                    if ($exactMatch) {
                        $status = 'match';
                        $contractorReport['jan_code_match']++;

                        if ($orderingMatch) {
                            $contractorReport['is_used_for_ordering_match']++;
                        }
                    } else {
                        // Check if item exists at all
                        $itemExists = DB::connection('sakemaru')
                            ->table('item_search_information')
                            ->where('item_id', $itemId)
                            ->where('is_active', true)
                            ->exists();

                        if ($itemExists) {
                            $status = 'mismatch';
                            $contractorReport['jan_code_mismatch']++;

                            // Get the actual codes for this item
                            $actualCodes = DB::connection('sakemaru')
                                ->table('item_search_information')
                                ->where('item_id', $itemId)
                                ->where('is_active', true)
                                ->pluck('search_string')
                                ->toArray();

                            $report['mismatches'][] = [
                                'contractor' => $contractorCode,
                                'file' => $filename,
                                'item_id' => $itemId,
                                'sample_jan' => $janCode,
                                'db_codes' => $actualCodes,
                            ];
                        } else {
                            $contractorReport['jan_code_not_found']++;
                        }
                    }

                    $fileReport['items'][] = [
                        'jan_code' => $janCode,
                        'item_code' => $itemCode,
                        'case_qty' => $caseQty,
                        'piece_qty' => $pieceQty,
                        'capacity' => $capacity,
                        'status' => $status,
                        'is_used_for_ordering' => $orderingMatch,
                    ];

                    // Track unique items
                    if (!isset($contractorReport['unique_items'][$itemId])) {
                        $contractorReport['unique_items'][$itemId] = [
                            'jan_code' => $janCode,
                            'count' => 0,
                            'status' => $status,
                        ];
                    }
                    $contractorReport['unique_items'][$itemId]['count']++;
                }
            }

            $pos += $recordLength;

            // Skip newline if present after record
            if ($pos < strlen($content) && ($content[$pos] === "\n" || $content[$pos] === "\r")) {
                $pos++;
                if ($pos < strlen($content) && $content[$pos] === "\n") {
                    $pos++;
                }
            }
        }

        $contractorReport['files_analyzed'][] = $fileReport;
    }

    $report['summary']['total_files'] += $contractorReport['file_count'];
    $report['summary']['total_records'] += $contractorReport['total_records'];
    $report['summary']['total_d_records'] += $contractorReport['d_records'];
    $report['summary']['jan_code_match'] += $contractorReport['jan_code_match'];
    $report['summary']['jan_code_mismatch'] += $contractorReport['jan_code_mismatch'];
    $report['summary']['jan_code_not_found'] += $contractorReport['jan_code_not_found'];
    $report['summary']['is_used_for_ordering_match'] += $contractorReport['is_used_for_ordering_match'];

    // Store unique items count instead of full list
    $contractorReport['unique_items_count'] = count($contractorReport['unique_items']);
    unset($contractorReport['unique_items']);
    unset($contractorReport['files_analyzed']); // Remove detailed file info to save memory

    $report['by_contractor'][$contractorCode] = $contractorReport;

    echo "  - Records: {$contractorReport['total_records']}, D records: {$contractorReport['d_records']}\n";
    echo "  - JAN match: {$contractorReport['jan_code_match']}, mismatch: {$contractorReport['jan_code_mismatch']}, not found: {$contractorReport['jan_code_not_found']}\n";
    echo "  - is_used_for_ordering match: {$contractorReport['is_used_for_ordering_match']}\n";
}

// Calculate percentages
$total = $report['summary']['jan_code_match'] + $report['summary']['jan_code_mismatch'] + $report['summary']['jan_code_not_found'];
$report['summary']['match_rate'] = $total > 0 ? round($report['summary']['jan_code_match'] / $total * 100, 2) : 0;
$report['summary']['is_used_for_ordering_rate'] = $total > 0 ? round($report['summary']['is_used_for_ordering_match'] / $total * 100, 2) : 0;

// Output JSON for further processing
echo "\n=== SUMMARY ===\n";
echo "Total files: {$report['summary']['total_files']}\n";
echo "Total records: {$report['summary']['total_records']}\n";
echo "Total D records: {$report['summary']['total_d_records']}\n";
echo "JAN code match: {$report['summary']['jan_code_match']} ({$report['summary']['match_rate']}%)\n";
echo "JAN code mismatch: {$report['summary']['jan_code_mismatch']}\n";
echo "JAN code not found: {$report['summary']['jan_code_not_found']}\n";
echo "is_used_for_ordering match: {$report['summary']['is_used_for_ordering_match']} ({$report['summary']['is_used_for_ordering_rate']}%)\n";

// Save report to JSON
file_put_contents(__DIR__ . '/analysis_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nReport saved to analysis_report.json\n";
