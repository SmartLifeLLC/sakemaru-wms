<?php

namespace App\Services\Shortage;

use App\Models\WmsPickingItemResult;
use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ピッキング完了時の欠品検出・記録サービス
 *
 * wms_picking_item_results.status=COMPLETEDに変わるタイミングで
 * wms_shortagesを生成する
 *
 * 欠品数量の計算:
 * - shortage_qty = order_qty - picked_qty (総欠品数)
 * - allocation_shortage_qty = order_qty - planned_qty (引当欠品数)
 * - picking_shortage_qty = planned_qty - picked_qty (ピッキング欠品数)
 */
class PickingShortageDetector
{
    /**
     * ピッキング完了時に欠品を検出して記録
     *
     * @param  int|null  $parentShortageId  横持ち出荷の場合の親欠品ID
     */
    public function detectAndRecord(
        WmsPickingItemResult $pickResult,
        ?int $parentShortageId = null
    ): ?WmsShortage {
        // 欠品数量を計算
        // shortage_qty = order_qty - picked_qty
        $orderQty = $pickResult->ordered_qty;
        $plannedQty = $pickResult->planned_qty;
        $pickedQty = $pickResult->picked_qty;

        $shortageQty = max(0, $orderQty - $pickedQty);

        // 欠品がない場合は何もしない
        if ($shortageQty <= 0) {
            return null;
        }

        $task = $pickResult->pickingTask;
        if (! $task) {
            Log::warning('Picking task not found for pick result', [
                'pick_result_id' => $pickResult->id,
            ]);

            return null;
        }

        $lockName = sprintf('wms_shortage_pick_result:%s', $pickResult->id);
        $lockAcquired = DB::connection('sakemaru')
            ->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName])?->acquired;

        if ((int) $lockAcquired !== 1) {
            throw new \RuntimeException("Could not acquire shortage creation lock for pick result ID {$pickResult->id}");
        }

        try {
            return DB::connection('sakemaru')->transaction(function () use (
                $pickResult,
                $task,
                $orderQty,
                $plannedQty,
                $pickedQty,
                $shortageQty,
                $parentShortageId
            ) {
                // picking_item_resultから受注単位とケース入数を取得
                if (! $pickResult->ordered_qty_type) {
                    throw new \RuntimeException(
                        "ordered_qty_type must be specified for pick result ID {$pickResult->id}"
                    );
                }
                $qtyType = $pickResult->ordered_qty_type;
                $caseSize = $pickResult->item?->capacity_case ?? 1;

                // earningをearning_id（直接参照）またはtrade_idから取得
                $earning = null;
                if ($pickResult->earning_id) {
                    $earning = DB::connection('sakemaru')
                        ->table('earnings')
                        ->where('id', $pickResult->earning_id)
                        ->first();
                }
                if (! $earning && $pickResult->trade_id) {
                    $earning = DB::connection('sakemaru')
                        ->table('earnings')
                        ->where('trade_id', $pickResult->trade_id)
                        ->first();
                }

                $earningId = $earning?->id;
                $stockTransfer = null;
                if (! $earning && $pickResult->stock_transfer_id) {
                    $stockTransfer = DB::connection('sakemaru')
                        ->table('stock_transfers')
                        ->where('id', $pickResult->stock_transfer_id)
                        ->first();
                }

                $shipmentDate = $earning?->delivered_date ?? $task->shipment_date;
                $deliveryCourseId = $earning?->delivery_course_id
                    ?? $stockTransfer?->delivery_course_id
                    ?? $task->delivery_course_id;

                $locationId = $this->resolveShortageLocationId(
                    $pickResult->location_id ? (int) $pickResult->location_id : null,
                    (int) $task->warehouse_id,
                    (int) $pickResult->item_id
                );

                // 欠品数量を計算
                $allocationShortageQty = max(0, $orderQty - $plannedQty);
                $pickingShortageQty = max(0, $plannedQty - $pickedQty);

                // 既存の欠品レコードを検索
                $existingShortage = WmsShortage::where(function ($query) use ($pickResult, $task) {
                    $query->where('source_pick_result_id', $pickResult->id)
                        ->orWhere(function ($subQuery) use ($pickResult, $task) {
                            $subQuery
                                ->where('wave_id', $task->wave_id)
                                ->where('warehouse_id', $task->warehouse_id)
                                ->where('item_id', $pickResult->item_id)
                                ->where('trade_item_id', $pickResult->trade_item_id);
                        });
                })
                    ->lockForUpdate()
                    ->first();

                if ($existingShortage) {
                    // 既存レコードを更新
                    $existingShortage->update([
                        'order_qty' => $orderQty,
                        'planned_qty' => $plannedQty,
                        'picked_qty' => $pickedQty,
                        'shortage_qty' => $shortageQty,
                        'allocation_shortage_qty' => $allocationShortageQty,
                        'picking_shortage_qty' => $pickingShortageQty,
                        'location_id' => $locationId,
                        'shipment_date' => $shipmentDate,
                        'delivery_course_id' => $deliveryCourseId,
                        'source_pick_result_id' => $pickResult->id,
                    ]);

                    Log::info('Existing shortage updated at picking completion', [
                        'shortage_id' => $existingShortage->id,
                        'pick_result_id' => $pickResult->id,
                        'order_qty' => $orderQty,
                        'planned_qty' => $plannedQty,
                        'picked_qty' => $pickedQty,
                        'shortage_qty' => $shortageQty,
                        'allocation_shortage_qty' => $allocationShortageQty,
                        'picking_shortage_qty' => $pickingShortageQty,
                    ]);

                    return $existingShortage;
                }

                // 新規欠品レコード作成
                $shortage = WmsShortage::create([
                    'wave_id' => $task->wave_id,
                    'shipment_date' => $shipmentDate,
                    'warehouse_id' => $task->warehouse_id,
                    'location_id' => $locationId,
                    'item_id' => $pickResult->item_id,
                    'trade_id' => $pickResult->trade_id,
                    'earning_id' => $earningId,
                    'delivery_course_id' => $deliveryCourseId,
                    'trade_item_id' => $pickResult->trade_item_id,
                    'order_qty' => $orderQty,
                    'planned_qty' => $plannedQty,
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'allocation_shortage_qty' => $allocationShortageQty,
                    'picking_shortage_qty' => $pickingShortageQty,
                    'qty_type_at_order' => $qtyType,
                    'case_size_snap' => $caseSize,
                    'source_pick_result_id' => $pickResult->id,
                    'parent_shortage_id' => $parentShortageId,
                    'status' => WmsShortage::STATUS_BEFORE,
                    'reason_code' => WmsShortage::REASON_NO_STOCK,
                ]);

                Log::info('Shortage created at picking completion', [
                    'shortage_id' => $shortage->id,
                    'pick_result_id' => $pickResult->id,
                    'wave_id' => $task->wave_id,
                    'warehouse_id' => $task->warehouse_id,
                    'item_id' => $pickResult->item_id,
                    'trade_item_id' => $pickResult->trade_item_id,
                    'order_qty' => $orderQty,
                    'planned_qty' => $plannedQty,
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'allocation_shortage_qty' => $allocationShortageQty,
                    'picking_shortage_qty' => $pickingShortageQty,
                    'qty_type' => $qtyType,
                    'parent_shortage_id' => $parentShortageId,
                ]);

                return $shortage;
            });
        } finally {
            DB::connection('sakemaru')->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function resolveShortageLocationId(?int $locationId, int $warehouseId, int $itemId): ?int
    {
        if ($locationId !== null) {
            return $locationId;
        }

        $defaultLocationId = DB::connection('sakemaru')
            ->table('item_incoming_default_locations as idl')
            ->where('idl.warehouse_id', $warehouseId)
            ->where('idl.item_id', $itemId)
            ->value('idl.location_id');

        if (! $defaultLocationId) {
            $defaultLocationId = DB::connection('sakemaru')
                ->table('item_incoming_default_locations as idl')
                ->join('locations as default_l', 'default_l.id', '=', 'idl.location_id')
                ->leftJoin('locations as exact_l', function ($join) use ($warehouseId) {
                    $join->where('exact_l.warehouse_id', '=', $warehouseId)
                        ->whereColumn('exact_l.code1', 'default_l.code1')
                        ->whereColumn('exact_l.code2', 'default_l.code2')
                        ->whereColumn('exact_l.code3', 'default_l.code3');
                })
                ->leftJoin('locations as partial_l', function ($join) use ($warehouseId) {
                    $join->where('partial_l.warehouse_id', '=', $warehouseId)
                        ->whereColumn('partial_l.code1', 'default_l.code1')
                        ->whereColumn('partial_l.code2', 'default_l.code2')
                        ->whereNull('partial_l.code3');
                })
                ->where('idl.item_id', $itemId)
                ->selectRaw('COALESCE(MIN(exact_l.id), MIN(partial_l.id)) as location_id')
                ->value('location_id');
        }

        return $defaultLocationId ? (int) $defaultLocationId : null;
    }
}
