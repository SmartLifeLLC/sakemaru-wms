<?php

namespace App\Services\Picking;

use App\Enums\PickingStrategyType;
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

        // 未割当タスクを取得（商品数をカウント）
        $unassignedTasks = WmsPickingTask::where('warehouse_id', $warehouseId)
            ->whereNull('picker_id')
            ->whereIn('status', [WmsPickingTask::STATUS_PENDING, WmsPickingTask::STATUS_PICKING_READY])
            ->withCount('pickingItemResults as item_count')
            ->with('pickingArea')
            ->get();

        if ($unassignedTasks->isEmpty()) {
            return [
                'success' => true,
                'assigned_count' => 0,
                'message' => '割り当て可能なタスクがありません',
            ];
        }

        // 配送コースでグルーピング
        $groups = $this->groupByDeliveryCourse($unassignedTasks, $warehouseId);

        // 戦略に基づいて割り当て
        return match ($strategy->strategy_key) {
            PickingStrategyType::EQUAL => $this->assignByItemCountEqual($groups, $pickers),
            PickingStrategyType::SKILL_BASED => $this->assignByItemCountSkillBased($groups, $pickers, $strategy),
            default => [
                'success' => false,
                'assigned_count' => 0,
                'message' => "未対応の戦略タイプです: {$strategy->strategy_key?->value}",
            ],
        };
    }

    /**
     * 指定倉庫の割り当て済みタスクを解除する（PICKING_READYのみ）
     *
     * @param  int  $warehouseId  倉庫ID
     * @return array ['success' => bool, 'unassigned_count' => int, 'message' => string]
     */
    public function unassign(int $warehouseId): array
    {
        $count = WmsPickingTask::where('warehouse_id', $warehouseId)
            ->whereNotNull('picker_id')
            ->where('status', WmsPickingTask::STATUS_PICKING_READY)
            ->update([
                'picker_id' => null,
                'status' => WmsPickingTask::STATUS_PENDING,
            ]);

        return [
            'success' => true,
            'unassigned_count' => $count,
            'message' => "{$count}件の割り当てを解除しました",
        ];
    }

    /**
     * タスクを配送コースでグルーピング
     *
     * delivery_course_id が NULL のタスクは warehouse_id で1グループにまとめる
     */
    protected function groupByDeliveryCourse(Collection $tasks, int $warehouseId): Collection
    {
        $grouped = $tasks->groupBy(function ($task) use ($warehouseId) {
            return $task->delivery_course_id ?? "warehouse_{$warehouseId}";
        });

        // 各グループのサマリーを作成し、合計商品数の降順でソート（First Fit Decreasing）
        return $grouped->map(fn ($groupTasks, $key) => [
            'key' => $key,
            'tasks' => $groupTasks,
            'total_items' => $groupTasks->sum('item_count'),
        ])->sortByDesc('total_items')->values();
    }

    /**
     * EQUAL戦略: 商品数均等割り当て
     *
     * 配送コース単位のグループを商品数降順で処理し、
     * 累計商品数が最少のピッカーに割り当てる（First Fit Decreasing）
     */
    protected function assignByItemCountEqual(Collection $groups, Collection $pickers): array
    {
        // ピッカーごとの累計商品数を初期化
        $pickerItemCounts = $pickers->mapWithKeys(fn ($p) => [$p->id => 0])->toArray();

        return $this->distributeGroupsToPickers($groups, $pickers, $pickerItemCounts);
    }

    /**
     * SKILL_BASED戦略: スキルレベル比率での商品数割り当て
     *
     * 各ピッカーのスキルレートに基づいて「重み付き累計商品数」を計算し、
     * 重み付き累計が最少のピッカーに割り当てることで、スキルに応じた比率を実現する
     */
    protected function assignByItemCountSkillBased(Collection $groups, Collection $pickers, WmsPickingAssignmentStrategy $strategy): array
    {
        // パラメータからカスタムスキルレートを取得
        $customSkillRates = $strategy->getParameter('skill_rates');

        // ピッカーごとの累計商品数を初期化（重み付き）
        $pickerItemCounts = $pickers->mapWithKeys(fn ($p) => [$p->id => 0])->toArray();

        // ピッカーごとのスキルレートをマッピング
        // カスタムレートがあればそれを優先、なければ PickerSkillLevel Enum の rate() を使用
        $pickerSkillRates = [];
        foreach ($pickers as $picker) {
            if ($customSkillRates) {
                $skillLevel = $picker->skill_level?->value ?? 3;
                $pickerSkillRates[$picker->id] = (float) ($customSkillRates[(string) $skillLevel] ?? 1.0);
            } else {
                $pickerSkillRates[$picker->id] = $picker->skill_level?->rate() ?? 1.0;
            }
        }

        return $this->distributeGroupsToPickers($groups, $pickers, $pickerItemCounts, $pickerSkillRates);
    }

    /**
     * グループをピッカーに配分する共通処理
     *
     * @param  Collection  $groups  配送コース別グループ（商品数降順ソート済み）
     * @param  Collection  $pickers  ピッカーコレクション
     * @param  array  $pickerItemCounts  ピッカーごとの累計商品数
     * @param  array|null  $pickerSkillRates  ピッカーごとのスキルレート（null=均等）
     */
    protected function distributeGroupsToPickers(
        Collection $groups,
        Collection $pickers,
        array $pickerItemCounts,
        ?array $pickerSkillRates = null
    ): array {
        $totalAssigned = 0;

        DB::connection('sakemaru')->beginTransaction();

        try {
            foreach ($groups as $group) {
                $tasks = $group['tasks'];
                $groupItemCount = $group['total_items'];

                // グループ内の全タスクを担当できるピッカーを探す
                $eligiblePickerId = $this->findEligiblePickerForGroup(
                    $tasks,
                    $pickers,
                    $pickerItemCounts,
                    $pickerSkillRates
                );

                if ($eligiblePickerId === null) {
                    continue;
                }

                // グループ内の全タスクを同一ピッカーに割り当て
                foreach ($tasks as $task) {
                    $task->update([
                        'picker_id' => $eligiblePickerId,
                        'status' => WmsPickingTask::STATUS_PICKING_READY,
                    ]);
                    $totalAssigned++;
                }

                // 累計商品数を加算
                $pickerItemCounts[$eligiblePickerId] += $groupItemCount;
            }

            DB::connection('sakemaru')->commit();

            return [
                'success' => true,
                'assigned_count' => $totalAssigned,
                'message' => "{$totalAssigned}件のタスクを割り当てました",
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
     * グループを担当できるピッカーを探す
     *
     * グループ内の全タスクを担当可能で、累計商品数（重み付き）が最少のピッカーを選択
     */
    protected function findEligiblePickerForGroup(
        Collection $tasks,
        Collection $pickers,
        array $pickerItemCounts,
        ?array $pickerSkillRates = null
    ): ?int {
        $eligiblePickers = [];

        foreach ($pickers as $picker) {
            // グループ内の全タスクを担当できるかチェック
            $canHandleAll = $tasks->every(fn ($task) => $this->canPickerHandleTask($picker, $task));

            if ($canHandleAll) {
                // 重み付き累計商品数を計算
                $actualCount = $pickerItemCounts[$picker->id] ?? 0;
                $skillRate = $pickerSkillRates[$picker->id] ?? 1.0;
                $weightedCount = $skillRate > 0 ? $actualCount / $skillRate : PHP_FLOAT_MAX;

                $eligiblePickers[] = [
                    'picker_id' => $picker->id,
                    'weighted_count' => $weightedCount,
                ];
            }
        }

        if (empty($eligiblePickers)) {
            return null;
        }

        // 重み付き累計商品数が最少のピッカーを選択
        usort($eligiblePickers, fn ($a, $b) => $a['weighted_count'] <=> $b['weighted_count']);

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
            $pickerAreaIds = $picker->pickingAreas->pluck('id')->toArray();

            if (! empty($pickerAreaIds) && ! in_array($task->wms_picking_area_id, $pickerAreaIds)) {
                return false;
            }
        }

        return true;
    }
}
