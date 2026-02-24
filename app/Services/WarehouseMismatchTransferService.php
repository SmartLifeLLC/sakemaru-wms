<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 出荷倉庫不一致対応サービス
 *
 * 出荷確定時に、配送コース倉庫と販売倉庫の不一致を検出し、
 * 在庫移動伝票を自動生成する。
 */
class WarehouseMismatchTransferService
{
    /**
     * 出荷確定時に倉庫不一致がある場合、在庫移動伝票キューを作成
     *
     * @return int|null 作成されたキューID（不一致なしまたは既作成の場合null）
     */
    public function createMismatchTransfer(int $earningId): ?int
    {
        // 1. earning取得
        $earning = DB::connection('sakemaru')
            ->table('earnings')
            ->where('id', $earningId)
            ->first();

        if (! $earning) {
            Log::warning('WarehouseMismatchTransfer: earning not found', ['earning_id' => $earningId]);

            return null;
        }

        // 2. 配送コースの倉庫を取得
        $courseWarehouseId = DB::connection('sakemaru')
            ->table('delivery_courses')
            ->where('id', $earning->delivery_course_id)
            ->value('warehouse_id');

        if (! $courseWarehouseId) {
            return null;
        }

        // 3. 実倉庫ベースで不一致チェック
        $earningWarehouseId = $earning->warehouse_id;

        if (WarehouseResolver::isSameRealWarehouse($earningWarehouseId, $courseWarehouseId)) {
            // 同一実倉庫 → 移動不要
            return null;
        }

        // 4. べき等性チェック
        $requestId = "wh-mismatch-{$earningId}";
        $existingQueue = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->exists();

        if ($existingQueue) {
            Log::info('WarehouseMismatchTransfer: already created', [
                'earning_id' => $earningId,
                'request_id' => $requestId,
            ]);

            return null;
        }

        // 5. ピッキング明細取得（横持ち出荷済み明細を除外）
        $pickingItems = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->where('pir.earning_id', $earningId)
            ->where('pir.source_type', 'EARNING')
            ->select('pir.*')
            ->get();

        if ($pickingItems->isEmpty()) {
            return null;
        }

        // 6. 在庫移動伝票キューを作成
        $items = [];
        foreach ($pickingItems as $pickingItem) {
            $item = DB::connection('sakemaru')
                ->table('items')
                ->where('id', $pickingItem->item_id)
                ->first();

            if (! $item) {
                continue;
            }

            $items[] = [
                'item_code' => $item->code,
                'quantity' => $pickingItem->picked_qty > 0 ? $pickingItem->picked_qty : $pickingItem->planned_qty,
                'quantity_type' => $pickingItem->picked_qty_type ?? $pickingItem->planned_qty_type,
                'stock_allocation_code' => '1',
                'note' => "倉庫不一致移動 earning_id:{$earningId}",
            ];
        }

        if (empty($items)) {
            return null;
        }

        // from: 配送コース倉庫（ピッキング倉庫）の実倉庫コード
        $fromWarehouseCode = WarehouseResolver::getRealWarehouseCode($courseWarehouseId);
        // to: 販売倉庫（earningの倉庫）の実倉庫コード
        $toWarehouseCode = WarehouseResolver::getRealWarehouseCode($earningWarehouseId);

        if (! $fromWarehouseCode || ! $toWarehouseCode) {
            Log::warning('WarehouseMismatchTransfer: warehouse code not found', [
                'earning_id' => $earningId,
                'course_warehouse_id' => $courseWarehouseId,
                'earning_warehouse_id' => $earningWarehouseId,
            ]);

            return null;
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $earning,
            $items,
            $requestId,
            $fromWarehouseCode,
            $toWarehouseCode,
            $earningId
        ) {
            $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
                'client_id' => config('app.client_id'),
                'slip_number' => $earning->trade_id,
                'process_date' => now()->format('Y-m-d'),
                'delivered_date' => $earning->delivered_date,
                'note' => '出荷倉庫不一致分',
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'from_warehouse_code' => $fromWarehouseCode,
                'to_warehouse_code' => $toWarehouseCode,
                'request_id' => $requestId,
                'status' => 'BEFORE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('WarehouseMismatchTransfer: queue created', [
                'queue_id' => $queueId,
                'earning_id' => $earningId,
                'from_warehouse' => $fromWarehouseCode,
                'to_warehouse' => $toWarehouseCode,
            ]);

            return $queueId;
        });
    }
}
