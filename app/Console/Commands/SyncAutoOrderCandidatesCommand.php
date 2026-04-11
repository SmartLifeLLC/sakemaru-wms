<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAutoOrderCandidatesCommand extends Command
{
    protected $signature = 'wms:sync-auto-order-candidates
                            {--csv= : CSVファイルパス（デフォルト: storage/init-data/auto_order_candidates.csv）}
                            {--dry-run : 実行せず件数のみ表示}';

    protected $description = 'CSVの商品リストに基づいてitem_contractors.is_auto_orderを一括更新';

    public function handle(): int
    {
        $csvPath = $this->option('csv') ?: storage_path('init-data/auto_order_candidates.csv');
        $dryRun = $this->option('dry-run');

        if (! file_exists($csvPath)) {
            $this->error("CSVファイルが見つかりません: {$csvPath}");

            return self::FAILURE;
        }

        $db = DB::connection('sakemaru');

        // 一時テーブル作成
        $this->info('一時テーブルを作成中...');
        $db->statement('DROP TEMPORARY TABLE IF EXISTS tmp_auto_order_candidates');
        $db->statement('
            CREATE TEMPORARY TABLE tmp_auto_order_candidates (
                store_code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                item_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                INDEX idx_lookup (store_code, item_code)
            )
        ');

        // CSVを一時テーブルに一括INSERT
        $this->info('CSVを読み込み中...');
        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle); // skip header

        $batch = [];
        $totalRows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $batch[] = [$row[0], $row[1]]; // store_code, item_code
            $totalRows++;

            if (count($batch) >= 5000) {
                $this->insertBatch($db, $batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $this->insertBatch($db, $batch);
        }

        fclose($handle);
        $this->info("CSV読み込み完了: {$totalRows}行");

        // マッチ件数を確認
        $matchCount = $db->selectOne('
            SELECT COUNT(DISTINCT ic.id) as cnt
            FROM item_contractors ic
            JOIN items i ON ic.item_id = i.id
            JOIN warehouses w ON ic.warehouse_id = w.id
            JOIN tmp_auto_order_candidates tmp ON CAST(w.code AS CHAR) = tmp.store_code AND CAST(i.code AS CHAR) = tmp.item_code
        ')->cnt;

        $currentOnCount = $db->selectOne('SELECT COUNT(*) as cnt FROM item_contractors WHERE is_auto_order = 1')->cnt;
        $totalCount = $db->selectOne('SELECT COUNT(*) as cnt FROM item_contractors')->cnt;

        $this->info("item_contractors 対象件数: {$totalCount}");
        $this->info("現在 is_auto_order=ON: {$currentOnCount}");
        $this->info("CSVマッチ（ONにする）: {$matchCount}");
        $this->info('OFFにする: '.($totalCount - $matchCount));

        if ($dryRun) {
            $this->warn('dry-runモードのため更新をスキップします');
            $db->statement('DROP TEMPORARY TABLE IF EXISTS tmp_auto_order_candidates');

            return self::SUCCESS;
        }

        if (! $this->confirm("is_auto_orderを更新しますか？（ON: {$matchCount}件 / OFF: ".($totalCount - $matchCount).'件）')) {
            $this->info('キャンセルしました');
            $db->statement('DROP TEMPORARY TABLE IF EXISTS tmp_auto_order_candidates');

            return self::SUCCESS;
        }

        // Step 1: 全件OFFにする
        $this->info('is_auto_order=false に更新中...');
        $offCount = $db->update('UPDATE item_contractors SET is_auto_order = 0 WHERE is_auto_order = 1');
        $this->info("  → {$offCount}件をOFFに変更");

        // Step 2: CSVマッチをONにする
        $this->info('CSVマッチ分を is_auto_order=true に更新中...');
        $onCount = $db->update('
            UPDATE item_contractors ic
            JOIN items i ON ic.item_id = i.id
            JOIN warehouses w ON ic.warehouse_id = w.id
            JOIN tmp_auto_order_candidates tmp ON CAST(w.code AS CHAR) = tmp.store_code AND CAST(i.code AS CHAR) = tmp.item_code
            SET ic.is_auto_order = 1
        ');
        $this->info("  → {$onCount}件をONに変更");

        // 後片付け
        $db->statement('DROP TEMPORARY TABLE IF EXISTS tmp_auto_order_candidates');

        $this->info('完了しました');

        return self::SUCCESS;
    }

    private function insertBatch($db, array $batch): void
    {
        $values = [];
        $bindings = [];

        foreach ($batch as [$storeCode, $itemCode]) {
            $values[] = '(?, ?)';
            $bindings[] = $storeCode;
            $bindings[] = $itemCode;
        }

        $db->insert(
            'INSERT INTO tmp_auto_order_candidates (store_code, item_code) VALUES '.implode(',', $values),
            $bindings
        );
    }
}
