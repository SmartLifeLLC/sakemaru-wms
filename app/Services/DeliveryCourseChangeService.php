<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 配送コース変更サービス
 *
 * 伝票の配送コースを変更し、picking_taskとpicking_item_resultsを再編成する
 */
class DeliveryCourseChangeService
{
    protected WaveService $waveService;

    public function __construct(WaveService $waveService)
    {
        $this->waveService = $waveService;
    }

    /**
     * 配送コースを変更
     *
     * @param int $tradeId 伝票ID
     * @param int $newCourseId 変更先の配送コースID
     * @return array 変更結果
     * @throws \Exception
     */
    public function changeDeliveryCourse(int $tradeId, int $newCourseId): array
    {
        return DB::transaction(function () use ($tradeId, $newCourseId) {
            // 1. 対象伝票に紐づく全picking_item_resultsを取得
            $itemResults = DB::connection('sakemaru')
                ->table('wms_picking_item_results as pir')
                ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
                ->where('pir.trade_id', $tradeId)
                ->where('pt.status', 'PENDING') // PENDING状態のみ変更可能
                ->select('pir.*', 'pt.warehouse_id', 'pt.shipment_date')
                ->get();

            if ($itemResults->isEmpty()) {
                throw new InvalidArgumentException("Trade ID {$tradeId} has no PENDING picking items");
            }

            // 倉庫IDと出荷日を取得（全アイテムで同じはず）
            $warehouseId = $itemResults->first()->warehouse_id;
            $shipmentDate = $itemResults->first()->shipment_date;

            // 変更先配送コースの情報を取得
            $newCourse = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->where('id', $newCourseId)
                ->first();

            if (!$newCourse) {
                throw new InvalidArgumentException("Delivery course ID {$newCourseId} not found");
            }

            // 元のタスクIDを記録（後でクリーンアップ用）
            $oldTaskIds = $itemResults->pluck('picking_task_id')->unique()->toArray();

            // 2. Waveを取得または生成
            $wave = $this->waveService->getOrCreateWave($warehouseId, $newCourseId, $shipmentDate);

            // 3. 各picking_item_resultを移動
            $movedCount = 0;
            foreach ($itemResults as $itemResult) {
                // アイテムのlocation情報を取得
                $location = DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $itemResult->location_id)
                    ->first();

                if (!$location) {
                    // location_idがnullの場合はスキップ（欠品等）
                    continue;
                }

                // wms_locations経由でpicking_area_idを取得
                $wmsLocation = DB::connection('sakemaru')
                    ->table('wms_locations')
                    ->where('location_id', $itemResult->location_id)
                    ->first();

                // 移動先のpicking_taskを検索または作成
                $targetTask = $this->findOrCreatePickingTask(
                    $wave->id,
                    $warehouseId,
                    $newCourseId,
                    $newCourse->code,
                    $wmsLocation->wms_picking_area_id ?? null,
                    $location->floor_id ?? null,
                    $location->temperature_type ?? null,
                    $location->is_restricted_area ?? false,
                    $shipmentDate
                );

                // picking_item_resultを移動先タスクに紐づけ
                DB::connection('sakemaru')
                    ->table('wms_picking_item_results')
                    ->where('id', $itemResult->id)
                    ->update([
                        'picking_task_id' => $targetTask->id,
                        'updated_at' => now(),
                    ]);

                $movedCount++;
            }

            // 4. 元のタスクをクリーンアップ
            $deletedTaskCount = 0;
            foreach ($oldTaskIds as $oldTaskId) {
                if ($this->cleanupEmptyTask($oldTaskId)) {
                    $deletedTaskCount++;
                }
            }

            return [
                'success' => true,
                'trade_id' => $tradeId,
                'new_course_id' => $newCourseId,
                'wave_id' => $wave->id,
                'moved_items' => $movedCount,
                'deleted_tasks' => $deletedTaskCount,
            ];
        });
    }

    /**
     * picking_taskを検索または作成
     *
     * @param int $waveId
     * @param int $warehouseId
     * @param int $deliveryCourseId
     * @param string $deliveryCourseCode
     * @param int|null $pickingAreaId
     * @param int|null $floorId
     * @param string|null $temperatureType
     * @param bool $isRestrictedArea
     * @param string $shipmentDate
     * @return object
     */
    protected function findOrCreatePickingTask(
        int $waveId,
        int $warehouseId,
        int $deliveryCourseId,
        string $deliveryCourseCode,
        ?int $pickingAreaId,
        ?int $floorId,
        ?string $temperatureType,
        bool $isRestrictedArea,
        string $shipmentDate
    ): object {
        // 同じ条件のpicking_taskを検索
        $existingTask = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('wave_id', $waveId)
            ->where('warehouse_id', $warehouseId)
            ->where('delivery_course_id', $deliveryCourseId)
            ->where('wms_picking_area_id', $pickingAreaId)
            ->where('floor_id', $floorId)
            ->where('temperature_type', $temperatureType)
            ->where('is_restricted_area', $isRestrictedArea)
            ->where('status', 'PENDING')
            ->first();

        if ($existingTask) {
            return $existingTask;
        }

        // 新規picking_taskを作成
        return $this->createPickingTask(
            $waveId,
            $warehouseId,
            $deliveryCourseId,
            $deliveryCourseCode,
            $pickingAreaId,
            $floorId,
            $temperatureType,
            $isRestrictedArea,
            $shipmentDate
        );
    }

    /**
     * 新規picking_taskを作成
     *
     * @param int $waveId
     * @param int $warehouseId
     * @param int $deliveryCourseId
     * @param string $deliveryCourseCode
     * @param int|null $pickingAreaId
     * @param int|null $floorId
     * @param string|null $temperatureType
     * @param bool $isRestrictedArea
     * @param string $shipmentDate
     * @return object
     */
    protected function createPickingTask(
        int $waveId,
        int $warehouseId,
        int $deliveryCourseId,
        string $deliveryCourseCode,
        ?int $pickingAreaId,
        ?int $floorId,
        ?string $temperatureType,
        bool $isRestrictedArea,
        string $shipmentDate
    ): object {
        // 倉庫コードを取得
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first();

        $pickingTaskId = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->insertGetId([
                'wave_id' => $waveId,
                'wms_picking_area_id' => $pickingAreaId,
                'warehouse_id' => $warehouseId,
                'warehouse_code' => $warehouse->code ?? (string) $warehouseId,
                'floor_id' => $floorId,
                'temperature_type' => $temperatureType,
                'is_restricted_area' => $isRestrictedArea,
                'delivery_course_id' => $deliveryCourseId,
                'delivery_course_code' => $deliveryCourseCode,
                'shipment_date' => $shipmentDate,
                'status' => 'PENDING',
                'task_type' => 'WAVE',
                'picker_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('id', $pickingTaskId)
            ->first();
    }

    /**
     * 空になったタスクを削除
     *
     * @param int $taskId
     * @return bool 削除されたかどうか
     */
    protected function cleanupEmptyTask(int $taskId): bool
    {
        // タスクにアイテムが残っているかチェック
        $remainingItems = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('picking_task_id', $taskId)
            ->count();

        if ($remainingItems === 0) {
            // アイテムが残っていない場合は削除
            DB::connection('sakemaru')
                ->table('wms_picking_tasks')
                ->where('id', $taskId)
                ->delete();

            return true;
        }

        return false;
    }

    /**
     * 複数伝票の配送コースを一括変更
     *
     * @param array $tradeIds 伝票IDの配列
     * @param int $newCourseId 変更先の配送コースID
     * @return array 変更結果
     */
    public function bulkChangeDeliveryCourse(array $tradeIds, int $newCourseId): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($tradeIds as $tradeId) {
            try {
                $result = $this->changeDeliveryCourse($tradeId, $newCourseId);
                $results[] = $result;
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'trade_id' => $tradeId,
                    'error' => $e->getMessage(),
                ];
                $failureCount++;
            }
        }

        return [
            'total' => count($tradeIds),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results,
        ];
    }
}
