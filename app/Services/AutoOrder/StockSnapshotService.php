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
     * 倉庫の在庫スナップショットを生成
     *
     * @param  int|null  $warehouseId  指定時はその倉庫のみ生成
     */
    public function generateAll(?int $warehouseId = null): WmsAutoOrderJobControl
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

            // 倉庫指定時はその倉庫のみに絞り込む
            if ($warehouseId !== null) {
                $warehouseIds = array_intersect($warehouseIds, [$warehouseId]);
            }

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
     * 入荷予定数は wms_order_incoming_schedules から集計
     * 過去のスナップショットは削除せず、job_control_id で紐付けて保持
     */
    public function generateSnapshots(array $warehouseIds, ?WmsAutoOrderJobControl $job = null): int
    {
        $snapshotAt = now()->format('Y-m-d H:i:s');
        $jobControlId = $job?->id ?? 'NULL';

        // INSERT INTO ... SELECT で一括処理
        $warehouseIdsList = implode(',', $warehouseIds);

        // 入荷予定数を含めたスナップショット生成
        // wms_order_incoming_schedules から PENDING/PARTIAL ステータスの残数量を集計
        // 注意: wms_v_stock_available はロット毎に行が複製されるため、
        //       real_stock_id で重複排除してから集計する
        $sql = "
            INSERT INTO wms_item_stock_snapshots
                (job_control_id, warehouse_id, item_id, snapshot_at, total_effective_piece, total_non_effective_piece, total_incoming_piece, created_at, updated_at)
            SELECT
                {$jobControlId} as job_control_id,
                COALESCE(s.warehouse_id, i.warehouse_id) as warehouse_id,
                COALESCE(s.item_id, i.item_id) as item_id,
                '{$snapshotAt}' as snapshot_at,
                COALESCE(s.total_effective, 0) as total_effective_piece,
                0 as total_non_effective_piece,
                COALESCE(i.total_incoming, 0) as total_incoming_piece,
                '{$snapshotAt}' as created_at,
                '{$snapshotAt}' as updated_at
            FROM (
                -- 現在の有効在庫を集計（real_stock_id毎に重複排除してから集計）
                SELECT
                    warehouse_id,
                    item_id,
                    SUM(stock_qty) as total_effective
                FROM (
                    SELECT DISTINCT
                        warehouse_id,
                        item_id,
                        real_stock_id,
                        available_for_wms as stock_qty
                    FROM wms_v_stock_available
                    WHERE warehouse_id IN ({$warehouseIdsList})
                ) dedup
                GROUP BY warehouse_id, item_id
            ) s
            LEFT JOIN (
                -- 入荷予定数を集計（PENDING/PARTIAL のみ、残数量）
                SELECT
                    warehouse_id,
                    item_id,
                    SUM(expected_quantity - received_quantity) as total_incoming
                FROM wms_order_incoming_schedules
                WHERE warehouse_id IN ({$warehouseIdsList})
                  AND status IN ('PENDING', 'PARTIAL')
                GROUP BY warehouse_id, item_id
            ) i ON s.warehouse_id = i.warehouse_id AND s.item_id = i.item_id

            UNION

            -- 在庫がないが入荷予定がある商品
            SELECT
                {$jobControlId} as job_control_id,
                i.warehouse_id,
                i.item_id,
                '{$snapshotAt}' as snapshot_at,
                0 as total_effective_piece,
                0 as total_non_effective_piece,
                i.total_incoming as total_incoming_piece,
                '{$snapshotAt}' as created_at,
                '{$snapshotAt}' as updated_at
            FROM (
                SELECT
                    warehouse_id,
                    item_id,
                    SUM(expected_quantity - received_quantity) as total_incoming
                FROM wms_order_incoming_schedules
                WHERE warehouse_id IN ({$warehouseIdsList})
                  AND status IN ('PENDING', 'PARTIAL')
                GROUP BY warehouse_id, item_id
            ) i
            LEFT JOIN (
                SELECT DISTINCT warehouse_id, item_id
                FROM wms_v_stock_available
                WHERE warehouse_id IN ({$warehouseIdsList})
            ) s ON s.warehouse_id = i.warehouse_id AND s.item_id = i.item_id
            WHERE s.warehouse_id IS NULL
        ";

        DB::connection('sakemaru')->statement($sql);

        $processedCount = WmsItemStockSnapshot::where('job_control_id', $job?->id)->count();

        if ($job) {
            $job->update(['total_records' => $processedCount]);
        }

        return $processedCount;
    }

    /**
     * 入荷予定数を取得
     *
     * wms_order_incoming_schedules から PENDING/PARTIAL ステータスの残数量を取得
     *
     * @param  array  $warehouseIds  倉庫ID配列
     * @return array ['warehouse_id-item_id' => quantity]
     */
    public function getIncomingStocks(array $warehouseIds): array
    {
        if (empty($warehouseIds)) {
            return [];
        }

        $results = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('warehouse_id, item_id, SUM(expected_quantity - received_quantity) as incoming_qty')
            ->groupBy('warehouse_id', 'item_id')
            ->get();

        $stocks = [];
        foreach ($results as $row) {
            $key = "{$row->warehouse_id}-{$row->item_id}";
            $stocks[$key] = (int) $row->incoming_qty;
        }

        return $stocks;
    }
}
