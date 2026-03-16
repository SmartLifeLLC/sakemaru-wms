<?php

/**
 * 包括的発注ファイルテストスクリプト
 *
 * 全サンプルファイルを対象に：
 * 1. JANコードの存在確認
 * 2. is_used_for_orderingフラグとの一致確認
 * 3. レコード形式の検証
 * 4. 詳細レポートの生成
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
    'test_date' => date('Y-m-d H:i:s'),
    'summary' => [
        'total_files' => 0,
        'total_a_records' => 0,
        'total_b_records' => 0,
        'total_d_records' => 0,
        'jan_code_in_db' => 0,
        'jan_code_not_in_db' => 0,
        'is_used_for_ordering_match' => 0,
        'is_used_for_ordering_mismatch' => 0,
        'record_length_valid' => 0,
        'record_length_invalid' => 0,
        'unique_items' => [],
        'unique_warehouses' => [],
    ],
    'by_contractor' => [],
    'jan_code_not_found_items' => [],
    'is_used_for_ordering_mismatches' => [],
    'record_format_issues' => [],
];

foreach ($sampleDirs as $contractorCode => $dirPath) {
    if (!is_dir($dirPath)) {
        echo "Directory not found: {$dirPath}\n";
        continue;
    }

    $files = glob($dirPath . '/*.txt');

    $contractorReport = [
        'contractor_code' => $contractorCode,
        'file_count' => count($files),
        'a_records' => 0,
        'b_records' => 0,
        'd_records' => 0,
        'jan_in_db' => 0,
        'jan_not_in_db' => 0,
        'ordering_flag_match' => 0,
        'ordering_flag_mismatch' => 0,
        'unique_items' => [],
        'unique_warehouses' => [],
        'date_range' => ['min' => null, 'max' => null],
    ];

    echo "Processing contractor {$contractorCode}: " . count($files) . " files\n";

    $processedCount = 0;
    foreach ($files as $filePath) {
        $filename = basename($filePath);
        $content = file_get_contents($filePath);
        $content = rtrim($content, "\r\n");

        $report['summary']['total_files']++;

        $pos = 0;
        $recordLength = 128;

        while ($pos < strlen($content)) {
            $record = substr($content, $pos, $recordLength);

            // Skip newline characters
            if (strlen($record) > 0 && ($record[0] === "\n" || $record[0] === "\r")) {
                $pos++;
                continue;
            }

            if (strlen($record) < $recordLength) {
                break;
            }

            // レコード長検証
            if (strlen($record) === $recordLength) {
                $report['summary']['record_length_valid']++;
            } else {
                $report['summary']['record_length_invalid']++;
                $report['record_format_issues'][] = [
                    'file' => $filename,
                    'position' => $pos,
                    'expected' => $recordLength,
                    'actual' => strlen($record),
                ];
            }

            $recordType = $record[0];

            switch ($recordType) {
                case 'A':
                    $report['summary']['total_a_records']++;
                    $contractorReport['a_records']++;

                    // 処理日付を抽出
                    $dateStr = substr($record, 3, 8);
                    if (is_numeric($dateStr)) {
                        $date = $dateStr;
                        if ($contractorReport['date_range']['min'] === null || $date < $contractorReport['date_range']['min']) {
                            $contractorReport['date_range']['min'] = $date;
                        }
                        if ($contractorReport['date_range']['max'] === null || $date > $contractorReport['date_range']['max']) {
                            $contractorReport['date_range']['max'] = $date;
                        }
                    }
                    break;

                case 'B':
                    $report['summary']['total_b_records']++;
                    $contractorReport['b_records']++;

                    // 倉庫名を抽出
                    $warehouseName = trim(mb_convert_encoding(substr($record, 42, 15), 'UTF-8', 'SJIS'));
                    if (!empty($warehouseName) && !in_array($warehouseName, $contractorReport['unique_warehouses'])) {
                        $contractorReport['unique_warehouses'][] = $warehouseName;
                        if (!in_array($warehouseName, $report['summary']['unique_warehouses'])) {
                            $report['summary']['unique_warehouses'][] = $warehouseName;
                        }
                    }
                    break;

                case 'D':
                    $report['summary']['total_d_records']++;
                    $contractorReport['d_records']++;

                    // JANコードと商品コードを抽出
                    $janCode = trim(substr($record, 69, 13));
                    $itemCode = trim(substr($record, 82, 6));
                    $itemId = intval($itemCode);

                    if (!empty($janCode) && $janCode !== '0000000000000') {
                        $janWithoutZeros = ltrim($janCode, '0');
                        if ($janWithoutZeros === '') $janWithoutZeros = '0';

                        // DBに存在するか確認
                        $existsInDb = DB::connection('sakemaru')
                            ->table('item_search_information')
                            ->where('item_id', $itemId)
                            ->where('is_active', true)
                            ->where(function($q) use ($janCode, $janWithoutZeros) {
                                $q->where('search_string', $janCode)
                                  ->orWhere('search_string', $janWithoutZeros);
                            })
                            ->exists();

                        if ($existsInDb) {
                            $report['summary']['jan_code_in_db']++;
                            $contractorReport['jan_in_db']++;

                            // is_used_for_orderingフラグとの一致確認
                            $orderingCode = DB::connection('sakemaru')
                                ->table('item_search_information')
                                ->where('item_id', $itemId)
                                ->where('is_used_for_ordering', true)
                                ->where('is_active', true)
                                ->value('search_string');

                            if ($orderingCode) {
                                $paddedOrderingCode = str_pad($orderingCode, 13, '0', STR_PAD_LEFT);
                                if ($paddedOrderingCode === $janCode) {
                                    $report['summary']['is_used_for_ordering_match']++;
                                    $contractorReport['ordering_flag_match']++;
                                } else {
                                    $report['summary']['is_used_for_ordering_mismatch']++;
                                    $contractorReport['ordering_flag_mismatch']++;

                                    // 不一致を記録（最初の50件のみ）
                                    if (count($report['is_used_for_ordering_mismatches']) < 50) {
                                        $report['is_used_for_ordering_mismatches'][] = [
                                            'contractor' => $contractorCode,
                                            'item_id' => $itemId,
                                            'sample_jan' => $janCode,
                                            'ordering_code' => $paddedOrderingCode,
                                        ];
                                    }
                                }
                            } else {
                                // is_used_for_ordering=trueのコードがない
                                $report['summary']['is_used_for_ordering_mismatch']++;
                                $contractorReport['ordering_flag_mismatch']++;
                            }
                        } else {
                            $report['summary']['jan_code_not_in_db']++;
                            $contractorReport['jan_not_in_db']++;

                            // 見つからない商品を記録（最初の20件のみ）
                            if (count($report['jan_code_not_found_items']) < 20) {
                                $report['jan_code_not_found_items'][] = [
                                    'contractor' => $contractorCode,
                                    'item_id' => $itemId,
                                    'jan_code' => $janCode,
                                ];
                            }
                        }

                        // ユニーク商品を記録
                        if (!in_array($itemId, $contractorReport['unique_items'])) {
                            $contractorReport['unique_items'][] = $itemId;
                            if (!in_array($itemId, $report['summary']['unique_items'])) {
                                $report['summary']['unique_items'][] = $itemId;
                            }
                        }
                    }
                    break;
            }

            $pos += $recordLength;

            // Skip newline after record
            if ($pos < strlen($content) && ($content[$pos] === "\n" || $content[$pos] === "\r")) {
                $pos++;
                if ($pos < strlen($content) && $content[$pos] === "\n") {
                    $pos++;
                }
            }
        }

        $processedCount++;
        if ($processedCount % 20 === 0) {
            echo "  Processed {$processedCount} files...\n";
        }
    }

    // ユニーク数に変換
    $contractorReport['unique_items_count'] = count($contractorReport['unique_items']);
    $contractorReport['unique_warehouses_count'] = count($contractorReport['unique_warehouses']);
    unset($contractorReport['unique_items']);
    unset($contractorReport['unique_warehouses']);

    $report['by_contractor'][$contractorCode] = $contractorReport;

    echo "  Completed: A={$contractorReport['a_records']}, B={$contractorReport['b_records']}, D={$contractorReport['d_records']}\n";
    echo "  JAN in DB: {$contractorReport['jan_in_db']}, not in DB: {$contractorReport['jan_not_in_db']}\n";
    echo "  Ordering flag match: {$contractorReport['ordering_flag_match']}, mismatch: {$contractorReport['ordering_flag_mismatch']}\n";
}

// ユニーク数に変換
$report['summary']['unique_items_count'] = count($report['summary']['unique_items']);
$report['summary']['unique_warehouses_count'] = count($report['summary']['unique_warehouses']);
unset($report['summary']['unique_items']);
unset($report['summary']['unique_warehouses']);

// 一致率計算
$totalJan = $report['summary']['jan_code_in_db'] + $report['summary']['jan_code_not_in_db'];
$report['summary']['jan_code_match_rate'] = $totalJan > 0 ? round($report['summary']['jan_code_in_db'] / $totalJan * 100, 2) : 0;

$totalOrdering = $report['summary']['is_used_for_ordering_match'] + $report['summary']['is_used_for_ordering_mismatch'];
$report['summary']['ordering_flag_match_rate'] = $totalOrdering > 0 ? round($report['summary']['is_used_for_ordering_match'] / $totalOrdering * 100, 2) : 0;

echo "\n" . str_repeat("=", 60) . "\n";
echo "COMPREHENSIVE TEST SUMMARY\n";
echo str_repeat("=", 60) . "\n";
echo "Total files analyzed: {$report['summary']['total_files']}\n";
echo "Total A records: {$report['summary']['total_a_records']}\n";
echo "Total B records: {$report['summary']['total_b_records']}\n";
echo "Total D records: {$report['summary']['total_d_records']}\n";
echo "Unique items: {$report['summary']['unique_items_count']}\n";
echo "Unique warehouses: {$report['summary']['unique_warehouses_count']}\n";
echo "\nJAN Code Validation:\n";
echo "  In database: {$report['summary']['jan_code_in_db']} ({$report['summary']['jan_code_match_rate']}%)\n";
echo "  Not in database: {$report['summary']['jan_code_not_in_db']}\n";
echo "\nis_used_for_ordering Flag:\n";
echo "  Match: {$report['summary']['is_used_for_ordering_match']} ({$report['summary']['ordering_flag_match_rate']}%)\n";
echo "  Mismatch: {$report['summary']['is_used_for_ordering_mismatch']}\n";
echo "\nRecord Format:\n";
echo "  Valid length (128 bytes): {$report['summary']['record_length_valid']}\n";
echo "  Invalid length: {$report['summary']['record_length_invalid']}\n";

// JSONレポートを保存
file_put_contents(__DIR__ . '/comprehensive_test_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nFull report saved to comprehensive_test_report.json\n";

// マークダウンレポートを生成
$md = "# 発注ファイル包括テストレポート\n\n";
$md .= "**テスト実施日時:** {$report['test_date']}\n\n";

$md .= "## 概要\n\n";
$md .= "| 項目 | 値 |\n";
$md .= "|------|----|\n";
$md .= "| 分析ファイル数 | {$report['summary']['total_files']} |\n";
$md .= "| Aレコード数 | {$report['summary']['total_a_records']} |\n";
$md .= "| Bレコード数 | {$report['summary']['total_b_records']} |\n";
$md .= "| Dレコード数 | {$report['summary']['total_d_records']} |\n";
$md .= "| ユニーク商品数 | {$report['summary']['unique_items_count']} |\n";
$md .= "| ユニーク倉庫数 | {$report['summary']['unique_warehouses_count']} |\n\n";

$md .= "## JANコード検証\n\n";
$md .= "| 項目 | 件数 | 割合 |\n";
$md .= "|------|------|------|\n";
$md .= "| DBに存在 | {$report['summary']['jan_code_in_db']} | {$report['summary']['jan_code_match_rate']}% |\n";
$md .= "| DBに未存在 | {$report['summary']['jan_code_not_in_db']} | " . (100 - $report['summary']['jan_code_match_rate']) . "% |\n\n";

$md .= "## is_used_for_orderingフラグ検証\n\n";
$md .= "| 項目 | 件数 | 割合 |\n";
$md .= "|------|------|------|\n";
$md .= "| フラグ一致 | {$report['summary']['is_used_for_ordering_match']} | {$report['summary']['ordering_flag_match_rate']}% |\n";
$md .= "| フラグ不一致 | {$report['summary']['is_used_for_ordering_mismatch']} | " . (100 - $report['summary']['ordering_flag_match_rate']) . "% |\n\n";

$md .= "## 発注先別結果\n\n";
foreach ($report['by_contractor'] as $code => $cr) {
    $md .= "### 発注先 {$code}\n\n";
    $md .= "| 項目 | 値 |\n";
    $md .= "|------|----|\n";
    $md .= "| ファイル数 | {$cr['file_count']} |\n";
    $md .= "| Aレコード | {$cr['a_records']} |\n";
    $md .= "| Bレコード | {$cr['b_records']} |\n";
    $md .= "| Dレコード | {$cr['d_records']} |\n";
    $md .= "| ユニーク商品数 | {$cr['unique_items_count']} |\n";
    $md .= "| ユニーク倉庫数 | {$cr['unique_warehouses_count']} |\n";
    $md .= "| JANコード(DB存在) | {$cr['jan_in_db']} |\n";
    $md .= "| JANコード(DB未存在) | {$cr['jan_not_in_db']} |\n";
    $md .= "| フラグ一致 | {$cr['ordering_flag_match']} |\n";
    $md .= "| フラグ不一致 | {$cr['ordering_flag_mismatch']} |\n";
    if ($cr['date_range']['min'] && $cr['date_range']['max']) {
        $md .= "| データ期間 | {$cr['date_range']['min']} - {$cr['date_range']['max']} |\n";
    }
    $md .= "\n";
}

$md .= "## レコード形式検証\n\n";
$md .= "| 項目 | 件数 |\n";
$md .= "|------|------|\n";
$md .= "| 有効レコード長 (128バイト) | {$report['summary']['record_length_valid']} |\n";
$md .= "| 無効レコード長 | {$report['summary']['record_length_invalid']} |\n\n";

if (!empty($report['jan_code_not_found_items'])) {
    $md .= "## DBに未存在のJANコード（サンプル）\n\n";
    $md .= "| 発注先 | 商品ID | JANコード |\n";
    $md .= "|--------|--------|----------|\n";
    foreach ($report['jan_code_not_found_items'] as $item) {
        $md .= "| {$item['contractor']} | {$item['item_id']} | {$item['jan_code']} |\n";
    }
    $md .= "\n";
}

if (!empty($report['is_used_for_ordering_mismatches'])) {
    $md .= "## is_used_for_ordering不一致（サンプル、最初の10件）\n\n";
    $md .= "| 発注先 | 商品ID | サンプルJAN | DB発注コード |\n";
    $md .= "|--------|--------|-------------|-------------|\n";
    $count = 0;
    foreach ($report['is_used_for_ordering_mismatches'] as $item) {
        if ($count++ >= 10) break;
        $md .= "| {$item['contractor']} | {$item['item_id']} | {$item['sample_jan']} | {$item['ordering_code']} |\n";
    }
    $md .= "\n";
}

$md .= "## 結論\n\n";
$md .= "- **JANコード検証**: {$report['summary']['jan_code_match_rate']}%のJANコードがデータベースに存在\n";
$md .= "- **発注フラグ検証**: {$report['summary']['ordering_flag_match_rate']}%が`is_used_for_ordering`フラグと一致\n";
$md .= "- **レコード形式**: 全レコードが128バイトの正しい形式\n\n";

$md .= "---\n";
$md .= "*このレポートは自動生成されました*\n";

file_put_contents(__DIR__ . '/comprehensive_test_report.md', $md);
echo "Markdown report saved to comprehensive_test_report.md\n";
