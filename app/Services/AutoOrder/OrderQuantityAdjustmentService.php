<?php

namespace App\Services\AutoOrder;

class OrderQuantityAdjustmentService
{
    /**
     * @return array{
     *     auto_order_quantity: int,
     *     max_stock: int,
     *     max_order_quantity: int|null,
     *     base_quantity: int,
     *     source_label: string,
     *     order_quantity: int,
     *     before_max_stock_quantity: int,
     *     max_stock_adjusted: bool,
     *     valid_order_unit: int,
     *     skipped_by_max_stock: bool,
     *     description: string
     * }
     */
    public function calculate(
        int $shortageQty,
        int $purchaseUnit,
        int $autoOrderQuantity,
        int $maxStock,
        int $calculatedStock,
        ?int $orderingUnitQty = null,
    ): array {
        $purchaseUnit = max(1, $purchaseUnit);
        $autoOrderQuantity = max(0, $autoOrderQuantity);
        $maxStock = max(0, $maxStock);
        $baseQuantity = $autoOrderQuantity > 0 ? $autoOrderQuantity : max(0, $shortageQty);
        $sourceLabel = $autoOrderQuantity > 0 ? '自動発注数' : '不足数';
        $validOrderUnit = $this->resolveValidOrderUnit($purchaseUnit, $orderingUnitQty);

        $beforeMaxStockQuantity = $this->roundUpToUnit($baseQuantity, $validOrderUnit);
        $maxOrderQuantity = $maxStock > 0 ? max(0, $maxStock - $calculatedStock) : null;

        $orderQuantity = $beforeMaxStockQuantity;
        $maxStockAdjusted = false;
        $skippedByMaxStock = false;

        if ($maxOrderQuantity !== null && $orderQuantity > $maxOrderQuantity) {
            $maxStockAdjusted = true;
            $orderQuantity = $this->roundDownToUnit($maxOrderQuantity, $validOrderUnit);
            $skippedByMaxStock = $orderQuantity <= 0;
        }

        return [
            'auto_order_quantity' => $autoOrderQuantity,
            'max_stock' => $maxStock,
            'max_order_quantity' => $maxOrderQuantity,
            'base_quantity' => $baseQuantity,
            'source_label' => $sourceLabel,
            'order_quantity' => $orderQuantity,
            'before_max_stock_quantity' => $beforeMaxStockQuantity,
            'max_stock_adjusted' => $maxStockAdjusted,
            'valid_order_unit' => $validOrderUnit,
            'skipped_by_max_stock' => $skippedByMaxStock,
            'description' => $this->buildDescription(
                $sourceLabel,
                $baseQuantity,
                $validOrderUnit,
                $beforeMaxStockQuantity,
                $maxStock,
                $maxOrderQuantity,
                $orderQuantity,
                $maxStockAdjusted,
                $orderingUnitQty,
            ),
        ];
    }

    private function resolveValidOrderUnit(int $purchaseUnit, ?int $orderingUnitQty): int
    {
        if ($orderingUnitQty === 6) {
            return $this->leastCommonMultiple($purchaseUnit, 24);
        }

        if ($orderingUnitQty !== null && $orderingUnitQty > 1) {
            return $this->leastCommonMultiple($purchaseUnit, $orderingUnitQty);
        }

        return max(1, $purchaseUnit);
    }

    private function buildDescription(
        string $sourceLabel,
        int $baseQuantity,
        int $validOrderUnit,
        int $beforeMaxStockQuantity,
        int $maxStock,
        ?int $maxOrderQuantity,
        int $orderQuantity,
        bool $maxStockAdjusted,
        ?int $orderingUnitQty,
    ): string {
        $description = "{$sourceLabel}{$baseQuantity}バラ";

        if ($orderingUnitQty === 6) {
            $description .= 'を6缶パック制約（4パック=24バラ単位）で調整';
        } elseif ($orderingUnitQty !== null && $orderingUnitQty > 1) {
            $description .= "を発注CD入数{$orderingUnitQty}バラ単位で調整";
        } elseif ($validOrderUnit > 1) {
            $description .= "を最小仕入単位{$validOrderUnit}で調整";
        } else {
            $description .= '（最小仕入単位1のため調整なし）';
        }

        if ($beforeMaxStockQuantity !== $baseQuantity) {
            $description .= " → {$beforeMaxStockQuantity}バラ";
        }

        if ($maxStockAdjusted) {
            $description .= "、最大発注点{$maxStock}・発注可能残{$maxOrderQuantity}バラを超えないよう {$orderQuantity}バラへ調整";
        }

        return $description;
    }

    private function roundUpToUnit(int $quantity, int $unit): int
    {
        if ($unit <= 1 || $quantity <= 0) {
            return max(0, $quantity);
        }

        return (int) ceil($quantity / $unit) * $unit;
    }

    private function roundDownToUnit(int $quantity, int $unit): int
    {
        if ($unit <= 1 || $quantity <= 0) {
            return max(0, $quantity);
        }

        return (int) floor($quantity / $unit) * $unit;
    }

    private function leastCommonMultiple(int $a, int $b): int
    {
        $a = max(1, abs($a));
        $b = max(1, abs($b));

        return intdiv($a * $b, $this->greatestCommonDivisor($a, $b));
    }

    private function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, $a);
    }
}
