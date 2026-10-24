<?php

namespace App\Services\AutoReplenishment;

/** Describes observed WMS-linked actual shipments; it never declares a physical pallet size. */
final class ShippingLotProfile
{
    public function summarize(array $receipts, int $warehouseId, int $capacityCase): array
    {
        return ['own' => $this->distribution(array_values(array_filter($receipts, fn ($r) => $r['warehouse_id'] === $warehouseId)), $capacityCase),
            'source_all_warehouses' => $this->distribution($receipts, $capacityCase),
            'capacity_case' => $capacityCase, 'pallet_confirmed' => false];
    }

    private function distribution(array $receipts, int $capacityCase): array
    {
        $quantities = array_column($receipts, 'pieces');
        if (! $quantities) {
            return ['count' => 0, 'days' => 0, 'warehouses' => 0, 'top' => []];
        }
        $frequencies = [];
        $gcd = 0;
        foreach ($quantities as $quantity) {
            throw_unless(is_int($quantity) && $quantity > 0, \InvalidArgumentException::class, '出庫実績の総バラ数を確認してください。');
            $frequencies[$quantity] = ($frequencies[$quantity] ?? 0) + 1;
            [$a, $b] = [$gcd, $quantity];
            while ($b !== 0) {
                [$a, $b] = [$b, $a % $b];
            }
            $gcd = $a;
        }
        $top = [];
        foreach ($frequencies as $quantity => $count) {
            $top[] = ['pieces' => $quantity, 'count' => $count,
                'cases' => $capacityCase > 0 && $quantity % $capacityCase === 0 ? intdiv($quantity, $capacityCase) : null];
        }
        usort($top, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: ($a['pieces'] <=> $b['pieces']));
        sort($quantities, SORT_NUMERIC);

        return ['count' => count($receipts), 'days' => count(array_unique(array_column($receipts, 'date'))),
            'warehouses' => count(array_unique(array_column($receipts, 'warehouse_id'))),
            'minimum_pieces' => $quantities[0], 'gcd_pieces' => $gcd,
            'median_pieces' => $quantities[(int) ceil(count($quantities) * .5) - 1],
            'top' => array_slice($top, 0, 5)];
    }
}
