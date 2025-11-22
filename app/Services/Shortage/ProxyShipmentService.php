<?php

namespace App\Services\Shortage;

use App\Actions\Wms\GetWarehousePriceForAllocation;
use App\Enums\QuantityType;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 横持ち出荷サービス
 * 段階4-5: 横持ち出荷指示と引当処理
 */
class ProxyShipmentService
{
    /**
     * 横持ち出荷指示を作成
     *
     * @param WmsShortage $shortage
     * @param int $fromWarehouseId 横持ち出荷倉庫（出荷元倉庫）
     * @param int $assignQty 横持ち出荷数量（受注単位ベース）
     * @param string $assignQtyType 横持ち出荷単位 (CASE, PIECE, CARTON)
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
        // 受注単位と横持ち出荷単位が一致しているか確認
        if ($assignQtyType !== $shortage->qty_type_at_order) {
            throw new \Exception('横持ち出荷単位は受注単位と一致させてください');
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
            // 1. 倉庫単価と容器単価を取得
            $quantityType = QuantityType::tryFrom($assignQtyType);
            if (!$quantityType) {
                throw new \Exception("Invalid quantity type: {$assignQtyType}");
            }

            $prices = GetWarehousePriceForAllocation::execute(
                itemId: $shortage->item_id,
                sourceWarehouseId: $shortage->warehouse_id,  // 元倉庫ID（欠品発生倉庫）
                quantityType: $quantityType
            );

            // 2. 販売単価を取得（trade_itemsから）
            $tradeItem = DB::connection('sakemaru')
                ->table('trade_items')
                ->where('id', $shortage->trade_item_id)
                ->first();

            $salePrice = $tradeItem?->price;

            // 3. 横持ち出荷指示レコード作成（受注単位ベース）
            $allocation = WmsShortageAllocation::create([
                'shortage_id' => $shortage->id,
                'shipment_date' => $shortage->shipment_date,  // 親のshipment_dateを引き継ぐ
                'delivery_course_id' => $shortage->delivery_course_id,  // 親のdelivery_course_idを引き継ぐ
                'target_warehouse_id' => $fromWarehouseId,  // 出荷元倉庫（横持ち出荷倉庫）
                'source_warehouse_id' => $shortage->warehouse_id,  // 元倉庫ID（欠品発生倉庫）
                'assign_qty' => $assignQty,
                'assign_qty_type' => $assignQtyType,
                'purchase_price' => $prices['purchase_price'],
                'tax_exempt_price' => $prices['tax_exempt_price'],
                'price' => $salePrice,
                'status' => WmsShortageAllocation::STATUS_PENDING,
                'is_confirmed' => false,
                'created_by' => $createdBy,
            ]);

            // 4. 欠品ステータスを REALLOCATING に更新し、更新者を記録
            $shortage->update([
                'status' => WmsShortage::STATUS_REALLOCATING,
                'updater_id' => $createdBy,
            ]);

            Log::info('Proxy shipment created', [
                'allocation_id' => $allocation->id,
                'shortage_id' => $shortage->id,
                'target_warehouse_id' => $fromWarehouseId,
                'source_warehouse_id' => $shortage->warehouse_id,
                'assign_qty' => $assignQty,
                'assign_qty_type' => $assignQtyType,
                'purchase_price' => $prices['purchase_price'],
                'tax_exempt_price' => $prices['tax_exempt_price'],
                'price' => $salePrice,
            ]);

            return $allocation;
        });
    }

    /**
     * 横持ち出荷指示を更新
     *
     * @param WmsShortageAllocation $allocation
     * @param int $fromWarehouseId 横持ち出荷倉庫
     * @param int $assignQty 横持ち出荷数量（受注単位ベース）
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
                'target_warehouse_id' => $fromWarehouseId,
                'assign_qty' => $assignQty,
                'updated_by' => $updatedBy,
            ]);

            Log::info('Proxy shipment updated', [
                'allocation_id' => $allocation->id,
                'target_warehouse_id' => $fromWarehouseId,
                'assign_qty' => $assignQty,
            ]);

            return $allocation;
        });
    }

    /**
     * 横持ち出荷を削除
     *
     * @param WmsShortageAllocation $allocation
     * @return void
     */
    public function deleteProxyShipment(WmsShortageAllocation $allocation): void
    {
        DB::connection('sakemaru')->transaction(function () use ($allocation) {
            $shortage = $allocation->shortage;
            $allocationId = $allocation->id;

            // 横持ち出荷レコードを物理削除
            $allocation->delete();

            // 他の横持ち出荷がなければ、欠品ステータスを BEFORE に戻す
            $activeAllocations = $shortage->allocations()
                ->where('status', '!=', WmsShortageAllocation::STATUS_FULFILLED)
                ->count();

            if ($activeAllocations === 0) {
                $shortage->update([
                    'status' => WmsShortage::STATUS_BEFORE,
                ]);
            }

            Log::info('Proxy shipment deleted', [
                'allocation_id' => $allocationId,
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
