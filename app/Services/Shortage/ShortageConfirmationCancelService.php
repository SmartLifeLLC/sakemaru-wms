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
        // is_confirmed = true または status = BEFORE の場合は処理しない
        if ($shortage->is_confirmed) {
            throw new \RuntimeException(
                "Shortage ID {$shortage->id} is already confirmed (is_confirmed: true)"
            );
        }

        if ($shortage->status === WmsShortage::STATUS_BEFORE) {
            throw new \RuntimeException(
                "Shortage ID {$shortage->id} is in BEFORE status and cannot be cancelled"
            );
        }

        DB::connection('sakemaru')->transaction(function () use ($shortage) {
            // 欠品処理確定前の取り消しなので、ピッキング結果は更新不要
            // ステータスをBEFOREに戻すのみ

            $shortage->status = WmsShortage::STATUS_BEFORE;
            $shortage->updater_id = auth()->id();
            $shortage->save();

            Log::info('Shortage processing cancelled', [
                'shortage_id' => $shortage->id,
                'old_status' => $shortage->getOriginal('status'),
                'new_status' => $shortage->status,
            ]);
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
