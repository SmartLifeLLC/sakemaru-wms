<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 自動発注対象の初期設定シーダー
 *
 * storage/init-data/auto_order_candidates.csv から年間6回以上販売の商品を読み込み、
 * item_contractors.is_auto_order を一括更新する。
 * CSVに含まれる (store_code, item_code) → ON、それ以外 → OFF。
 *
 * 実行方法:
 *   php artisan db:seed --class=AutoOrderCandidateInitSeeder
 */
class AutoOrderCandidateInitSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = storage_path('init-data/auto_order_candidates.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSVファイルが見つかりません: {$csvPath}");

            return;
        }

        $this->command->info('自動発注対象の初期設定を開始します...');

        $db = DB::connection('sakemaru');

        // 一時テーブル作成
        $db->statement('DROP TEMPORARY TABLE IF EXISTS tmp_auto_order_candidates');
        $db->statement('
            CREATE TEMPORARY TABLE tmp_auto_order_candidates (
                store_code INT NOT NULL,
                item_code INT NOT NULL,
                INDEX idx_lookup (store_code, item_code)
            )
        ');

        // CSV読み込み → 一時テーブルに一括INSERT
        $handle = fopen($csvPath, 'r');
        fgetcsv($handle); // skip header

        $batch = [];
        $totalRows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $batch[] = [$row[0], $row[1]];
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
        $this->command->info("CSV読み込み完了: {$totalRows}行");

        // マッチ件数を確認
        $matchCount = $db->selectOne('
            SELECT COUNT(DISTINCT ic.id) as cnt
            FROM item_contractors ic
            JOIN items i ON ic.item_id = i.id
            JOIN warehouses w ON ic.warehouse_id = w.id
            JOIN tmp_auto_order_candidates tmp ON w.code = tmp.store_code AND i.code = tmp.item_code
        ')->cnt;

        $totalCount = $db->selectOne('SELECT COUNT(*) as cnt FROM item_contractors')->cnt;

        $this->command->info('item_contractors 対象件数: '.number_format($totalCount));
        $this->command->info('CSVマッチ（ONにする）: '.number_format($matchCount));
        $this->command->info('OFFにする: '.number_format($totalCount - $matchCount));

        // Step 1: 全件OFFにする
        $offCount = $db->update('UPDATE item_contractors SET is_auto_order = 0 WHERE is_auto_order = 1');
        $this->command->info('  → '.number_format($offCount).'件をOFFに変更');

        // Step 2: CSVマッチをONにする
        $onCount = $db->update('
            UPDATE item_contractors ic
            JOIN items i ON ic.item_id = i.id
            JOIN warehouses w ON ic.warehouse_id = w.id
            JOIN tmp_auto_order_candidates tmp ON w.code = tmp.store_code AND i.code = tmp.item_code
            SET ic.is_auto_order = 1
        ');
        $this->command->info('  → '.number_format($onCount).'件をONに変更');

        // 後片付け
        $db->statement('DROP TEMPORARY TABLE IF EXISTS tmp_auto_order_candidates');

        $this->command->table(
            ['項目', '件数'],
            [
                ['ON（自動発注対象）', number_format($onCount)],
                ['OFF（対象外）', number_format($totalCount - $onCount)],
            ]
        );
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
