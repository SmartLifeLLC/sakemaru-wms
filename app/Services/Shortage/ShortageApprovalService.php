<?php

namespace App\Services\Shortage;

use App\Models\Wave;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShortageApprovalService
{
    /**
     * 欠品承認後にピッキングタスクのステータスを更新
     * 全ての欠品が承認済みの場合、タスクをCOMPLETEDに変更
     *
     * @param WmsShortage $shortage 承認された欠品
     * @return void
     */
    public function updatePickingTaskStatusAfterApproval(WmsShortage $shortage): void
    {
        // 欠品が所属するwaveを取得
        $wave = $shortage->wave;
        if (!$wave) {
            Log::warning('Wave not found for shortage', ['shortage_id' => $shortage->id]);
            return;
        }

        // このwaveに紐づく全てのピッキングタスクを取得
        $pickingTasks = $wave->pickingTasks;

        foreach ($pickingTasks as $task) {
            $this->checkAndUpdateTaskStatus($task);
        }
    }

    /**
     * ピッキングタスクのステータスをチェックし、必要に応じてCOMPLETEDに更新
     *
     * @param WmsPickingTask $task
     * @return void
     */
    protected function checkAndUpdateTaskStatus(WmsPickingTask $task): void
    {
        // 既にCOMPLETEDの場合はスキップ
        if ($task->status === WmsPickingTask::STATUS_COMPLETED) {
            return;
        }

        // このタスクに関連する全ての欠品を取得
        // wms_shortages.source_pick_result_id = wms_picking_item_results.id
        $pickingItemResultIds = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('picking_task_id', $task->id)
            ->pluck('id');

        $relatedShortageIds = DB::connection('sakemaru')
            ->table('wms_shortages')
            ->whereIn('source_pick_result_id', $pickingItemResultIds)
            ->pluck('id')
            ->unique();

        // 欠品がない場合はスキップ
        if ($relatedShortageIds->isEmpty()) {
            return;
        }

        // 全ての欠品が承認済みかチェック
        $allShortagesConfirmed = WmsShortage::whereIn('id', $relatedShortageIds)
            ->where('is_confirmed', false)
            ->doesntExist();

        if ($allShortagesConfirmed) {
            $task->status = WmsPickingTask::STATUS_COMPLETED;
            $task->completed_at = now();
            $task->save();

            Log::info('Picking task marked as COMPLETED after all shortages confirmed', [
                'task_id' => $task->id,
                'wave_id' => $task->wave_id,
            ]);
        }
    }

    /**
     * 配送コースが印刷可能な状態かチェック
     *
     * @param int $deliveryCourseId 配送コースID
     * @param string $shipmentDate 納品日
     * @return array ['can_print' => bool, 'error_message' => string|null]
     */
    public function checkPrintability(int $deliveryCourseId, string $shipmentDate): array
    {
        // 対象配送コースの全てのピッキングタスクを取得
        $tasks = WmsPickingTask::where('delivery_course_id', $deliveryCourseId)
            ->where('shipment_date', $shipmentDate)
            ->with(['pickingItemResults.shortage'])
            ->get();

        if ($tasks->isEmpty()) {
            return [
                'can_print' => false,
                'error_message' => '対象のピッキングタスクが見つかりません。',
            ];
        }

        // チェック1: 全てのwms_picking_item_resultsがCOMPLETEDまたはSHORTAGEであるか
        foreach ($tasks as $task) {
            $itemResults = $task->pickingItemResults;

            foreach ($itemResults as $itemResult) {
                if (!in_array($itemResult->status, [
                    WmsPickingItemResult::STATUS_COMPLETED,
                    WmsPickingItemResult::STATUS_SHORTAGE,
                ])) {
                    return [
                        'can_print' => false,
                        'error_message' => '該当配送コースのピッキングが完了していません。',
                    ];
                }
            }
        }

        // チェック2: 欠品が全てis_synced=trueであるか
        foreach ($tasks as $task) {
            $itemResults = $task->pickingItemResults()
                ->with('shortage')
                ->get();

            foreach ($itemResults as $itemResult) {
                // このitem_resultに紐づく欠品があるかチェック
                $shortage = $itemResult->shortage;
                if ($shortage && !$shortage->is_synced) {
                    return [
                        'can_print' => false,
                        'error_message' => '欠品対応が完了していません。在庫同期が完了するまでお待ちください。',
                    ];
                }
            }
        }

        return [
            'can_print' => true,
            'error_message' => null,
        ];
    }
}
