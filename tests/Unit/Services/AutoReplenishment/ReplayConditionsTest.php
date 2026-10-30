<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayEngine;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The replay fed with production conditions (linked receipts, output unit, warehouse × product quantity constraint)
 * computes exactly what the production calculator computes for the same inputs, and never relaxes a constraint on its own.
 */
class ReplayConditionsTest extends TestCase
{
    private function demand(string $start, string $end, int $weekday = 10, int $saturday = 20): array
    {
        $series = [];
        for ($d = CarbonImmutable::parse($start); $d->toDateString() <= $end; $d = $d->addDay()) {
            $series[$d->toDateString()] = $d->isSaturday() ? $saturday : $weekday;
        }

        return $series;
    }

    private function series(array $demand, string $channel = 'EOS', array $route = []): array
    {
        return ['client_id' => 6, 'warehouse_id' => 2, 'warehouse_code' => '2', 'item_id' => 143024, 'item_code' => '143024', 'item_name' => 'テスト', 'capacity_case' => 24,
            'channel' => $channel, 'policy_id' => 1, 'demand' => $demand,
            'route' => $route + ['item_id' => 143024, 'channel' => $channel, 'contractor_id' => 10, 'supplier_id' => 20, 'source_warehouse_id' => $channel === 'LOGISTICS' ? 91 : null,
                'delivery_course_id' => $channel === 'LOGISTICS' ? 5 : null, 'order_unit_mode' => 'AUTO', 'shipping_unit_mode' => 'STANDARD', 'strategy_key' => 'arrival_adaptive_v1']];
    }

    private function scenario(array $overrides = []): array
    {
        return array_replace(['name' => 'base', 'start' => '2025-10-06', 'end' => '2025-11-16', 'mode' => 'weekly', 'unit_mode' => 'CASE', 'safety_stock_days' => 5, 'review_days' => 7,
            'events' => [], 'segment_events' => [], 'warmup_days' => 0, 'max_change_ratio' => null, 'history_not_before' => null], $overrides);
    }

    /** $days receipts of $pieces on consecutive Mondays ending before $before. */
    private function receipts(int $days, int $pieces, string $before, int $warehouse = 2): array
    {
        $rows = [];
        $d = CarbonImmutable::parse($before)->subDay();
        while ($d->dayOfWeek !== 1) {
            $d = $d->subDay();
        }
        for ($i = 0; $i < $days; $i++) {
            $rows[] = ['warehouse_id' => $warehouse, 'pieces' => $pieces, 'quantity_types' => ['CASE'], 'date' => $d->subWeeks($i)->toDateString(), 'lead_days' => 2, 'has_shortage' => false, 'source' => ['type' => 'purchase']];
        }

        return $rows;
    }

    private function provider(array $receipts, array $options = [], array $shippingUnit = ['mode' => 'STANDARD'], int $packing = 24, array $basis = []): \Closure
    {
        $unit = ['mode' => 'AUTO', 'piece_orderable' => $packing === 1, 'quantity_type' => $packing === 1 ? 'PIECE' : 'CASE', 'capacity_case' => 24, 'packing_unit' => $packing,
            'label' => $packing === 1 ? 'バラ発注' : 'ケース発注', 'automatic_label' => $packing === 1 ? 'バラ発注' : 'ケース発注', 'reason' => 'テスト用の発注単位'];

        return fn (array $series, string $asOf, array $period) => ['receipts' => $receipts, 'order_unit' => $unit,
            'packing_unit' => $packing, 'options' => $options, 'shipping_unit' => $shippingUnit, 'basis' => $basis,
            'scope' => ['channel' => $series['route']['channel'], 'contractor_id' => 10, 'supplier_id' => 20, 'source_warehouse_id' => $series['route']['source_warehouse_id'], 'delivery_course_id' => $series['route']['delivery_course_id'], 'order_unit_mode' => 'AUTO'],
            'masters_as_of' => '2026-10-31'];
    }

    public function test_recommendation_with_linked_receipts_equals_the_production_calculator_for_the_same_inputs(): void
    {
        $demand = $this->demand('2025-06-01', '2025-11-16');
        $receipts = $this->receipts(6, 72, '2025-10-06'); // 6 receipt days → linked_receipts basis in production
        $calendar = ReplayCalendar::simple(2);
        $run = app(ReplayEngine::class)->run($this->series($demand), $this->scenario(['mode' => 'fixed', 'conditions_provider' => $this->provider($receipts)]), $calendar);
        $recalc = $run['recalcs'][0];
        self::assertSame('INITIAL', $recalc['outcome'], $recalc['reason'] ?? '');
        self::assertSame('linked_receipts', $recalc['conditions']['basis_type']);
        self::assertSame(6, $recalc['conditions']['receipts']);
        self::assertSame(['start' => '2025-07-08', 'end' => '2025-10-05'], $recalc['conditions']['period']);
        // The production calculator with the identical history (same window, receipts, schedule, unit) gives the identical values.
        $schedule = $calendar->schedule('2025-10-06');
        $daily = [];
        for ($d = CarbonImmutable::parse('2025-07-08'); $d->toDateString() <= '2025-10-05'; $d = $d->addDay()) {
            $daily[$d->toDateString()] = $demand[$d->toDateString()];
        }
        $history = ['scope' => ['client_id' => 6, 'warehouse_id' => 2, 'warehouse_code' => '2', 'item_id' => 143024, 'item_code' => '143024', 'channel' => 'EOS', 'contractor_id' => 10, 'supplier_id' => 20,
            'source_warehouse_id' => null, 'delivery_course_id' => null, 'capacity_case' => 24, 'packing_unit' => 24, 'order_unit' => ['mode' => 'AUTO', 'piece_orderable' => false, 'quantity_type' => 'CASE', 'capacity_case' => 24, 'packing_unit' => 24, 'label' => 'ケース発注', 'automatic_label' => 'ケース発注', 'reason' => 'テスト用の発注単位'], 'order_unit_mode' => 'AUTO'],
            'period' => ['start' => '2025-07-08', 'end' => '2025-10-05'], 'receipts' => $receipts, 'daily' => $daily, 'warnings' => [], 'nominal_lead_days' => $schedule['nominal_lead_days'],
            'review_interval_days' => 1, 'review_weekdays' => $schedule['weekdays'], 'maximum_arrival_gap_days' => $schedule['maximum_gap_days'], 'arrival_schedule' => $schedule];
        $production = (new SettingsRecommendationCalculator)->calculate($history, ['unit_mode' => 'CASE', 'review_days' => 7, 'safety_stock_days' => 5, 'existing_reorder_point' => null, 'variability_buffer' => false]);
        self::assertTrue($production['available']);
        self::assertSame($production['values'], $recalc['values']);
        self::assertSame(72, $recalc['values']['min_order_pieces']); // the usual batch from the receipts, not from sales
        // Sales-only replay of the same series gives a different usual batch: the receipts are what production uses.
        $sales = app(ReplayEngine::class)->run($this->series($demand), $this->scenario(['mode' => 'fixed']), $calendar)['recalcs'][0];
        self::assertSame('sales', $sales['conditions']['basis_type']);
        self::assertNotSame($sales['values']['min_order_pieces'], $recalc['values']['min_order_pieces']);
    }

    public function test_receipt_window_follows_the_production_rule_zero_four_and_five_days_and_never_uses_future_receipts(): void
    {
        $demand = $this->demand('2025-06-01', '2025-11-16');
        $calendar = ReplayCalendar::simple(2);
        $basisFor = function (array $receipts) use ($demand, $calendar): array {
            $run = app(ReplayEngine::class)->run($this->series($demand), $this->scenario(['mode' => 'fixed', 'conditions_provider' => $this->provider($receipts)]), $calendar);

            return $run['recalcs'][0]['conditions'];
        };
        self::assertSame('sales', $basisFor([])['basis_type']);
        self::assertSame('sales', $basisFor($this->receipts(4, 72, '2025-10-06'))['basis_type']); // 4 days: sales (the production threshold is 5 eligible days)
        self::assertSame('linked_receipts', $basisFor($this->receipts(5, 72, '2025-10-06'))['basis_type']);
        // Boundaries: a receipt on the window's last day counts, one on the calculation date or later never does, one before the window start is out.
        $edge = [['warehouse_id' => 2, 'pieces' => 72, 'quantity_types' => ['CASE'], 'date' => '2025-10-05', 'lead_days' => 2, 'has_shortage' => false, 'source' => []],
            ['warehouse_id' => 2, 'pieces' => 72, 'quantity_types' => ['CASE'], 'date' => '2025-10-06', 'lead_days' => 2, 'has_shortage' => false, 'source' => []],
            ['warehouse_id' => 2, 'pieces' => 72, 'quantity_types' => ['CASE'], 'date' => '2025-10-20', 'lead_days' => 2, 'has_shortage' => false, 'source' => []],
            ['warehouse_id' => 2, 'pieces' => 72, 'quantity_types' => ['CASE'], 'date' => '2025-07-07', 'lead_days' => 2, 'has_shortage' => false, 'source' => []],
            ['warehouse_id' => 2, 'pieces' => 72, 'quantity_types' => ['CASE'], 'date' => '2025-07-08', 'lead_days' => 2, 'has_shortage' => false, 'source' => []]];
        self::assertSame(2, $basisFor($edge)['receipts']);
        // The production lower bound of the window is applied only when asked for, and leaves a past year without a calculation — as production would.
        $bounded = app(ReplayEngine::class)->run($this->series($demand), $this->scenario(['mode' => 'fixed', 'history_not_before' => '2026-08-01', 'conditions_provider' => $this->provider([])]), $calendar)['recalcs'][0];
        self::assertSame('UNAVAILABLE', $bounded['outcome']);
        self::assertSame('算定対象期間の完了実績がありません。', $bounded['reason']);
    }

    public function test_the_route_channel_reaches_the_calculator_instead_of_a_fixed_eos(): void
    {
        $demand = $this->demand('2025-06-01', '2025-11-16');
        $receipts = $this->receipts(6, 72, '2025-10-06');
        $logistics = app(ReplayEngine::class)->run($this->series($demand, 'LOGISTICS'), $this->scenario(['mode' => 'fixed', 'conditions_provider' => $this->provider($receipts)]), ReplayCalendar::simple(2));
        $eos = app(ReplayEngine::class)->run($this->series($demand, 'EOS'), $this->scenario(['mode' => 'fixed', 'conditions_provider' => $this->provider($receipts)]), ReplayCalendar::simple(2));
        self::assertSame('LOGISTICS', $logistics['recalcs'][0]['conditions']['channel']);
        self::assertSame('LOGISTICS', $logistics['conditions']['channel']);
        self::assertSame('INITIAL', $logistics['recalcs'][0]['outcome'], $logistics['recalcs'][0]['reason'] ?? '');
        self::assertNotEmpty($logistics['orders']);
        self::assertSame('EOS', $eos['recalcs'][0]['conditions']['channel']);
        // Output unit: a piece-orderable product (packing 1) and a case-output one (packing 24) order in different multiples.
        $piece = app(ReplayEngine::class)->run($this->series($demand), $this->scenario(['mode' => 'fixed', 'unit_mode' => 'PIECE', 'conditions_provider' => $this->provider([], [], ['mode' => 'STANDARD'], 1)]), ReplayCalendar::simple(2));
        $case = app(ReplayEngine::class)->run($this->series($demand), $this->scenario(['mode' => 'fixed', 'unit_mode' => 'PIECE', 'conditions_provider' => $this->provider([], [], ['mode' => 'STANDARD'], 24)]), ReplayCalendar::simple(2));
        self::assertSame(1, $piece['recalcs'][0]['conditions']['packing_unit']);
        self::assertSame(24, $case['recalcs'][0]['conditions']['packing_unit']);
        self::assertSame(1, $piece['recalcs'][0]['values']['order_multiple_pieces']);
        self::assertSame(24, $case['recalcs'][0]['values']['order_multiple_pieces']);
        foreach ($case['orders'] as $order) {
            self::assertSame(0, $order['pieces'] % 24);
        }
    }

    public function test_pallet_constraint_is_applied_per_warehouse_and_never_relaxed(): void
    {
        $demand = $this->demand('2025-06-01', '2025-11-16', 30, 60);
        $pallet = fn (int $pieces) => $this->provider([], ['unit_mode' => 'PALLET', 'pallet_pieces' => $pieces], ['mode' => 'PALLET', 'id' => 1, 'version' => 1, 'pieces_per_pallet' => $pieces], 24, ['pallet_unit' => ['pieces_per_pallet' => $pieces]]);
        $scenario = $this->scenario(['mode' => 'fixed']);
        $withPallet = app(ReplayEngine::class)->run($this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'PALLET']), $scenario + ['conditions_provider' => $pallet(96)], ReplayCalendar::simple(2));
        $standard = app(ReplayEngine::class)->run($this->series($demand, 'LOGISTICS') + ['warehouse_id' => 3], $scenario + ['conditions_provider' => $this->provider([])], ReplayCalendar::simple(2));
        $recalc = $withPallet['recalcs'][0];
        self::assertSame('INITIAL', $recalc['outcome'], $recalc['reason'] ?? '');
        self::assertSame('PALLET', $recalc['conditions']['unit_mode']);
        self::assertSame('PALLET', $recalc['conditions']['shipping_unit']);
        self::assertSame(96, $recalc['values']['order_multiple_pieces']);
        self::assertSame(0, $recalc['values']['min_order_pieces'] % 96);
        self::assertNotEmpty($withPallet['orders']);
        foreach ($withPallet['orders'] as $order) {
            self::assertSame(0, $order['pieces'] % 96, '毎回の発注数量はパレット入数の倍数');
        }
        // The same product in another warehouse without a pallet binding keeps the case multiple: application is per warehouse × product.
        self::assertSame(24, $standard['recalcs'][0]['values']['order_multiple_pieces']);
        self::assertSame('STANDARD', $standard['recalcs'][0]['conditions']['shipping_unit']);
        // A saved PALLET unit mode with a provider follows production: AUTO, then the binding re-establishes PALLET with the registered pieces.
        $saved = $this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'PALLET']) + ['saved_policy' => ['reorder_point_pieces' => 100, 'target_stock_pieces' => 300, 'min_order_pieces' => 96, 'max_order_pieces' => 480, 'order_multiple_pieces' => 96,
            'calculation_basis' => ['unit_mode' => 'PALLET', 'review_days' => 7, 'safety_stock_days' => 5]]];
        $savedRun = app(ReplayEngine::class)->run($saved, $scenario + ['saved_conditions' => true, 'conditions_provider' => $pallet(96)], ReplayCalendar::simple(2))['recalcs'][0];
        self::assertSame('INITIAL', $savedRun['outcome']);
        self::assertSame('PALLET', $savedRun['conditions']['unit_mode']);
        self::assertSame(96, $savedRun['values']['order_multiple_pieces']);
        // A pallet far above the demand still orders whole pallets (never a smaller quantity) when the recommendation accepts it.
        $huge = app(ReplayEngine::class)->run($this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'PALLET']), $scenario + ['initial_stock' => 0, 'conditions_provider' => $pallet(24 * 400)], ReplayCalendar::simple(2));
        self::assertTrue($huge['recalcs'][0]['outcome'] === 'UNAVAILABLE' || $huge['orders'] !== [], $huge['recalcs'][0]['reason'] ?? '');
        foreach ($huge['orders'] as $order) {
            self::assertSame(0, $order['pieces'] % (24 * 400));
        }
        // A saved maximum below one pallet: the daily calculation holds with the production reason and orders nothing (no rounding below the pallet).
        $tooSmall = $this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'PALLET']) + ['saved_policy' => ['reorder_point_pieces' => 400, 'target_stock_pieces' => 600, 'min_order_pieces' => 960, 'max_order_pieces' => 480, 'order_multiple_pieces' => 960, 'strategy_key' => 'arrival_adaptive_v1',
            'calculation_basis' => ['unit_mode' => 'PALLET', 'review_days' => 7, 'safety_stock_days' => 5, 'demand_profile' => ['weekdays' => array_fill(0, 7, ['mean' => 30.0, 'p95' => 60.0])]]]];
        $held = app(ReplayEngine::class)->run($tooSmall, $this->scenario(['mode' => 'saved', 'initial_stock' => 0, 'conditions_provider' => $pallet(960)]), ReplayCalendar::simple(2));
        self::assertSame([], $held['orders']);
        $reasons = array_unique(array_column(array_filter($held['days'], fn ($d) => $d['phase'] === 'HOLD'), 'reason'));
        self::assertNotEmpty($reasons, '制約で発注できない理由が記録される');
        self::assertMatchesRegularExpression('/最大発注数|補充目標|数量条件/u', implode(' ', $reasons)); // ArrivalBatchStrategy: min > max is refused before any quantity is derived
        // An invalid registered quantity (0) is refused with the production message, not replaced by STANDARD.
        $invalid = app(ReplayEngine::class)->run($this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'PALLET']), $scenario + ['conditions_provider' => $pallet(0)], ReplayCalendar::simple(2))['recalcs'][0];
        self::assertSame('UNAVAILABLE', $invalid['outcome']);
        self::assertSame('物流発注のパレット入数を登録してから再計算してください。', $invalid['reason']);
    }

    public function test_registered_shipping_quantities_bound_every_order_and_a_missing_candidate_is_a_recorded_skip(): void
    {
        $demand = $this->demand('2025-06-01', '2025-11-16', 30, 60);
        $shipment = fn (array $quantities) => $this->provider([], ['unit_mode' => 'AUTO', 'shipping_quantities' => $quantities], ['mode' => 'SHIPMENT', 'id' => 7, 'version' => 1, 'quantities' => $quantities], 24, ['shipping_profile' => ['quantities' => $quantities]]);
        $scenario = $this->scenario(['mode' => 'fixed']);
        $run = app(ReplayEngine::class)->run($this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'SHIPMENT']), $scenario + ['conditions_provider' => $shipment([48, 96, 144, 192, 240, 288])], ReplayCalendar::simple(2));
        $recalc = $run['recalcs'][0];
        self::assertSame('INITIAL', $recalc['outcome'], $recalc['reason'] ?? '');
        self::assertSame('SHIPMENT', $recalc['conditions']['shipping_unit']);
        self::assertSame(48, $recalc['values']['order_multiple_pieces']); // the gcd of the candidates, as production
        self::assertNotEmpty($run['orders']);
        foreach ($run['orders'] as $order) {
            self::assertContains($order['pieces'], [48, 96, 144, 192, 240, 288], '発注数量は登録候補のいずれか（合算や倍数は作らない）');
        }
        // Candidates that no quantity condition can accept: the recommendation is unavailable or the daily order is held with the production reason; nothing is ordered outside the candidates.
        $none = app(ReplayEngine::class)->run($this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'SHIPMENT']), $scenario + ['conditions_provider' => $shipment([24000, 48000])], ReplayCalendar::simple(2));
        self::assertSame([], $none['orders']);
        $reasons = array_filter(array_unique(array_merge(array_column($none['recalcs'], 'reason'), array_column($none['days'], 'reason'))));
        self::assertNotEmpty($reasons);
        self::assertMatchesRegularExpression('/出荷数量候補|最大発注数|補えません/u', implode(' ', $reasons));
    }

    public function test_initial_supply_arrives_once_and_counts_as_incoming_until_then(): void
    {
        $demand = $this->demand('2025-06-01', '2025-11-16');
        $scenario = $this->scenario(['mode' => 'fixed', 'initial_stock' => 0, 'conditions_provider' => $this->provider([])]);
        $without = app(ReplayEngine::class)->run($this->series($demand), $scenario, ReplayCalendar::simple(2));
        $with = app(ReplayEngine::class)->run($this->series($demand), $scenario + ['initial_supply' => [['date' => '2025-10-08', 'pieces' => 240]]], ReplayCalendar::simple(2));
        self::assertSame(240, $with['conditions']['initial_supply_pieces']);
        self::assertSame(0, $without['conditions']['initial_supply_pieces']);
        $day0 = $with['days'][0];
        $day2 = $with['days'][2];
        self::assertSame('2025-10-08', $day2['date']);
        self::assertSame(240, $day2['received']);
        self::assertGreaterThanOrEqual(240, $day0['pending'], '開始時点の既発注は未入荷残として保持される');
        // Exactly one receipt: stock after the day = opening + 240 + that day's own receipts − demand, and no later day receives it again.
        self::assertSame(max(0, $day2['opening'] + $day2['received'] - (int) $day2['demand']), $day2['stock']);
        // Every supply (the initial one and each simulated order) is received exactly once over the replay.
        $arrivedInRange = array_sum(array_column(array_filter($with['orders'], fn ($o) => $o['arrival_date'] <= '2025-11-16'), 'pieces'));
        self::assertSame(240 + $arrivedInRange, array_sum(array_column($with['days'], 'received')));
        // With the supply already on its way the first order is smaller or absent compared with no supply.
        $orderedBefore = fn (array $run) => array_sum(array_column(array_filter($run['orders'], fn ($o) => $o['date'] <= '2025-10-08'), 'pieces'));
        self::assertLessThan($orderedBefore($without), $orderedBefore($with));
    }

    public function test_saved_mode_takes_output_unit_and_constraint_from_the_provider(): void
    {
        $demand = $this->demand('2025-06-01', '2025-11-16', 30, 60);
        $saved = $this->series($demand, 'LOGISTICS', ['shipping_unit_mode' => 'PALLET']) + ['saved_policy' => ['reorder_point_pieces' => 200, 'target_stock_pieces' => 400, 'min_order_pieces' => 96, 'max_order_pieces' => 480, 'order_multiple_pieces' => 96, 'strategy_key' => 'arrival_adaptive_v1',
            'calculation_basis' => ['unit_mode' => 'PALLET', 'review_days' => 7, 'safety_stock_days' => 5, 'demand_profile' => ['weekdays' => array_fill(0, 7, ['mean' => 30.0, 'p95' => 60.0])]]]];
        $provider = $this->provider([], ['unit_mode' => 'PALLET', 'pallet_pieces' => 96], ['mode' => 'PALLET', 'id' => 1, 'version' => 1, 'pieces_per_pallet' => 96], 24);
        $run = app(ReplayEngine::class)->run($saved, $this->scenario(['mode' => 'saved', 'conditions_provider' => $provider]), ReplayCalendar::simple(2));
        self::assertSame('SAVED', $run['recalcs'][0]['outcome']);
        self::assertSame(['packing_unit' => 24, 'shipping_unit' => 'PALLET', 'order_unit' => 'ケース発注'], $run['recalcs'][0]['conditions']);
        foreach ($run['orders'] as $order) {
            self::assertSame(0, $order['pieces'] % 96);
        }
        // A provider refusal (production message) skips the series instead of replaying it with STANDARD.
        $refusing = fn () => throw new \RuntimeException('パレット入数が変更・削除されています。商品設定を開き直して再保存してください。');
        $skipped = app(ReplayEngine::class)->run($saved, $this->scenario(['mode' => 'saved', 'conditions_provider' => $refusing]), ReplayCalendar::simple(2));
        self::assertSame('パレット入数が変更・削除されています。商品設定を開き直して再保存してください。', $skipped['skipped']);
    }

    public function test_a_database_failure_in_the_provider_propagates_instead_of_becoming_an_unavailable_recalculation(): void
    {
        $failing = fn () => throw new \Illuminate\Database\QueryException('sakemaru', 'select 1', [], new \RuntimeException('connection lost'));
        $this->expectException(\Illuminate\Database\QueryException::class);
        app(ReplayEngine::class)->run($this->series($this->demand('2025-06-01', '2025-11-16')), $this->scenario(['mode' => 'fixed', 'conditions_provider' => $failing]), ReplayCalendar::simple(2));
    }
}
