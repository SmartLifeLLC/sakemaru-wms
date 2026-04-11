<?php

namespace Database\Seeders;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsMonthlySafetyStock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 月別発注点の初期データシーダー
 *
 * storage/init-data/monthly_order_points.csv から月別発注点をインポートする。
 * CSVのorder_point_int を wms_monthly_safety_stocks.safety_stock として登録。
 * contractor_id は item_contractors テーブルから (item_id, warehouse_id) で逆引き。
 *
 * 実行方法:
 *   php artisan db:seed --class=MonthlySafetyStockInitSeeder
 */
class MonthlySafetyStockInitSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = storage_path('init-data/monthly_order_points.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSVファイルが見つかりません: {$csvPath}");

            return;
        }

        $this->command->info('月別発注点の初期データインポートを開始します...');

        // マスタデータキャッシュ
        $items = Item::query()->pluck('id', 'code');
        $warehouses = Warehouse::query()->pluck('id', 'code');

        // item_contractors: warehouse_id → (item_id → [contractor_id, ...])
        $itemContractors = [];
        DB::connection('sakemaru')
            ->table('item_contractors')
            ->select(['item_id', 'warehouse_id', 'contractor_id'])
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$itemContractors) {
                foreach ($rows as $row) {
                    $itemContractors[$row->warehouse_id][$row->item_id][] = $row->contractor_id;
                }
            });

        $this->command->info('マスタデータキャッシュ完了');

        // CSV読み込み
        $content = file_get_contents($csvPath);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = array_filter(explode("\n", $content));
        array_shift($lines); // ヘッダースキップ

        $totalRows = count($lines);
        $this->command->info("CSV行数: {$totalRows}");

        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $chunkSize = 1000;
        $upsertBatchSize = 500;
        $chunks = array_chunk($lines, $chunkSize);

        $bar = $this->command->getOutput()->createProgressBar(count($chunks));
        $bar->start();

        foreach ($chunks as $chunk) {
            $upsertBuffer = [];

            DB::beginTransaction();
            try {
                foreach ($chunk as $line) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    $cols = str_getcsv($line, ',', '"', '');

                    if (count($cols) < 10) {
                        $errors++;

                        continue;
                    }

                    $warehouseCode = trim($cols[0]);
                    $itemCode = trim($cols[1]);
                    $month = (int) $cols[2];
                    $orderPointInt = max(0, (int) $cols[9]); // order_point_int

                    $warehouseId = $warehouses[$warehouseCode] ?? null;
                    $itemId = $items[$itemCode] ?? null;

                    if (! $warehouseId || ! $itemId) {
                        $skipped++;

                        continue;
                    }

                    if ($month < 1 || $month > 12) {
                        $errors++;

                        continue;
                    }

                    $contractorIds = $itemContractors[$warehouseId][$itemId] ?? [];

                    if (empty($contractorIds)) {
                        $skipped++;

                        continue;
                    }

                    $now = now()->format('Y-m-d H:i:s');

                    foreach ($contractorIds as $contractorId) {
                        $upsertBuffer[] = [
                            'item_id' => $itemId,
                            'warehouse_id' => $warehouseId,
                            'contractor_id' => $contractorId,
                            'month' => $month,
                            'safety_stock' => $orderPointInt,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $imported++;
                    }

                    if (count($upsertBuffer) >= $upsertBatchSize) {
                        WmsMonthlySafetyStock::upsert(
                            $upsertBuffer,
                            ['item_id', 'warehouse_id', 'contractor_id', 'month'],
                            ['safety_stock', 'updated_at']
                        );
                        $upsertBuffer = [];
                    }
                }

                if (! empty($upsertBuffer)) {
                    WmsMonthlySafetyStock::upsert(
                        $upsertBuffer,
                        ['item_id', 'warehouse_id', 'contractor_id', 'month'],
                        ['safety_stock', 'updated_at']
                    );
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error("チャンクエラー: {$e->getMessage()}");
                Log::error('MonthlySafetyStockInitSeeder エラー', ['error' => $e->getMessage()]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);

        $this->command->table(
            ['項目', '件数'],
            [
                ['upsert成功', number_format($imported)],
                ['スキップ（マスタ不在）', number_format($skipped)],
                ['エラー', number_format($errors)],
            ]
        );
    }
}
