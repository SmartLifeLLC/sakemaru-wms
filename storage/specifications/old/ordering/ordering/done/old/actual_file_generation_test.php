<?php

/**
 * 実際のファイル生成・比較テスト
 *
 * HanaOrderFileGeneratorを使って実際にファイルを生成し、
 * サンプルファイルと完全比較する
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
        'files_generated' => 0,
        'generation_failed' => 0,
        'd_records_compared' => 0,
        'd_records_match' => 0,
        'field_stats' => [
            'jan_code' => ['total' => 0, 'match' => 0],
            'item_code' => ['total' => 0, 'match' => 0],
            'case_qty' => ['total' => 0, 'match' => 0],
            'piece_qty' => ['total' => 0, 'match' => 0],
        ],
    ],
    'by_contractor' => [],
    'mismatches' => [],
];

$generator = new HanaOrderFileGenerator();

echo "=== 実際のファイル生成・比較テスト ===\n\n";

foreach ($sampleDirs as $contractorCode => $dirPath) {
    if (!is_dir($dirPath)) {
        echo "Directory not found: {$dirPath}\n";
        continue;
    }

    $files = glob($dirPath . '/*.txt');

    $contractorReport = [
        'contractor_code' => $contractorCode,
        'files_total' => count($files),
        'files_generated' => 0,
        'generation_failed' => 0,
        'd_records_compared' => 0,
        'd_records_match' => 0,
        'jan_match' => 0,
        'jan_mismatch' => 0,
        'qty_match' => 0,
        'qty_mismatch' => 0,
    ];

    echo "Processing contractor {$contractorCode}: " . count($files) . " files\n";

    $processedCount = 0;
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $report['summary']['total_files']++;

        // サンプルファイルを読み込み
        $sampleContent = file_get_contents($filePath);
        $sampleContent = rtrim($sampleContent, "\r\n");

        // サンプルからレコードを抽出
        $sampleRecords = extractRecords($sampleContent);

        if (empty($sampleRecords['D'])) {
            continue;
        }

        // サンプルのDレコードから発注候補データを作成
        $candidates = createCandidatesFromSample($sampleRecords, $contractorCode);

        if ($candidates->isEmpty()) {
            $contractorReport['generation_failed']++;
            $report['summary']['generation_failed']++;
            continue;
        }

        // HanaOrderFileGeneratorでファイル生成
        try {
            $generatedFiles = $generator->generate($candidates);
        } catch (\Exception $e) {
            echo "  {$filename}: Generation error - {$e->getMessage()}\n";
            $contractorReport['generation_failed']++;
            $report['summary']['generation_failed']++;
            continue;
        }

        if (empty($generatedFiles)) {
            $contractorReport['generation_failed']++;
            $report['summary']['generation_failed']++;
            continue;
        }

        $contractorReport['files_generated']++;
        $report['summary']['files_generated']++;

        $generatedContent = $generatedFiles[0]['content'];
        $generatedRecords = extractRecords($generatedContent);

        // サンプルと生成結果をDレコードの商品コードでマッピングして比較
        $sampleByItemCode = indexDRecordsByItemCode($sampleRecords['D']);
        $generatedByItemCode = indexDRecordsByItemCode($generatedRecords['D']);

        foreach ($sampleByItemCode as $itemCode => $sampleD) {
            $contractorReport['d_records_compared']++;
            $report['summary']['d_records_compared']++;

            if (!isset($generatedByItemCode[$itemCode])) {
                // 生成結果に商品がない（スキップされた）
                continue;
            }

            $generatedD = $generatedByItemCode[$itemCode];

            // JANコード比較（位置69-82、13桁）
            $sampleJan = trim(substr($sampleD, 69, 13));
            $generatedJan = trim(substr($generatedD, 69, 13));

            $report['summary']['field_stats']['jan_code']['total']++;
            if ($sampleJan === $generatedJan) {
                $report['summary']['field_stats']['jan_code']['match']++;
                $contractorReport['jan_match']++;
            } else {
                $contractorReport['jan_mismatch']++;
                // 不一致を記録
                if (count($report['mismatches']) < 100) {
                    $report['mismatches'][] = [
                        'contractor' => $contractorCode,
                        'file' => $filename,
                        'item_code' => $itemCode,
                        'field' => 'jan_code',
                        'sample' => $sampleJan,
                        'generated' => $generatedJan,
                    ];
                }
            }

            // 商品コード比較（位置82-88、6桁）
            $sampleItemCode = trim(substr($sampleD, 82, 6));
            $generatedItemCode = trim(substr($generatedD, 82, 6));

            $report['summary']['field_stats']['item_code']['total']++;
            if ($sampleItemCode === $generatedItemCode) {
                $report['summary']['field_stats']['item_code']['match']++;
            }

            // ケース数比較（位置94-101、7桁）
            $sampleCaseQty = intval(trim(substr($sampleD, 94, 7)));
            $generatedCaseQty = intval(trim(substr($generatedD, 94, 7)));

            $report['summary']['field_stats']['case_qty']['total']++;
            if ($sampleCaseQty === $generatedCaseQty) {
                $report['summary']['field_stats']['case_qty']['match']++;
                $contractorReport['qty_match']++;
            } else {
                $contractorReport['qty_mismatch']++;
                if (count($report['mismatches']) < 100) {
                    $report['mismatches'][] = [
                        'contractor' => $contractorCode,
                        'file' => $filename,
                        'item_code' => $itemCode,
                        'field' => 'case_qty',
                        'sample' => $sampleCaseQty,
                        'generated' => $generatedCaseQty,
                    ];
                }
            }

            // バラ数比較（位置101-108、7桁）
            $samplePieceQty = intval(trim(substr($sampleD, 101, 7)));
            $generatedPieceQty = intval(trim(substr($generatedD, 101, 7)));

            $report['summary']['field_stats']['piece_qty']['total']++;
            if ($samplePieceQty === $generatedPieceQty) {
                $report['summary']['field_stats']['piece_qty']['match']++;
            }

            // 全フィールド一致判定
            if ($sampleJan === $generatedJan && $sampleCaseQty === $generatedCaseQty && $samplePieceQty === $generatedPieceQty) {
                $contractorReport['d_records_match']++;
                $report['summary']['d_records_match']++;
            }
        }

        $processedCount++;
        if ($processedCount % 20 === 0) {
            echo "  Processed {$processedCount} files...\n";
        }
    }

    $report['by_contractor'][$contractorCode] = $contractorReport;

    // 一致率計算
    $totalRecords = $contractorReport['d_records_compared'];
    $janMatchRate = $contractorReport['jan_match'] + $contractorReport['jan_mismatch'] > 0
        ? round($contractorReport['jan_match'] / ($contractorReport['jan_match'] + $contractorReport['jan_mismatch']) * 100, 2)
        : 0;

    echo "  Files generated: {$contractorReport['files_generated']}/{$contractorReport['files_total']}\n";
    echo "  D records compared: {$totalRecords}\n";
    echo "  JAN code match: {$contractorReport['jan_match']} ({$janMatchRate}%)\n";
    echo "  JAN code mismatch: {$contractorReport['jan_mismatch']}\n\n";
}

// フィールド別一致率計算
foreach ($report['summary']['field_stats'] as $field => &$stats) {
    $stats['match_rate'] = $stats['total'] > 0 ? round($stats['match'] / $stats['total'] * 100, 2) : 0;
}

// 全体の一致率計算
$totalDRecords = $report['summary']['d_records_compared'];
$totalDMatch = $report['summary']['d_records_match'];
$overallMatchRate = $totalDRecords > 0 ? round($totalDMatch / $totalDRecords * 100, 2) : 0;

echo str_repeat("=", 60) . "\n";
echo "ACTUAL FILE GENERATION TEST SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Total sample files: {$report['summary']['total_files']}\n";
echo "Files generated: {$report['summary']['files_generated']}\n";
echo "Generation failed: {$report['summary']['generation_failed']}\n";
echo "\nD Records compared: {$totalDRecords}\n";
echo "D Records all fields match: {$totalDMatch} ({$overallMatchRate}%)\n";

echo "\nField-by-field match rates:\n";
foreach ($report['summary']['field_stats'] as $field => $stats) {
    echo "  {$field}: {$stats['match']}/{$stats['total']} ({$stats['match_rate']}%)\n";
}

// 不一致サンプル表示
if (!empty($report['mismatches'])) {
    echo "\nSample Mismatches:\n";
    foreach (array_slice($report['mismatches'], 0, 20) as $mm) {
        echo "  [{$mm['contractor']}] Item {$mm['item_code']} {$mm['field']}: sample='{$mm['sample']}' vs generated='{$mm['generated']}'\n";
    }
}

// JSONレポートを保存
file_put_contents(__DIR__ . '/actual_file_generation_test_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nReport saved to actual_file_generation_test_report.json\n";

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
 * Dレコードを商品コードでインデックス化
 */
function indexDRecordsByItemCode(array $dRecords): array
{
    $indexed = [];
    foreach ($dRecords as $record) {
        $itemCode = trim(substr($record, 82, 6));
        if (!empty($itemCode)) {
            // 同じ商品コードが複数ある場合は最初のものを使用
            if (!isset($indexed[$itemCode])) {
                $indexed[$itemCode] = $record;
            }
        }
    }
    return $indexed;
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

    // 商品コードごとに集計（同一商品の重複を避ける）
    $itemDataMap = [];

    foreach ($records['D'] as $dRecord) {
        $itemCode = trim(substr($dRecord, 82, 6));
        $itemId = intval($itemCode);

        if (!isset($itemDataMap[$itemId])) {
            $itemDataMap[$itemId] = [
                'case_qty' => 0,
                'piece_qty' => 0,
                'capacity' => intval(trim(substr($dRecord, 88, 6))),
            ];
        }

        $itemDataMap[$itemId]['case_qty'] += intval(trim(substr($dRecord, 94, 7)));
        $itemDataMap[$itemId]['piece_qty'] += intval(trim(substr($dRecord, 101, 7)));
    }

    foreach ($itemDataMap as $itemId => $data) {
        // 商品が存在するか確認
        $item = Item::find($itemId);
        if (!$item) {
            continue;
        }

        $capacity = $data['capacity'] ?: ($item->capacity_case ?? 1);
        $caseQty = $data['case_qty'];
        $pieceQty = $data['piece_qty'];

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
