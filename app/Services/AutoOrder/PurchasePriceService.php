<?php

namespace App\Services\AutoOrder;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 自社仕入単価取得サービス
 *
 * 4段階フォールバックで単価を取得:
 * 1. item_partner_prices WHERE partner_id = supplier_partner_id（仕入先個別単価）
 * 2. item_partner_prices WHERE partner_id = partner_price_group_id（単価グループ1）
 * 3. item_partner_prices WHERE partner_id = partner_price_group2_id（単価グループ2）
 * 4. item_prices → items.purchase_price_type で分岐（PRODUCER/COST/WHOLESALE）
 */
class PurchasePriceService
{
    private array $partnerPriceCache = [];

    private array $itemPriceCache = [];

    private array $partnerCache = [];

    /**
     * 商品の仕入単価を取得
     *
     * @param  int  $itemId  商品ID
     * @param  int|null  $supplierPartnerId  仕入先partner_id
     * @param  int|null  $warehouseId  倉庫ID
     * @param  string|null  $processDate  処理日
     * @return array{unit_price: float, case_price: float, source: string}
     */
    public function getPrice(int $itemId, ?int $supplierPartnerId, ?int $warehouseId = null, ?string $processDate = null): array
    {
        $date = $processDate ?? now()->toDateString();

        // Step 1: 仕入先個別単価
        if ($supplierPartnerId) {
            $price = $this->getPartnerPrice($itemId, $supplierPartnerId, $warehouseId, $date);
            if ($price) {
                return [
                    'unit_price' => (float) ($price->unit_price ?? 0),
                    'case_price' => (float) ($price->case_price ?? 0),
                    'source' => 'PARTNER',
                ];
            }

            // Step 2: 単価グループ1
            $partner = $this->getPartner($supplierPartnerId);
            if ($partner && $partner->partner_price_group_id) {
                $price = $this->getPartnerPrice($itemId, $partner->partner_price_group_id, $warehouseId, $date);
                if ($price) {
                    return [
                        'unit_price' => (float) ($price->unit_price ?? 0),
                        'case_price' => (float) ($price->case_price ?? 0),
                        'source' => 'PARTNER_PRICE_GROUP',
                    ];
                }
            }

            // Step 3: 単価グループ2
            if ($partner && $partner->partner_price_group2_id) {
                $price = $this->getPartnerPrice($itemId, $partner->partner_price_group2_id, $warehouseId, $date);
                if ($price) {
                    return [
                        'unit_price' => (float) ($price->unit_price ?? 0),
                        'case_price' => (float) ($price->case_price ?? 0),
                        'source' => 'PARTNER_PRICE_GROUP2',
                    ];
                }
            }
        }

        // Step 4: item_prices + purchase_price_type
        $itemPrice = $this->getItemPrice($itemId, $date);
        if ($itemPrice) {
            $priceType = $itemPrice->purchase_price_type;
            $unitPrice = match ($priceType) {
                'PRODUCER' => (float) ($itemPrice->producer_unit_price ?? 0),
                'COST' => (float) ($itemPrice->cost_unit_price ?? 0),
                'WHOLESALE' => (float) ($itemPrice->wholesale_unit_price ?? 0),
                default => 0.0,
            };
            $casePrice = match ($priceType) {
                'PRODUCER' => (float) ($itemPrice->producer_case_price ?? 0),
                'COST' => (float) ($itemPrice->cost_case_price ?? 0),
                'WHOLESALE' => (float) ($itemPrice->wholesale_case_price ?? 0),
                default => 0.0,
            };

            return [
                'unit_price' => $unitPrice,
                'case_price' => $casePrice,
                'source' => 'ITEM_PRICE',
            ];
        }

        return ['unit_price' => 0.0, 'case_price' => 0.0, 'source' => 'NONE'];
    }

    /**
     * 複数商品の単価を一括プリロード
     */
    public function preloadPrices(array $itemIds, ?int $supplierPartnerId, ?int $warehouseId = null, ?string $processDate = null): void
    {
        if (empty($itemIds)) {
            return;
        }

        $date = $processDate ?? now()->toDateString();

        // partner_price をプリロード
        $partnerIds = [$supplierPartnerId];
        if ($supplierPartnerId) {
            $partner = $this->getPartner($supplierPartnerId);
            if ($partner) {
                if ($partner->partner_price_group_id) {
                    $partnerIds[] = $partner->partner_price_group_id;
                }
                if ($partner->partner_price_group2_id) {
                    $partnerIds[] = $partner->partner_price_group2_id;
                }
            }
        }

        $partnerIds = array_filter($partnerIds);
        if (! empty($partnerIds)) {
            $this->preloadPartnerPrices($itemIds, $partnerIds, $warehouseId, $date);
        }

        // item_prices をプリロード
        $this->preloadItemPrices($itemIds, $date);
    }

    private function getPartnerPrice(int $itemId, int $partnerId, ?int $warehouseId, string $date): ?object
    {
        $cacheKey = "{$itemId}_{$partnerId}_{$warehouseId}_{$date}";
        if (array_key_exists($cacheKey, $this->partnerPriceCache)) {
            return $this->partnerPriceCache[$cacheKey];
        }

        $price = DB::connection('sakemaru')
            ->table('item_partner_prices')
            ->where('item_id', $itemId)
            ->where('partner_id', $partnerId)
            ->where(function ($query) use ($warehouseId) {
                $query->where('warehouse_id', $warehouseId)
                    ->orWhereNull('warehouse_id');
            })
            ->where('start_date', '<=', $date)
            ->orderBy('start_date', 'desc')
            ->first();

        $this->partnerPriceCache[$cacheKey] = $price;

        return $price;
    }

    private function getPartner(int $partnerId): ?object
    {
        if (array_key_exists($partnerId, $this->partnerCache)) {
            return $this->partnerCache[$partnerId];
        }

        $partner = DB::connection('sakemaru')
            ->table('partners')
            ->where('id', $partnerId)
            ->first(['id', 'partner_price_group_id', 'partner_price_group2_id']);

        $this->partnerCache[$partnerId] = $partner;

        return $partner;
    }

    private function getItemPrice(int $itemId, string $date): ?object
    {
        $cacheKey = "{$itemId}_{$date}";
        if (array_key_exists($cacheKey, $this->itemPriceCache)) {
            return $this->itemPriceCache[$cacheKey];
        }

        $price = DB::connection('sakemaru')
            ->table('item_prices as ip')
            ->join('items as i', 'i.id', '=', 'ip.item_id')
            ->where('ip.item_id', $itemId)
            ->where('ip.is_active', true)
            ->where('ip.start_date', '<=', $date)
            ->orderBy('ip.start_date', 'desc')
            ->first([
                'ip.*',
                'i.purchase_price_type',
            ]);

        $this->itemPriceCache[$cacheKey] = $price;

        return $price;
    }

    private function preloadPartnerPrices(array $itemIds, array $partnerIds, ?int $warehouseId, string $date): void
    {
        $latestDates = DB::connection('sakemaru')
            ->table('item_partner_prices')
            ->select('item_id', 'partner_id', DB::raw('MAX(start_date) as max_start_date'))
            ->whereIn('item_id', $itemIds)
            ->whereIn('partner_id', $partnerIds)
            ->where(function ($query) use ($warehouseId) {
                $query->where('warehouse_id', $warehouseId)
                    ->orWhereNull('warehouse_id');
            })
            ->where('start_date', '<=', $date)
            ->groupBy('item_id', 'partner_id');

        $prices = DB::connection('sakemaru')
            ->table('item_partner_prices as ipp')
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('ipp.item_id', '=', 'latest.item_id')
                    ->on('ipp.partner_id', '=', 'latest.partner_id')
                    ->on('ipp.start_date', '=', 'latest.max_start_date');
            })
            ->where(function ($query) use ($warehouseId) {
                $query->where('ipp.warehouse_id', $warehouseId)
                    ->orWhereNull('ipp.warehouse_id');
            })
            ->get();

        foreach ($prices as $price) {
            $cacheKey = "{$price->item_id}_{$price->partner_id}_{$warehouseId}_{$date}";
            $this->partnerPriceCache[$cacheKey] = $price;
        }

        Log::info('[PurchasePriceService] PartnerPrices プリロード完了', [
            'items' => count($itemIds),
            'partners' => count($partnerIds),
            'loaded' => count($prices),
        ]);
    }

    private function preloadItemPrices(array $itemIds, string $date): void
    {
        $latestDates = DB::connection('sakemaru')
            ->table('item_prices')
            ->select('item_id', DB::raw('MAX(start_date) as max_start_date'))
            ->whereIn('item_id', $itemIds)
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->groupBy('item_id');

        $prices = DB::connection('sakemaru')
            ->table('item_prices as ip')
            ->join('items as i', 'i.id', '=', 'ip.item_id')
            ->joinSub($latestDates, 'latest', function ($join) {
                $join->on('ip.item_id', '=', 'latest.item_id')
                    ->on('ip.start_date', '=', 'latest.max_start_date');
            })
            ->where('ip.is_active', true)
            ->get(['ip.*', 'i.purchase_price_type']);

        foreach ($prices as $price) {
            $cacheKey = "{$price->item_id}_{$date}";
            $this->itemPriceCache[$cacheKey] = $price;
        }

        Log::info('[PurchasePriceService] ItemPrices プリロード完了', [
            'requested' => count($itemIds),
            'loaded' => count($prices),
        ]);
    }
}
