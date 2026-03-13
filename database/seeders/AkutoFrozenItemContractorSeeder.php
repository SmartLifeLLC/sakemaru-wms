<?php

namespace Database\Seeders;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * アクト中食冷凍商品のitem_contractors設定シーダー
 *
 * 対象24商品について以下を設定:
 * 1. オレンジ倉庫(901) × アクト中食(1497) のEXTERNAL発注設定
 * 2. サテライト12倉庫の contractor_id を 1497→9901 に変更（INTERNAL移動依頼用）
 * 3. 倉庫91の is_auto_order を false に無効化
 *
 * 実行方法:
 *   php artisan db:seed --class=AkutoFrozenItemContractorSeeder
 */
class AkutoFrozenItemContractorSeeder extends Seeder
{
    private const AKUTO_CONTRACTOR_ID = 1497;

    private const AKUTO_SUPPLIER_ID = 8901;

    private const ORANGE_CONTRACTOR_ID = 9901;

    private const ORANGE_WAREHOUSE_CODE = '901';

    private const SATELLITE_WAREHOUSE_IDS = [1, 2, 3, 4, 7, 8, 9, 10, 11, 21, 22, 23];

    private const WAREHOUSE_91_ID = 91;

    public function run(): void
    {
        $this->command->info('アクト中食冷凍商品のitem_contractors設定を開始します...');

        // Step 1: CSVファイル読み込み＋item_id取得
        $itemData = $this->loadItemsFromCsv();
        if ($itemData->isEmpty()) {
            $this->command->error('  [ERROR] 対象商品が見つかりません');

            return;
        }

        $orangeWarehouse = Warehouse::where('code', self::ORANGE_WAREHOUSE_CODE)->first();
        if (! $orangeWarehouse) {
            $this->command->error('  [ERROR] オレンジ冷凍倉庫(901)が見つかりません。先にOrangeWarehouseSeederを実行してください');

            return;
        }

        // Step 2: オレンジ倉庫(901) × アクト中食(1497) のEXTERNAL発注設定
        $this->createOrangeItemContractors($orangeWarehouse->id, $itemData);

        // Step 3: サテライト倉庫の contractor_id 変更（1497→9901）
        $this->updateSatelliteContractors($itemData->pluck('item_id')->toArray());

        // Step 4: 倉庫91の is_auto_order 無効化
        $this->disableWarehouse91AutoOrder($itemData->pluck('item_id')->toArray());

        $this->command->info('アクト中食冷凍商品のitem_contractors設定が完了しました');
    }

    private function loadItemsFromCsv(): \Illuminate\Support\Collection
    {
        $csvPath = storage_path('seeders/akuto-frozoz-items.csv');
        if (! file_exists($csvPath)) {
            $this->command->error("  [ERROR] CSVファイルが見つかりません: {$csvPath}");

            return collect();
        }

        $rows = array_map('str_getcsv', file($csvPath));
        $header = array_shift($rows);

        $codes = [];
        $safetyStocks = [];
        foreach ($rows as $row) {
            if (empty($row[0])) {
                continue;
            }
            $code = mb_convert_kana(trim($row[0]), 'n');
            $codes[] = $code;
            // 入数カラム（index 3）: 全角→半角変換
            $safetyStocks[$code] = (int) mb_convert_kana(trim($row[3] ?? '0'), 'n');
        }

        // items テーブルから item_id を一括取得
        $items = DB::connection('sakemaru')
            ->table('items')
            ->whereIn('code', $codes)
            ->pluck('id', 'code');

        $result = collect();
        foreach ($codes as $code) {
            if (isset($items[$code])) {
                $result->push([
                    'code' => $code,
                    'item_id' => $items[$code],
                    'safety_stock' => $safetyStocks[$code],
                ]);
            } else {
                $this->command->warn("  [WARN] items テーブルに商品コード {$code} が見つかりません");
            }
        }

        $this->command->info("  [OK] CSV読み込み完了: {$result->count()}商品");

        return $result;
    }

    private function createOrangeItemContractors(int $orangeWarehouseId, \Illuminate\Support\Collection $itemData): void
    {
        $count = 0;
        foreach ($itemData as $item) {
            DB::connection('sakemaru')->table('item_contractors')->updateOrInsert(
                [
                    'warehouse_id' => $orangeWarehouseId,
                    'item_id' => $item['item_id'],
                    'contractor_id' => self::AKUTO_CONTRACTOR_ID,
                ],
                [
                    'client_id' => 6,
                    'supplier_id' => self::AKUTO_SUPPLIER_ID,
                    'is_auto_order' => true,
                    'safety_stock' => $item['safety_stock'],
                    'updated_at' => now(),
                ]
            );
            $count++;
        }

        $this->command->info("  [OK] オレンジ倉庫(901) × アクト中食(1497): {$count}件 updateOrInsert");
    }

    private function updateSatelliteContractors(array $itemIds): void
    {
        $updated = DB::connection('sakemaru')->table('item_contractors')
            ->whereIn('warehouse_id', self::SATELLITE_WAREHOUSE_IDS)
            ->whereIn('item_id', $itemIds)
            ->where('contractor_id', self::AKUTO_CONTRACTOR_ID)
            ->update([
                'contractor_id' => self::ORANGE_CONTRACTOR_ID,
                'supplier_id' => self::ORANGE_CONTRACTOR_ID,
                'is_auto_order' => true,
                'updated_at' => now(),
            ]);

        $this->command->info("  [OK] サテライト12倉庫 contractor_id 1497→9901: {$updated}件更新");
    }

    private function disableWarehouse91AutoOrder(array $itemIds): void
    {
        $updated = DB::connection('sakemaru')->table('item_contractors')
            ->where('warehouse_id', self::WAREHOUSE_91_ID)
            ->whereIn('item_id', $itemIds)
            ->where('contractor_id', self::AKUTO_CONTRACTOR_ID)
            ->update([
                'is_auto_order' => false,
                'updated_at' => now(),
            ]);

        $this->command->info("  [OK] 倉庫91 is_auto_order=false: {$updated}件更新");
    }
}
