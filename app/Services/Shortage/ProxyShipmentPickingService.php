<?php

namespace App\Services\Shortage;

use App\Models\WmsPicker;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProxyShipmentPickingService
{
    public function __construct(
        protected StockTransferQueueService $stockTransferQueueService,
    ) {}

    /**
     * 横持ち出荷を開始（RESERVED→PICKING）
     */
    public function start(WmsShortageAllocation $allocation, WmsPicker $picker): WmsShortageAllocation
    {
        // PICKING の再送は成功扱い
        if ($allocation->status === WmsShortageAllocation::STATUS_PICKING) {
            return $allocation->fresh();
        }

        if ($allocation->status !== WmsShortageAllocation::STATUS_RESERVED) {
            abort(422, 'この横持ち出荷は開始できません（ステータス: ' . $allocation->status . '）');
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
    }

    /**
     * ピック数更新
     */
    public function update(WmsShortageAllocation $allocation, WmsPicker $picker, int $pickedQty): WmsShortageAllocation
    {
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
    }

    /**
     * 横持ち出荷完了
     *
     * @return array{allocation: WmsShortageAllocation, stock_transfer_queue_id: int|null}
     */
    public function complete(WmsShortageAllocation $allocation, WmsPicker $picker, ?int $pickedQty = null): array
    {
        // べき等性: 完了済み or 欠品確定済みの再送は200を返す
        if ($allocation->is_finished || $allocation->status === WmsShortageAllocation::STATUS_SHORTAGE) {
            $existingQueueId = $this->findExistingQueueId($allocation);

            return [
                'allocation' => $allocation,
                'stock_transfer_queue_id' => $existingQueueId,
            ];
        }

        // picked_qty が送られた場合は最後の更新値として採用
        if ($pickedQty !== null) {
            if ($pickedQty > $allocation->assign_qty) {
                abort(422, "ピック数({$pickedQty})が指示数({$allocation->assign_qty})を超えています");
            }
            $allocation->picked_qty = $pickedQty;
        }

        $finalPickedQty = $allocation->picked_qty ?? 0;

        // ステータス判定
        $finalStatus = $finalPickedQty >= $allocation->assign_qty
            ? WmsShortageAllocation::STATUS_FULFILLED
            : WmsShortageAllocation::STATUS_SHORTAGE;

        $isFulfilled = $finalStatus === WmsShortageAllocation::STATUS_FULFILLED;

        return DB::connection('sakemaru')->transaction(function () use ($allocation, $picker, $finalStatus, $finalPickedQty, $isFulfilled) {
            // allocation 更新（欠品の場合は完了にしない → 管理画面に残す）
            $updateData = [
                'status' => $finalStatus,
                'picked_qty' => $finalPickedQty,
                'finished_picker_id' => $picker->id,
            ];

            if ($isFulfilled) {
                $updateData['is_finished'] = true;
                $updateData['finished_at'] = now();
            }

            $allocation->update($updateData);

            // picked_qty > 0 の場合のみ stock_transfer_queue 作成
            $queueId = null;
            if ($finalPickedQty > 0) {
                $queueId = $this->stockTransferQueueService->createStockTransferQueue(
                    $allocation->fresh()
                );
            }

            // 親 shortage の集計再計算
            $this->recalculateParentShortage($allocation);

            Log::info('Proxy shipment completed', [
                'allocation_id' => $allocation->id,
                'status' => $finalStatus,
                'picked_qty' => $finalPickedQty,
                'stock_transfer_queue_id' => $queueId,
                'picker_id' => $picker->id,
            ]);

            return [
                'allocation' => $allocation->fresh(),
                'stock_transfer_queue_id' => $queueId,
            ];
        });
    }

    /**
     * 親 shortage の集計状態を再計算
     */
    protected function recalculateParentShortage(WmsShortageAllocation $allocation): void
    {
        $shortage = $allocation->shortage;
        if (! $shortage) {
            return;
        }

        // 完了済み allocation の picked_qty 合計
        $totalPickedQty = WmsShortageAllocation::where('shortage_id', $shortage->id)
            ->where('is_finished', true)
            ->sum('picked_qty');

        if ($totalPickedQty <= 0) {
            return;
        }

        if ($totalPickedQty >= $shortage->shortage_qty) {
            $shortage->update(['status' => 'SHORTAGE']);
        } else {
            $shortage->update(['status' => 'PARTIAL_SHORTAGE']);
        }
    }

    /**
     * 既存の stock_transfer_queue ID を取得（べき等性用）
     */
    protected function findExistingQueueId(WmsShortageAllocation $allocation): ?int
    {
        $requestId = "proxy-shipment-{$allocation->id}";

        $existing = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $requestId)
            ->value('id');

        return $existing ? (int) $existing : null;
    }
}
