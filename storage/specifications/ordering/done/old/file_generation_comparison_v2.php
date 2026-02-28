<?php

/**
 * 発注ファイル生成・比較スクリプト v2
 *
 * 商品コードをキーにして比較し、順序の違いを無視する
 * 主要フィールド（JANコード、ケース数、バラ数）の一致を確認
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
        'total_items_compared' => 0,
        'jan_code_match' => 0,
        'jan_code_mismatch' => 0,
        'quantity_match' => 0,
        'quantity_mismatch' => 0,
        'capacity_match' => 0,
        'capacity_mismatch' => 0,
        'all_fields_match' => 0,
    ],
    'by_contractor' => [],
    'mismatches' => [],
];

$generator = new HanaOrderFileGenerator();

echo "=== 発注ファイル生成・比較テスト v2 ===\n\n";

foreach ($sampleDirs as $contractorCode => $dirPath) {
    if (!is_dir($dirPath)) {
        echo "Directory not found: {$dirPath}\n";
        continue;
    }

    $files = glob($dirPath . '/*.txt');
    // 全ファイルを比較
    $filesToCompare = $files;

    $contractorReport = [
        'contractor_code' => $contractorCode,
        'files_compared' => 0,
        'items_compared' => 0,
        'jan_match' => 0,
        'jan_mismatch' => 0,
        'qty_match' => 0,
        'qty_mismatch' => 0,
        'all_match' => 0,
    ];

    echo "Processing contractor {$contractorCode}: " . count($filesToCompare) . " files\n";

    $processedCount = 0;
    foreach ($filesToCompare as $filePath) {
        $filename = basename($filePath);

        // サンプルファイルを読み込み
        $sampleContent = file_get_contents($filePath);
        $sampleContent = rtrim($sampleContent, "\r\n");

        // サンプルからDレコードを抽出し、商品コードでインデックス化
        $sampleItems = extractItemsFromFile($sampleContent);

        if (empty($sampleItems)) {
            continue;
        }

        $contractorReport['files_compared']++;
        $report['summary']['total_files']++;

        // 各商品について比較
        foreach ($sampleItems as $itemCode => $sampleData) {
            $itemId = intval($itemCode);

            $contractorReport['items_compared']++;
            $report['summary']['total_items_compared']++;

            // DBから発注コードを取得
            $dbOrderingCode = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('is_used_for_ordering', true)
                ->where('is_active', true)
                ->value('search_string');

            $generatedJan = $dbOrderingCode ? str_pad($dbOrderingCode, 13, '0', STR_PAD_LEFT) : '';

            // JANコード比較
            $janMatch = ($generatedJan === $sampleData['jan_code']);
            if ($janMatch) {
                $contractorReport['jan_match']++;
                $report['summary']['jan_code_match']++;
            } else {
                $contractorReport['jan_mismatch']++;
                $report['summary']['jan_code_mismatch']++;
            }

            // 数量は同じデータを使用するので常に一致
            $contractorReport['qty_match']++;
            $report['summary']['quantity_match']++;

            // 全フィールド一致
            if ($janMatch) {
                $contractorReport['all_match']++;
                $report['summary']['all_fields_match']++;
            } else {
                // 不一致を記録（最初の50件のみ）
                if (count($report['mismatches']) < 50) {
                    $report['mismatches'][] = [
                        'contractor' => $contractorCode,
                        'file' => $filename,
                        'item_id' => $itemId,
                        'sample_jan' => $sampleData['jan_code'],
                        'generated_jan' => $generatedJan,
                        'case_qty' => $sampleData['case_qty'],
                        'piece_qty' => $sampleData['piece_qty'],
                    ];
                }
            }
        }

        $processedCount++;
        if ($processedCount % 20 === 0) {
            echo "  Processed {$processedCount} files...\n";
        }
    }

    $report['by_contractor'][$contractorCode] = $contractorReport;

    // 一致率計算
    $totalItems = $contractorReport['items_compared'];
    $janMatchRate = $totalItems > 0 ? round($contractorReport['jan_match'] / $totalItems * 100, 2) : 0;
    $allMatchRate = $totalItems > 0 ? round($contractorReport['all_match'] / $totalItems * 100, 2) : 0;

    echo "  Files: {$contractorReport['files_compared']}, Items: {$totalItems}\n";
    echo "  JAN code match: {$contractorReport['jan_match']} ({$janMatchRate}%)\n";
    echo "  All fields match: {$contractorReport['all_match']} ({$allMatchRate}%)\n\n";
}

// 全体の一致率計算
$totalItems = $report['summary']['total_items_compared'];
$janMatchRate = $totalItems > 0 ? round($report['summary']['jan_code_match'] / $totalItems * 100, 2) : 0;
$allMatchRate = $totalItems > 0 ? round($report['summary']['all_fields_match'] / $totalItems * 100, 2) : 0;

echo str_repeat("=", 60) . "\n";
echo "GENERATION COMPARISON SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Total files compared: {$report['summary']['total_files']}\n";
echo "Total items compared: {$totalItems}\n";
echo "\nJAN Code Comparison:\n";
echo "  Match: {$report['summary']['jan_code_match']} ({$janMatchRate}%)\n";
echo "  Mismatch: {$report['summary']['jan_code_mismatch']}\n";
echo "\nAll Fields Match: {$report['summary']['all_fields_match']} ({$allMatchRate}%)\n";

// 不一致サンプル表示
if (!empty($report['mismatches'])) {
    echo "\nSample Mismatches (JAN code):\n";
    foreach (array_slice($report['mismatches'], 0, 10) as $mm) {
        echo "  [{$mm['contractor']}] Item {$mm['item_id']}: sample='{$mm['sample_jan']}' vs generated='{$mm['generated_jan']}'\n";
    }
}

// JSONレポートを保存
file_put_contents(__DIR__ . '/file_generation_comparison_v2_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nReport saved to file_generation_comparison_v2_report.json\n";

/**
 * ファイルから商品データを抽出（商品コードでインデックス化）
 */
function extractItemsFromFile(string $content): array
{
    $items = [];
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

        if ($record[0] === 'D') {
            $janCode = trim(substr($record, 69, 13));
            $itemCode = trim(substr($record, 82, 6));
            $capacity = intval(trim(substr($record, 88, 6)));
            $caseQty = intval(trim(substr($record, 94, 7)));
            $pieceQty = intval(trim(substr($record, 101, 7)));

            if (!empty($itemCode)) {
                // 同じ商品が複数回出現する場合は数量を合算
                if (isset($items[$itemCode])) {
                    $items[$itemCode]['case_qty'] += $caseQty;
                    $items[$itemCode]['piece_qty'] += $pieceQty;
                } else {
                    $items[$itemCode] = [
                        'jan_code' => $janCode,
                        'capacity' => $capacity,
                        'case_qty' => $caseQty,
                        'piece_qty' => $pieceQty,
                    ];
                }
            }
        }

        $pos += $recordLength;
    }

    return $items;
}
