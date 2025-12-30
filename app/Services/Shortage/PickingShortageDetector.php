<?php

namespace App\Services\Shortage;

use App\Models\WmsPickingItemResult;
use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ピッキング完了時の欠品検出・記録サービス
 *
 * wms_picking_item_results.status=COMPLETEDに変わるタイミングで
 * wms_shortagesを生成する
 *
 * 欠品数量の計算:
 * - shortage_qty = order_qty - picked_qty (総欠品数)
 * - allocation_shortage_qty = order_qty - planned_qty (引当欠品数)
 * - picking_shortage_qty = planned_qty - picked_qty (ピッキング欠品数)
 */
class PickingShortageDetector
{
    /**
     * ピッキング完了時に欠品を検出して記録
     *
     * @param WmsPickingItemResult $pickResult
     * @param int|null $parentShortageId 横持ち出荷の場合の親欠品ID
     * @return WmsShortage|null
     */
    public function detectAndRecord(
        WmsPickingItemResult $pickResult,
        ?int $parentShortageId = null
    ): ?WmsShortage {
        // 欠品数量を計算
        // shortage_qty = order_qty - picked_qty
        $orderQty = $pickResult->ordered_qty;
        $plannedQty = $pickResult->planned_qty;
        $pickedQty = $pickResult->picked_qty;

        $shortageQty = max(0, $orderQty - $pickedQty);

        // 欠品がない場合は何もしない
        if ($shortageQty <= 0) {
            return null;
        }

        $task = $pickResult->pickingTask;
        if (!$task) {
            Log::warning('Picking task not found for pick result', [
                'pick_result_id' => $pickResult->id,
            ]);
            return null;
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $pickResult,
            $task,
            $orderQty,
            $plannedQty,
            $pickedQty,
            $shortageQty,
            $parentShortageId
        ) {
            // picking_item_resultから受注単位とケース入数を取得
            if (!$pickResult->ordered_qty_type) {
                throw new \RuntimeException(
                    "ordered_qty_type must be specified for pick result ID {$pickResult->id}"
                );
            }
            $qtyType = $pickResult->ordered_qty_type;
            $caseSize = $pickResult->item?->capacity_case ?? 1;

            // earningをtrade_idから取得
            $earning = DB::connection('sakemaru')
                ->table('earnings')
                ->where('trade_id', $pickResult->trade_id)
                ->first();

            $earningId = $earning?->id;
            $shipmentDate = $earning?->delivered_date;
            $deliveryCourseId = $earning?->delivery_course_id;

            // 欠品数量を計算
            $allocationShortageQty = max(0, $orderQty - $plannedQty);
            $pickingShortageQty = max(0, $plannedQty - $pickedQty);

            // 既存の欠品レコードを検索
            $existingShortage = WmsShortage::where('wave_id', $task->wave_id)
                ->where('warehouse_id', $task->warehouse_id)
                ->where('item_id', $pickResult->item_id)
                ->where('trade_item_id', $pickResult->trade_item_id)
                ->first();

            if ($existingShortage) {
                // 既存レコードを更新
                $existingShortage->update([
                    'order_qty' => $orderQty,
                    'planned_qty' => $plannedQty,
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'allocation_shortage_qty' => $allocationShortageQty,
                    'picking_shortage_qty' => $pickingShortageQty,
                    'source_pick_result_id' => $pickResult->id,
                ]);

                Log::info('Existing shortage updated at picking completion', [
                    'shortage_id' => $existingShortage->id,
                    'pick_result_id' => $pickResult->id,
                    'order_qty' => $orderQty,
                    'planned_qty' => $plannedQty,
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'allocation_shortage_qty' => $allocationShortageQty,
                    'picking_shortage_qty' => $pickingShortageQty,
                ]);

                return $existingShortage;
            }

            // 新規欠品レコード作成
            $shortage = WmsShortage::create([
                'wave_id' => $task->wave_id,
                'shipment_date' => $shipmentDate,
                'warehouse_id' => $task->warehouse_id,
                'item_id' => $pickResult->item_id,
                'trade_id' => $pickResult->trade_id,
                'earning_id' => $earningId,
                'delivery_course_id' => $deliveryCourseId,
                'trade_item_id' => $pickResult->trade_item_id,
                'order_qty' => $orderQty,
                'planned_qty' => $plannedQty,
                'picked_qty' => $pickedQty,
                'shortage_qty' => $shortageQty,
                'allocation_shortage_qty' => $allocationShortageQty,
                'picking_shortage_qty' => $pickingShortageQty,
                'qty_type_at_order' => $qtyType,
                'case_size_snap' => $caseSize,
                'source_pick_result_id' => $pickResult->id,
                'parent_shortage_id' => $parentShortageId,
                'status' => WmsShortage::STATUS_BEFORE,
                'reason_code' => WmsShortage::REASON_NO_STOCK,
            ]);

            Log::info('Shortage created at picking completion', [
                'shortage_id' => $shortage->id,
                'pick_result_id' => $pickResult->id,
                'wave_id' => $task->wave_id,
                'warehouse_id' => $task->warehouse_id,
                'item_id' => $pickResult->item_id,
                'trade_item_id' => $pickResult->trade_item_id,
                'order_qty' => $orderQty,
                'planned_qty' => $plannedQty,
                'picked_qty' => $pickedQty,
                'shortage_qty' => $shortageQty,
                'allocation_shortage_qty' => $allocationShortageQty,
                'picking_shortage_qty' => $pickingShortageQty,
                'qty_type' => $qtyType,
                'parent_shortage_id' => $parentShortageId,
            ]);

            return $shortage;
        });
    }
}
