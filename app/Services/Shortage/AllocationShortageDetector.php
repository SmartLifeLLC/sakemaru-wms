<?php

namespace App\Services\Shortage;

use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 引当時の欠品検出サービス
 * 段階1: ALLOCATION 欠品の生成
 */
class AllocationShortageDetector
{
    /**
     * 引当処理後に欠品を検出して記録
     *
     * @param  int  $orderQty  受注数量（受注単位ベース）
     * @param  int  $reservedQty  引当数量（受注単位ベース）
     * @param  string  $qtyTypeAtOrder  受注単位 (CASE, PIECE, CARTON)
     * @param  int  $caseSizeSnap  ケース入数のスナップショット
     * @param  int|null  $sourceReservationId  元引当レコードID（トレーサビリティ用）
     */
    public function detectAndRecord(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $tradeId,
        int $tradeItemId,
        int $orderQty,
        int $reservedQty,
        string $qtyTypeAtOrder,
        int $caseSizeSnap,
        ?int $sourceReservationId = null
    ): ?WmsShortage {
        $shortageQty = $orderQty - $reservedQty;

        // 欠品がない場合は何もしない
        if ($shortageQty <= 0) {
            return null;
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $waveId,
            $warehouseId,
            $itemId,
            $tradeId,
            $tradeItemId,
            $orderQty,
            $reservedQty,
            $shortageQty,
            $qtyTypeAtOrder,
            $caseSizeSnap,
            $sourceReservationId
        ) {
            // earningをtrade_idから取得
            $earning = DB::connection('sakemaru')
                ->table('earnings')
                ->where('trade_id', $tradeId)
                ->first();

            $earningId = $earning?->id;
            $shipmentDate = $earning?->delivered_date;  // earnings.delivered_dateから取得
            $deliveryCourseId = $earning?->delivery_course_id;

            // 欠品レコード作成（受注単位ベース）
            $shortage = WmsShortage::create([
                'wave_id' => $waveId,
                'shipment_date' => $shipmentDate,
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'trade_id' => $tradeId,
                'earning_id' => $earningId,
                'delivery_course_id' => $deliveryCourseId,
                'trade_item_id' => $tradeItemId,
                'order_qty' => $orderQty,
                'planned_qty' => $reservedQty,
                'picked_qty' => 0,
                'shortage_qty' => $shortageQty,
                'allocation_shortage_qty' => $shortageQty,
                'picking_shortage_qty' => 0,
                'qty_type_at_order' => $qtyTypeAtOrder,
                'case_size_snap' => $caseSizeSnap,
                'source_reservation_id' => $sourceReservationId,
                'status' => WmsShortage::STATUS_BEFORE,
                'reason_code' => WmsShortage::REASON_NO_STOCK,
            ]);

            Log::info('Allocation shortage detected', [
                'shortage_id' => $shortage->id,
                'wave_id' => $waveId,
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'trade_item_id' => $tradeItemId,
                'order_qty' => $orderQty,
                'reserved_qty' => $reservedQty,
                'shortage_qty' => $shortageQty,
            ]);

            return $shortage;
        });
    }
}
