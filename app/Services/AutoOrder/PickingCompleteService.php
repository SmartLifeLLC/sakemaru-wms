<?php

namespace App\Services\AutoOrder;

use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ピッキング完了サービス
 *
 * 倉庫間移動のピッキング完了時の処理
 * - stock_transfer_queue (action_type=UPDATE) を作成（数量差異がある場合）
 * - WmsOrderIncomingSchedule.expected_quantity を更新
 */
class PickingCompleteService
{
    /**
     * ピッキング完了時の処理
     *
     * @param  int  $stockTransferId  stock_transfers.id
     * @param  int  $pickedQuantity  実績数量
     * @param  int  $originalQuantity  元の予定数量
     * @param  string  $quantityType  UNIT/CASE/CARTON
     * @param  int  $incomingScheduleId  WmsOrderIncomingSchedule.id
     */
    public function handlePickingComplete(
        int $stockTransferId,
        int $pickedQuantity,
        int $originalQuantity,
        string $quantityType,
        int $incomingScheduleId
    ): void {
        DB::connection('sakemaru')->transaction(function () use (
            $stockTransferId, $pickedQuantity, $originalQuantity, $quantityType, $incomingScheduleId
        ) {
            // 1. stock_transfer_queue (UPDATE) を作成（差異がある場合のみ）
            if ($pickedQuantity !== $originalQuantity) {
                $this->createUpdateQueue(
                    $stockTransferId,
                    $pickedQuantity,
                    $originalQuantity,
                    $quantityType
                );
            }

            // 2. WmsOrderIncomingSchedule.expected_quantity を更新
            // ※ 入荷検品時の差異計算のため、ピッキング実績を反映
            WmsOrderIncomingSchedule::where('id', $incomingScheduleId)
                ->update(['expected_quantity' => $pickedQuantity]);

            Log::info('Picking complete processed', [
                'stock_transfer_id' => $stockTransferId,
                'original_quantity' => $originalQuantity,
                'picked_quantity' => $pickedQuantity,
                'incoming_schedule_id' => $incomingScheduleId,
                'has_difference' => $pickedQuantity !== $originalQuantity,
            ]);
        });
    }

    /**
     * stock_transfer_queue (UPDATE) を作成
     *
     * @param  int  $stockTransferId  stock_transfers.id
     * @param  int  $pickedQuantity  実績数量
     * @param  int  $originalQuantity  元の予定数量
     * @param  string  $quantityType  UNIT/CASE/CARTON
     */
    private function createUpdateQueue(
        int $stockTransferId,
        int $pickedQuantity,
        int $originalQuantity,
        string $quantityType
    ): void {
        $requestId = "transfer-update-{$stockTransferId}-".now()->format('YmdHis');

        DB::connection('sakemaru')->table('stock_transfer_queue')->insert([
            'client_id' => config('app.client_id'),
            'request_id' => $requestId,
            'stock_transfer_id' => $stockTransferId,
            'items' => json_encode([
                'picked_quantity' => $pickedQuantity,
                'original_quantity' => $originalQuantity,
                'quantity_type' => $quantityType,
            ], JSON_UNESCAPED_UNICODE),
            'note' => 'ピッキング完了 数量更新',
            'status' => 'BEFORE',
            'action_type' => 'UPDATE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Update queue created', [
            'stock_transfer_id' => $stockTransferId,
            'request_id' => $requestId,
            'picked_quantity' => $pickedQuantity,
            'original_quantity' => $originalQuantity,
            'difference' => $originalQuantity - $pickedQuantity,
        ]);
    }
}
