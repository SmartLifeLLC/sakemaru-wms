<?php

namespace App\Services\Shortage;

use App\Models\WmsPicker;
use App\Models\WmsShortageAllocation;
use App\Services\QuantityUpdate\AllocationSyncService;
use Illuminate\Support\Facades\Log;

class ProxyShipmentPickingService
{
    public function __construct(
        protected StockTransferQueueService $stockTransferQueueService,
        protected AllocationSyncService $allocationSyncService,
    ) {}

    /**
     * 横持ち出荷を開始（RESERVED→PICKING）
     */
    public function start(WmsShortageAllocation $allocation, WmsPicker $picker): WmsShortageAllocation
    {
        return app(ProxyShipmentLifecycleService::class)->locked($allocation->id, function (WmsShortageAllocation $allocation) use ($picker) {
            $allocation->assertWorkable();
            // PICKING の再送は成功扱い
            if ($allocation->status === WmsShortageAllocation::STATUS_PICKING) {
                return $allocation->fresh();
            }

            if ($allocation->status !== WmsShortageAllocation::STATUS_RESERVED) {
                abort(422, 'この横持ち出荷は開始できません（ステータス: '.$allocation->status.'）');
            }

            $allocation->update([
                'status' => WmsShortageAllocation::STATUS_PICKING,
                'started_at' => now(),
                'started_picker_id' => $picker->id,
            ]);

            Log::info('Proxy shipment started', [
                'allocation_id' => $allocation->id,
                'picker_id' => $picker->id,
            ]);

            return $allocation->fresh();
        });
    }

    /**
     * ピック数更新
     */
    public function update(WmsShortageAllocation $allocation, WmsPicker $picker, int $pickedQty): WmsShortageAllocation
    {
        return app(ProxyShipmentLifecycleService::class)->locked($allocation->id, function (WmsShortageAllocation $allocation) use ($picker, $pickedQty) {
            $allocation->assertWorkable();
            if ($pickedQty > $allocation->assign_qty) {
                abort(422, "ピック数({$pickedQty})が指示数({$allocation->assign_qty})を超えています");
            }

            $updateData = ['picked_qty' => $pickedQty];

            // RESERVED→暗黙的にPICKINGに遷移
            if ($allocation->status === WmsShortageAllocation::STATUS_RESERVED) {
                $updateData['status'] = WmsShortageAllocation::STATUS_PICKING;
                $updateData['started_at'] = now();
                $updateData['started_picker_id'] = $picker->id;
            }

            $allocation->update($updateData);

            Log::info('Proxy shipment updated', [
                'allocation_id' => $allocation->id,
                'picked_qty' => $pickedQty,
                'picker_id' => $picker->id,
            ]);

            return $allocation->fresh();
        });
    }

    /**
     * 横持ち出荷完了
     *
     * @return array{allocation: WmsShortageAllocation, stock_transfer_queue_id: int|null, quantity_update_queue_id: int|null, quantity_sync_failed: bool}
     */
    public function complete(WmsShortageAllocation $allocation, WmsPicker $picker, ?int $pickedQty = null): array
    {
        return app(ProxyShipmentLifecycleService::class)->complete($allocation->id, pickerId: $picker->id, pickedQty: $pickedQty);
    }
}
