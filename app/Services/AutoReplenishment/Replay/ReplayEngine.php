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
 * Pure: no database, no writes. Inputs are arrays; the calendar owns any schedule lookups and the optional
 * conditions provider (scenario['conditions_provider']) owns the production inputs that sales cannot give —
 * linked receipts of the history window, the output unit, the warehouse × product quantity constraint.
 */
final class ReplayEngine
{
    public const HISTORY_DAYS = 90;

    public function __construct(private SettingsRecommendationCalculator $calculator, private StrategyCalculator $strategy,
        private ProposalSafety $safety, private DemandEventCalendar $events) {}

    /**
     * @param  array  $series  ['warehouse_id','item_id','capacity_case','demand' => [date => int|null], 'channel', 'route' => [...], ...labels]
     * @param  array  $scenario  ['name','start','end','mode' => 'weekly'|'fixed'|'saved','unit_mode' => 'CASE'|'PIECE','safety_stock_days','review_days',
     *                           'variability_buffer' => bool,'events' => [...],'factors' => ?callable(int $itemId): array,'segment_events' => [...],'warmup_days' => int,
     *                           'conditions_provider' => ?callable(array $series, string $asOf, array $period): array (see ReplayDataReader::conditionsProvider),
     *                           'history_not_before' => ?string (production lower bound of the history window; null = plain 90 days),
     *                           'initial_supply' => [['date' => Y-m-d, 'pieces' => int], ...] (open supply at the start; default none)]
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
        foreach ($scenario['initial_supply'] ?? [] as $supply) {
            throw_unless(isset($supply['date'], $supply['pieces']) && is_int($supply['pieces']) && $supply['pieces'] >= 0, \RuntimeException::class, '開始時点の既発注（date, pieces）を確認してください。');
            $pending[] = ['date' => CarbonImmutable::parse($supply['date'])->toDateString(), 'pieces' => $supply['pieces']];
        }
        $rows = [];
        $orders = [];
        $recalcs = [];
        $mode = $scenario['mode'] ?? 'weekly';
        $maxChange = $scenario['max_change_ratio'] ?? null;
        $consecutiveHolds = 0;
        $provider = $scenario['conditions_provider'] ?? null;
        $channel = (string) ($series['route']['channel'] ?? $series['channel'] ?? 'EOS');
        // Daily order conditions: the output unit and the quantity constraint of this warehouse × product (refreshed with every recalculation).
        $packing = (int) ($series['packing_unit'] ?? 1);
        $shippingUnit = is_array($series['shipping_unit'] ?? null) ? $series['shipping_unit'] : ['mode' => 'STANDARD'];
        $conditions = ['channel' => $channel, 'provider' => $provider !== null, 'history_not_before' => $scenario['history_not_before'] ?? null,
            'initial_supply_pieces' => array_sum(array_column($pending, 'pieces')), 'initial_supply_count' => count($pending),
            'claims' => '再生が自ら生成した発注以外の未確定候補は無し（本番の PENDING/APPROVED 候補は入力していない）',
            'masters' => $provider !== null ? '現在のマスタ（パレット入数・出荷数量候補・発注単位の出力条件）を過去日へ適用' : '系列に与えた値のみ'];
        if ($mode === 'saved') {
            // The production setting as saved (not recalculated); series without a usable saved setting are skipped.
            $saved = $series['saved_policy'] ?? null;
            if ($saved === null) {
                return ['series' => array_diff_key($series, ['demand' => true]), 'scenario' => $scenario['name'], 'skipped' => '保存済みの数量条件がありません。', 'days' => [], 'orders' => [], 'recalcs' => [],
                    'conditions' => $conditions, 'metrics' => (new ReplayMetrics)->summarize([], [], 0)];
            }
            $log = ['date' => $scenario['start'], 'available' => true, 'reason' => '保存済み設定', 'values' => array_intersect_key($saved, array_flip(RecommendationChangeGuard::GUARDED)), 'outcome' => 'SAVED'];
            if ($provider !== null) {
                try {
                    $cond = $provider($series, $scenario['start'], ['start' => $start->subDays(self::HISTORY_DAYS)->toDateString(), 'end' => $start->subDay()->toDateString()]);
                    $packing = (int) $cond['packing_unit'];
                    $shippingUnit = $cond['shipping_unit'];
                    $log['conditions'] = ['packing_unit' => $packing, 'shipping_unit' => $shippingUnit['mode'], 'order_unit' => $cond['order_unit']['label'] ?? null];
                } catch (\RuntimeException $e) {
                    throw_if($e instanceof \Illuminate\Database\QueryException, $e);

                    return ['series' => array_diff_key($series, ['demand' => true]), 'scenario' => $scenario['name'], 'skipped' => $e->getMessage(), 'days' => [], 'orders' => [], 'recalcs' => [],
                        'conditions' => $conditions, 'metrics' => (new ReplayMetrics)->summarize([], [], 0)];
                }
            }
            $policy = $saved + $this->policyIdentity($series, $channel, $packing);
            $recalcs[] = $log;
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
                if ($recalc['policy'] !== null && $log['outcome'] !== 'HELD') {
                    // The constraint and output unit follow the accepted recalculation (a held one keeps the previous conditions too).
                    $packing = (int) $recalc['policy']['packing_unit'];
                    $shippingUnit = $recalc['shipping_unit'];
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
                // Same shape as SnapshotReader: opening stock, every outstanding supply (today's receipt included) as incoming,
                // today's receipt as incoming_today — the calculation adapter moves it into stock once, never twice.
                $snapshot = ['stock' => $opening, 'incoming' => array_sum(array_column($pending, 'pieces')) + $received, 'incoming_today' => $received, 'claims' => 0,
                    'packing_unit' => $packing, 'business_date' => $date, 'arrival_date' => $arrival,
                    'next_arrival_date' => $next['arrival_date'] ?? null, 'shipping_unit' => $shippingUnit];
                try {
                    // Same refusal as SnapshotReader (assertBinding): quantity conditions that do not match the registered pallet /
                    // candidates hold the order instead of ordering an unrelated multiple (saved settings, fixed bounds).
                    $this->assertConstraint($policy, $shippingUnit, (int) ($series['capacity_case'] ?? 0));
                    $proposal = $this->strategy->calculate($policy, $snapshot);
                    $this->safety->validate($policy, $proposal, $packing, $shippingUnit);
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
            'conditions' => $conditions, 'metrics' => (new ReplayMetrics)->summarize($rows, $orders, (int) ($scenario['warmup_days'] ?? 28), $recalcs)];
    }

    /** Build the calculator history from the series' past demand, the calendar and the conditions provider, then recommend. */
    private function recommend(array $series, array $scenario, ReplayCalendar $calendar, string $date, ?array $previous): array
    {
        $log = ['date' => $date, 'available' => false, 'reason' => null, 'values' => null];
        $shippingUnit = is_array($series['shipping_unit'] ?? null) ? $series['shipping_unit'] : ['mode' => 'STANDARD'];
        try {
            $schedule = $calendar->schedule($date);
            $periodEnd = CarbonImmutable::parse($date)->subDay();
            $periodStart = $periodEnd->subDays(self::HISTORY_DAYS - 1);
            if (! empty($scenario['history_not_before'])) {
                // Production lower bound of the history window (release constraint); the past replay normally leaves it off.
                $periodStart = max($periodStart, CarbonImmutable::parse($scenario['history_not_before']));
                throw_if($periodStart->gt($periodEnd), \RuntimeException::class, '算定対象期間の完了実績がありません。');
            }
            $period = ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()];
            $daily = [];
            for ($d = $periodStart; $d->lte($periodEnd); $d = $d->addDay()) {
                $daily[$d->toDateString()] = $series['demand'][$d->toDateString()] ?? null;
            }
            $capacity = (int) ($series['capacity_case'] ?? 0);
            $channel = (string) ($series['route']['channel'] ?? $series['channel'] ?? 'EOS');
            // Production inputs beyond sales: linked receipts of this window, the output unit as of this date, the quantity constraint.
            $cond = isset($scenario['conditions_provider']) ? ($scenario['conditions_provider'])($series, $date, $period) : null;
            $receipts = $cond['receipts'] ?? ($series['receipts'] ?? []);
            $receipts = array_values(array_filter($receipts, fn ($r) => ($r['date'] ?? '') >= $period['start'] && ($r['date'] ?? '') <= $period['end'])); // never a receipt after the calculation date
            $packing = (int) ($cond['packing_unit'] ?? $series['packing_unit'] ?? 1);
            $orderUnit = $cond['order_unit'] ?? ($series['order_unit'] ?? null);
            $scope = ['client_id' => (int) ($series['client_id'] ?? 0), 'warehouse_id' => (int) $series['warehouse_id'], 'warehouse_code' => (string) ($series['warehouse_code'] ?? ''),
                'item_id' => (int) $series['item_id'], 'item_code' => (string) ($series['item_code'] ?? ''), 'channel' => $channel,
                'contractor_id' => (int) ($cond['scope']['contractor_id'] ?? $series['route']['contractor_id'] ?? $series['contractor_id'] ?? 0),
                'supplier_id' => (int) ($cond['scope']['supplier_id'] ?? $series['route']['supplier_id'] ?? 0),
                'source_warehouse_id' => $cond['scope']['source_warehouse_id'] ?? $series['route']['source_warehouse_id'] ?? null,
                'delivery_course_id' => $cond['scope']['delivery_course_id'] ?? $series['route']['delivery_course_id'] ?? null,
                'capacity_case' => $capacity, 'packing_unit' => $packing, 'order_unit' => $orderUnit,
                'order_unit_mode' => (string) ($cond['scope']['order_unit_mode'] ?? $series['route']['order_unit_mode'] ?? 'AUTO')];
            // Conditions: the scenario's, or — like the weekly refresh — the ones the operator saved with the product (unit mode, review days, safety days).
            // Only a basis saved by the recommendation (it carries safety_stock_days) counts as saved conditions — the same rule the weekly refresh uses to pick its candidates.
            $savedBasis = ! empty($scenario['saved_conditions']) && is_array($series['saved_policy']['calculation_basis'] ?? null) && isset($series['saved_policy']['calculation_basis']['safety_stock_days'])
                ? $series['saved_policy']['calculation_basis'] : null;
            // A saved PALLET mode is advisory in production too: the weekly refresh passes AUTO and the route's pallet binding re-establishes
            // PALLET with the registered pieces (constrainedOptions). With a conditions provider the replay does exactly that. Without one
            // there is no binding to consult, so the saved PALLET stays and the calculator reports the production unavailability instead
            // of silently downgrading to AUTO.
            $requestedUnit = $savedBasis ? ($savedBasis['unit_mode'] ?? 'AUTO') : ($scenario['unit_mode'] ?? 'CASE');
            if ($requestedUnit === 'PALLET' && $cond !== null) {
                $requestedUnit = 'AUTO';
            }
            $unitMode = $requestedUnit === 'PIECE' || ($capacity <= 0 && $requestedUnit !== 'PALLET') ? 'PIECE' : $requestedUnit;
            $reviewDays = $savedBasis ? (int) ($savedBasis['review_days'] ?? 7) : (int) ($scenario['review_days'] ?? 7);
            $safetyDays = $savedBasis ? $savedBasis['safety_stock_days'] : ($scenario['safety_stock_days'] ?? 5);
            // Production definition (execution times included for scheduled calendars), never below the observed generation gaps.
            $reviewInterval = $calendar->reviewInterval();
            $generation = array_column($schedule['opportunities'], 'generation_date');
            for ($i = 1; $i < min(count($generation), 15); $i++) {
                $reviewInterval = max($reviewInterval, (int) CarbonImmutable::parse($generation[$i - 1])->diffInDays(CarbonImmutable::parse($generation[$i])));
            }
            $history = ['scope' => $scope, 'period' => $period, 'receipts' => $receipts, 'daily' => $daily, 'warnings' => [],
                'nominal_lead_days' => $schedule['nominal_lead_days'], 'review_interval_days' => min(7, $reviewInterval), 'review_weekdays' => $schedule['weekdays'],
                'maximum_arrival_gap_days' => $schedule['maximum_gap_days'], 'arrival_schedule' => $schedule];
            $options = ['unit_mode' => $unitMode, 'review_days' => $reviewDays, 'safety_stock_days' => $safetyDays,
                'existing_reorder_point' => $previous['reorder_point_pieces'] ?? null, 'variability_buffer' => (bool) ($scenario['variability_buffer'] ?? false)];
            if ($cond !== null) {
                // PALLET → unit_mode PALLET + pallet_pieces; SHIPMENT → AUTO + shipping_quantities; STANDARD → unchanged (SettingsRecommendationService::constrainedOptions).
                $options = array_replace($options, $cond['options'] ?? []);
                $shippingUnit = $cond['shipping_unit'] ?? $shippingUnit;
            }
            if ($savedBasis) {
                $log['saved_conditions'] = ['unit_mode' => $unitMode, 'review_days' => $reviewDays, 'safety_stock_days' => $safetyDays];
            }
            // The events applied to this product: the scenario's list, or the series' own list when set (商品別の適用イベント).
            $events = array_key_exists('events', $series) && ! empty($scenario['events']) ? $series['events'] : ($scenario['events'] ?? []);
            if (! empty($events)) {
                $factors = isset($scenario['factors']) ? ($scenario['factors'])((int) $series['item_id']) : [];
                $options['demand_events'] = ['events' => $events, 'factors' => $factors]; // category factors are dropped by the calculator unless category_fallback is on
            }
            $result = $this->calculator->calculate($history, $options);
            // unit_mode: the effective one (the calculator lets the master output unit override a requested CASE/PIECE, as production does); requested kept beside it.
            $log['conditions'] = ['period' => $period, 'receipts' => count($receipts), 'basis_type' => $result['basis']['basis_type'] ?? null,
                'unit_mode' => $result['basis']['unit_mode'] ?? $options['unit_mode'], 'requested_unit_mode' => $options['unit_mode'], 'packing_unit' => $packing, 'order_unit' => $orderUnit['label'] ?? null, 'shipping_unit' => $shippingUnit['mode'],
                'channel' => $channel, 'masters_as_of' => $cond['masters_as_of'] ?? null];
            if (! $result['available']) {
                $log['reason'] = $result['basis']['reason'] ?? '算定不可';

                return ['policy' => null, 'log' => $log, 'shipping_unit' => $shippingUnit];
            }
            $values = $result['values'];
            if (! empty($scenario['fixed_bounds']) && is_array($series['saved_policy'] ?? null)) {
                // Comparison scenario only (not the weekly operation, which recalculates these too): the saved minimum, maximum and multiple are held fixed.
                foreach (['min_order_pieces', 'max_order_pieces', 'order_multiple_pieces'] as $key) {
                    if ((int) ($series['saved_policy'][$key] ?? 0) > 0) {
                        $values[$key] = (int) $series['saved_policy'][$key];
                    }
                }
                $values['min_order_pieces'] = min($values['min_order_pieces'], $values['max_order_pieces']);
                $log['fixed_bounds'] = true;
            }
            $policy = $values + ['calculation_basis' => $result['basis'] + ($cond['basis'] ?? [])] + $this->policyIdentity($series, $channel, $packing);
            $log['available'] = true;
            $log['values'] = $values;
            $log['mean_daily'] = $result['basis']['mean_daily'] ?? null;
            $log['event_add_on'] = $result['basis']['event_adjustment']['target_add_on_pieces'] ?? 0;
            $log['selection'] = $result['basis']['demand_profile']['selection'] ?? null;

            return ['policy' => $policy, 'log' => $log, 'shipping_unit' => $shippingUnit];
        } catch (\RuntimeException $e) {
            // A business refusal (production message) makes the recalculation unavailable; a database failure is not a result and propagates.
            throw_if($e instanceof \Illuminate\Database\QueryException, $e);
            $log['reason'] = $e->getMessage();

            return ['policy' => null, 'log' => $log, 'shipping_unit' => $shippingUnit];
        }
    }

    /** Production's binding checks against the policy's quantity conditions (PalletUnitService / ShippingQuantityService::assertPolicyMatches). */
    private function assertConstraint(array $policy, array $shippingUnit, int $capacity): void
    {
        $mode = $shippingUnit['mode'] ?? 'STANDARD';
        if ($mode === 'STANDARD') {
            return;
        }
        if (array_key_exists('binding', $shippingUnit)) {
            // The policy's binding row is known (production inputs): run the production check itself — a binding whose version or pieces
            // no longer match the master, a deleted binding, a capacity change or a non-matching quantity condition all hold the order.
            throw_unless(is_array($shippingUnit['binding']), \RuntimeException::class, $mode === 'PALLET'
                ? 'パレット入数が変更・削除されています。商品設定を開き直して再保存してください。' : '出荷数量候補が変更・削除されています。商品設定を開き直して保存してください。');
            $row = (object) $shippingUnit['binding'];
            if ($mode === 'PALLET') {
                app(\App\Services\AutoReplenishment\PalletUnitService::class)->assertBinding($row, $policy, $capacity);
            } else {
                app(\App\Services\AutoReplenishment\ShippingQuantityService::class)->assertBinding($row, $policy, $capacity);
            }

            return;
        }
        if ($mode === 'PALLET') {
            throw_unless(($policy['channel'] ?? '') === 'LOGISTICS', \RuntimeException::class, 'パレット単位は物流発注（店間）で選択してください。');
            app(\App\Services\AutoReplenishment\PalletUnitService::class)->assertPolicyMatches($policy, (int) ($shippingUnit['pieces_per_pallet'] ?? 0));
        } else {
            throw_unless(($policy['channel'] ?? '') === 'LOGISTICS' && ($policy['strategy_key'] ?? '') === 'arrival_adaptive_v1', \RuntimeException::class, '出荷数量候補は物流発注（店間）の入荷時の補充目標を確認する計算方式で利用してください。');
            app(\App\Services\AutoReplenishment\ShippingQuantityService::class)->assertPolicyMatches($policy, (array) ($shippingUnit['quantities'] ?? []));
        }
    }

    /** The policy fields the daily strategy and the safety check read besides the quantities. */
    private function policyIdentity(array $series, string $channel, int $packing): array
    {
        return ['channel' => $channel, 'is_enabled' => true, 'packing_unit' => $packing, 'client_id' => (int) ($series['client_id'] ?? 0),
            'warehouse_id' => (int) $series['warehouse_id'], 'item_id' => (int) $series['item_id'],
            'source_warehouse_id' => $series['route']['source_warehouse_id'] ?? null,
            'strategy_key' => $series['saved_policy']['strategy_key'] ?? 'arrival_adaptive_v1'];
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
