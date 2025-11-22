<?php

namespace App\Actions\Wms;

use App\Enums\QuantityType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代理出荷の倉庫単価を取得するアクション
 *
 * 取得優先順位:
 * 1. item_partner_prices (倉庫単価) - warehouse_id指定、partner_id = null
 * 2. item_prices (原価単価) - フォールバック
 * 3. デフォルト値 (0, 0)
 */
class GetWarehousePriceForAllocation
{
    /**
     * 倉庫単価と容器単価を取得
     *
     * @param int $itemId 商品ID
     * @param int $sourceWarehouseId 元倉庫ID（欠品発生倉庫）
     * @param QuantityType $quantityType 数量タイプ (PIECE, CASE, CARTON)
     * @param string|null $asOfDate 基準日 (null = 今日)
     * @return array ['purchase_price' => float, 'tax_exempt_price' => float]
     */
    public static function execute(
        int $itemId,
        int $sourceWarehouseId,
        QuantityType $quantityType,
        ?string $asOfDate = null
    ): array {
        $date = $asOfDate ?? now()->toDateString();

        // Priority 1: item_partner_prices から倉庫単価を取得
        $warehousePrice = self::getFromItemPartnerPrices($itemId, $sourceWarehouseId, $quantityType, $date);

        if ($warehousePrice !== null) {
            Log::info('Warehouse price retrieved from item_partner_prices', [
                'item_id' => $itemId,
                'source_warehouse_id' => $sourceWarehouseId,
                'quantity_type' => $quantityType->value,
                'purchase_price' => $warehousePrice['purchase_price'],
                'tax_exempt_price' => $warehousePrice['tax_exempt_price'],
            ]);

            return $warehousePrice;
        }

        // Priority 2: item_prices から原価単価を取得
        $itemPrice = self::getFromItemPrices($itemId, $quantityType, $date);

        if ($itemPrice !== null) {
            Log::info('Warehouse price retrieved from item_prices (fallback)', [
                'item_id' => $itemId,
                'source_warehouse_id' => $sourceWarehouseId,
                'quantity_type' => $quantityType->value,
                'purchase_price' => $itemPrice['purchase_price'],
                'tax_exempt_price' => $itemPrice['tax_exempt_price'],
            ]);

            return $itemPrice;
        }

        // Priority 3: デフォルト値
        Log::warning('No price found for warehouse allocation, using default (0, 0)', [
            'item_id' => $itemId,
            'source_warehouse_id' => $sourceWarehouseId,
            'quantity_type' => $quantityType->value,
        ]);

        return [
            'purchase_price' => 0.00,
            'tax_exempt_price' => 0.00,
        ];
    }

    /**
     * item_partner_pricesから倉庫単価を取得
     *
     * @param int $itemId
     * @param int $warehouseId
     * @param QuantityType $quantityType
     * @param string $date
     * @return array|null
     */
    protected static function getFromItemPartnerPrices(
        int $itemId,
        int $warehouseId,
        QuantityType $quantityType,
        string $date
    ): ?array {
        // 数量タイプに応じたカラム名を決定
        [$purchaseField, $taxExemptField] = self::getItemPartnerPriceFields($quantityType);

        if ($purchaseField === null || $taxExemptField === null) {
            return null;
        }

        $result = DB::connection('sakemaru')
            ->table('item_partner_prices')
            ->select($purchaseField, $taxExemptField)
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('partner_id')
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->orderBy('start_date', 'desc')
            ->first();

        if (!$result) {
            return null;
        }

        // 単価がnullまたは0の場合はnullを返す
        $purchasePrice = $result->{$purchaseField} ?? 0;
        $taxExemptPrice = $result->{$taxExemptField} ?? 0;

        if ($purchasePrice == 0 && $taxExemptPrice == 0) {
            return null;
        }

        return [
            'purchase_price' => (float) $purchasePrice,
            'tax_exempt_price' => (float) $taxExemptPrice,
        ];
    }

    /**
     * item_pricesから原価単価を取得
     *
     * @param int $itemId
     * @param QuantityType $quantityType
     * @param string $date
     * @return array|null
     */
    protected static function getFromItemPrices(
        int $itemId,
        QuantityType $quantityType,
        string $date
    ): ?array {
        // 数量タイプに応じたカラム名を決定
        [$costField, $taxExemptField] = self::getItemPriceFields($quantityType);

        if ($costField === null || $taxExemptField === null) {
            return null;
        }

        $result = DB::connection('sakemaru')
            ->table('item_prices')
            ->select($costField, $taxExemptField)
            ->where('item_id', $itemId)
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->orderBy('start_date', 'desc')
            ->first();

        if (!$result) {
            return null;
        }

        // 単価がnullまたは0の場合はnullを返す
        $costPrice = $result->{$costField} ?? 0;
        $taxExemptPrice = $result->{$taxExemptField} ?? 0;

        if ($costPrice == 0 && $taxExemptPrice == 0) {
            return null;
        }

        return [
            'purchase_price' => (float) $costPrice,
            'tax_exempt_price' => (float) $taxExemptPrice,
        ];
    }

    /**
     * 数量タイプに応じた item_partner_prices のフィールド名を返す
     *
     * @param QuantityType $quantityType
     * @return array [purchase_field, tax_exempt_field]
     */
    protected static function getItemPartnerPriceFields(QuantityType $quantityType): array
    {
        return match ($quantityType) {
            QuantityType::PIECE => ['purchase_unit_price', 'tax_exempt_unit_price'],
            QuantityType::CASE => ['purchase_case_price', 'tax_exempt_case_price'],
            QuantityType::CARTON => [null, 'tax_exempt_carton_price'], // CARTONはpurchase_priceなし
        };
    }

    /**
     * 数量タイプに応じた item_prices のフィールド名を返す
     *
     * @param QuantityType $quantityType
     * @return array [cost_field, tax_exempt_field]
     */
    protected static function getItemPriceFields(QuantityType $quantityType): array
    {
        return match ($quantityType) {
            QuantityType::PIECE => ['cost_unit_price', 'tax_exempt_unit_price'],
            QuantityType::CASE => ['cost_case_price', 'tax_exempt_case_price'],
            QuantityType::CARTON => [null, 'tax_exempt_carton_price'], // CARTONはcost_priceなし
        };
    }
}
