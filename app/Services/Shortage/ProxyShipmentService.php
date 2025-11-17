<?php

namespace App\Services\Shortage;

use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 移動出荷サービス
 * 段階4-5: 移動出荷指示と引当処理
 */
class ProxyShipmentService
{
    /**
     * 移動出荷指示を作成
     *
     * @param WmsShortage $shortage
     * @param int $fromWarehouseId 移動出荷倉庫
     * @param int $assignQty 移動出荷数量
     * @param string $assignQtyType 移動出荷単位 (CASE, PIECE, CARTON)
     * @param int $createdBy 作成者
     * @return WmsShortageAllocation
     * @throws \Exception
     */
    public function createProxyShipment(
        WmsShortage $shortage,
        int $fromWarehouseId,
        int $assignQty,
        string $assignQtyType,
        int $createdBy
    ): WmsShortageAllocation {
        // CASE受注の場合、PIECE/CARTON指定を拒否
        if ($shortage->isCaseOrder() && $assignQtyType !== WmsShortage::QTY_TYPE_CASE) {
            throw new \Exception('CASE受注に対してバラ/カートン指定はできません');
        }

        // PIECE換算
        $assignQtyEach = QuantityConverter::convertToEach(
            $assignQty,
            $assignQtyType,
            $shortage->case_size_snap
        );

        // 欠品数を超える指定は警告
        if ($assignQtyEach > $shortage->remaining_qty_each) {
            Log::warning('Proxy shipment quantity exceeds shortage', [
                'shortage_id' => $shortage->id,
                'remaining_qty_each' => $shortage->remaining_qty_each,
                'assign_qty_each' => $assignQtyEach,
            ]);
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $shortage,
            $fromWarehouseId,
            $assignQtyEach,
            $assignQtyType,
            $createdBy
        ) {
            // 移動出荷指示レコード作成
            $allocation = WmsShortageAllocation::create([
                'shortage_id' => $shortage->id,
                'from_warehouse_id' => $fromWarehouseId,
                'assign_qty_each' => $assignQtyEach,
                'assign_qty_type' => $assignQtyType,
                'status' => WmsShortageAllocation::STATUS_PENDING,
                'created_by' => $createdBy,
            ]);

            // 欠品ステータスを REALLOCATING に更新し、更新者を記録
            $shortage->update([
                'status' => WmsShortage::STATUS_REALLOCATING,
                'updater_id' => $createdBy,
            ]);

            Log::info('Proxy shipment created', [
                'allocation_id' => $allocation->id,
                'shortage_id' => $shortage->id,
                'from_warehouse_id' => $fromWarehouseId,
                'assign_qty_each' => $assignQtyEach,
                'assign_qty_type' => $assignQtyType,
            ]);

            return $allocation;
        });
    }

    /**
     * 移動出荷をキャンセル
     *
     * @param WmsShortageAllocation $allocation
     * @return void
     */
    public function cancelProxyShipment(WmsShortageAllocation $allocation): void
    {
        DB::connection('sakemaru')->transaction(function () use ($allocation) {
            $allocation->update([
                'status' => WmsShortageAllocation::STATUS_CANCELLED,
            ]);

            // 他の移動出荷がなければ、欠品ステータスを OPEN に戻す
            $shortage = $allocation->shortage;
            $activeAllocations = $shortage->allocations()
                ->whereNotIn('status', [
                    WmsShortageAllocation::STATUS_CANCELLED,
                    WmsShortageAllocation::STATUS_FULFILLED,
                ])
                ->count();

            if ($activeAllocations === 0) {
                $shortage->update([
                    'status' => WmsShortage::STATUS_OPEN,
                ]);
            }

            Log::info('Proxy shipment cancelled', [
                'allocation_id' => $allocation->id,
                'shortage_id' => $shortage->id,
            ]);
        });
    }

    /**
     * 欠品の充足状況を更新
     *
     * @param WmsShortage $shortage
     * @return void
     */
    public function updateFulfillmentStatus(WmsShortage $shortage): void
    {
        $totalPicked = $shortage->allocations()
            ->where('status', WmsShortageAllocation::STATUS_FULFILLED)
            ->sum('assign_qty_each');

        $remaining = $shortage->shortage_qty_each - $totalPicked;

        $newStatus = $remaining <= 0
            ? WmsShortage::STATUS_FULFILLED
            : WmsShortage::STATUS_OPEN;

        if ($shortage->status !== $newStatus) {
            $shortage->update(['status' => $newStatus]);

            Log::info('Shortage fulfillment status updated', [
                'shortage_id' => $shortage->id,
                'total_picked' => $totalPicked,
                'remaining' => $remaining,
                'new_status' => $newStatus,
            ]);
        }
    }
}
