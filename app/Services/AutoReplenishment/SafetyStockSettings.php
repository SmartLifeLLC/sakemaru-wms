<?php

namespace App\Services\AutoReplenishment;

/** Pure quantities; no history reads or setting/order writes. */
final class SafetyStockSettings
{
    public function calculate(float $mean, int $days, int $normal, int $minimum, int $packing, int $lead, int $arrivalGap, int $reviewInterval, ?int $existingPoint = null, ?int $leadConsumption = null, ?int $gapConsumption = null): array
    {
        // Safety days use the stored mean; arrival protection can use the shared weekday forecast.
        $mean = round($mean, 4);
        $buffer = ceil($mean * $days / $packing) * $packing;
        $requestedTarget = $normal + $buffer;
        $target = ceil(max($requestedTarget, ($gapConsumption ?? ceil($mean * $arrivalGap)) + $minimum) / $packing) * $packing;
        $boundary = $target - $normal + ($leadConsumption ?? floor($mean * $lead));
        $point = $boundary + 1; // Runtime orders strictly below the reorder point.
        throw_if(max($target, $point) > 2147483647, \RuntimeException::class, '算定数量が上限を超えています。安全在庫日数を減らして再計算してください。');
        // Preserve a nearby existing gate only when it cannot delay the usual order.
        $preserved = $existingPoint !== null && $existingPoint >= $point
            && $existingPoint <= $point + ceil($mean * $reviewInterval) && $existingPoint <= $target;

        return ['point' => $preserved ? $existingPoint : (int) $point, 'target' => (int) $target,
            'basis' => ['quantity_model' => 'safety-stock-days-v1', 'safety_stock_days' => $days,
                'safety_stock_pieces' => (int) $buffer, 'safety_stock_rounding_unit' => $packing,
                'safety_requested_target_pieces' => (int) $requestedTarget,
                'safety_arrival_gap_adjustment_pieces' => (int) ($target - $requestedTarget),
                'safety_lead_days' => $lead, 'safety_lead_consumption_pieces' => (int) ($leadConsumption ?? floor($mean * $lead)), 'normal_order_stock_pieces' => (int) $boundary,
                'reorder_point_preserved' => $preserved]];
    }

    public function normalOrderBoundary(int $point, int $target, int $normal, int $outgoing, int $incoming = 0, int $claims = 0): int
    {
        $remainingAllowance = $target - $normal - $incoming - $claims;
        // Forecast consumption stops at zero; actual negative stock is kept without
        // subtracting forecast demand again, just as in ArrivalBatchStrategy.
        $stockBoundary = $remainingAllowance < 0 ? $remainingAllowance : $remainingAllowance + $outgoing;

        return min($point - 1, $stockBoundary);
    }
}
