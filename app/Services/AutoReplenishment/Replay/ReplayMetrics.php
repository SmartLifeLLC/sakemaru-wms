<?php

namespace App\Services\AutoReplenishment\Replay;

/** Per-series and aggregate indicators for the replay (warm-up excluded). Pure. */
final class ReplayMetrics
{
    public const OVERSTOCK_DAYS = 21;

    public function summarize(array $rows, array $orders, int $warmup, array $recalcs = []): array
    {
        $scored = array_slice($rows, $warmup);
        $observed = array_filter($scored, fn ($r) => $r['demand'] !== null);
        $demand = array_sum(array_column($observed, 'demand'));
        $unmet = array_sum(array_column($observed, 'unmet'));
        $stocks = array_column($scored, 'stock');
        $days = count($scored);
        // Stock in "days of demand" uses the normal-day level, so seasonal peaks do not distort the ratio.
        $normal = array_filter($observed, fn ($r) => $r['segment'] === '平常日');
        $meanDaily = count($normal) ? array_sum(array_column($normal, 'demand')) / count($normal) : (count($observed) ? $demand / count($observed) : 0.0);
        $holds = array_filter($recalcs, fn ($r) => ($r['outcome'] ?? null) === 'HELD');
        $maxConsecutiveHolds = $recalcs ? max(array_map(fn ($r) => (int) ($r['consecutive_holds'] ?? 0), $recalcs)) : 0;
        $firstScored = $scored[0]['date'] ?? null;
        $scoredOrders = array_values(array_filter($orders, fn ($o) => $firstScored !== null && $o['date'] >= $firstScored));
        $segments = [];
        foreach ($scored as $row) {
            $segment = &$segments[$row['segment']];
            $segment['days'] = ($segment['days'] ?? 0) + 1;
            if ($row['demand'] !== null) {
                $segment['demand'] = ($segment['demand'] ?? 0) + $row['demand'];
                $segment['unmet'] = ($segment['unmet'] ?? 0) + $row['unmet'];
                $segment['unmet_days'] = ($segment['unmet_days'] ?? 0) + ($row['unmet'] > 0 ? 1 : 0);
            }
            $segment['stock_sum'] = ($segment['stock_sum'] ?? 0) + $row['stock'];
            unset($segment);
        }
        foreach ($segments as &$segment) {
            $segment += ['demand' => 0, 'unmet' => 0, 'unmet_days' => 0];
            $segment['stockout_rate'] = $segment['demand'] > 0 ? round($segment['unmet'] / $segment['demand'], 4) : null;
            $segment['mean_stock'] = round($segment['stock_sum'] / max(1, $segment['days']), 1);
            unset($segment['stock_sum']);
        }
        unset($segment);
        $constrained = count(array_filter($scored, fn ($r) => $r['phase'] === 'NO_ORDER' && (str_contains($r['reason'], '最小発注数') || str_contains($r['reason'], '補充目標内'))));
        $newYear = array_values(array_filter($scored, fn ($r) => substr($r['date'], 5) >= '01-06' && substr($r['date'], 5) <= '01-20'));
        $newYearStockDays = $newYear && $meanDaily > 0 ? array_sum(array_column($newYear, 'stock')) / count($newYear) / $meanDaily : null;

        return ['scored_days' => $days, 'observed_days' => count($observed), 'missing_days' => $days - count($observed), 'demand' => $demand, 'unmet' => $unmet,
            'recalcs' => count($recalcs), 'held_recalcs' => count($holds), 'max_consecutive_holds' => $maxConsecutiveHolds,
            'unavailable_recalcs' => count(array_filter($recalcs, fn ($r) => ($r['outcome'] ?? null) === 'UNAVAILABLE')),
            'forecast_only_updates' => count(array_filter($recalcs, fn ($r) => ($r['outcome'] ?? null) === 'FORECAST_UPDATED')),
            'unmet_days' => count(array_filter($observed, fn ($r) => $r['unmet'] > 0)),
            'stockout_rate' => $demand > 0 ? round($unmet / $demand, 4) : null,
            'mean_stock' => $days ? round(array_sum($stocks) / $days, 1) : 0.0, 'max_stock' => $stocks ? max($stocks) : 0,
            'mean_stock_days' => $meanDaily > 0 && $days ? round(array_sum($stocks) / $days / $meanDaily, 1) : null,
            'orders' => count($scoredOrders), 'order_pieces' => array_sum(array_column($scoredOrders, 'pieces')),
            'minimum_lot_orders' => count(array_filter($scoredOrders, fn ($o) => $o['pieces'] === $o['minimum'])),
            'event_orders' => count(array_filter($scoredOrders, fn ($o) => $o['event'] !== null)),
            'constrained_skips' => $constrained, 'hold_days' => count(array_filter($scored, fn ($r) => $r['phase'] === 'HOLD')),
            'new_year_stock_days' => $newYearStockDays !== null ? round($newYearStockDays, 1) : null,
            'new_year_overstock' => $newYearStockDays !== null && $newYearStockDays > self::OVERSTOCK_DAYS,
            'overstock_order_days' => count(array_filter($scored, fn ($r) => $r['ordered'] > 0 && $meanDaily > 0 && $r['opening'] / $meanDaily > self::OVERSTOCK_DAYS)),
            'segments' => $segments];
    }

    /** Aggregate across series: quantity-weighted and per-series median rates, plus per-segment totals. */
    public function aggregate(array $results): array
    {
        $demand = $unmet = $stock = $days = $orders = $minimumLot = $eventOrders = $overstockOrders = $constrained = 0;
        $rates = [];
        $stockDays = [];
        $newYearOver = 0;
        $held = $maxHolds = $missing = 0;
        $segments = [];
        foreach ($results as $result) {
            $m = $result['metrics'];
            if (($m['scored_days'] ?? 0) === 0) {
                continue;
            }
            $held += $m['held_recalcs'] ?? 0;
            $maxHolds = max($maxHolds, $m['max_consecutive_holds'] ?? 0);
            $missing += $m['missing_days'] ?? 0;
            $demand += $m['demand'];
            $unmet += $m['unmet'];
            $stock += $m['mean_stock'] * $m['scored_days'];
            $days += $m['scored_days'];
            $orders += $m['orders'];
            $minimumLot += $m['minimum_lot_orders'];
            $eventOrders += $m['event_orders'];
            $overstockOrders += $m['overstock_order_days'];
            $constrained += $m['constrained_skips'];
            $newYearOver += $m['new_year_overstock'] ? 1 : 0;
            if ($m['stockout_rate'] !== null) {
                $rates[] = $m['stockout_rate'];
            }
            if ($m['mean_stock_days'] !== null) {
                $stockDays[] = $m['mean_stock_days'];
            }
            foreach ($m['segments'] as $name => $segment) {
                $segments[$name]['demand'] = ($segments[$name]['demand'] ?? 0) + $segment['demand'];
                $segments[$name]['unmet'] = ($segments[$name]['unmet'] ?? 0) + $segment['unmet'];
                $segments[$name]['days'] = ($segments[$name]['days'] ?? 0) + $segment['days'];
                $segments[$name]['stock_sum'] = ($segments[$name]['stock_sum'] ?? 0) + $segment['mean_stock'] * $segment['days'];
            }
        }
        foreach ($segments as &$segment) {
            $segment['stockout_rate'] = $segment['demand'] > 0 ? round($segment['unmet'] / $segment['demand'], 4) : null;
            $segment['mean_stock'] = round($segment['stock_sum'] / max(1, $segment['days']), 1);
            unset($segment['stock_sum']);
        }
        unset($segment);
        sort($rates);
        sort($stockDays);

        return ['series' => count(array_filter($results, fn ($r) => ($r['metrics']['scored_days'] ?? 0) > 0)), 'skipped_series' => count(array_filter($results, fn ($r) => ($r['metrics']['scored_days'] ?? 0) === 0)),
            'held_recalcs' => $held, 'max_consecutive_holds' => $maxHolds, 'missing_days' => $missing,
            'demand' => $demand, 'unmet' => $unmet, 'stockout_rate' => $demand > 0 ? round($unmet / $demand, 4) : null,
            'median_series_stockout_rate' => $rates ? $rates[intdiv(count($rates), 2)] : null,
            'mean_stock' => $days ? round($stock / $days, 1) : 0.0, 'median_series_stock_days' => $stockDays ? $stockDays[intdiv(count($stockDays), 2)] : null,
            'orders' => $orders, 'minimum_lot_share' => $orders ? round($minimumLot / $orders, 4) : null, 'event_orders' => $eventOrders,
            'overstock_order_days' => $overstockOrders, 'constrained_skips' => $constrained, 'new_year_overstock_series' => $newYearOver, 'segments' => $segments];
    }
}
