<?php

namespace App\Services\Shortage;

use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 欠品処理確定サービス
 * 移動出荷指示を確定し、ピッキング結果に反映する
 */
class ShortageConfirmationService
{
    /**
     * 欠品処理を確定する
     *
     * @param WmsShortage $shortage 欠品レコード
     * @return void
     */
    public function confirm(WmsShortage $shortage): void
    {
        DB::connection('sakemaru')->transaction(function () use ($shortage) {
            // 1. 全ての移動出荷指示（CANCELLED以外）を取得
            $allocations = $shortage->allocations()
                ->whereNotIn('status', [WmsShortageAllocation::STATUS_CANCELLED])
                ->get();

            if ($allocations->isEmpty()) {
                Log::warning('No allocations to confirm', [
                    'shortage_id' => $shortage->id,
                ]);
                return;
            }

            // 2. 合計移動出荷数を計算
            $totalAllocatedQty = $allocations->sum('assign_qty_each');

            // 3. 対応するピッキング結果を取得
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

            // 4. shortage_allocated_qtyとshortage_allocated_qty_typeを更新
            $pickResult->shortage_allocated_qty = $totalAllocatedQty;
            $pickResult->shortage_allocated_qty_type = $shortage->qty_type_at_order;

            // 5. is_ready_to_shipmentとshipment_ready_atを更新
            $pickResult->is_ready_to_shipment = true;
            $pickResult->shipment_ready_at = now();

            $pickResult->save();

            // 6. 欠品レコードのステータスをCONFIRMEDに更新し、確定者と日時を記録
            $shortage->status = WmsShortage::STATUS_CONFIRMED;
            $shortage->confirmed_user_id = auth()->id();
            $shortage->confirmed_at = now();
            $shortage->save();

            Log::info('Shortage confirmation completed', [
                'shortage_id' => $shortage->id,
                'pick_result_id' => $pickResult->id,
                'allocated_qty' => $totalAllocatedQty,
                'qty_type' => $shortage->qty_type_at_order,
            ]);

            // 7. ピッキングタスクのステータスを確認・更新
            $this->updatePickingTaskStatus($pickResult->pickingTask);
        });
    }

    /**
     * ピッキングタスクの全アイテムがis_ready_to_shipment = trueの場合、
     * タスクのステータスをCOMPLETEDにする
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

        // 全てのアイテムがis_ready_to_shipment = trueかチェック
        $allReady = $allResults->every(function ($item) {
            return $item->is_ready_to_shipment === true;
        });

        if ($allReady && $task->status !== WmsPickingTask::STATUS_COMPLETED) {
            $task->status = WmsPickingTask::STATUS_COMPLETED;
            $task->save();

            Log::info('Picking task status updated to COMPLETED', [
                'picking_task_id' => $task->id,
            ]);
        }
    }
}
