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
}
