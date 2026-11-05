<?php

namespace App\Services\AutoReplenishment;

/** Pure quantities; no history reads or setting/order writes. */
final class SafetyStockSettings
{
    /**
     * @param  array{event_uplift?: float, variability?: float}  $extra  additional safety pieces
     *                                                                   (event demand above the weekday level inside the safety window; variability term for high-CV products)
     */
    public function calculate(float $mean, int $days, int $normal, int $minimum, int $packing, int $lead, int $arrivalGap, int $reviewInterval, ?int $existingPoint = null, ?int $leadConsumption = null, ?int $gapConsumption = null, array $extra = []): array
    {
        // Safety days use the stored mean; arrival protection can use the shared weekday forecast.
        $mean = round($mean, 4);
        // The event uplift may be negative after a peak (1/2〜1/5 など); the buffer never drops below zero.
        $eventUplift = (float) ($extra['event_uplift'] ?? 0);
        $variability = max(0.0, (float) ($extra['variability'] ?? 0));
        throw_unless(is_finite($eventUplift) && is_finite($variability) && abs($eventUplift) + $variability <= 2147483647, \RuntimeException::class, '安全在庫の加算量を確認してください。');
        $buffer = max(0, ceil(($mean * $days + $eventUplift + $variability) / $packing) * $packing);
        $requestedTarget = $normal + $buffer;
        // Keep the requested buffer after waiting for the next ordering/arrival opportunity.
        // Previously the arrival-gap floor could consume the safety buffer itself.
        $waitingDemand = max($gapConsumption ?? ceil($mean * $arrivalGap), ceil($mean * $reviewInterval));
        $target = ceil(($buffer + max($normal, $minimum) + $waitingDemand) / $packing) * $packing;
        $boundary = $target - $normal + ($leadConsumption ?? floor($mean * $lead));
        $point = $boundary + 1; // Runtime orders strictly below the reorder point.
        throw_if(max($target, $point) > 2147483647, \RuntimeException::class, '算定数量が上限を超えています。安全在庫日数を減らして再計算してください。');
        // Preserve a nearby existing gate only when it cannot delay the usual order.
        $preserved = $existingPoint !== null && $existingPoint >= $point
            && $existingPoint <= $point + ceil($mean * $reviewInterval) && $existingPoint <= $target;

        return ['point' => $preserved ? $existingPoint : (int) $point, 'target' => (int) $target,
            'basis' => ['quantity_model' => 'safety-stock-days-v2', 'safety_stock_days' => $days,
                'safety_stock_pieces' => (int) $buffer, 'safety_stock_rounding_unit' => $packing,
                'safety_event_uplift_pieces' => (int) round($eventUplift), 'safety_variability_pieces' => (int) round($variability),
                'safety_waiting_consumption_pieces' => (int) $waitingDemand,
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
