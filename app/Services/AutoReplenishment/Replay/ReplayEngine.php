<?php

namespace App\Services\AutoReplenishment\Replay;

use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\RecommendationChangeGuard;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use App\Services\AutoReplenishment\StrategyCalculator;
use Carbon\CarbonImmutable;

/**
 * Replays one warehouse × item series through the real recommendation and order calculations:
 * weekly (or once) `SettingsRecommendationCalculator`, daily `StrategyCalculator` (ArrivalBatchStrategy),
 * simulated orders arriving on their calendar dates, actual sales depleting stock.
 * Pure: no database, no writes. Inputs are arrays; the calendar owns any schedule lookups.
 */
final class ReplayEngine
{
    public const HISTORY_DAYS = 90;

    public function __construct(private SettingsRecommendationCalculator $calculator, private StrategyCalculator $strategy,
        private ProposalSafety $safety, private DemandEventCalendar $events) {}

    /**
     * @param  array  $series  ['warehouse_id','item_id','capacity_case','demand' => [date => int|null], ...labels]
     * @param  array  $scenario  ['name','start','end','mode' => 'weekly'|'fixed','unit_mode' => 'CASE'|'PIECE','safety_stock_days','review_days',
     *                           'variability_buffer' => bool,'events' => [...],'factors' => ?callable(int $itemId): array,'segment_events' => [...],'warmup_days' => int]
     */
    public function run(array $series, array $scenario, ReplayCalendar $calendar): array
    {
        $start = CarbonImmutable::parse($scenario['start']);
        $end = CarbonImmutable::parse($scenario['end']);
        throw_if($start->gt($end) || $start->diffInDays($end) > 800, \RuntimeException::class, '再生期間を確認してください。');
        $demand = $series['demand'];
        $segments = $this->segments($scenario['segment_events'] ?? [], $scenario['start'], $scenario['end']);
        $policy = null;
        $stock = null;
        $pending = [];
        $rows = [];
        $orders = [];
        $recalcs = [];
        $mode = $scenario['mode'] ?? 'weekly';
        $maxChange = $scenario['max_change_ratio'] ?? null;
        $consecutiveHolds = 0;
        if ($mode === 'saved') {
            // The production setting as saved (not recalculated); series without a usable saved setting are skipped.
            $saved = $series['saved_policy'] ?? null;
            if ($saved === null) {
                return ['series' => array_diff_key($series, ['demand' => true]), 'scenario' => $scenario['name'], 'skipped' => '保存済みの数量条件がありません。', 'days' => [], 'orders' => [], 'recalcs' => [],
                    'metrics' => (new ReplayMetrics)->summarize([], [], 0)];
            }
            $policy = $saved + ['channel' => $series['channel'] ?? 'EOS', 'is_enabled' => true, 'packing_unit' => 1, 'warehouse_id' => (int) $series['warehouse_id'], 'item_id' => (int) $series['item_id']];
            $recalcs[] = ['date' => $scenario['start'], 'available' => true, 'reason' => '保存済み設定', 'values' => array_intersect_key($saved, array_flip(RecommendationChangeGuard::GUARDED)), 'outcome' => 'SAVED'];
        }
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($mode !== 'saved' && ($policy === null || ($mode === 'weekly' && $day->dayOfWeek === 1))) {
                $recalc = $this->recommend($series, $scenario, $calendar, $date, $policy);
                $log = $recalc['log'];
                if ($recalc['policy'] === null) {
                    $log['outcome'] = 'UNAVAILABLE';
                } elseif ($policy === null || $maxChange === null) {
                    $log['outcome'] = $policy === null ? 'INITIAL' : 'UPDATED';
                    $policy = $recalc['policy'];
                } else {
                    // Same acceptance rule as the weekly refresh: a change beyond the ratio is held and the old setting stays in force.
                    $guard = (new RecommendationChangeGuard)->evaluate($policy, $recalc['policy'], (float) $maxChange,
                        is_array($policy['calculation_basis']['event_adjustment']['baseline'] ?? null) ? $policy['calculation_basis']['event_adjustment']['baseline'] : []);
                    $log['changes'] = $guard['changes'];
                    if ($guard['held']) {
                        $log['outcome'] = 'HELD';
                        $log['reason'] = $guard['reason'];
                        $consecutiveHolds++;
                    } else {
                        $log['outcome'] = $guard['changes'] ? 'UPDATED' : 'FORECAST_UPDATED';
                        $policy = $recalc['policy'];
                        $consecutiveHolds = 0;
                    }
                }
                $log['consecutive_holds'] = $consecutiveHolds;
                $recalcs[] = $log;
            }
            if ($stock === null) {
                $initial = $scenario['initial_stock'] ?? 'target';
                $stock = is_int($initial) ? $initial : ($policy ? (int) $policy['target_stock_pieces'] : 0);
            }
            $opening = $stock;
            $received = 0;
            foreach ($pending as $i => $supply) {
                if ($supply['date'] === $date) {
                    $received += $supply['pieces'];
                    unset($pending[$i]);
                }
            }
            $pending = array_values($pending);
            $stock += $received;
            $proposal = ['phase' => 'SKIP', 'quantity' => 0, 'reason' => $policy ? '発注日ではありません。' : '数量条件を算定できていません。', 'formula' => []];
            $arrival = $calendar->arrivalFor($date);
            if ($policy !== null && $arrival !== null) {
                $next = $calendar->nextArrivalAfter($date);
                $snapshot = ['stock' => $opening, 'incoming' => array_sum(array_column($pending, 'pieces')) + $received, 'incoming_today' => $received, 'claims' => 0,
                    'packing_unit' => (int) $policy['packing_unit'], 'business_date' => $date, 'arrival_date' => $arrival,
                    'next_arrival_date' => $next['arrival_date'] ?? null, 'shipping_unit' => ['mode' => 'STANDARD']];
                try {
                    $proposal = $this->strategy->calculate($policy, $snapshot);
                    $this->safety->validate($policy, $proposal, (int) $policy['packing_unit'], ['mode' => 'STANDARD']);
                } catch (\RuntimeException $e) {
                    $proposal = ['phase' => 'HOLD', 'quantity' => 0, 'reason' => $e->getMessage(), 'formula' => []];
                }
                if ($proposal['phase'] === 'READY') {
                    $pending[] = ['date' => $arrival, 'pieces' => (int) $proposal['quantity']];
                    $orders[] = ['date' => $date, 'arrival_date' => $arrival, 'pieces' => (int) $proposal['quantity'], 'minimum' => (int) $policy['min_order_pieces'],
                        'event' => $this->eventReason($policy)];
                }
            }
            $actual = $demand[$date] ?? null;
            $consumption = (int) ($actual ?? 0);
            $unmet = max(0, $consumption - $stock);
            $stock = max(0, $stock - $consumption);
            $rows[] = ['date' => $date, 'weekday' => $day->dayOfWeek, 'segment' => $segments[$date] ?? '平常日', 'opening' => $opening, 'received' => $received,
                'ordered' => $proposal['phase'] === 'READY' ? (int) $proposal['quantity'] : 0, 'arrival_date' => $proposal['phase'] === 'READY' ? $arrival : null,
                'demand' => $actual, 'unmet' => $unmet, 'stock' => $stock, 'pending' => array_sum(array_column($pending, 'pieces')),
                'phase' => $proposal['phase'], 'reason' => $proposal['reason'] ?? '',
                'point' => $policy['reorder_point_pieces'] ?? null, 'target' => $policy['target_stock_pieces'] ?? null,
                'max' => $policy['max_order_pieces'] ?? null, 'min' => $policy['min_order_pieces'] ?? null,
                'forecast' => $policy ? $this->forecast($policy, $date) : null];
        }

        return ['series' => array_diff_key($series, ['demand' => true]), 'scenario' => $scenario['name'], 'days' => $rows, 'orders' => $orders, 'recalcs' => $recalcs,
            'metrics' => (new ReplayMetrics)->summarize($rows, $orders, (int) ($scenario['warmup_days'] ?? 28), $recalcs)];
    }

    /** Build the calculator history from the series' past demand and the calendar, then recommend. */
    private function recommend(array $series, array $scenario, ReplayCalendar $calendar, string $date, ?array $previous): array
    {
        $log = ['date' => $date, 'available' => false, 'reason' => null, 'values' => null];
        try {
            $schedule = $calendar->schedule($date);
            $periodEnd = CarbonImmutable::parse($date)->subDay();
            $periodStart = $periodEnd->subDays(self::HISTORY_DAYS - 1);
            $daily = [];
            for ($d = $periodStart; $d->lte($periodEnd); $d = $d->addDay()) {
                $daily[$d->toDateString()] = $series['demand'][$d->toDateString()] ?? null;
            }
            $capacity = (int) ($series['capacity_case'] ?? 0);
            $unitMode = ($scenario['unit_mode'] ?? 'CASE') === 'CASE' && $capacity > 0 ? 'CASE' : 'PIECE';
            // Production definition (execution times included for scheduled calendars), never below the observed generation gaps.
            $reviewInterval = $calendar->reviewInterval();
            $generation = array_column($schedule['opportunities'], 'generation_date');
            for ($i = 1; $i < min(count($generation), 15); $i++) {
                $reviewInterval = max($reviewInterval, (int) CarbonImmutable::parse($generation[$i - 1])->diffInDays(CarbonImmutable::parse($generation[$i])));
            }
            $history = ['scope' => ['client_id' => (int) ($series['client_id'] ?? 0), 'warehouse_id' => (int) $series['warehouse_id'], 'warehouse_code' => (string) ($series['warehouse_code'] ?? ''),
                'item_id' => (int) $series['item_id'], 'item_code' => (string) ($series['item_code'] ?? ''), 'channel' => 'EOS', 'contractor_id' => 0, 'supplier_id' => 0, // sales-based path: no linked receipts are replayed
                'source_warehouse_id' => null, 'delivery_course_id' => null, 'capacity_case' => $capacity, 'packing_unit' => 1, 'order_unit' => null, 'order_unit_mode' => 'AUTO'],
                'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()], 'receipts' => [], 'daily' => $daily, 'warnings' => [],
                'nominal_lead_days' => $schedule['nominal_lead_days'], 'review_interval_days' => min(7, $reviewInterval), 'review_weekdays' => $schedule['weekdays'],
                'maximum_arrival_gap_days' => $schedule['maximum_gap_days'], 'arrival_schedule' => $schedule];
            $options = ['unit_mode' => $unitMode, 'review_days' => $scenario['review_days'] ?? 7, 'safety_stock_days' => $scenario['safety_stock_days'] ?? 5,
                'existing_reorder_point' => $previous['reorder_point_pieces'] ?? null, 'variability_buffer' => (bool) ($scenario['variability_buffer'] ?? false)];
            // The events applied to this product: the scenario's list, or the series' own list when set (商品別の適用イベント).
            $events = array_key_exists('events', $series) && ! empty($scenario['events']) ? $series['events'] : ($scenario['events'] ?? []);
            if (! empty($events)) {
                $factors = isset($scenario['factors']) ? ($scenario['factors'])((int) $series['item_id']) : [];
                $options['demand_events'] = ['events' => $events, 'factors' => $factors];
            }
            $result = $this->calculator->calculate($history, $options);
            if (! $result['available']) {
                $log['reason'] = $result['basis']['reason'] ?? '算定不可';

                return ['policy' => null, 'log' => $log];
            }
            $values = $result['values'];
            if (! empty($scenario['saved_constraints']) && is_array($series['saved_policy'] ?? null)) {
                // The product's real order constraints (minimum, maximum, multiple) as saved by the operator; the level and target stay recalculated.
                foreach (['min_order_pieces', 'max_order_pieces', 'order_multiple_pieces'] as $key) {
                    if ((int) ($series['saved_policy'][$key] ?? 0) > 0) {
                        $values[$key] = (int) $series['saved_policy'][$key];
                    }
                }
                $values['min_order_pieces'] = min($values['min_order_pieces'], $values['max_order_pieces']);
                $log['saved_constraints'] = true;
            }
            $policy = $values + ['calculation_basis' => $result['basis'], 'channel' => 'EOS', 'is_enabled' => true, 'packing_unit' => 1,
                'warehouse_id' => (int) $series['warehouse_id'], 'item_id' => (int) $series['item_id']];
            $log['available'] = true;
            $log['values'] = $values;
            $log['mean_daily'] = $result['basis']['mean_daily'] ?? null;
            $log['event_add_on'] = $result['basis']['event_adjustment']['target_add_on_pieces'] ?? 0;
            $log['selection'] = $result['basis']['demand_profile']['selection'] ?? null;

            return ['policy' => $policy, 'log' => $log];
        } catch (\RuntimeException $e) {
            $log['reason'] = $e->getMessage();

            return ['policy' => null, 'log' => $log];
        }
    }

    private function forecast(array $policy, string $date): ?float
    {
        $days = $policy['calculation_basis']['demand_profile']['event_forecast']['days'] ?? null;
        if (is_array($days) && isset($days[$date])) {
            return round((float) $days[$date], 2);
        }
        $weekdays = $policy['calculation_basis']['demand_profile']['weekdays'] ?? null;

        return is_array($weekdays) ? round((float) $weekdays[CarbonImmutable::parse($date)->dayOfWeek]['mean'], 2) : null;
    }

    private function eventReason(array $policy): ?string
    {
        $events = $policy['calculation_basis']['event_adjustment']['events'] ?? [];
        foreach ($events as $event) {
            if (! empty($event['in_target'])) {
                return $event['name'];
            }
        }

        return null;
    }

    private function segments(array $events, string $start, string $end): array
    {
        if (! $events) {
            return [];
        }
        $days = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1;
        $labels = [];
        foreach ($this->events->positions($events, $start, $days) as $date => $position) {
            if ($position['in_block']) {
                $labels[$date] = DemandEventCalendar::NAMES[$position['event']['type']] ?? $position['event']['type'];
            }
        }

        return $labels;
    }
}
