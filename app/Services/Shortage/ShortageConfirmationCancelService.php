<?php

namespace App\Services\Shortage;

use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 欠品処理確定取り消しサービス
 * 確定済みの欠品処理を取り消す
 */
class ShortageConfirmationCancelService
{
    /**
     * 欠品処理確定を取り消す
     *
     * @param WmsShortage $shortage 欠品レコード
     * @return void
     */
    public function cancel(WmsShortage $shortage): void
    {
        // CONFIRMED以外は処理しない
        if ($shortage->status !== WmsShortage::STATUS_CONFIRMED) {
            throw new \RuntimeException(
                "Shortage ID {$shortage->id} is not confirmed (status: {$shortage->status})"
            );
        }

        DB::connection('sakemaru')->transaction(function () use ($shortage) {
            // 1. 対応するピッキング結果を取得
            $pickResult = WmsPickingItemResult::where('trade_item_id', $shortage->trade_item_id)
                ->whereHas('pickingTask', function ($query) use ($shortage) {
                    $query->where('wave_id', $shortage->wave_id);
                })
                ->first();

            if (!$pickResult) {
                throw new \RuntimeException(
                    "Picking item result not found for shortage ID {$shortage->id}"
                );
            }

            // 2. shortage_allocated_qtyとshortage_allocated_qty_typeをクリア
            $pickResult->shortage_allocated_qty = 0;
            $pickResult->shortage_allocated_qty_type = null;

            // 3. is_ready_to_shipmentとshipment_ready_atをクリア
            $pickResult->is_ready_to_shipment = false;
            $pickResult->shipment_ready_at = null;

            $pickResult->save();

            // 4. 欠品レコードのステータスをREALLOCATINGまたはOPENに戻す
            // 移動出荷指示が存在する場合はREALLOCATING、なければOPEN
            $hasAllocations = $shortage->allocations()
                ->whereNotIn('status', ['CANCELLED'])
                ->exists();

            $shortage->status = $hasAllocations
                ? WmsShortage::STATUS_REALLOCATING
                : WmsShortage::STATUS_OPEN;
            $shortage->confirmed_user_id = null;
            $shortage->confirmed_at = null;
            $shortage->updater_id = auth()->id();
            $shortage->save();

            Log::info('Shortage confirmation cancelled', [
                'shortage_id' => $shortage->id,
                'pick_result_id' => $pickResult->id,
                'new_status' => $shortage->status,
            ]);

            // 5. ピッキングタスクのステータスを確認・更新
            $this->updatePickingTaskStatus($pickResult->pickingTask);
        });
    }

    /**
     * ピッキングタスクのステータスを更新
     * 全アイテムがis_ready_to_shipment = falseの場合、
     * タスクのステータスをPICKINGに戻す
     *
     * @param WmsPickingTask|null $task
     * @return void
     */
    protected function updatePickingTaskStatus(?WmsPickingTask $task): void
    {
        if (!$task) {
            return;
        }

        // タスクに属する全てのピッキング結果を取得
        $allResults = $task->pickingItemResults;

        if (!$allResults || $allResults->isEmpty()) {
            return;
        }

        // 全てのアイテムがis_ready_to_shipment = falseかチェック
        $allNotReady = $allResults->every(function ($item) {
            return $item->is_ready_to_shipment === false;
        });

        // 全て未準備ならPICKINGに戻す
        if ($allNotReady && $task->status === WmsPickingTask::STATUS_COMPLETED) {
            $task->status = WmsPickingTask::STATUS_PICKING;
            $task->save();

            Log::info('Picking task status reverted to PICKING', [
                'picking_task_id' => $task->id,
            ]);
        }
    }
}
