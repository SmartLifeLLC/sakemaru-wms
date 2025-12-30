<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\JobProcessName;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsItemStockSnapshot;
use App\Models\WmsWarehouseAutoOrderSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 在庫スナップショット生成サービス
 */
class StockSnapshotService
{
    /**
     * 全倉庫の在庫スナップショットを生成
     */
    public function generateAll(): WmsAutoOrderJobControl
    {
        // 既に実行中のジョブがあればエラー
        if (WmsAutoOrderJobControl::hasRunningJob(JobProcessName::STOCK_SNAPSHOT)) {
            throw new \RuntimeException('Stock snapshot job is already running');
        }

        $job = WmsAutoOrderJobControl::startJob(JobProcessName::STOCK_SNAPSHOT);

        try {
            // 自動発注有効な倉庫を取得
            $warehouseIds = WmsWarehouseAutoOrderSetting::enabled()
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($warehouseIds)) {
                Log::info('No warehouses enabled for auto order');
                $job->markAsSuccess(0);
                return $job;
            }

            $processedCount = $this->generateSnapshots($warehouseIds, $job);

            $job->markAsSuccess($processedCount);

            Log::info('Stock snapshot completed', [
                'batch_code' => $job->batch_code,
                'processed_count' => $processedCount,
            ]);

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            Log::error('Stock snapshot failed', [
                'batch_code' => $job->batch_code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $job;
    }

    /**
     * 指定倉庫の在庫スナップショットを生成
     *
     * INSERT INTO ... SELECT で一括処理（高速）
     */
    public function generateSnapshots(array $warehouseIds, ?WmsAutoOrderJobControl $job = null): int
    {
        $snapshotAt = now()->format('Y-m-d H:i:s');

        // 既存データをTRUNCATE
        WmsItemStockSnapshot::truncate();

        // INSERT INTO ... SELECT で一括処理
        $warehouseIdsList = implode(',', $warehouseIds);

        $sql = "
            INSERT INTO wms_item_stock_snapshots
                (warehouse_id, item_id, snapshot_at, total_effective_piece, total_non_effective_piece, total_incoming_piece, created_at, updated_at)
            SELECT
                warehouse_id,
                item_id,
                '{$snapshotAt}' as snapshot_at,
                SUM(available_for_wms) as total_effective_piece,
                0 as total_non_effective_piece,
                0 as total_incoming_piece,
                '{$snapshotAt}' as created_at,
                '{$snapshotAt}' as updated_at
            FROM wms_v_stock_available
            WHERE warehouse_id IN ({$warehouseIdsList})
            GROUP BY warehouse_id, item_id
        ";

        DB::connection('sakemaru')->statement($sql);

        $processedCount = WmsItemStockSnapshot::count();

        if ($job) {
            $job->update(['total_records' => $processedCount]);
        }

        return $processedCount;
    }

    /**
     * 入荷予定数を取得
     * TODO: 実際の発注残テーブルから取得する処理を実装
     */
    private function getIncomingStocks(array $warehouseIds): array
    {
        // 現時点では空の配列を返す
        // 将来的には発注残テーブルから集計
        return [];
    }
}
