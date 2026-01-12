<?php

namespace App\Services\Shortage;

use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 横持ち出荷完了時に倉庫移動伝票キューを作成するサービス
 */
class StockTransferQueueService
{
    /**
     * 横持ち出荷完了時に倉庫移動伝票キューを作成
     *
     * @return int|null 作成されたキューID
     *
     * @throws \Exception
     */
    public function createStockTransferQueue(WmsShortageAllocation $allocation): ?int
    {
        // ピック数が0の場合は何もしない
        if ($allocation->picked_qty <= 0) {
            Log::info('No stock transfer queue created (picked_qty = 0)', [
                'allocation_id' => $allocation->id,
            ]);

            return null;
        }

        // shortage経由でtradeを取得
        $shortage = $allocation->shortage;
        if (! $shortage) {
            throw new \Exception("Shortage not found for allocation ID {$allocation->id}");
        }

        $trade = $shortage->trade;
        if (! $trade) {
            throw new \Exception("Trade not found for shortage ID {$shortage->id}");
        }

        // target_warehouse (横持ち出荷倉庫) と source_warehouse (元倉庫) のコードを取得
        $targetWarehouse = $allocation->targetWarehouse;
        $sourceWarehouse = $allocation->sourceWarehouse;

        if (! $targetWarehouse || ! $sourceWarehouse) {
            throw new \Exception("Warehouse not found: target={$allocation->target_warehouse_id}, source={$allocation->source_warehouse_id}");
        }

        // itemの取得
        $item = $shortage->item;
        if (! $item) {
            throw new \Exception("Item not found for shortage ID {$shortage->id}");
        }

        // items配列を作成
        $items = [
            [
                'item_code' => $item->code,
                'quantity' => $allocation->picked_qty,
                'quantity_type' => $allocation->assign_qty_type,
                'stock_allocation_code' => '1', // デフォルトの在庫区分コード（通常在庫）
                'purchase_price' => (float) $allocation->purchase_price,
                'note' => "横持ち出荷ID: {$allocation->id}",
            ],
        ];

        // request_idにはwms_shortage_allocations.idを使用
        $requestId = (string) $allocation->id;

        return DB::connection('sakemaru')->transaction(function () use (
            $allocation,
            $trade,
            $targetWarehouse,
            $sourceWarehouse,
            $items,
            $requestId
        ) {
            // stock_transfer_queueレコード作成
            $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
                'client_id' => config('app.client_id'),
                'slip_number' => $trade->serial_id, // trades.serial_idを使用
                'process_date' => $allocation->shipment_date,
                'delivered_date' => $allocation->shipment_date,
                'note' => '横持ち出荷分',
                'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                'from_warehouse_code' => $targetWarehouse->code, // 横持ち出荷倉庫から
                'to_warehouse_code' => $sourceWarehouse->code,   // 元倉庫へ
                'request_id' => $requestId,
                'status' => 'BEFORE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Stock transfer queue created for horizontal shipment', [
                'queue_id' => $queueId,
                'allocation_id' => $allocation->id,
                'slip_number' => $trade->serial_id,
                'from_warehouse' => $targetWarehouse->code,
                'to_warehouse' => $sourceWarehouse->code,
                'picked_qty' => $allocation->picked_qty,
                'quantity_type' => $allocation->assign_qty_type,
            ]);

            return $queueId;
        });
    }
}
