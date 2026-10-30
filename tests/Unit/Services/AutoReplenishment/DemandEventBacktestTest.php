<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\DemandEventBacktest;
use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\DemandEventLearner;
use App\Services\AutoReplenishment\DemandEventLearningService;
use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayEngine;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

/** Re-review R2/R4/R5: applicability per product, category rule, per-day block positions, window coverage. */
class DemandEventBacktestTest extends TestCase
{
    private function backtest(array $instance, array $positions): DemandEventBacktest
    {
        $learning = Mockery::mock(DemandEventLearningService::class);
        $learning->shouldReceive('trainingInstances')->andReturn([$instance]);
        $learning->shouldReceive('learn')->andReturn(['items' => [1 => ['YEAR_END' => ['positions' => $positions, 'pieces' => 1000]], 2 => ['YEAR_END' => ['positions' => $positions, 'pieces' => 1000]]],
            'categories' => [3142 => ['YEAR_END' => ['positions' => array_map(fn ($p) => ['factor' => 9.0, 'factor_weekday' => 9.0], $positions), 'pieces' => 5000]]]]);

        return new DemandEventBacktest($learning, app(ReplayEngine::class), app(DemandEventCalendar::class));
    }

    public function test_only_series_with_the_event_applied_are_adjusted_and_judged_candidates_are_reported_separately(): void
    {
        $calendar = app(DemandEventCalendar::class);
        $instance = $calendar->normalize([['type' => 'YEAR_END', 'start' => '2025-12-20', 'end' => '2026-01-07']])[0];
        $upcoming = $calendar->normalize([['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-07']])[0];
        $positions = [];
        for ($d = CarbonImmutable::parse($instance['start']); $d->lte(CarbonImmutable::parse($instance['end'])); $d = $d->addDay()) {
            $positions[$d->format('m-d')] = ['factor' => 3.0, 'factor_weekday' => 3.0];
        }
        $demand = [];
        for ($d = CarbonImmutable::parse('2025-07-01'); $d->lte(CarbonImmutable::parse('2026-02-15')); $d = $d->addDay()) {
            $demand[$d->toDateString()] = $d->between(CarbonImmutable::parse($instance['start']), CarbonImmutable::parse($instance['end'])) ? 30 : 10;
        }
        $series = fn (int $item, array $applied) => ['client_id' => 6, 'warehouse_id' => 2, 'item_id' => $item, 'item_code' => (string) $item, 'capacity_case' => 1, 'item_category3_id' => 3142, 'applied_types' => $applied];
        $backtest = $this->backtest($instance, $positions);
        $calendarSimple = ReplayCalendar::simple(2);
        // Nothing applied (pipeline shape): the verdict cannot come from a provisional application.
        $none = $backtest->run(6, $upcoming, [$series(1, []), $series(2, [])], [2 => [1 => $demand, 2 => $demand]], '2026-10-30', 1, $calendarSimple);
        self::assertSame('NO_DATA', $none['verdict']);
        self::assertStringContainsString('適用した商品がありません', $none['reason']);
        self::assertNull($none['pooled']['base']['wape']);
        self::assertSame(2, $none['candidate']['series']['scored']);
        self::assertSame(2, $none['candidate']['series']['improved']);
        self::assertGreaterThan($none['candidate']['pooled']['event']['wape'], $none['candidate']['pooled']['base']['wape']);
        // One applied: the verdict is from that series only; the other is a candidate.
        $one = $backtest->run(6, $upcoming, [$series(1, ['YEAR_END']), $series(2, ['OBON'])], [2 => [1 => $demand, 2 => $demand]], '2026-10-30', 1, $calendarSimple);
        self::assertSame('PASS', $one['verdict']);
        self::assertSame(1, $one['series']['scored']);
        self::assertSame(1, $one['candidate']['series']['scored']);
        self::assertSame(1, $one['conditions']['applied_series']);
        self::assertGreaterThan($one['pooled']['event']['wape'], $one['pooled']['base']['wape']);
        // A series without the event applied is replayed exactly like the base case (base = event) — explicit no-events input gives the same numbers.
        $explicit = $backtest->run(6, $upcoming, [$series(2, []) + ['events' => []]], [2 => [2 => $demand]], '2026-10-30', 1, $calendarSimple);
        self::assertSame($none['candidate']['pooled']['base']['wape'], $explicit['candidate']['pooled']['base']['wape']);
        // Series without the key (CLI / older callers) count as applied.
        $legacy = $backtest->run(6, $upcoming, [array_diff_key($series(1, []), ['applied_types' => 1])], [2 => [1 => $demand]], '2026-10-30', 1, $calendarSimple);
        self::assertSame('PASS', $legacy['verdict']);
        self::assertSame(['物流発注の出荷候補・パレット制約', '再生開始時点の既発注・入荷予定', '連携出庫実績（売上基準で算定）'], $legacy['conditions']['not_reproduced']);
    }

    public function test_category_factors_are_dropped_by_the_calculator_unless_the_fallback_is_on(): void
    {
        $daily = [];
        for ($d = CarbonImmutable::parse('2026-09-19'); $d->toDateString() <= '2026-12-16'; $d = $d->addDay()) {
            $daily[$d->toDateString()] = 10;
        }
        $history = ['scope' => ['channel' => 'EOS', 'warehouse_id' => 2, 'packing_unit' => 1, 'capacity_case' => 24], 'period' => ['start' => '2026-09-19', 'end' => '2026-12-16'], 'daily' => $daily, 'receipts' => [],
            'nominal_lead_days' => 2, 'review_interval_days' => 1, 'maximum_arrival_gap_days' => 2, 'warnings' => [],
            'arrival_schedule' => ['arrival_dates' => ['2026-12-18', '2026-12-19'], 'end' => '2027-02-10', 'opportunities' => [['generation_date' => '2026-12-18', 'arrival_date' => '2026-12-18'], ['generation_date' => '2026-12-19', 'arrival_date' => '2026-12-19']]]];
        $positions = ['12-25' => ['factor' => 2.0, 'factor_weekday' => 2.0]];
        $events = ['events' => [['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-07']],
            'factors' => ['item' => ['YEAR_END' => ['pieces' => 10, 'positions' => $positions]], 'category' => ['YEAR_END' => ['pieces' => 1000, 'positions' => ['12-25' => ['factor' => 8.0, 'factor_weekday' => 8.0]]]]]];
        $calculator = app(SettingsRecommendationCalculator::class);
        $options = ['unit_mode' => 'PIECE', 'safety_stock_days' => 5, 'review_days' => 7, 'demand_events' => $events];
        config(['replenishment.demand_events.category_fallback' => false]);
        $off = $calculator->calculate($history, $options);
        self::assertEqualsWithDelta(20.0, $off['basis']['demand_profile']['event_forecast']['days']['2026-12-25'], 0.01); // ×2 (own), category ignored
        config(['replenishment.demand_events.category_fallback' => true]);
        $on = $calculator->calculate($history, $options);
        self::assertEqualsWithDelta(40.0, $on['basis']['demand_profile']['event_forecast']['days']['2026-12-25'], 0.01); // own 2 shrunk toward category 8 at w = 0.5 → 4
        config(['replenishment.demand_events.category_fallback' => false]);
    }

    public function test_block_days_are_learned_per_day_and_every_adjusted_day_belongs_to_exactly_one_window(): void
    {
        $calendar = app(DemandEventCalendar::class);
        $gw = $calendar->normalize([['type' => 'GW', 'start' => '2025-05-03', 'end' => '2025-05-07']])[0];
        // R4: three middle days with 100・200・300 pieces over a flat baseline of 10 → d2 = 10×, d3 = 20×, d4 = 30× (not one shared 'mid' = 30×).
        $daily = [];
        for ($d = CarbonImmutable::parse('2025-02-01'); $d->lte(CarbonImmutable::parse('2025-05-09')); $d = $d->addDay()) {
            $daily[$d->toDateString()] = 10;
        }
        $daily['2025-05-04'] = 100;
        $daily['2025-05-05'] = 200;
        $daily['2025-05-06'] = 300;
        $ratios = app(DemandEventLearner::class)->instanceRatios($daily, $gw, [$gw]);
        self::assertSame(['pre10', 'pre9', 'pre8', 'pre7', 'pre6', 'pre5', 'pre4', 'pre3', 'pre2', 'pre1', 'd1', 'd2', 'd3', 'd4', 'last', 'post1', 'post2'], array_keys($ratios['positions']));
        self::assertSame([10.0, 20.0, 30.0], [$ratios['positions']['d2']['ratio'], $ratios['positions']['d3']['ratio'], $ratios['positions']['d4']['ratio']]);
        self::assertArrayNotHasKey('mid', $ratios['positions']);
        self::assertSame('event-factors-v2', DemandEventLearner::MODEL_VERSION);
        // R5: the pre window ends the day before the block, the block window includes the post days, the post window starts after them.
        $windows = app(DemandEventBacktest::class)->scoringWindows($gw);
        self::assertSame([['2025-04-09', '2025-05-02'], ['2025-05-03', '2025-05-09'], ['2025-05-10', '2025-05-23']], array_map(fn ($w) => [$w['start'], $w['end']], array_values($windows)));
        foreach ($calendar->adjustedDates([$gw], '2025-04-01', '2025-06-01') as $date) {
            $covering = array_keys(array_filter($windows, fn ($w) => $date >= $w['start'] && $date <= $w['end']));
            self::assertCount(1, $covering, "adjusted day {$date} must be scored in exactly one window");
            self::assertContains($covering[0], ['pre', 'block']);
        }
        $yearEnd = $calendar->normalize([['type' => 'YEAR_END', 'start' => '2025-12-20', 'end' => '2026-01-07']])[0];
        $windows = app(DemandEventBacktest::class)->scoringWindows($yearEnd);
        self::assertSame([['2025-12-06', '2025-12-19'], ['2025-12-20', '2026-01-07'], ['2026-01-08', '2026-01-21']], array_map(fn ($w) => [$w['start'], $w['end']], array_values($windows)));
    }
}
