<?php

namespace App\Services\LotAdjustment;

use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\RealStockLot;

/**
 * B. 非ACTIVE残数の分類・修復（§3.3）
 *
 * 数量を持つ非ACTIVE LOT（status != ACTIVE かつ current<>0 or reserved<>0）を検出し分類する。
 *
 * - REACTIVATE_SAME_LOT : 対象LOT自身が棚番・仕入情報を持ち、ACTIVE化すると親在庫へ近づく
 *                         → そのLOT自身を ACTIVE 化（別の0/0 LOTは使わない・棚番不変）
 * - MANUAL_REQUIRED      : 帰属LOT・棚番・仕入情報を一意に判断できない → 検出のみ・適用しない
 *
 * 棚番事故防止のため、別の 0/0 DEPLETED LOT を ACTIVE 化して数量を寄せることはしない。
 * floor_id / location_id は変更しない。
 */
class LotResidualReactivationService
{
    /**
     * @return array<int, array<string, mixed>> 明細（type=REACTIVATE / SKIP）
     */
    public function applyForRealStock(int $realStockId): array
    {
        $changes = [];

        $realStock = RealStock::query()->whereKey($realStockId)->lockForUpdate()->first();
        if (! $realStock) {
            return $changes;
        }

        $residuals = RealStockLot::query()
            ->where('real_stock_id', $realStockId)
            ->where('status', '!=', RealStockLot::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->where('current_quantity', '<>', 0)
                    ->orWhere('reserved_quantity', '<>', 0);
            })
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($residuals->isEmpty()) {
            return $changes;
        }

        $parentCurrent = (int) $realStock->current_quantity;
        $activeSum = (int) RealStockLot::query()
            ->where('real_stock_id', $realStockId)
            ->where('status', RealStockLot::STATUS_ACTIVE)
            ->sum('current_quantity');

        foreach ($residuals as $lot) {
            $classification = $this->classify($lot, $parentCurrent, $activeSum);

            if ($classification === 'REACTIVATE_SAME_LOT') {
                $before = ['status' => $lot->status];
                $lot->status = RealStockLot::STATUS_ACTIVE;
                // floor_id / location_id は触らない（対象LOT自身の棚番のまま）
                $lot->save();
                $activeSum += (int) $lot->current_quantity;

                $changes[] = [
                    'type' => 'REACTIVATE',
                    'real_stock_id' => $realStockId,
                    'lot_id' => (int) $lot->id,
                    'status_before' => $before['status'],
                    'status_after' => $lot->status,
                    'current_before' => (int) $lot->current_quantity,
                    'current_after' => (int) $lot->current_quantity,
                    'reserved_before' => (int) $lot->reserved_quantity,
                    'reserved_after' => (int) $lot->reserved_quantity,
                    'location_id' => $lot->location_id !== null ? (int) $lot->location_id : null,
                    'reason' => 'REACTIVATE_SAME_LOT',
                ];
            } else {
                $changes[] = [
                    'type' => 'SKIP',
                    'real_stock_id' => $realStockId,
                    'lot_id' => (int) $lot->id,
                    'status_before' => $lot->status,
                    'status_after' => $lot->status,
                    'current_before' => (int) $lot->current_quantity,
                    'current_after' => (int) $lot->current_quantity,
                    'reserved_before' => (int) $lot->reserved_quantity,
                    'reserved_after' => (int) $lot->reserved_quantity,
                    'location_id' => $lot->location_id !== null ? (int) $lot->location_id : null,
                    'reason' => $classification,
                ];
            }
        }

        return $changes;
    }

    /**
     * 非ACTIVE残数LOTの分類。自動適用してよいのは REACTIVATE_SAME_LOT のみ。
     */
    private function classify(RealStockLot $lot, int $parentCurrent, int $activeSum): string
    {
        // 予約残のある非ACTIVE、負数残、棚番なし、仕入根拠なしは自動判断しない
        if ((int) $lot->reserved_quantity !== 0) {
            return 'MANUAL_REQUIRED';
        }
        if ((int) $lot->current_quantity <= 0) {
            return 'MANUAL_REQUIRED';
        }
        if ($lot->location_id === null) {
            return 'MANUAL_REQUIRED';
        }
        if ($lot->purchase_id === null) {
            return 'MANUAL_REQUIRED';
        }

        // ACTIVE化で親在庫へ近づく場合のみ自動適用（オーバーシュートしない）
        $oldDiff = abs($parentCurrent - $activeSum);
        $newDiff = abs($parentCurrent - ($activeSum + (int) $lot->current_quantity));
        if ($newDiff < $oldDiff) {
            return 'REACTIVATE_SAME_LOT';
        }

        return 'MANUAL_REQUIRED';
    }
}
