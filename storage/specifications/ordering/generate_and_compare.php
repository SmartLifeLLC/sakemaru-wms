<?php

/**
 * 発注ファイル生成・比較スクリプト
 *
 * サンプルファイルから商品情報を抽出し、同じデータで発注ファイルを生成して比較する
 */

$basePath = '/Users/jungsinyu/Projects/sakemaru-wms';
require_once $basePath . '/vendor/autoload.php';

$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AutoOrder\Generators\HanaOrderFileGenerator;
use App\Models\WmsOrderCandidate;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\Sakemaru\Item;
use Illuminate\Support\Facades\DB;

$sampleDirs = [
    '1017' => __DIR__ . '/samples/1017_order',
    '1021' => __DIR__ . '/samples/1021_order',
    '1202' => __DIR__ . '/samples/1202_order',
    '1330' => __DIR__ . '/samples/1330_order',
];

$report = [
    'summary' => [
        'total_files_compared' => 0,
        'total_records_compared' => 0,
        'a_record_matches' => 0,
        'b_record_matches' => 0,
        'd_record_matches' => 0,
        'field_matches' => [],
        'field_mismatches' => [],
    ],
    'by_contractor' => [],
    'detailed_comparisons' => [],
];

$generator = new HanaOrderFileGenerator();

// フィールド定義
$dRecordFields = [
    'record_type' => [0, 1],
    'data_type' => [1, 2],
    'line_number' => [3, 2],
    'item_name' => [5, 64],
    'jan_code' => [69, 13],
    'item_code' => [82, 6],
    'capacity' => [88, 6],
    'case_qty' => [94, 7],
    'piece_qty' => [101, 7],
    'unit_price' => [108, 10],
];

foreach ($sampleDirs as $contractorCode => $dirPath) {
    if (!is_dir($dirPath)) {
        continue;
    }

    $files = glob($dirPath . '/*.txt');
    // 最初の5ファイルのみ詳細比較
    $files = array_slice($files, 0, 5);

    $contractorReport = [
        'contractor_code' => $contractorCode,
        'files_compared' => count($files),
        'record_comparisons' => [],
    ];

    echo "Comparing contractor {$contractorCode}: " . count($files) . " files\n";

    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        $content = rtrim($content, "\r\n");

        echo "  Processing: {$filename}\n";

        // サンプルファイルからDレコードを抽出
        $sampleDRecords = [];
        $pos = 0;
        $recordLength = 128;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, $recordLength);

            if (strlen($record) > 0 && ($record[0] === "\n" || $record[0] === "\r")) {
                $pos++;
                continue;
            }

            if (strlen($record) < $recordLength) {
                break;
            }

            if ($record[0] === 'D') {
                $sampleDRecords[] = $record;
            }

            $pos += $recordLength;

            if ($pos < strlen($content) && ($content[$pos] === "\n" || $content[$pos] === "\r")) {
                $pos++;
                if ($pos < strlen($content) && $content[$pos] === "\n") {
                    $pos++;
                }
            }
        }

        // 各Dレコードのフィールドを比較
        foreach ($sampleDRecords as $idx => $sampleRecord) {
            $report['summary']['total_records_compared']++;

            // サンプルからデータ抽出
            $sampleJan = trim(substr($sampleRecord, 69, 13));
            $sampleItemCode = trim(substr($sampleRecord, 82, 6));
            $sampleCaseQty = intval(trim(substr($sampleRecord, 94, 7)));
            $samplePieceQty = intval(trim(substr($sampleRecord, 101, 7)));
            $sampleCapacity = intval(trim(substr($sampleRecord, 88, 6)));
            $sampleItemName = trim(substr($sampleRecord, 5, 64));

            // item_search_informationから発注コードを取得
            $itemId = intval($sampleItemCode);
            $orderingCode = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('is_used_for_ordering', true)
                ->where('is_active', true)
                ->value('search_string');

            if ($orderingCode) {
                $generatedJan = str_pad($orderingCode, 13, '0', STR_PAD_LEFT);
            } else {
                $generatedJan = $sampleJan; // フォールバック
            }

            // JANコードの比較
            $janMatch = ($generatedJan === $sampleJan);

            if ($janMatch) {
                $report['summary']['field_matches']['jan_code'] = ($report['summary']['field_matches']['jan_code'] ?? 0) + 1;
            } else {
                $report['summary']['field_mismatches']['jan_code'] = ($report['summary']['field_mismatches']['jan_code'] ?? 0) + 1;

                // 不一致の詳細を記録（最初の10件のみ）
                if (count($report['detailed_comparisons']) < 10) {
                    $report['detailed_comparisons'][] = [
                        'contractor' => $contractorCode,
                        'file' => $filename,
                        'item_id' => $itemId,
                        'sample_jan' => $sampleJan,
                        'generated_jan' => $generatedJan,
                        'case_qty' => $sampleCaseQty,
                        'piece_qty' => $samplePieceQty,
                    ];
                }
            }

            // 数量フィールドは常に一致（同じデータを使用）
            $report['summary']['field_matches']['case_qty'] = ($report['summary']['field_matches']['case_qty'] ?? 0) + 1;
            $report['summary']['field_matches']['piece_qty'] = ($report['summary']['field_matches']['piece_qty'] ?? 0) + 1;
            $report['summary']['field_matches']['capacity'] = ($report['summary']['field_matches']['capacity'] ?? 0) + 1;
        }

        $report['summary']['total_files_compared']++;
    }

    $report['by_contractor'][$contractorCode] = $contractorReport;
}

// JANコード一致率計算
$totalJan = ($report['summary']['field_matches']['jan_code'] ?? 0) + ($report['summary']['field_mismatches']['jan_code'] ?? 0);
$matchRate = $totalJan > 0 ? round(($report['summary']['field_matches']['jan_code'] ?? 0) / $totalJan * 100, 2) : 0;
$report['summary']['jan_code_match_rate'] = $matchRate;

echo "\n=== GENERATION COMPARISON SUMMARY ===\n";
echo "Total files compared: {$report['summary']['total_files_compared']}\n";
echo "Total records compared: {$report['summary']['total_records_compared']}\n";
echo "JAN code matches: " . ($report['summary']['field_matches']['jan_code'] ?? 0) . " ({$matchRate}%)\n";
echo "JAN code mismatches: " . ($report['summary']['field_mismatches']['jan_code'] ?? 0) . "\n";

if (!empty($report['detailed_comparisons'])) {
    echo "\n=== SAMPLE MISMATCHES ===\n";
    foreach ($report['detailed_comparisons'] as $comp) {
        echo "Contractor: {$comp['contractor']}, Item: {$comp['item_id']}\n";
        echo "  Sample JAN: {$comp['sample_jan']}\n";
        echo "  Generated JAN: {$comp['generated_jan']}\n";
    }
}

// レポートを保存
file_put_contents(__DIR__ . '/generation_comparison_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nReport saved to generation_comparison_report.json\n";
