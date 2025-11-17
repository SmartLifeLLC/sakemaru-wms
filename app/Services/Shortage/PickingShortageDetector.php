<?php

namespace App\Services\Shortage;

use App\Models\WmsPickingItemResult;
use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ピッキング時の欠品検出サービス
 * 段階2: PICKING 欠品の生成
 */
class PickingShortageDetector
{
    /**
     * ピッキング完了時に欠品を検出して記録
     *
     * @param WmsPickingItemResult $pickResult
     * @param int|null $parentShortageId 移動出荷の場合の親欠品ID
     * @return WmsShortage|null
     */
    public function detectAndRecord(
        WmsPickingItemResult $pickResult,
        ?int $parentShortageId = null
    ): ?WmsShortage {
        $shortEach = max(0, $pickResult->planned_qty - $pickResult->picked_qty);

        // 欠品がない場合は何もしない
        if ($shortEach <= 0) {
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
            $shortEach,
            $parentShortageId
        ) {
            // picking_item_resultから受注単位とケース入数を取得
            // ordered_qty_typeは必須フィールドなのでnullチェック不要
            if (!$pickResult->ordered_qty_type) {
                throw new \RuntimeException(
                    "ordered_qty_type must be specified for pick result ID {$pickResult->id}"
                );
            }
            $qtyType = $pickResult->ordered_qty_type;
            $caseSize = $pickResult->item?->case_size ?? 1;

            // 既存の欠品レコードを検索または作成
            $shortage = WmsShortage::firstOrCreate(
                [
                    'wave_id' => $task->wave_id,
                    'warehouse_id' => $task->warehouse_id,
                    'item_id' => $pickResult->item_id,
                    'trade_item_id' => $pickResult->trade_item_id,
                    'status' => WmsShortage::STATUS_OPEN,
                ],
                [
                    'trade_id' => $pickResult->trade_id,
                    'order_qty_each' => $pickResult->planned_qty,
                    'planned_qty_each' => $pickResult->planned_qty,
                    'picked_qty_each' => 0,
                    'shortage_qty_each' => 0,
                    'allocation_shortage_qty' => 0,
                    'picking_shortage_qty' => 0,
                    'qty_type_at_order' => $qtyType,
                    'case_size_snap' => $caseSize,
                    'source_pick_result_id' => $pickResult->id,
                    'parent_shortage_id' => $parentShortageId,
                    'reason_code' => WmsShortage::REASON_NO_STOCK,
                ]
            );

            // 欠品数量を加算
            if (!$shortage->wasRecentlyCreated) {
                $shortage->increment('shortage_qty_each', $shortEach);
                $shortage->increment('picking_shortage_qty', $shortEach);
                $shortage->picked_qty_each = $pickResult->picked_qty;
                $shortage->save();
            } else {
                $shortage->shortage_qty_each = $shortEach;
                $shortage->picking_shortage_qty = $shortEach;
                $shortage->picked_qty_each = $pickResult->picked_qty;
                $shortage->save();
            }

            Log::info('Picking shortage detected', [
                'shortage_id' => $shortage->id,
                'pick_result_id' => $pickResult->id,
                'wave_id' => $task->wave_id,
                'warehouse_id' => $task->warehouse_id,
                'item_id' => $pickResult->item_id,
                'trade_item_id' => $pickResult->trade_item_id,
                'planned_qty' => $pickResult->planned_qty,
                'picked_qty' => $pickResult->picked_qty,
                'shortage_qty_each' => $shortEach,
                'parent_shortage_id' => $parentShortageId,
            ]);

            return $shortage;
        });
    }
}
