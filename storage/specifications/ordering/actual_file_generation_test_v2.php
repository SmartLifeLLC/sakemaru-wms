<?php

/**
 * 実際のファイル生成・比較テスト v2
 *
 * 倉庫ごとに分離して比較する（Bレコード単位）
 * サンプルファイルの構造を維持したまま生成・比較
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
        'd_records_in_sample' => 0,
        'd_records_in_generated' => 0,
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

echo "=== 実際のファイル生成・比較テスト v2（倉庫別比較）===\n\n";

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
        'd_records_sample' => 0,
        'd_records_generated' => 0,
        'jan_match' => 0,
        'jan_mismatch' => 0,
        'case_qty_match' => 0,
        'case_qty_mismatch' => 0,
        'piece_qty_match' => 0,
        'piece_qty_mismatch' => 0,
    ];

    echo "Processing contractor {$contractorCode}: " . count($files) . " files\n";

    $processedCount = 0;
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $report['summary']['total_files']++;

        // サンプルファイルを読み込み
        $sampleContent = file_get_contents($filePath);
        $sampleContent = rtrim($sampleContent, "\r\n");

        // サンプルを倉庫別に解析
        $sampleByWarehouse = parseFileByWarehouse($sampleContent);

        if (empty($sampleByWarehouse)) {
            continue;
        }

        // 発注先を取得
        $contractor = Contractor::where('code', $contractorCode)->first();
        if (!$contractor) {
            continue;
        }

        // 各倉庫ごとに発注候補を作成し、生成・比較
        foreach ($sampleByWarehouse as $warehouseKey => $warehouseData) {
            $candidates = createCandidatesForWarehouse($warehouseData, $contractor);

            if ($candidates->isEmpty()) {
                continue;
            }

            $contractorReport['d_records_sample'] += count($warehouseData['items']);
            $report['summary']['d_records_in_sample'] += count($warehouseData['items']);

            // HanaOrderFileGeneratorでファイル生成
            try {
                $generatedFiles = $generator->generate($candidates);
            } catch (\Exception $e) {
                continue;
            }

            if (empty($generatedFiles)) {
                continue;
            }

            $generatedContent = $generatedFiles[0]['content'];
            $generatedByWarehouse = parseFileByWarehouse($generatedContent);

            // 生成結果から最初の倉庫データを取得
            $generatedWarehouseData = reset($generatedByWarehouse);
            if (!$generatedWarehouseData) {
                continue;
            }

            $contractorReport['d_records_generated'] += count($generatedWarehouseData['items']);
            $report['summary']['d_records_in_generated'] += count($generatedWarehouseData['items']);

            // 商品コードでインデックス化して比較
            $sampleItems = [];
            foreach ($warehouseData['items'] as $item) {
                $sampleItems[$item['item_code']] = $item;
            }

            $generatedItems = [];
            foreach ($generatedWarehouseData['items'] as $item) {
                $generatedItems[$item['item_code']] = $item;
            }

            // 比較
            foreach ($sampleItems as $itemCode => $sampleItem) {
                if (!isset($generatedItems[$itemCode])) {
                    continue;
                }

                $generatedItem = $generatedItems[$itemCode];

                // JANコード比較
                $report['summary']['field_stats']['jan_code']['total']++;
                if ($sampleItem['jan_code'] === $generatedItem['jan_code']) {
                    $report['summary']['field_stats']['jan_code']['match']++;
                    $contractorReport['jan_match']++;
                } else {
                    $contractorReport['jan_mismatch']++;
                    if (count($report['mismatches']) < 100) {
                        $report['mismatches'][] = [
                            'contractor' => $contractorCode,
                            'file' => $filename,
                            'item_code' => $itemCode,
                            'field' => 'jan_code',
                            'sample' => $sampleItem['jan_code'],
                            'generated' => $generatedItem['jan_code'],
                        ];
                    }
                }

                // 商品コード比較
                $report['summary']['field_stats']['item_code']['total']++;
                if ($sampleItem['item_code'] === $generatedItem['item_code']) {
                    $report['summary']['field_stats']['item_code']['match']++;
                }

                // ケース数比較
                $report['summary']['field_stats']['case_qty']['total']++;
                if ($sampleItem['case_qty'] === $generatedItem['case_qty']) {
                    $report['summary']['field_stats']['case_qty']['match']++;
                    $contractorReport['case_qty_match']++;
                } else {
                    $contractorReport['case_qty_mismatch']++;
                    if (count($report['mismatches']) < 100) {
                        $report['mismatches'][] = [
                            'contractor' => $contractorCode,
                            'file' => $filename,
                            'item_code' => $itemCode,
                            'field' => 'case_qty',
                            'sample' => $sampleItem['case_qty'],
                            'generated' => $generatedItem['case_qty'],
                        ];
                    }
                }

                // バラ数比較
                $report['summary']['field_stats']['piece_qty']['total']++;
                if ($sampleItem['piece_qty'] === $generatedItem['piece_qty']) {
                    $report['summary']['field_stats']['piece_qty']['match']++;
                    $contractorReport['piece_qty_match']++;
                } else {
                    $contractorReport['piece_qty_mismatch']++;
                }
            }
        }

        $contractorReport['files_generated']++;
        $report['summary']['files_generated']++;

        $processedCount++;
        if ($processedCount % 20 === 0) {
            echo "  Processed {$processedCount} files...\n";
        }
    }

    $report['by_contractor'][$contractorCode] = $contractorReport;

    // 一致率計算
    $janTotal = $contractorReport['jan_match'] + $contractorReport['jan_mismatch'];
    $janMatchRate = $janTotal > 0 ? round($contractorReport['jan_match'] / $janTotal * 100, 2) : 0;

    $qtyTotal = $contractorReport['case_qty_match'] + $contractorReport['case_qty_mismatch'];
    $qtyMatchRate = $qtyTotal > 0 ? round($contractorReport['case_qty_match'] / $qtyTotal * 100, 2) : 0;

    echo "  Files generated: {$contractorReport['files_generated']}/{$contractorReport['files_total']}\n";
    echo "  D records (sample): {$contractorReport['d_records_sample']}\n";
    echo "  JAN code match: {$contractorReport['jan_match']}/{$janTotal} ({$janMatchRate}%)\n";
    echo "  Case qty match: {$contractorReport['case_qty_match']}/{$qtyTotal} ({$qtyMatchRate}%)\n\n";
}

// フィールド別一致率計算
foreach ($report['summary']['field_stats'] as $field => &$stats) {
    $stats['match_rate'] = $stats['total'] > 0 ? round($stats['match'] / $stats['total'] * 100, 2) : 0;
}

echo str_repeat("=", 60) . "\n";
echo "ACTUAL FILE GENERATION TEST v2 SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Total sample files: {$report['summary']['total_files']}\n";
echo "Files generated: {$report['summary']['files_generated']}\n";
echo "\nD Records in sample: {$report['summary']['d_records_in_sample']}\n";
echo "D Records in generated: {$report['summary']['d_records_in_generated']}\n";

echo "\nField-by-field match rates:\n";
foreach ($report['summary']['field_stats'] as $field => $stats) {
    echo "  {$field}: {$stats['match']}/{$stats['total']} ({$stats['match_rate']}%)\n";
}

// 不一致サンプル表示
if (!empty($report['mismatches'])) {
    echo "\nSample Mismatches:\n";
    $janMismatches = array_filter($report['mismatches'], fn($m) => $m['field'] === 'jan_code');
    $qtyMismatches = array_filter($report['mismatches'], fn($m) => $m['field'] === 'case_qty');

    echo "\nJAN Code Mismatches:\n";
    foreach (array_slice($janMismatches, 0, 10) as $mm) {
        echo "  [{$mm['contractor']}] Item {$mm['item_code']}: sample='{$mm['sample']}' vs generated='{$mm['generated']}'\n";
    }

    if (!empty($qtyMismatches)) {
        echo "\nCase Qty Mismatches:\n";
        foreach (array_slice($qtyMismatches, 0, 10) as $mm) {
            echo "  [{$mm['contractor']}] Item {$mm['item_code']}: sample='{$mm['sample']}' vs generated='{$mm['generated']}'\n";
        }
    }
}

// JSONレポートを保存
file_put_contents(__DIR__ . '/actual_file_generation_test_v2_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nReport saved to actual_file_generation_test_v2_report.json\n";

/**
 * ファイルを倉庫別に解析
 */
function parseFileByWarehouse(string $content): array
{
    $warehouses = [];
    $currentWarehouse = null;
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

        if ($type === 'B') {
            // 新しい倉庫
            $warehouseName = trim(mb_convert_encoding(substr($record, 42, 15), 'UTF-8', 'SJIS'));
            $warehouseCode = trim(substr($record, 57, 10));
            $currentWarehouse = $warehouseName . '_' . $warehouseCode;
            $warehouses[$currentWarehouse] = [
                'name' => $warehouseName,
                'code' => $warehouseCode,
                'items' => [],
            ];
        } elseif ($type === 'D' && $currentWarehouse !== null) {
            $janCode = trim(substr($record, 69, 13));
            $itemCode = trim(substr($record, 82, 6));
            $capacity = intval(trim(substr($record, 88, 6)));
            $caseQty = intval(trim(substr($record, 94, 7)));
            $pieceQty = intval(trim(substr($record, 101, 7)));

            $warehouses[$currentWarehouse]['items'][] = [
                'jan_code' => $janCode,
                'item_code' => $itemCode,
                'capacity' => $capacity,
                'case_qty' => $caseQty,
                'piece_qty' => $pieceQty,
            ];
        }

        $pos += $recordLength;
    }

    return $warehouses;
}

/**
 * 倉庫データから発注候補を作成
 */
function createCandidatesForWarehouse(array $warehouseData, $contractor): Collection
{
    $candidates = collect();

    // 倉庫を検索
    $warehouse = DB::connection('sakemaru')
        ->table('warehouses')
        ->where('name', 'like', '%' . $warehouseData['name'] . '%')
        ->first();

    $warehouseId = $warehouse ? $warehouse->id : 1;
    $warehouseModel = Warehouse::find($warehouseId);

    foreach ($warehouseData['items'] as $itemData) {
        $itemId = intval($itemData['item_code']);
        $item = Item::find($itemId);
        if (!$item) {
            continue;
        }

        $capacity = $itemData['capacity'] ?: ($item->capacity_case ?? 1);
        $caseQty = $itemData['case_qty'];
        $pieceQty = $itemData['piece_qty'];

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
        $candidate->warehouse = $warehouseModel;
        $candidate->item_id = $itemId;
        $candidate->item = $item;
        $candidate->order_quantity = $orderQuantity;
        $candidate->ordering_code = $orderingCode;
        $candidate->expected_arrival_date = now()->addDays(2);

        $candidates->push($candidate);
    }

    return $candidates;
}
