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
     * @param int $assignQty 移動出荷数量（受注単位ベース）
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
        // 受注単位と移動出荷単位が一致しているか確認
        if ($assignQtyType !== $shortage->qty_type_at_order) {
            throw new \Exception('移動出荷単位は受注単位と一致させてください');
        }

        // 欠品数を超える指定は警告
        if ($assignQty > $shortage->remaining_qty) {
            Log::warning('Proxy shipment quantity exceeds shortage', [
                'shortage_id' => $shortage->id,
                'remaining_qty' => $shortage->remaining_qty,
                'assign_qty' => $assignQty,
            ]);
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $shortage,
            $fromWarehouseId,
            $assignQty,
            $assignQtyType,
            $createdBy
        ) {
            // 移動出荷指示レコード作成（受注単位ベース）
            $allocation = WmsShortageAllocation::create([
                'shortage_id' => $shortage->id,
                'from_warehouse_id' => $fromWarehouseId,
                'assign_qty' => $assignQty,
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
                'assign_qty' => $assignQty,
                'assign_qty_type' => $assignQtyType,
            ]);

            return $allocation;
        });
    }

    /**
     * 移動出荷指示を更新
     *
     * @param WmsShortageAllocation $allocation
     * @param int $fromWarehouseId 移動出荷倉庫
     * @param int $assignQty 移動出荷数量（受注単位ベース）
     * @param int $updatedBy 更新者
     * @return WmsShortageAllocation
     * @throws \Exception
     */
    public function updateProxyShipment(
        WmsShortageAllocation $allocation,
        int $fromWarehouseId,
        int $assignQty,
        int $updatedBy
    ): WmsShortageAllocation {
        $shortage = $allocation->shortage;

        // 欠品数を超える指定は警告
        if ($assignQty > $shortage->shortage_qty) {
            Log::warning('Proxy shipment quantity exceeds shortage', [
                'allocation_id' => $allocation->id,
                'shortage_id' => $shortage->id,
                'shortage_qty' => $shortage->shortage_qty,
                'assign_qty' => $assignQty,
            ]);
        }

        return DB::connection('sakemaru')->transaction(function () use (
            $allocation,
            $fromWarehouseId,
            $assignQty,
            $updatedBy
        ) {
            $allocation->update([
                'from_warehouse_id' => $fromWarehouseId,
                'assign_qty' => $assignQty,
                'updated_by' => $updatedBy,
            ]);

            Log::info('Proxy shipment updated', [
                'allocation_id' => $allocation->id,
                'from_warehouse_id' => $fromWarehouseId,
                'assign_qty' => $assignQty,
            ]);

            return $allocation;
        });
    }

    /**
     * 移動出荷をキャンセル
     *
     * @param WmsShortageAllocation $allocation
     * @param int|null $cancelledBy キャンセル実行者（オプション）
     * @return void
     */
    public function cancelProxyShipment(WmsShortageAllocation $allocation, ?int $cancelledBy = null): void
    {
        DB::connection('sakemaru')->transaction(function () use ($allocation, $cancelledBy) {
            $updateData = [
                'status' => WmsShortageAllocation::STATUS_CANCELLED,
            ];

            if ($cancelledBy !== null) {
                $updateData['cancelled_by'] = $cancelledBy;
            }

            $allocation->update($updateData);

            // 他の移動出荷がなければ、欠品ステータスを BEFORE に戻す
            $shortage = $allocation->shortage;
            $activeAllocations = $shortage->allocations()
                ->whereNotIn('status', [
                    WmsShortageAllocation::STATUS_CANCELLED,
                    WmsShortageAllocation::STATUS_FULFILLED,
                ])
                ->count();

            if ($activeAllocations === 0) {
                $shortage->update([
                    'status' => WmsShortage::STATUS_BEFORE,
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
            ->sum('assign_qty');

        $remaining = $shortage->shortage_qty - $totalPicked;

        // 完全に充足されたら欠品確定、残りがあれば部分欠品
        if ($remaining <= 0) {
            $newStatus = WmsShortage::STATUS_SHORTAGE;
        } elseif ($totalPicked > 0) {
            $newStatus = WmsShortage::STATUS_PARTIAL_SHORTAGE;
        } else {
            $newStatus = WmsShortage::STATUS_BEFORE;
        }

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
