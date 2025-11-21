<?php

namespace App\Services\Shortage;

use App\Actions\Wms\ConfirmShortageAllocations;
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
            // 1. 全ての移動出荷指示を取得
            $allocations = $shortage->allocations()->get();

            if ($allocations->isEmpty()) {
                Log::warning('No allocations to confirm', [
                    'shortage_id' => $shortage->id,
                ]);
                return;
            }

            // 2. 合計移動出荷数を計算（受注単位ベース）
            $totalAllocatedQty = $allocations->sum('assign_qty');

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

            // 6. 欠品レコードのステータスを判定して更新
            // - 移動出荷数が0の場合: SHORTAGE（欠品確定）
            // - 移動出荷数 > 0 かつ 残欠品数 > 0の場合: PARTIAL_SHORTAGE（部分欠品）
            // - 移動出荷数 > 0 かつ 残欠品数 = 0の場合: SHORTAGE（完全充足だが確定）
            $remainingShortage = $shortage->shortage_qty - $totalAllocatedQty;

            if ($totalAllocatedQty === 0) {
                // 移動出荷が無い場合は欠品確定
                $shortage->status = WmsShortage::STATUS_SHORTAGE;
            } elseif ($remainingShortage > 0) {
                // 移動出荷があるが欠品が残る場合は部分欠品
                $shortage->status = WmsShortage::STATUS_PARTIAL_SHORTAGE;
            } else {
                // 移動出荷で完全に充足した場合も確定扱い
                $shortage->status = WmsShortage::STATUS_SHORTAGE;
            }

            $shortage->is_confirmed = true;
            $shortage->confirmed_by = auth()->id();
            $shortage->confirmed_at = now();
            $shortage->confirmed_user_id = auth()->id();  // 後方互換性のため
            $shortage->save();

            // 7. 関連する代理出荷も承認
            $confirmedAllocationsCount = ConfirmShortageAllocations::execute(
                wmsShortageId: $shortage->id,
                confirmedUserId: auth()->id() ?? 0
            );

            Log::info('Shortage confirmation completed', [
                'shortage_id' => $shortage->id,
                'pick_result_id' => $pickResult->id,
                'allocated_qty' => $totalAllocatedQty,
                'remaining_shortage' => $remainingShortage,
                'status' => $shortage->status,
                'qty_type' => $shortage->qty_type_at_order,
                'confirmed_allocations_count' => $confirmedAllocationsCount,
            ]);

            // 8. ピッキングタスクのステータスを確認・更新
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
