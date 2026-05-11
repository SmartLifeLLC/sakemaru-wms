<?php

namespace App\Services\AutoOrder;

use App\Enums\EPurchasePriceType;
use App\Enums\QuantityType;
use Illuminate\Support\Facades\DB;

class OrderOutputQuantityResolver
{
    private array $orderingUnitQtyCache = [];

    private array $orderingCodeInfoCache = [];

    private array $purchaseUnitPriceCache = [];

    private array $preferredOrderingUnitCodeCache = [];

    private array $janCodeCache = [];

    /**
     * 発注データ出力用の数量に変換する。候補レコード自体は更新しない。
     *
     * @return array{
     *     ordering_code: string|null,
     *     ordering_unit_quantity: int|null,
     *     display_capacity: int,
     *     order_quantity: int,
     *     quantity_type: string,
     *     unit_label: string,
     *     case_quantity: int,
     *     piece_quantity: int
     * }
     */
    public function resolve($candidate): array
    {
        $item = $candidate->item;
        $capacityCase = max(1, (int) ($item?->capacity_case ?? 1));
        $orderingCode = $this->resolveOrderingCode($candidate);
        $orderingUnitQty = $this->getOrderingUnitQuantity($item?->id, $orderingCode, $capacityCase);

        $orderQuantity = max(0, (int) $candidate->order_quantity);
        $quantityType = $candidate->quantity_type instanceof QuantityType
            ? $candidate->quantity_type
            : QuantityType::tryFrom((string) $candidate->quantity_type);

        if ($orderingUnitQty !== null && ! $this->isAlreadyConvertedToOrderingUnit($candidate, $orderingUnitQty)) {
            $orderQuantity = $this->convertToOrderingUnitQuantity($candidate, $orderingUnitQty, $capacityCase);
        }

        if ($orderingUnitQty !== null) {
            return [
                'ordering_code' => $orderingCode,
                'ordering_unit_quantity' => $orderingUnitQty,
                'display_capacity' => $orderingUnitQty,
                'order_quantity' => $orderQuantity,
                'quantity_type' => QuantityType::CASE->value,
                'unit_label' => QuantityType::CASE->name(),
                'case_quantity' => $orderQuantity,
                'piece_quantity' => 0,
            ];
        }

        $isCase = $quantityType === QuantityType::CASE;
        $isCarton = $quantityType === QuantityType::CARTON;
        $resolvedType = $quantityType?->value ?? QuantityType::PIECE->value;

        return [
            'ordering_code' => $orderingCode,
            'ordering_unit_quantity' => null,
            'display_capacity' => $capacityCase,
            'order_quantity' => $orderQuantity,
            'quantity_type' => $resolvedType,
            'unit_label' => match ($quantityType) {
                QuantityType::CASE => QuantityType::CASE->name(),
                QuantityType::CARTON => QuantityType::CARTON->name(),
                default => QuantityType::PIECE->name(),
            },
            'case_quantity' => $isCase ? $orderQuantity : 0,
            'piece_quantity' => (! $isCase && ! $isCarton) ? $orderQuantity : 0,
        ];
    }

    public function resolveUnitPrice($candidate, $schedule = null): float|string
    {
        $output = $this->resolve($candidate);

        if ($output['ordering_unit_quantity'] !== null) {
            $pieceUnitPrice = $schedule?->unit_price;

            if ($pieceUnitPrice === null && $schedule?->case_price !== null && $candidate->item?->capacity_case > 0) {
                $pieceUnitPrice = (float) $schedule->case_price / (int) $candidate->item->capacity_case;
            }

            if ($pieceUnitPrice === null) {
                $pieceUnitPrice = $this->getCurrentPurchaseUnitPrice($candidate->item?->id);
            }

            return $pieceUnitPrice !== null
                ? round((float) $pieceUnitPrice * $output['ordering_unit_quantity'], 2)
                : '';
        }

        if (! $schedule) {
            return '';
        }

        $unitPrice = match ($schedule->price_type) {
            'CASE' => $schedule->case_price,
            default => $schedule->unit_price,
        };

        return $unitPrice !== null ? (float) $unitPrice : '';
    }

    public function sumOutputOrderQuantity(iterable $candidates): int
    {
        $total = 0;

        foreach ($candidates as $candidate) {
            $total += $this->resolve($candidate)['order_quantity'];
        }

        return $total;
    }

    private function convertToOrderingUnitQuantity($candidate, int $orderingUnitQty, int $capacityCase): int
    {
        $quantity = max(0, (int) $candidate->order_quantity);
        $quantityType = $candidate->quantity_type instanceof QuantityType
            ? $candidate->quantity_type
            : QuantityType::tryFrom((string) $candidate->quantity_type);

        $pieceQuantity = $quantityType === QuantityType::CASE
            ? $quantity * max(1, $capacityCase)
            : $quantity;

        $orderQuantity = (int) ceil($pieceQuantity / $orderingUnitQty);
        if ($orderingUnitQty === 6 && $orderQuantity > 0) {
            $orderQuantity = (int) (ceil($orderQuantity / 4) * 4);
        }

        return $orderQuantity;
    }

    private function isAlreadyConvertedToOrderingUnit($candidate, int $orderingUnitQty): bool
    {
        if ($candidate->purchase_unit_price === null) {
            return false;
        }

        $piecePurchasePrice = $this->getCurrentPurchaseUnitPrice($candidate->item?->id);
        if ($piecePurchasePrice === null) {
            return false;
        }

        $expectedUnitPrice = round($piecePurchasePrice * $orderingUnitQty, 2);

        return abs(round((float) $candidate->purchase_unit_price, 2) - $expectedUnitPrice) < 0.01;
    }

    private function getOrderingUnitQuantity(?int $itemId, ?string $orderingCode = null, ?int $capacityCase = null): ?int
    {
        if (! $itemId) {
            return null;
        }

        $cacheKey = $itemId.':'.($orderingCode ?? '');
        if (array_key_exists($cacheKey, $this->orderingUnitQtyCache)) {
            return $this->orderingUnitQtyCache[$cacheKey];
        }

        if (array_key_exists($cacheKey, $this->orderingCodeInfoCache)) {
            $row = $this->orderingCodeInfoCache[$cacheKey];
        } else {
            $query = DB::connection('sakemaru')
                ->table('item_search_information as isi')
                ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
                ->where('isi.item_id', $itemId)
                ->where('isi.is_active', true)
                ->where('iqi.quantity', '>', 1);

            if ($orderingCode) {
                $query->whereRaw('LPAD(isi.search_string, 13, "0") = ?', [$orderingCode]);
            } else {
                $query->where('isi.is_used_for_ordering', true);
            }

            $row = $query->select('iqi.quantity')->first();
            $this->orderingCodeInfoCache[$cacheKey] = $row;
        }

        $qty = $row ? (int) $row->quantity : null;

        if ($qty !== null && $qty > 1) {
            $caseCapacity = $capacityCase ?? (int) (DB::connection('sakemaru')
                ->table('items')->where('id', $itemId)->value('capacity_case') ?? 0);

            if ($qty === $caseCapacity) {
                $qty = null;
            }
        } else {
            $qty = null;
        }

        $this->orderingUnitQtyCache[$cacheKey] = $qty;

        return $qty;
    }

    private function resolveOrderingCode($candidate): ?string
    {
        $item = $candidate->item;
        $candidateCode = $this->normalizeOrderingCode($candidate->ordering_code);
        $capacityCase = (int) ($item?->capacity_case ?? 1);

        if ($candidateCode) {
            return $candidateCode;
        }

        return $this->getPreferredOrderingUnitCode($item?->id, $capacityCase)
            ?? $candidateCode
            ?? $this->normalizeOrderingCode($this->getJanCode($item?->id));
    }

    private function getPreferredOrderingUnitCode(?int $itemId, int $capacityCase): ?string
    {
        if (! $itemId) {
            return null;
        }

        if (array_key_exists($itemId, $this->preferredOrderingUnitCodeCache)) {
            return $this->preferredOrderingUnitCodeCache[$itemId];
        }

        $row = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->join('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->where('isi.item_id', $itemId)
            ->where('isi.is_active', true)
            ->where('iqi.quantity', '>', 1)
            ->when($capacityCase > 1, fn ($query) => $query->where('iqi.quantity', '!=', $capacityCase))
            ->whereRaw("isi.search_string REGEXP '[1-9]'")
            ->orderByDesc('isi.is_used_for_ordering')
            ->orderBy('iqi.quantity')
            ->value('isi.search_string');

        return $this->preferredOrderingUnitCodeCache[$itemId] = $this->normalizeOrderingCode($row);
    }

    private function getJanCode(?int $itemId): string
    {
        if (! $itemId) {
            return '';
        }

        if (isset($this->janCodeCache[$itemId])) {
            return $this->janCodeCache[$itemId];
        }

        $codeInfo = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->whereRaw("search_string REGEXP '[1-9]'")
            ->first();

        if (! $codeInfo) {
            $codeInfo = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('code_type', 'JAN')
                ->where('is_active', true)
                ->whereRaw("search_string REGEXP '[1-9]'")
                ->orderByRaw("CASE WHEN quantity_type = 'PIECE' THEN 0 ELSE 1 END")
                ->first();
        }

        if (! $codeInfo) {
            $codeInfo = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $itemId)
                ->where('code_type', 'OTHER')
                ->where('is_active', true)
                ->whereRaw("search_string REGEXP '[1-9]'")
                ->whereRaw("search_string REGEXP '^[0-9]{7,}$'")
                ->orderByRaw("CASE WHEN quantity_type = 'PIECE' THEN 0 ELSE 1 END")
                ->first();
        }

        $code = $this->normalizeOrderingCode($codeInfo->search_string ?? '') ?? '';

        $this->janCodeCache[$itemId] = $code;

        return $code;
    }

    private function getCurrentPurchaseUnitPrice(?int $itemId): ?float
    {
        if (! $itemId) {
            return null;
        }

        if (array_key_exists($itemId, $this->purchaseUnitPriceCache)) {
            return $this->purchaseUnitPriceCache[$itemId];
        }

        $price = DB::connection('sakemaru')
            ->table('item_prices as ip')
            ->join('items as i', 'i.id', '=', 'ip.item_id')
            ->where('ip.item_id', $itemId)
            ->where('ip.is_active', true)
            ->where('ip.start_date', '<=', now()->toDateString())
            ->orderBy('ip.start_date', 'desc')
            ->select([
                'i.purchase_price_type',
                'ip.producer_unit_price',
                'ip.cost_unit_price',
                'ip.wholesale_unit_price',
            ])
            ->first();

        $purchaseUnitPrice = null;
        if ($price) {
            $purchaseUnitPrice = match ($price->purchase_price_type) {
                EPurchasePriceType::PRODUCER->value => $price->producer_unit_price,
                EPurchasePriceType::COST->value => $price->cost_unit_price,
                EPurchasePriceType::WHOLESALE->value => $price->wholesale_unit_price,
                default => null,
            };
        }

        $this->purchaseUnitPriceCache[$itemId] = $purchaseUnitPrice !== null ? (float) $purchaseUnitPrice : null;

        return $this->purchaseUnitPriceCache[$itemId];
    }

    private function normalizeOrderingCode(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '' || preg_match('/^0+$/', $code) === 1) {
            return null;
        }

        return str_pad($code, 13, '0', STR_PAD_LEFT);
    }
}
