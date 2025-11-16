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
     * @param int $waveId
     * @param int $warehouseId
     * @param int $itemId
     * @param int $tradeId
     * @param int $tradeItemId
     * @param int $orderQtyEach 受注数量（PIECE換算済み）
     * @param int $reservedQtyEach 引当数量（PIECE換算済み）
     * @param string $qtyTypeAtOrder 受注単位 (CASE, PIECE, CARTON)
     * @param int $caseSizeSnap ケース入数のスナップショット
     * @param int|null $sourceReservationId 元引当レコードID（トレーサビリティ用）
     * @return WmsShortage|null
     */
    public function detectAndRecord(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $tradeId,
        int $tradeItemId,
        int $orderQtyEach,
        int $reservedQtyEach,
        string $qtyTypeAtOrder,
        int $caseSizeSnap,
        ?int $sourceReservationId = null
    ): ?WmsShortage {
        $remainingEach = $orderQtyEach - $reservedQtyEach;

        // 欠品がない場合は何もしない
        if ($remainingEach <= 0) {
            return null;
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $waveId,
            $warehouseId,
            $itemId,
            $tradeId,
            $tradeItemId,
            $orderQtyEach,
            $reservedQtyEach,
            $remainingEach,
            $qtyTypeAtOrder,
            $caseSizeSnap,
            $sourceReservationId
        ) {
            // 欠品レコード作成
            $shortage = WmsShortage::create([
                'type' => WmsShortage::TYPE_ALLOCATION,
                'wave_id' => $waveId,
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'trade_id' => $tradeId,
                'trade_item_id' => $tradeItemId,
                'order_qty_each' => $orderQtyEach,
                'planned_qty_each' => $reservedQtyEach,
                'picked_qty_each' => 0,
                'shortage_qty_each' => $remainingEach,
                'qty_type_at_order' => $qtyTypeAtOrder,
                'case_size_snap' => $caseSizeSnap,
                'source_reservation_id' => $sourceReservationId,
                'status' => WmsShortage::STATUS_OPEN,
                'reason_code' => WmsShortage::REASON_NO_STOCK,
            ]);

            Log::info('Allocation shortage detected', [
                'shortage_id' => $shortage->id,
                'wave_id' => $waveId,
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'trade_item_id' => $tradeItemId,
                'order_qty_each' => $orderQtyEach,
                'reserved_qty_each' => $reservedQtyEach,
                'shortage_qty_each' => $remainingEach,
            ]);

            return $shortage;
        });
    }
}
