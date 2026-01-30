<?php

/**
 * 発注ファイル生成・完全比較スクリプト
 *
 * サンプルファイルから商品情報を抽出し、HanaOrderFileGeneratorで再生成して
 * 元のファイルと同一かを検証する
 */

$basePath = '/Users/jungsinyu/Projects/sakemaru-wms';
require_once $basePath . '/vendor/autoload.php';

$app = require_once $basePath . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AutoOrder\Generators\HanaOrderFileGenerator;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\Sakemaru\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

$sampleDirs = [
    '1017' => __DIR__ . '/samples/1017_order',
    '1021' => __DIR__ . '/samples/1021_order',
    '1202' => __DIR__ . '/samples/1202_order',
    '1330' => __DIR__ . '/samples/1330_order',
];

$report = [
    'test_date' => date('Y-m-d H:i:s'),
    'summary' => [
        'total_files' => 0,
        'files_compared' => 0,
        'perfect_match' => 0,
        'partial_match' => 0,
        'field_comparisons' => [
            'a_record' => ['total' => 0, 'match' => 0],
            'b_record' => ['total' => 0, 'match' => 0],
            'd_record' => ['total' => 0, 'match' => 0],
        ],
        'd_record_fields' => [
            'record_type' => ['total' => 0, 'match' => 0],
            'data_type' => ['total' => 0, 'match' => 0],
            'line_number' => ['total' => 0, 'match' => 0],
            'item_name' => ['total' => 0, 'match' => 0],
            'jan_code' => ['total' => 0, 'match' => 0],
            'item_code' => ['total' => 0, 'match' => 0],
            'capacity' => ['total' => 0, 'match' => 0],
            'case_qty' => ['total' => 0, 'match' => 0],
            'piece_qty' => ['total' => 0, 'match' => 0],
        ],
    ],
    'by_contractor' => [],
    'field_mismatches' => [],
];

$generator = new HanaOrderFileGenerator();

// Dレコードのフィールド定義（開始位置、長さ）
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
];

echo "=== 発注ファイル生成・比較テスト ===\n\n";

foreach ($sampleDirs as $contractorCode => $dirPath) {
    if (!is_dir($dirPath)) {
        echo "Directory not found: {$dirPath}\n";
        continue;
    }

    $files = glob($dirPath . '/*.txt');
    // 最初の10ファイルを比較
    $filesToCompare = array_slice($files, 0, 10);

    $contractorReport = [
        'contractor_code' => $contractorCode,
        'files_compared' => count($filesToCompare),
        'perfect_match' => 0,
        'partial_match' => 0,
        'd_records_compared' => 0,
        'd_records_match' => 0,
        'field_matches' => [],
    ];

    echo "Processing contractor {$contractorCode}: " . count($filesToCompare) . " files\n";

    foreach ($filesToCompare as $filePath) {
        $filename = basename($filePath);
        $report['summary']['total_files']++;

        // サンプルファイルを読み込み
        $sampleContent = file_get_contents($filePath);
        $sampleContent = rtrim($sampleContent, "\r\n");

        // サンプルからレコードを抽出
        $sampleRecords = extractRecords($sampleContent);

        if (empty($sampleRecords['D'])) {
            echo "  {$filename}: No D records found, skipping\n";
            continue;
        }

        // サンプルのDレコードから発注候補データを作成
        $candidates = createCandidatesFromSample($sampleRecords, $contractorCode);

        if ($candidates->isEmpty()) {
            echo "  {$filename}: Could not create candidates, skipping\n";
            continue;
        }

        // HanaOrderFileGeneratorでファイル生成
        $generatedFiles = $generator->generate($candidates);

        if (empty($generatedFiles)) {
            echo "  {$filename}: Generation failed, skipping\n";
            continue;
        }

        $generatedContent = $generatedFiles[0]['content'];
        $generatedRecords = extractRecords($generatedContent);

        $report['summary']['files_compared']++;

        // Dレコードを比較
        $sampleDRecords = $sampleRecords['D'];
        $generatedDRecords = $generatedRecords['D'];

        $fileMatch = true;
        $dRecordMatches = 0;

        // 各Dレコードのフィールドを比較
        $minCount = min(count($sampleDRecords), count($generatedDRecords));

        for ($i = 0; $i < $minCount; $i++) {
            $sampleD = $sampleDRecords[$i];
            $generatedD = $generatedDRecords[$i];

            $contractorReport['d_records_compared']++;
            $report['summary']['field_comparisons']['d_record']['total']++;

            $recordMatch = true;

            foreach ($dRecordFields as $fieldName => $fieldDef) {
                list($start, $length) = $fieldDef;

                $sampleValue = substr($sampleD, $start, $length);
                $generatedValue = substr($generatedD, $start, $length);

                $report['summary']['d_record_fields'][$fieldName]['total']++;

                if ($sampleValue === $generatedValue) {
                    $report['summary']['d_record_fields'][$fieldName]['match']++;
                } else {
                    $recordMatch = false;
                    $fileMatch = false;

                    // 不一致を記録（最初の30件のみ）
                    if (count($report['field_mismatches']) < 30) {
                        $report['field_mismatches'][] = [
                            'contractor' => $contractorCode,
                            'file' => $filename,
                            'record_index' => $i,
                            'field' => $fieldName,
                            'sample' => trim($sampleValue),
                            'generated' => trim($generatedValue),
                        ];
                    }
                }
            }

            if ($recordMatch) {
                $dRecordMatches++;
                $report['summary']['field_comparisons']['d_record']['match']++;
            }
        }

        $contractorReport['d_records_match'] += $dRecordMatches;

        if ($fileMatch && count($sampleDRecords) === count($generatedDRecords)) {
            $contractorReport['perfect_match']++;
            $report['summary']['perfect_match']++;
            echo "  {$filename}: ✅ Perfect match\n";
        } else {
            $contractorReport['partial_match']++;
            $report['summary']['partial_match']++;
            $matchRate = $minCount > 0 ? round($dRecordMatches / $minCount * 100, 1) : 0;
            echo "  {$filename}: ⚠ Partial match ({$dRecordMatches}/{$minCount} D records, {$matchRate}%)\n";
        }
    }

    $report['by_contractor'][$contractorCode] = $contractorReport;
}

// サマリー計算
foreach ($report['summary']['d_record_fields'] as $field => &$stats) {
    $stats['match_rate'] = $stats['total'] > 0 ? round($stats['match'] / $stats['total'] * 100, 2) : 0;
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "FILE GENERATION COMPARISON SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Total files compared: {$report['summary']['files_compared']}\n";
echo "Perfect match: {$report['summary']['perfect_match']}\n";
echo "Partial match: {$report['summary']['partial_match']}\n";

echo "\nD Record Field Match Rates:\n";
foreach ($report['summary']['d_record_fields'] as $field => $stats) {
    echo "  {$field}: {$stats['match']}/{$stats['total']} ({$stats['match_rate']}%)\n";
}

if (!empty($report['field_mismatches'])) {
    echo "\nSample Field Mismatches:\n";
    foreach (array_slice($report['field_mismatches'], 0, 10) as $mm) {
        echo "  [{$mm['contractor']}] {$mm['field']}: '{$mm['sample']}' vs '{$mm['generated']}'\n";
    }
}

// JSONレポートを保存
file_put_contents(__DIR__ . '/file_generation_comparison_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nReport saved to file_generation_comparison_report.json\n";

/**
 * ファイル内容からレコードを抽出
 */
function extractRecords(string $content): array
{
    $records = ['A' => [], 'B' => [], 'D' => []];
    $pos = 0;
    $recordLength = 128;

    while ($pos < strlen($content)) {
        // 改行をスキップ
        while ($pos < strlen($content) && ($content[$pos] === "\n" || $content[$pos] === "\r")) {
            $pos++;
        }

        if ($pos >= strlen($content)) {
            break;
        }

        $record = substr($content, $pos, $recordLength);

        if (strlen($record) < $recordLength) {
            break;
        }

        $type = $record[0];
        if (isset($records[$type])) {
            $records[$type][] = $record;
        }

        $pos += $recordLength;
    }

    return $records;
}

/**
 * サンプルレコードから発注候補を作成
 */
function createCandidatesFromSample(array $records, string $contractorCode): Collection
{
    $candidates = collect();

    // 発注先を取得
    $contractor = Contractor::where('code', $contractorCode)->first();
    if (!$contractor) {
        return $candidates;
    }

    // Bレコードから倉庫情報を取得
    $warehouseId = null;
    if (!empty($records['B'])) {
        $bRecord = $records['B'][0];
        $warehouseName = trim(mb_convert_encoding(substr($bRecord, 42, 15), 'UTF-8', 'SJIS'));

        // 倉庫名から倉庫IDを取得（部分一致）
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('name', 'like', '%' . $warehouseName . '%')
            ->first();

        if ($warehouse) {
            $warehouseId = $warehouse->id;
        }
    }

    // デフォルト倉庫
    if (!$warehouseId) {
        $warehouseId = 1; // フォールバック
    }

    foreach ($records['D'] as $dRecord) {
        $itemCode = trim(substr($dRecord, 82, 6));
        $itemId = intval($itemCode);

        // 商品が存在するか確認
        $item = Item::find($itemId);
        if (!$item) {
            continue;
        }

        $caseQty = intval(trim(substr($dRecord, 94, 7)));
        $pieceQty = intval(trim(substr($dRecord, 101, 7)));
        $capacity = intval(trim(substr($dRecord, 88, 6))) ?: ($item->capacity_case ?? 1);

        // 発注数量を計算
        $orderQuantity = ($caseQty * $capacity) + $pieceQty;

        // is_used_for_orderingの発注コードを取得
        $orderingCode = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->value('search_string');

        if ($orderingCode) {
            $orderingCode = str_pad($orderingCode, 13, '0', STR_PAD_LEFT);
        }

        // 擬似的な発注候補オブジェクトを作成
        $candidate = new \stdClass();
        $candidate->contractor_id = $contractor->id;
        $candidate->contractor = $contractor;
        $candidate->warehouse_id = $warehouseId;
        $candidate->warehouse = Warehouse::find($warehouseId);
        $candidate->item_id = $itemId;
        $candidate->item = $item;
        $candidate->order_quantity = $orderQuantity;
        $candidate->ordering_code = $orderingCode;
        $candidate->expected_arrival_date = now()->addDays(2);

        $candidates->push($candidate);
    }

    return $candidates;
}
