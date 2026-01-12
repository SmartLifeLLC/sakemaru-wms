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

        // このタスクのpicking_item_resultsを取得
        $pickingItemResults = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('picking_task_id', $task->id)
            ->get();

        $pickingItemResultIds = $pickingItemResults->pluck('id');

        // 全てのpicking_item_resultsがCOMPLETEDまたはSHORTAGEであるかチェック
        $incompleteItems = $pickingItemResults->filter(function ($item) {
            return !in_array($item->status, [
                WmsPickingItemResult::STATUS_COMPLETED,
                WmsPickingItemResult::STATUS_SHORTAGE,
            ]);
        });

        // まだピッキングが完了していないアイテムがある場合はスキップ
        if ($incompleteItems->isNotEmpty()) {
            Log::debug('Picking task has incomplete items', [
                'task_id' => $task->id,
                'incomplete_count' => $incompleteItems->count(),
            ]);
            return;
        }

        // このタスクに関連する欠品を取得
        $relatedShortageIds = DB::connection('sakemaru')
            ->table('wms_shortages')
            ->whereIn('source_pick_result_id', $pickingItemResultIds)
            ->pluck('id')
            ->unique();

        // 欠品がある場合、全て承認済みかチェック
        if ($relatedShortageIds->isNotEmpty()) {
            $allShortagesConfirmed = WmsShortage::whereIn('id', $relatedShortageIds)
                ->where('is_confirmed', false)
                ->doesntExist();

            if (!$allShortagesConfirmed) {
                Log::debug('Picking task has unconfirmed shortages', [
                    'task_id' => $task->id,
                    'shortage_ids' => $relatedShortageIds->toArray(),
                ]);
                return;
            }
        }

        // 全条件を満たした場合、タスクをCOMPLETEDに更新
        $task->status = WmsPickingTask::STATUS_COMPLETED;
        $task->completed_at = $task->completed_at ?? now();
        $task->save();

        Log::info('Picking task marked as COMPLETED', [
            'task_id' => $task->id,
            'wave_id' => $task->wave_id,
            'had_shortages' => $relatedShortageIds->isNotEmpty(),
        ]);
    }

    /**
     * 配送コースが印刷可能な状態かチェック
     *
     * @param int $deliveryCourseId 配送コースID
     * @param string $shipmentDate 納品日
     * @param int|null $waveId ウェーブID (Optional)
     * @return array ['can_print' => bool, 'error_message' => string|null, 'incomplete_items' => array, 'unsynced_shortages' => array]
     */
    public function checkPrintability(int $deliveryCourseId, string $shipmentDate, ?int $waveId = null): array
    {
        // 対象配送コースの全てのピッキングタスクを取得
        $query = WmsPickingTask::where('delivery_course_id', $deliveryCourseId)
            ->where('shipment_date', $shipmentDate);

        if ($waveId) {
            $query->where('wave_id', $waveId);
        }

        $tasks = $query->with(['pickingItemResults.shortage', 'pickingItemResults.item'])->get();

        if ($tasks->isEmpty()) {
            return [
                'can_print' => false,
                'error_message' => '対象のピッキングタスクが見つかりません。',
                'incomplete_items' => [],
                'unsynced_shortages' => [],
            ];
        }

        $incompleteItems = [];
        $unsyncedShortages = [];

        // チェック1: 全てのwms_picking_item_resultsがCOMPLETEDまたはSHORTAGEであるか
        foreach ($tasks as $task) {
            $itemResults = $task->pickingItemResults;

            foreach ($itemResults as $itemResult) {
                if (!in_array($itemResult->status, [
                    WmsPickingItemResult::STATUS_COMPLETED,
                    WmsPickingItemResult::STATUS_SHORTAGE,
                ])) {
                    $incompleteItems[] = [
                        'task_id' => $task->id,
                        'item_result_id' => $itemResult->id,
                        'item_name' => $itemResult->item?->name ?? '不明',
                        'item_code' => $itemResult->item?->code ?? '-',
                        'status' => $itemResult->status,
                    ];
                }

                // チェック2: 欠品が全てis_synced=trueであるか
                $shortage = $itemResult->shortage;
                if ($shortage && !$shortage->is_synced) {
                    $unsyncedShortages[] = [
                        'task_id' => $task->id,
                        'shortage_id' => $shortage->id,
                        'item_name' => $itemResult->item?->name ?? '不明',
                        'item_code' => $itemResult->item?->code ?? '-',
                        'shortage_qty' => $shortage->shortage_qty,
                        'is_confirmed' => $shortage->is_confirmed,
                    ];
                }
            }
        }

        // 結果を返す
        if (!empty($incompleteItems)) {
            return [
                'can_print' => false,
                'error_message' => '該当配送コースのピッキングが完了していません。',
                'incomplete_items' => $incompleteItems,
                'unsynced_shortages' => $unsyncedShortages,
            ];
        }

        if (!empty($unsyncedShortages)) {
            return [
                'can_print' => false,
                'error_message' => '欠品対応が完了していません。在庫同期が完了するまでお待ちください。',
                'incomplete_items' => $incompleteItems,
                'unsynced_shortages' => $unsyncedShortages,
            ];
        }

        return [
            'can_print' => true,
            'error_message' => null,
            'incomplete_items' => [],
            'unsynced_shortages' => [],
        ];
    }
}
