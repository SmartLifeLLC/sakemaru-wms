<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 移動候補と発注候補の連動再計算サービス
 *
 * 移動候補の数量変更時に、関連する発注候補の数量を再計算する
 */
class TransferOrderRecalculationService
{
    /**
     * 移動候補の数量変更時に関連発注候補を再計算
     *
     * @param  WmsStockTransferCandidate  $transfer  変更された移動候補
     * @param  int  $oldQuantity  変更前の数量
     * @param  int  $newQuantity  変更後の数量
     * @return WmsOrderCandidate|null 更新された発注候補（存在しない場合はnull）
     */
    public function recalculateOrderForTransfer(
        WmsStockTransferCandidate $transfer,
        int $oldQuantity,
        int $newQuantity
    ): ?WmsOrderCandidate {
        // 数量に変化がない場合は何もしない
        if ($oldQuantity === $newQuantity) {
            return null;
        }

        // 関連する発注候補を取得
        $orderCandidate = $this->findRelatedOrderCandidate($transfer);

        if (! $orderCandidate) {
            Log::info('TransferOrderRecalculation: 関連発注候補なし', [
                'transfer_id' => $transfer->id,
                'batch_code' => $transfer->batch_code,
                'hub_warehouse_id' => $transfer->hub_warehouse_id,
                'item_id' => $transfer->item_id,
            ]);

            return null;
        }

        // satellite_demand_qty を再計算
        $newSatelliteDemand = $this->calculateSatelliteDemand(
            $transfer->batch_code,
            $transfer->hub_warehouse_id,
            $transfer->item_id
        );

        $totalRequired = $orderCandidate->self_shortage_qty + $newSatelliteDemand;
        $settings = $this->getOrderSettings($orderCandidate->warehouse_id, $orderCandidate->item_id);
        $calculatedStock = max(0, (int) $orderCandidate->safety_stock - $totalRequired);
        $quantityCalculation = app(OrderQuantityAdjustmentService::class)->calculate(
            shortageQty: $totalRequired,
            purchaseUnit: $settings['purchase_unit'],
            autoOrderQuantity: $settings['auto_order_quantity'],
            maxStock: $settings['max_stock'],
            calculatedStock: $calculatedStock,
            orderingUnitQty: $settings['ordering_unit_quantity'],
        );
        $newSuggestedQuantity = $quantityCalculation['order_quantity'];
        if ($newSuggestedQuantity <= 0) {
            $orderCandidate->status = CandidateStatus::EXCLUDED;
            $orderCandidate->exclusion_reason = '最大発注点制約により有効な発注単位がありません';
            $orderCandidate->is_manually_modified = true;
            $orderCandidate->modified_by = auth()->id();
            $orderCandidate->modified_at = now();
            $orderCandidate->save();

            return $orderCandidate;
        }

        $newOrderQuantity = $this->toStoredOrderQuantity($orderCandidate, $newSuggestedQuantity, $settings['capacity_case']);

        // demand_breakdown を再計算
        $newDemandBreakdown = $this->buildDemandBreakdown(
            $transfer->batch_code,
            $orderCandidate->warehouse_id,
            $orderCandidate->item_id,
            $orderCandidate->self_shortage_qty
        );

        // 発注候補を更新
        $orderCandidate->satellite_demand_qty = $newSatelliteDemand;
        $orderCandidate->suggested_quantity = $newSuggestedQuantity;
        $orderCandidate->order_quantity = $newOrderQuantity;
        $orderCandidate->demand_breakdown = $newDemandBreakdown;
        $orderCandidate->is_manually_modified = true;
        $orderCandidate->modified_by = auth()->id();
        $orderCandidate->modified_at = now();
        $orderCandidate->save();

        Log::info('TransferOrderRecalculation: 発注候補を再計算', [
            'order_candidate_id' => $orderCandidate->id,
            'old_satellite_demand' => $orderCandidate->getOriginal('satellite_demand_qty'),
            'new_satellite_demand' => $newSatelliteDemand,
            'new_suggested_quantity' => $newSuggestedQuantity,
            'new_order_quantity' => $newOrderQuantity,
        ]);

        return $orderCandidate;
    }

    /**
     * 移動候補に関連する発注候補を取得
     */
    public function findRelatedOrderCandidate(WmsStockTransferCandidate $transfer): ?WmsOrderCandidate
    {
        return WmsOrderCandidate::where('batch_code', $transfer->batch_code)
            ->where('warehouse_id', $transfer->hub_warehouse_id)
            ->where('item_id', $transfer->item_id)
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
            ->first();
    }

    /**
     * サテライト需要（移動出庫）の合計を計算
     */
    public function calculateSatelliteDemand(string $batchCode, int $hubWarehouseId, int $itemId): int
    {
        return (int) WmsStockTransferCandidate::where('batch_code', $batchCode)
            ->where('hub_warehouse_id', $hubWarehouseId)
            ->where('item_id', $itemId)
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
            ->sum('transfer_quantity');
    }

    /**
     * 需要内訳を構築
     */
    public function buildDemandBreakdown(
        string $batchCode,
        int $warehouseId,
        int $itemId,
        int $selfShortageQty
    ): array {
        $breakdown = [];

        // 自倉庫の不足分
        if ($selfShortageQty > 0) {
            $breakdown[] = [
                'warehouse_id' => $warehouseId,
                'quantity' => $selfShortageQty,
            ];
        }

        // サテライト倉庫からの需要（移動出庫の内訳）
        $transfers = WmsStockTransferCandidate::where('batch_code', $batchCode)
            ->where('hub_warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
            ->get();

        foreach ($transfers as $transfer) {
            if ($transfer->transfer_quantity > 0) {
                $breakdown[] = [
                    'warehouse_id' => $transfer->satellite_warehouse_id,
                    'quantity' => $transfer->transfer_quantity,
                ];
            }
        }

        return $breakdown;
    }

    /**
     * @return array{purchase_unit: int, max_stock: int, auto_order_quantity: int, ordering_unit_quantity: int|null, capacity_case: int}
     */
    private function getOrderSettings(int $warehouseId, int $itemId): array
    {
        $settings = DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->join('items as i', 'i.id', '=', 'ic.item_id')
            ->where('ic.warehouse_id', $warehouseId)
            ->where('ic.item_id', $itemId)
            ->select('ic.purchase_unit', 'ic.max_stock', 'ic.auto_order_quantity', 'i.capacity_case')
            ->first();

        return [
            'purchase_unit' => max(1, (int) ($settings?->purchase_unit ?? 1)),
            'max_stock' => max(0, (int) ($settings?->max_stock ?? 0)),
            'auto_order_quantity' => max(0, (int) ($settings?->auto_order_quantity ?? 0)),
            'ordering_unit_quantity' => $this->getOrderingUnitQuantity($itemId),
            'capacity_case' => max(1, (int) ($settings?->capacity_case ?? 1)),
        ];
    }

    private function getOrderingUnitQuantity(int $itemId): ?int
    {
        $qty = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->where('isi.item_id', $itemId)
            ->where('isi.is_used_for_ordering', true)
            ->where('isi.is_active', true)
            ->where('iqi.quantity', '>', 1)
            ->value('iqi.quantity');

        $qty = $qty !== null ? (int) $qty : null;

        return $qty !== null && $qty > 1 ? $qty : null;
    }

    private function toStoredOrderQuantity(WmsOrderCandidate $candidate, int $pieceQuantity, int $capacityCase): int
    {
        $quantityType = $candidate->quantity_type instanceof QuantityType
            ? $candidate->quantity_type
            : QuantityType::tryFrom((string) $candidate->quantity_type);

        if ($quantityType !== QuantityType::CASE) {
            return $pieceQuantity;
        }

        return $pieceQuantity > 0 ? (int) ceil($pieceQuantity / max(1, $capacityCase)) : 0;
    }

    /**
     * 数量を指定単位で切り上げ。
     * 既存テストと外部参照の互換性維持用。
     */
    private function roundUpToUnit(int $quantity, int $unit): int
    {
        if ($unit <= 1 || $quantity <= 0) {
            return max(0, $quantity);
        }

        return (int) ceil($quantity / $unit) * $unit;
    }

    /**
     * 移動候補除外時に発注候補も不要になるか判定し、必要なら除外
     *
     * @param  WmsStockTransferCandidate  $transfer  除外された移動候補
     * @return bool 発注候補が除外されたかどうか
     */
    public function checkAndExcludeOrderCandidate(WmsStockTransferCandidate $transfer): bool
    {
        $orderCandidate = $this->findRelatedOrderCandidate($transfer);

        if (! $orderCandidate) {
            return false;
        }

        // 移動候補を除外した後のサテライト需要を計算
        $remainingSatelliteDemand = $this->calculateSatelliteDemand(
            $transfer->batch_code,
            $transfer->hub_warehouse_id,
            $transfer->item_id
        );

        // 自倉庫の不足分もサテライト需要も0なら発注不要
        if ($orderCandidate->self_shortage_qty <= 0 && $remainingSatelliteDemand <= 0) {
            $orderCandidate->status = CandidateStatus::EXCLUDED;
            $orderCandidate->exclusion_reason = '移動数量変更により発注不要';
            $orderCandidate->is_manually_modified = true;
            $orderCandidate->modified_by = auth()->id();
            $orderCandidate->modified_at = now();
            $orderCandidate->save();

            Log::info('TransferOrderRecalculation: 発注候補を除外', [
                'order_candidate_id' => $orderCandidate->id,
                'reason' => '移動数量変更により発注不要',
            ]);

            return true;
        }

        // 発注は必要だがサテライト需要は減少
        $this->recalculateOrderForTransfer($transfer, $transfer->transfer_quantity, 0);

        return false;
    }
}
