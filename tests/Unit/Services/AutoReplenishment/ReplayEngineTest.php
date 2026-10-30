<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsNationalHoliday;
use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayEngine;
use App\Services\AutoReplenishment\Replay\ReplayMetrics;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ReplayEngineTest extends TestCase
{
    private function demand(string $start, string $end, float $peak = 3.0): array
    {
        $series = [];
        for ($d = CarbonImmutable::parse($start); $d->toDateString() <= $end; $d = $d->addDay()) {
            $value = $d->isSaturday() ? 20 : 10;
            $md = $d->format('m-d');
            if ($md >= '12-25' && $md <= '12-31') {
                $value *= $peak;
            } elseif ($md >= '01-02' && $md <= '01-04') {
                $value *= 0.5;
            }
            $series[$d->toDateString()] = (int) round($value);
        }

        return $series;
    }

    private function series(array $demand): array
    {
        return ['client_id' => 6, 'warehouse_id' => 2, 'warehouse_code' => '2', 'item_id' => 143024, 'item_code' => '143024', 'item_name' => 'テスト', 'capacity_case' => 24, 'demand' => $demand];
    }

    private function events(): array
    {
        $calendar = app(DemandEventCalendar::class);
        $events = [];
        foreach ([2025, 2026] as $year) {
            $events = array_merge($events, $calendar->proposals($year, array_column(WmsNationalHoliday::generateJapaneseHolidays($year), 'holiday_name', 'holiday_date')));
        }

        return $events;
    }

    public function test_simple_calendar_skips_sundays_and_stop_ranges_and_builds_a_schedule(): void
    {
        $calendar = ReplayCalendar::simple(2, [['2025-12-31', '2026-01-03']]);
        self::assertSame('2025-12-17', $calendar->arrivalFor('2025-12-15')); // Monday → Wednesday
        self::assertSame('2025-12-22', $calendar->arrivalFor('2025-12-19')); // Friday + 2 = Sunday → Monday
        self::assertSame('2026-01-05', $calendar->arrivalFor('2025-12-29')); // stop range, then Sunday 1/4
        self::assertSame(['generation_date' => '2025-12-16', 'arrival_date' => '2025-12-18'], $calendar->nextArrivalAfter('2025-12-15'));
        $schedule = $calendar->schedule('2025-12-01');
        self::assertSame(56, $schedule['generation_count']);
        self::assertSame(3, $schedule['nominal_lead_days']); // Friday + 2 lands on Sunday → Monday, the worst case inside the first week
        self::assertGreaterThanOrEqual(5, $schedule['maximum_gap_days']); // 12/30 → 1/5
        self::assertSame(range(0, 6), $schedule['weekdays']);
        $weekdaysOnly = ReplayCalendar::simple(1, [], [1, 2, 3, 4, 5]);
        self::assertNull($weekdaysOnly->arrivalFor('2025-12-20'));
        self::assertSame('2025-12-23', $weekdaysOnly->arrivalFor('2025-12-22'));
    }

    public function test_replay_runs_the_real_calculator_and_strategy_and_event_factors_reduce_year_end_shortages(): void
    {
        $demand = $this->demand('2025-07-01', '2026-01-31');
        $series = $this->series($demand);
        $calendar = ReplayCalendar::simple(2, [['2025-12-31', '2026-01-03']]);
        $events = $this->events();
        $factors = fn (int $item) => ['item' => ['YEAR_END' => ['pieces' => 5000, 'positions' => array_combine(
            ['12-25', '12-26', '12-27', '12-28', '12-29', '12-30', '12-31', '01-02', '01-03', '01-04'],
            array_merge(array_fill(0, 7, ['factor' => 3.0, 'factor_weekday' => 3.0]), array_fill(0, 3, ['factor' => 0.5, 'factor_weekday' => 0.5])))]]];
        $base = ['name' => 'base', 'start' => '2025-10-06', 'end' => '2026-01-25', 'mode' => 'weekly', 'unit_mode' => 'CASE', 'safety_stock_days' => 5,
            'review_days' => 7, 'variability_buffer' => false, 'events' => [], 'segment_events' => $events, 'warmup_days' => 28];
        $withEvents = array_replace($base, ['name' => 'eventA', 'events' => $events, 'factors' => $factors]);
        $engine = app(ReplayEngine::class);
        $baseResult = $engine->run($series, $base, $calendar);
        $eventResult = $engine->run($series, $withEvents, $calendar);
        foreach ([$baseResult, $eventResult] as $result) {
            self::assertCount(112, $result['days']);
            self::assertSame('2025-10-06', $result['recalcs'][0]['date']);
            self::assertTrue($result['recalcs'][0]['available']);
            self::assertSame(0, $result['metrics']['hold_days']);
            foreach ($result['days'] as $row) {
                self::assertGreaterThanOrEqual(0, $row['stock']);
                self::assertSame($row['opening'] + $row['received'] - min($row['opening'] + $row['received'], (int) $row['demand']), $row['stock']);
            }
            foreach ($result['orders'] as $order) {
                self::assertSame(0, $order['pieces'] % 24);
                self::assertSame($calendar->arrivalFor($order['date']), $order['arrival_date']);
            }
            self::assertSame(16, count(array_filter($result['recalcs'], fn ($r) => $r['available']))); // every Monday + the first day
        }
        $baseYearEnd = $baseResult['metrics']['segments']['年末年始'];
        $eventYearEnd = $eventResult['metrics']['segments']['年末年始'];
        self::assertGreaterThan(0, $baseYearEnd['unmet']);
        self::assertLessThan($baseYearEnd['unmet'], $eventYearEnd['unmet']);
        self::assertGreaterThan(0, $eventResult['metrics']['event_orders']);
        self::assertSame('年末年始', $eventResult['orders'][array_key_last($eventResult['orders'])]['event'] ?? '年末年始');
        $recalc = array_values(array_filter($eventResult['recalcs'], fn ($r) => $r['date'] === '2025-12-22'))[0];
        self::assertGreaterThan(0, $recalc['event_add_on']);
        // The weekday-only level recovers after the peak when event days are excluded from the 28-day window.
        $january = array_values(array_filter($eventResult['recalcs'], fn ($r) => $r['date'] === '2026-01-12'))[0];
        self::assertLessThan(13, $january['mean_daily']);
        $aggregate = (new ReplayMetrics)->aggregate([$baseResult, $eventResult]);
        self::assertSame(2, $aggregate['series']);
        self::assertArrayHasKey('平常日', $aggregate['segments']);
    }

    public function test_fixed_mode_recalculates_once_and_missing_days_are_not_sales(): void
    {
        $demand = $this->demand('2025-07-01', '2025-12-15');
        $demand['2025-11-10'] = null;
        $series = $this->series($demand);
        $scenario = ['name' => 'fixed', 'start' => '2025-10-06', 'end' => '2025-12-14', 'mode' => 'fixed', 'unit_mode' => 'PIECE', 'safety_stock_days' => 5,
            'review_days' => 7, 'events' => [], 'segment_events' => [], 'warmup_days' => 7];
        $result = app(ReplayEngine::class)->run($series, $scenario, ReplayCalendar::simple(1));
        self::assertCount(1, $result['recalcs']);
        $targets = array_unique(array_column($result['days'], 'target'));
        self::assertCount(1, $targets);
        $row = array_values(array_filter($result['days'], fn ($r) => $r['date'] === '2025-11-10'))[0];
        self::assertNull($row['demand']);
        self::assertSame($row['opening'] + $row['received'], $row['stock']);
        self::assertSame($result['metrics']['scored_days'] - 1, $result['metrics']['observed_days']);
        self::assertSame(0, $result['metrics']['unmet']);
    }

    public function test_weekly_recalculation_applies_the_same_change_guard_as_the_refresh_and_keeps_the_old_setting_when_held(): void
    {
        $demand = [];
        for ($d = CarbonImmutable::parse('2025-06-01'); $d->toDateString() <= '2025-11-30'; $d = $d->addDay()) {
            $demand[$d->toDateString()] = $d->toDateString() < '2025-10-06' ? 10 : 100; // level jumps ×10 at the replay start
        }
        $series = $this->series($demand);
        $scenario = ['name' => 'guard', 'start' => '2025-10-06', 'end' => '2025-11-16', 'mode' => 'weekly', 'unit_mode' => 'PIECE', 'safety_stock_days' => 5,
            'review_days' => 7, 'events' => [], 'segment_events' => [], 'warmup_days' => 0, 'max_change_ratio' => 2.0];
        $guarded = app(ReplayEngine::class)->run($series, $scenario, ReplayCalendar::simple(1));
        $outcomes = array_column($guarded['recalcs'], 'outcome');
        self::assertSame('INITIAL', $outcomes[0]);
        self::assertContains('HELD', $outcomes);
        $firstHeld = array_values(array_filter($guarded['recalcs'], fn ($r) => $r['outcome'] === 'HELD'))[0];
        self::assertStringContainsString('変化幅の上限', $firstHeld['reason']);
        self::assertGreaterThanOrEqual(1, $guarded['metrics']['held_recalcs']);
        self::assertGreaterThanOrEqual(1, $guarded['metrics']['max_consecutive_holds']);
        // While held, the target in force stays the initial one; without a guard the ×10 level is adopted at once.
        $heldDay = array_values(array_filter($guarded['days'], fn ($r) => $r['date'] === $firstHeld['date']))[0];
        self::assertSame($guarded['recalcs'][0]['values']['target_stock_pieces'], $heldDay['target']);
        $free = app(ReplayEngine::class)->run($series, array_replace($scenario, ['max_change_ratio' => null]), ReplayCalendar::simple(1));
        self::assertNotContains('HELD', array_column($free['recalcs'], 'outcome'));
        self::assertGreaterThan($guarded['days'][20]['target'], $free['days'][20]['target']);
        self::assertSame(0, $free['metrics']['held_recalcs']);
    }

    public function test_fixed_bounds_is_a_comparison_only_and_the_event_add_on_unwinds_without_being_held(): void
    {
        $demand = $this->demand('2025-07-01', '2026-02-15');
        $series = $this->series($demand) + ['saved_policy' => ['reorder_point_pieces' => 50, 'target_stock_pieces' => 90, 'min_order_pieces' => 48, 'max_order_pieces' => 96, 'order_multiple_pieces' => 48]];
        $calendar = ReplayCalendar::simple(2, [['2025-12-31', '2026-01-03']]);
        $events = $this->events();
        $factors = fn (int $item) => ['item' => ['YEAR_END' => ['pieces' => 5000, 'positions' => array_combine(
            ['12-25', '12-26', '12-27', '12-28', '12-29', '12-30', '12-31', '01-02', '01-03', '01-04'],
            array_merge(array_fill(0, 7, ['factor' => 3.0, 'factor_weekday' => 3.0]), array_fill(0, 3, ['factor' => 0.5, 'factor_weekday' => 0.5])))]]];
        $scenario = ['name' => 'eventA', 'start' => '2025-10-06', 'end' => '2026-02-08', 'mode' => 'weekly', 'unit_mode' => 'CASE', 'safety_stock_days' => 5, 'review_days' => 7,
            'variability_buffer' => false, 'events' => $events, 'factors' => $factors, 'segment_events' => $events, 'warmup_days' => 28, 'max_change_ratio' => 2.0, 'fixed_bounds' => true];
        $result = app(ReplayEngine::class)->run($series, $scenario, $calendar);
        foreach (array_filter($result['recalcs'], fn ($r) => $r['available']) as $recalc) {
            self::assertSame([48, 96, 48], [$recalc['values']['min_order_pieces'], $recalc['values']['max_order_pieces'], $recalc['values']['order_multiple_pieces']]);
            self::assertTrue($recalc['fixed_bounds']);
        }
        foreach ($result['orders'] as $order) {
            self::assertSame(0, $order['pieces'] % 48);
            self::assertLessThanOrEqual(96, $order['pieces']);
        }
        // The target rises for 年末年始 and comes back down in January: the decrease unwinds the add-on and is never held.
        $targets = [];
        foreach ($result['recalcs'] as $recalc) {
            if ($recalc['available']) {
                $targets[$recalc['date']] = $recalc['values']['target_stock_pieces'];
            }
        }
        self::assertGreaterThan($targets['2025-11-24'], $targets['2025-12-22']);
        self::assertLessThan($targets['2025-12-22'], $targets['2026-01-19']);
        self::assertNotContains('HELD', array_column(array_filter($result['recalcs'], fn ($r) => $r['date'] >= '2026-01-01'), 'outcome'));
        // The weekly operation (default) recalculates the bounds too, like RecommendationRefreshService does.
        $free = app(ReplayEngine::class)->run($series, array_replace($scenario, ['fixed_bounds' => false]), $calendar);
        self::assertArrayNotHasKey('fixed_bounds', $free['recalcs'][0]);
        self::assertNotSame(48, $free['recalcs'][0]['values']['min_order_pieces']); // calculator: one case (24) minimum
        self::assertNotSame($free['recalcs'][0]['values'], $result['recalcs'][0]['values']);
    }

    /** R3: the weekly-operation replay uses the conditions the operator saved with the product, not the scenario defaults. */
    public function test_saved_conditions_follow_the_product_basis_like_the_weekly_refresh(): void
    {
        $demand = $this->demand('2025-07-01', '2025-12-15');
        $scenario = ['name' => 'base', 'start' => '2025-10-06', 'end' => '2025-11-16', 'mode' => 'weekly', 'unit_mode' => 'CASE', 'safety_stock_days' => 5, 'review_days' => 7,
            'events' => [], 'segment_events' => [], 'warmup_days' => 0, 'max_change_ratio' => 2.0, 'saved_conditions' => true];
        $basis = ['unit_mode' => 'PIECE', 'review_days' => 14, 'safety_stock_days' => 3];
        $withBasis = $this->series($demand) + ['saved_policy' => ['reorder_point_pieces' => 50, 'target_stock_pieces' => 90, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24, 'calculation_basis' => $basis]];
        $withoutBasis = $this->series($demand);
        $a = app(ReplayEngine::class)->run($withBasis, $scenario, ReplayCalendar::simple(2));
        $b = app(ReplayEngine::class)->run($withoutBasis, $scenario, ReplayCalendar::simple(2));
        self::assertSame(['unit_mode' => 'PIECE', 'review_days' => 14, 'safety_stock_days' => 3], $a['recalcs'][0]['saved_conditions']);
        self::assertArrayNotHasKey('saved_conditions', $b['recalcs'][0]);
        self::assertSame(1, $a['recalcs'][0]['values']['order_multiple_pieces']); // PIECE as saved, not CASE (24)
        self::assertSame(24, $b['recalcs'][0]['values']['order_multiple_pieces']);
        self::assertNotSame($a['recalcs'][0]['values']['target_stock_pieces'], $b['recalcs'][0]['values']['target_stock_pieces']); // 14-day review vs 7
        // A product saved as PALLET falls back to AUTO, as the refresh does.
        $pallet = $withBasis;
        $pallet['saved_policy']['calculation_basis']['unit_mode'] = 'PALLET';
        self::assertSame('AUTO', app(ReplayEngine::class)->run($pallet, $scenario, ReplayCalendar::simple(2))['recalcs'][0]['saved_conditions']['unit_mode']);
    }

    public function test_saved_mode_uses_the_production_setting_or_skips_the_series(): void
    {
        $series = $this->series($this->demand('2025-07-01', '2025-12-15'));
        $scenario = ['name' => 'saved', 'start' => '2025-10-06', 'end' => '2025-11-30', 'mode' => 'saved', 'unit_mode' => 'PIECE', 'safety_stock_days' => 5,
            'review_days' => 7, 'events' => [], 'segment_events' => [], 'warmup_days' => 0];
        $skipped = app(ReplayEngine::class)->run($series, $scenario, ReplayCalendar::simple(1));
        self::assertArrayHasKey('skipped', $skipped);
        self::assertSame(0, $skipped['metrics']['scored_days']);
        $series['saved_policy'] = ['reorder_point_pieces' => 60, 'target_stock_pieces' => 120, 'min_order_pieces' => 24, 'max_order_pieces' => 96, 'order_multiple_pieces' => 24,
            'strategy_key' => 'arrival_adaptive_v1', 'calculation_basis' => ['mean_daily' => 11.4, 'batch_quantity_pieces' => 72]];
        $saved = app(ReplayEngine::class)->run($series, $scenario, ReplayCalendar::simple(1));
        self::assertCount(1, $saved['recalcs']);
        self::assertSame('SAVED', $saved['recalcs'][0]['outcome']);
        self::assertSame([120], array_values(array_unique(array_column($saved['days'], 'target'))));
        self::assertGreaterThan(0, $saved['metrics']['orders']);
        foreach ($saved['orders'] as $order) {
            self::assertSame(0, $order['pieces'] % 24);
            self::assertLessThanOrEqual(96, $order['pieces']);
        }
    }

    public function test_review_interval_follows_the_calendar_definition(): void
    {
        self::assertSame(1, ReplayCalendar::simple(2)->reviewInterval());
        self::assertSame(3, ReplayCalendar::simple(2, [], [1, 2, 3, 4, 5])->reviewInterval()); // Friday → Monday
        self::assertSame(7, ReplayCalendar::simple(2, [], [1])->reviewInterval());
        $scheduled = ReplayCalendar::scheduled(fn (string $asOf) => [], 2);
        self::assertSame(2, $scheduled->reviewInterval()); // Saturday 10:00 → Sunday 22:00 rounded up by the production schedule
    }
}
