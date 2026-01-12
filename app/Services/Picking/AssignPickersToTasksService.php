<?php

namespace App\Services\Picking;

use App\Models\WmsPicker;
use App\Models\WmsPickingAssignmentStrategy;
use App\Models\WmsPickingTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AssignPickersToTasksService
{
    /**
     * ピッカーをタスクに割り当てる
     *
     * @param  int  $warehouseId  倉庫ID
     * @param  Collection|array  $pickerIds  割り当て対象ピッカーID
     * @param  int  $strategyId  戦略ID
     * @return array 結果 ['success' => bool, 'assigned_count' => int, 'message' => string]
     */
    public function execute(int $warehouseId, Collection|array $pickerIds, int $strategyId): array
    {
        $pickerIds = collect($pickerIds);

        if ($pickerIds->isEmpty()) {
            return [
                'success' => false,
                'assigned_count' => 0,
                'message' => 'ピッカーが選択されていません',
            ];
        }

        // ピッカー情報を取得（エリア情報含む）
        $pickers = WmsPicker::whereIn('id', $pickerIds)
            ->with('pickingAreas')
            ->get()
            ->keyBy('id');

        if ($pickers->isEmpty()) {
            return [
                'success' => false,
                'assigned_count' => 0,
                'message' => '有効なピッカーが見つかりません',
            ];
        }

        // 戦略を取得
        $strategy = WmsPickingAssignmentStrategy::find($strategyId);

        if (! $strategy) {
            return [
                'success' => false,
                'assigned_count' => 0,
                'message' => '割当戦略が見つかりません',
            ];
        }

        // 未割当タスクを取得
        $unassignedTasks = WmsPickingTask::where('warehouse_id', $warehouseId)
            ->whereNull('picker_id')
            ->whereIn('status', [WmsPickingTask::STATUS_PENDING, WmsPickingTask::STATUS_PICKING_READY])
            ->with('pickingArea')
            ->get();

        if ($unassignedTasks->isEmpty()) {
            return [
                'success' => true,
                'assigned_count' => 0,
                'message' => '割り当て可能なタスクがありません',
            ];
        }

        // 倉庫991の場合は2F優先ロジックを適用
        if ($warehouseId === 991) {
            return $this->assignWithFloorPriority($unassignedTasks, $pickers);
        }

        // 通常の均等割り当て
        return $this->assignEqually($unassignedTasks, $pickers);
    }

    /**
     * 991倉庫向け: 2F優先 → 1F均等割り当て
     */
    protected function assignWithFloorPriority(Collection $tasks, Collection $pickers): array
    {
        // 2Fタスク (floor_id = 2) と 1Fタスク (floor_id = 1) に分離
        $floor2Tasks = $tasks->filter(fn ($task) => $task->floor_id === 2);
        $floor1Tasks = $tasks->filter(fn ($task) => $task->floor_id === 1);
        $otherTasks = $tasks->filter(fn ($task) => ! in_array($task->floor_id, [1, 2]));

        $totalAssigned = 0;
        $pickerTaskCounts = $pickers->mapWithKeys(fn ($p) => [$p->id => 0])->toArray();

        DB::connection('sakemaru')->beginTransaction();

        try {
            // 1. 2Fのタスクを均等に割り当て
            $result2F = $this->distributeTasksToPickers($floor2Tasks, $pickers, $pickerTaskCounts);
            $totalAssigned += $result2F['assigned'];
            $pickerTaskCounts = $result2F['counts'];

            // 2. 1Fのタスクを均等に割り当て
            $result1F = $this->distributeTasksToPickers($floor1Tasks, $pickers, $pickerTaskCounts);
            $totalAssigned += $result1F['assigned'];
            $pickerTaskCounts = $result1F['counts'];

            // 3. その他のタスク（floor_idが1,2以外）を均等に割り当て
            $resultOther = $this->distributeTasksToPickers($otherTasks, $pickers, $pickerTaskCounts);
            $totalAssigned += $resultOther['assigned'];

            DB::connection('sakemaru')->commit();

            return [
                'success' => true,
                'assigned_count' => $totalAssigned,
                'message' => "{$totalAssigned}件のタスクを割り当てました（2F: {$result2F['assigned']}件, 1F: {$result1F['assigned']}件）",
            ];
        } catch (\Exception $e) {
            DB::connection('sakemaru')->rollBack();

            return [
                'success' => false,
                'assigned_count' => 0,
                'message' => '割り当て処理中にエラーが発生しました: '.$e->getMessage(),
            ];
        }
    }

    /**
     * タスクをピッカーに均等に配分
     */
    protected function distributeTasksToPickers(Collection $tasks, Collection $pickers, array $pickerTaskCounts): array
    {
        $assigned = 0;

        foreach ($tasks as $task) {
            // このタスクを担当できるピッカーを探す
            $eligiblePickerId = $this->findEligiblePicker($task, $pickers, $pickerTaskCounts);

            if ($eligiblePickerId === null) {
                // 担当できるピッカーがいない場合はスキップ
                continue;
            }

            // タスクを割り当て
            $task->update([
                'picker_id' => $eligiblePickerId,
                'status' => WmsPickingTask::STATUS_PICKING_READY,
            ]);

            $pickerTaskCounts[$eligiblePickerId]++;
            $assigned++;
        }

        return [
            'assigned' => $assigned,
            'counts' => $pickerTaskCounts,
        ];
    }

    /**
     * タスクを担当できるピッカーを探す（均等割り当てのため、タスク数が最も少ないピッカーを優先）
     */
    protected function findEligiblePicker(WmsPickingTask $task, Collection $pickers, array $pickerTaskCounts): ?int
    {
        $eligiblePickers = [];

        foreach ($pickers as $picker) {
            if ($this->canPickerHandleTask($picker, $task)) {
                $eligiblePickers[] = [
                    'picker_id' => $picker->id,
                    'task_count' => $pickerTaskCounts[$picker->id] ?? 0,
                ];
            }
        }

        if (empty($eligiblePickers)) {
            return null;
        }

        // タスク数が最も少ないピッカーを選択
        usort($eligiblePickers, fn ($a, $b) => $a['task_count'] <=> $b['task_count']);

        return $eligiblePickers[0]['picker_id'];
    }

    /**
     * ピッカーがタスクを担当できるかチェック
     */
    protected function canPickerHandleTask(WmsPicker $picker, WmsPickingTask $task): bool
    {
        // 1. 制限エリアのチェック
        if ($task->is_restricted_area && ! $picker->can_access_restricted_area) {
            return false;
        }

        // 2. ピッキングエリアのチェック（タスクにエリアが設定されている場合）
        if ($task->wms_picking_area_id) {
            // ピッカーが担当できるエリアを取得
            $pickerAreaIds = $picker->pickingAreas->pluck('id')->toArray();

            // ピッカーに担当エリアが設定されている場合は、そのエリアのみ担当可能
            if (! empty($pickerAreaIds) && ! in_array($task->wms_picking_area_id, $pickerAreaIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 通常の均等割り当て
     */
    protected function assignEqually(Collection $tasks, Collection $pickers): array
    {
        $pickerTaskCounts = $pickers->mapWithKeys(fn ($p) => [$p->id => 0])->toArray();

        DB::connection('sakemaru')->beginTransaction();

        try {
            $result = $this->distributeTasksToPickers($tasks, $pickers, $pickerTaskCounts);

            DB::connection('sakemaru')->commit();

            return [
                'success' => true,
                'assigned_count' => $result['assigned'],
                'message' => "{$result['assigned']}件のタスクを割り当てました",
            ];
        } catch (\Exception $e) {
            DB::connection('sakemaru')->rollBack();

            return [
                'success' => false,
                'assigned_count' => 0,
                'message' => '割り当て処理中にエラーが発生しました: '.$e->getMessage(),
            ];
        }
    }
}
