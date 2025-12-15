<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\JobStatus;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Models\WmsWarehouseItemTotalStock;
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
     */
    public function generateSnapshots(array $warehouseIds, ?WmsAutoOrderJobControl $job = null): int
    {
        $snapshotAt = now();
        $processedCount = 0;

        // wms_v_stock_available ビューから有効在庫を集計
        // 倉庫×商品ごとにグループ化
        $stocks = DB::connection('sakemaru')
            ->table('wms_v_stock_available')
            ->select([
                'warehouse_id',
                'item_id',
                DB::raw('SUM(available_piece) as total_effective_piece'),
            ])
            ->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('warehouse_id', 'item_id')
            ->get();

        $totalRecords = $stocks->count();
        if ($job) {
            $job->update(['total_records' => $totalRecords]);
        }

        // 入荷予定数を取得（発注残）
        $incomingStocks = $this->getIncomingStocks($warehouseIds);

        foreach ($stocks as $stock) {
            $incomingPiece = $incomingStocks[$stock->warehouse_id][$stock->item_id] ?? 0;

            WmsWarehouseItemTotalStock::updateOrCreate(
                [
                    'warehouse_id' => $stock->warehouse_id,
                    'item_id' => $stock->item_id,
                ],
                [
                    'snapshot_at' => $snapshotAt,
                    'total_effective_piece' => $stock->total_effective_piece,
                    'total_non_effective_piece' => 0, // TODO: 期限切れ在庫の集計
                    'total_incoming_piece' => $incomingPiece,
                ]
            );

            $processedCount++;

            if ($job && $processedCount % 100 === 0) {
                $job->updateProgress($processedCount, $totalRecords);
            }
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
