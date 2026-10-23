<?php

namespace App\Services\AutoReplenishment;

/** Exact observed/approved quantities, never an inferred pallet or arbitrary multiple. */
final class ShippingQuantitySet
{
    public function normalize(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
        }
        throw_unless(is_array($input) && array_is_list($input) && count($input) >= 1 && count($input) <= 500,
            \RuntimeException::class, '出荷数量候補は1〜500件のJSON配列で入力してください。');
        foreach ($input as $quantity) {
            throw_unless(is_int($quantity) && $quantity > 0 && $quantity <= 2147483647,
                \RuntimeException::class, '出荷数量候補は1以上の整数（総バラ）で入力してください。');
        }
        $input = array_values(array_unique($input));
        sort($input, SORT_NUMERIC);

        return $input;
    }

    public function floor(array $quantities, int $limit, int $minimum = 1, int $unit = 1): int
    {
        $result = 0;
        foreach ($quantities as $q) {
            if ($q >= $minimum && $q <= $limit && $unit > 0 && $q % $unit === 0) {
                $result = max($result, $q);
            }
        }

        return $result;
    }

    public function gcd(array $quantities): int
    {
        $a = 0;
        foreach ($quantities as $b) {
            while ($b !== 0) {
                [$a, $b] = [$b, $a % $b];
            }
        }

        return $a;
    }

    public function fromReceipts(array $receipts, array $period): array
    {
        $counts = [];
        $ids = [];
        foreach ($receipts as $receipt) {
            if (! empty($receipt['has_shortage'])) {
                continue;
            }
            $q = $receipt['pieces'];
            $this->normalize([$q]);
            $counts[$q] = ($counts[$q] ?? 0) + 1;
            if (isset($receipt['source']['id'])) {
                $ids[] = $receipt['source']['id'];
            }
        }
        throw_unless($counts, \RuntimeException::class, 'WMSの移動発注に紐づく出荷実績がありません。数量候補を手入力してください。');
        ksort($counts, SORT_NUMERIC);

        return ['quantities' => $this->normalize(array_keys($counts)), 'basis' => [
            'method' => 'wms-linked-actual-shipments-v1', 'period' => $period,
            'counts' => $counts, 'documents' => array_sum($counts), 'sample_transfer_ids' => array_slice(array_unique($ids), 0, 10),
            'excluded_shortages' => count(array_filter($receipts, fn ($r) => ! empty($r['has_shortage']))),
        ]];
    }
}
