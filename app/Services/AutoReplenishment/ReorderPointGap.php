<?php

namespace App\Services\AutoReplenishment;

/** Preserve the inventory ceiling; only lower the recommendation's reorder gate. */
final class ReorderPointGap
{
    public function calculate(int $point, int $target, float $mean, int $minimum, int $unit, int $packing, int $palletMinimum = 0, ?array $shippingQuantities = null, int $maximum = 2147483647): array
    {
        throw_unless(is_finite($mean) && $mean > 0 && min($point, $target, $minimum, $unit, $packing) > 0,
            \RuntimeException::class, '発注点の間隔を算定できません。需要・数量条件を確認してください。');
        $twoDays = (int) ceil(round($mean, 4) * 2);
        $orderMinimum = (int) (ceil($minimum / $unit) * $unit);
        if ($shippingQuantities !== null) {
            $eligible = array_filter($shippingQuantities, fn ($q) => $q >= $minimum && $q <= $maximum && $q % $unit === 0);
            throw_unless($eligible, \RuntimeException::class, '最小発注数・最大発注数・発注単位に合う出荷数量候補がありません。');
            $orderMinimum = min($eligible);
        }
        $gap = (int) (ceil(max($twoDays, $orderMinimum, $palletMinimum) / $packing) * $packing);
        throw_if($target - $gap < 1, \RuntimeException::class,
            '過剰在庫線の範囲では最低ギャップと発注点を確保できません。過剰在庫線・最小発注数・出荷単位を見直してください。');
        $adjusted = min($point, $target - $gap);

        return ['point' => $adjusted, 'basis' => [
            'reorder_gap_model' => 'fixed-target-minimum-gap-v1',
            'reorder_gap_demand_days' => 2, 'reorder_gap_demand_pieces' => $twoDays,
            'reorder_gap_minimum_pieces' => $orderMinimum, 'reorder_gap_pallet_pieces' => $palletMinimum,
            'reorder_gap_required_pieces' => $gap, 'reorder_gap_actual_pieces' => $target - $adjusted,
            'reorder_point_before_gap' => $point, 'reorder_point_gap_reduction' => $point - $adjusted,
        ]];
    }
}
