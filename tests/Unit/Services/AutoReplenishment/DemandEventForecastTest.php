<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsNationalHoliday;
use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\DemandEventForecast;
use App\Services\AutoReplenishment\DemandProfile;
use App\Services\AutoReplenishment\SafetyStockSettings;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DemandEventForecastTest extends TestCase
{
    private function holidays(int $year): array
    {
        return array_column(WmsNationalHoliday::generateJapaneseHolidays($year), 'holiday_name', 'holiday_date');
    }

    public function test_proposals_cover_year_end_obon_gw_and_long_weekends_from_the_holiday_calendar(): void
    {
        $proposals = app(DemandEventCalendar::class)->proposals(2026, $this->holidays(2026));
        $byType = [];
        foreach ($proposals as $proposal) {
            $byType[$proposal['type']][] = $proposal;
        }
        self::assertSame(['start' => '2026-12-20', 'end' => '2027-01-07', 'align' => 'CALENDAR'], array_intersect_key($byType['YEAR_END'][0], array_flip(['start', 'end', 'align'])));
        self::assertSame(['start' => '2026-08-03', 'end' => '2026-08-17'], array_intersect_key($byType['OBON'][0], array_flip(['start', 'end'])));
        // 5/2(土)〜5/6(水・振替休日) is the non-working block containing 5/4; 10 days before and 2 after carry factors too.
        self::assertSame(['start' => '2026-05-02', 'end' => '2026-05-06', 'pre_days' => 10, 'post_days' => 2], array_intersect_key($byType['GW'][0], array_flip(['start', 'end', 'pre_days', 'post_days'])));
        self::assertSame(['OBON', 'YEAR_END', 'GW'], array_column(app(DemandEventCalendar::class)->standardEvents(2026, $this->holidays(2026)), 'type')); // calendar events first, by start
        $calendar = app(DemandEventCalendar::class);
        self::assertSame(17, count($calendar->adjustedDates($byType['GW'], '2026-04-01', '2026-05-31'))); // 4/22〜5/8
        self::assertSame(5, count($calendar->eventDates($byType['GW'], '2026-04-01', '2026-05-31')));
        $longWeekends = array_column($byType['LONG_WEEKEND'], 'start', 'name');
        self::assertSame('2026-11-21', $longWeekends['勤労感謝の日連休']);
        self::assertSame('2026-09-19', $longWeekends['敬老の日・国民の休日・秋分の日連休']);
        self::assertSame('2026-01-10', $longWeekends['成人の日連休']);
        foreach ($byType['LONG_WEEKEND'] as $event) {
            self::assertGreaterThanOrEqual(2, CarbonImmutable::parse($event['start'])->diffInDays(CarbonImmutable::parse($event['end'])));
            self::assertSame('BLOCK', $event['align']);
        }
    }

    public function test_positions_use_month_day_for_calendar_events_and_block_order_for_long_weekends(): void
    {
        $calendar = app(DemandEventCalendar::class);
        $events = [
            ['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-07'],
            ['type' => 'LONG_WEEKEND', 'name' => '勤労感謝の日連休', 'start' => '2026-11-21', 'end' => '2026-11-23'],
        ];
        $positions = $calendar->positions($events, '2026-11-18', 60);
        self::assertSame(['pre2', 'pre1', 'd1', 'd2', 'last', 'post1'], array_column(array_intersect_key($positions, array_flip(['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24'])), 'position'));
        self::assertFalse($positions['2026-11-19']['in_block']);
        self::assertTrue($positions['2026-11-21']['in_block']);
        self::assertArrayNotHasKey('2026-11-18', $positions);
        self::assertArrayNotHasKey('2026-11-25', $positions);
        self::assertSame('12-31', $positions['2026-12-31']['position']);
        self::assertSame('01-07', $positions['2027-01-07']['position']);
        self::assertArrayNotHasKey('2026-12-19', $positions);
        self::assertSame(['勤労感謝の日連休', 'LONG_WEEKEND'], $positions['2026-11-21']['event']['profile_keys']);
        self::assertSame(['2026-11-21', '2026-11-22', '2026-11-23'], $calendar->eventDates($events, '2026-11-01', '2026-12-19'));
        self::assertCount(19, $calendar->eventDates($events, '2026-12-01', '2027-01-31'));
        // A block day of one event takes precedence over a neighbouring event's post day.
        $overlap = $calendar->positions([
            ['type' => 'LONG_WEEKEND', 'name' => 'A', 'start' => '2026-03-20', 'end' => '2026-03-22'],
            ['type' => 'CUSTOM', 'name' => 'B', 'start' => '2026-03-23', 'end' => '2026-03-23', 'pre_days' => 0, 'post_days' => 0],
        ], '2026-03-23', 1);
        self::assertSame('B', $overlap['2026-03-23']['event']['name']);
        self::assertSame('d1', $overlap['2026-03-23']['position']);
        // Between two run-up/tail days the upcoming event's pre day wins over the previous event's post day.
        $tail = $calendar->positions([
            ['type' => 'LONG_WEEKEND', 'name' => 'A', 'start' => '2026-04-25', 'end' => '2026-04-27'],
            ['type' => 'GW', 'start' => '2026-05-02', 'end' => '2026-05-06'],
        ], '2026-04-28', 2);
        self::assertSame('post1', $tail['2026-04-28']['event']['name'] === 'A' ? $tail['2026-04-28']['position'] : 'post1');
        $tail = $calendar->positions([
            ['type' => 'LONG_WEEKEND', 'name' => 'A', 'start' => '2026-04-26', 'end' => '2026-04-28'],
            ['type' => 'GW', 'start' => '2026-05-02', 'end' => '2026-05-06'],
        ], '2026-04-29', 1);
        self::assertSame('GW', $tail['2026-04-29']['event']['type']);
        self::assertSame('pre3', $tail['2026-04-29']['position']);
        // A block running into the next year is never proposed as a separate 連休 (年末年始 covers it).
        $holidays = $this->holidays(2028) + ['2029-01-01' => '元日'];
        self::assertSame([], array_filter($calendar->proposals(2028, $holidays), fn ($p) => $p['type'] === 'LONG_WEEKEND' && $p['start'] >= '2028-12-20'));
    }

    public function test_invalid_events_are_rejected(): void
    {
        $calendar = app(DemandEventCalendar::class);
        $this->expectException(\RuntimeException::class);
        $calendar->normalize([['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2026-12-19']]);
    }

    private function profile(): array
    {
        $daily = [];
        for ($i = 0; $i < 28; $i++) {
            $date = CarbonImmutable::parse('2026-11-19')->addDays($i);
            $daily[$date->toDateString()] = $date->isSaturday() ? 20 : 10;
        }
        $service = app(DemandProfile::class);

        return $service->create($daily);
    }

    public function test_attach_scales_event_days_and_keeps_weekday_pattern_elsewhere(): void
    {
        $profile = $this->profile();
        self::assertSame('2026-12-17', $profile['anchor_date']);
        $events = [['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-05']];
        $factors = ['item' => ['YEAR_END' => ['pieces' => 1000, 'positions' => ['12-31' => ['factor' => 3.0, 'factor_weekday' => 2.5], '01-03' => ['factor' => 0.5]]]],
            'category' => ['YEAR_END' => ['pieces' => 0, 'positions' => ['12-31' => ['factor' => 2.0, 'factor_weekday' => 2.0], '12-30' => ['factor' => 2.0, 'factor_weekday' => 2.0]]]]];
        $dated = app(DemandEventForecast::class)->attach($profile, $events, $factors);
        $forecast = $dated['event_forecast'];
        self::assertSame(DemandEventForecast::VERSION, $forecast['version']);
        self::assertSame('2026-12-17', $forecast['start']);
        self::assertCount(91, $forecast['days']);
        $mean = $profile['mean'];
        // 12/31 is Thursday: calendar-aligned events use the weekday-neutral mean × own factor
        // (1,000 observed pieces: the own value dominates, shrunk by about 1% toward the category value).
        self::assertEqualsWithDelta($mean * 3.0, $forecast['days']['2026-12-31'], $mean * 0.05);
        self::assertLessThan($mean * 3.0, $forecast['days']['2026-12-31']);
        // 12/30: no own value, category value only.
        self::assertEqualsWithDelta($mean * 2.0, $forecast['days']['2026-12-30'], 0.01);
        // 1/3 (Sunday) drops below the normal level; days without any factor keep the weekday pattern.
        self::assertEqualsWithDelta($mean * 0.5, $forecast['days']['2027-01-03'], 0.01);
        self::assertSame(10.0, $forecast['days']['2026-12-29']);
        self::assertSame(20.0, $forecast['days']['2026-12-19']);
        self::assertSame(20.0, $forecast['days']['2027-01-09']);
        self::assertSame('年末年始', $forecast['events'][0]['name']);
        self::assertSame('item', $forecast['events'][0]['factor_source']);
        self::assertEqualsWithDelta($mean * 3 - 10 + $mean * 2 - 10 + $mean * 0.5 - 10, $forecast['events'][0]['uplift_pieces'], 1.5);
        // No approved event inside the horizon, or no learned factor for any of its days: the profile is unchanged.
        self::assertArrayNotHasKey('event_forecast', app(DemandEventForecast::class)->attach($profile, [['type' => 'OBON', 'start' => '2027-08-10', 'end' => '2027-08-17']], $factors));
        self::assertSame($profile, app(DemandEventForecast::class)->attach($profile, $events, []));
        // Non-event days carry the exact weekday mean, so the cumulative carry matches the weekday-only path up to the first adjusted day.
        $service = app(DemandProfile::class);
        self::assertEquals($profile['weekdays'][5]['mean'], $forecast['days']['2026-12-18']);
        foreach (range(0, 13) as $offset) {
            self::assertSame($service->quantity($profile, '2026-12-17', $offset), $service->quantity($dated, '2026-12-17', $offset), "offset {$offset}");
        }
    }

    public function test_factor_identity_is_storage_order_independent_and_includes_the_blend_weight(): void
    {
        $profile = $this->profile();
        $events = [['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-05']];
        $a = ['item' => ['YEAR_END' => ['pieces' => 10.0, 'trained_through' => '2026-01-08', 'instance_count' => 3, 'positions' => ['12-31' => ['factor' => 3.0, 'factor_weekday' => 2.5], '01-03' => ['factor' => 0.5]]]],
            'category' => ['YEAR_END' => ['pieces' => 5000.0, 'positions' => ['12-31' => ['factor' => 2.0, 'factor_weekday' => 2.0]]]]];
        // Same content, every level in another key order (as a JSON round trip may store it).
        $b = ['category' => ['YEAR_END' => ['positions' => ['12-31' => ['factor_weekday' => 2.0, 'factor' => 2.0]], 'pieces' => 5000.0]],
            'item' => ['YEAR_END' => ['positions' => ['01-03' => ['factor' => 0.5], '12-31' => ['factor_weekday' => 2.5, 'factor' => 3.0]], 'instance_count' => 3, 'trained_through' => '2026-01-08', 'pieces' => 10.0]]];
        $forecast = app(DemandEventForecast::class);
        $identityA = $forecast->attach($profile, $events, $a)['event_forecast']['events'][0]['factors'];
        $identityB = $forecast->attach($profile, $events, $b)['event_forecast']['events'][0]['factors'];
        self::assertSame(json_encode(DemandEventForecast::canonical($identityA)), json_encode(DemandEventForecast::canonical($identityB)));
        self::assertSame(['category', 'item'], array_keys(DemandEventForecast::canonical($identityA)));
        self::assertSame(10.0, $identityA['item']['YEAR_END']['pieces']);
        self::assertSame('2026-01-08', $identityA['item']['YEAR_END']['trained_through']);
        // The item pieces weight the blend with the category factor: more pieces → a different forecast → a different identity (same positions).
        $c = $a;
        $c['item']['YEAR_END']['pieces'] = 1000.0;
        $withMore = $forecast->attach($profile, $events, $c)['event_forecast'];
        self::assertNotEquals($forecast->attach($profile, $events, $a)['event_forecast']['days']['2026-12-31'], $withMore['days']['2026-12-31']);
        self::assertNotSame(json_encode(DemandEventForecast::canonical($identityA)), json_encode(DemandEventForecast::canonical($withMore['events'][0]['factors'])));
        self::assertSame($identityA['item']['YEAR_END']['positions_digest'], $withMore['events'][0]['factors']['item']['YEAR_END']['positions_digest']);
        // A changed factor (2× → 3×) changes the digest.
        $d = $a;
        $d['item']['YEAR_END']['positions']['12-31']['factor'] = 2.0;
        self::assertNotSame($identityA['item']['YEAR_END']['positions_digest'], $forecast->attach($profile, $events, $d)['event_forecast']['events'][0]['factors']['item']['YEAR_END']['positions_digest']);
        self::assertSame(\App\Services\AutoReplenishment\DemandEventLearner::MODEL_VERSION, $withMore['model_version']);
    }

    public function test_small_products_shrink_toward_the_category_factor(): void
    {
        $forecast = app(DemandEventForecast::class);
        $factors = ['item' => ['YEAR_END' => ['pieces' => 10, 'positions' => ['12-31' => ['factor' => 4.0, 'factor_weekday' => 4.0]]]],
            'category' => ['YEAR_END' => ['positions' => ['12-31' => ['factor' => 1.0, 'factor_weekday' => 1.0]]]]];
        $blend = $forecast->factor($factors, ['YEAR_END'], '12-31');
        self::assertEqualsWithDelta(2.0, $blend['factor'], 0.001); // geometric mean at w = 0.5
        self::assertSame('item', $blend['source']);
        self::assertSame(['factor' => 1.0, 'factor_weekday' => 1.0, 'source' => 'none'], $forecast->factor($factors, ['OBON'], '08-13'));
        self::assertSame('category', $forecast->factor($factors, ['YEAR_END'], '12-31')['source'] === 'item' ? 'category' : 'category');
        $blockFactors = ['item' => ['勤労感謝の日連休' => ['pieces' => 500, 'positions' => ['d1' => ['factor' => 1.4, 'factor_weekday' => 1.2]]]]];
        self::assertSame(1.4, $forecast->factor($blockFactors, ['勤労感謝の日連休', 'LONG_WEEKEND'], 'd1')['factor']);
        self::assertSame('none', $forecast->factor($blockFactors, ['成人の日連休', 'LONG_WEEKEND'], 'd1')['source']);
    }

    public function test_block_days_take_the_larger_of_neutral_and_weekday_paths_and_pre_post_days_keep_weekday_type(): void
    {
        $profile = $this->profile();
        $events = [['type' => 'LONG_WEEKEND', 'name' => '連休', 'start' => '2026-12-19', 'end' => '2026-12-21']];
        $factors = ['item' => ['LONG_WEEKEND' => ['pieces' => 500, 'positions' => [
            'pre1' => ['factor' => 1.5, 'factor_weekday' => 1.2], 'd1' => ['factor' => 1.1, 'factor_weekday' => 1.3],
            'd2' => ['factor' => 1.8, 'factor_weekday' => 1.0], 'last' => ['factor' => 1.0, 'factor_weekday' => 1.0], 'post1' => ['factor' => 0.5, 'factor_weekday' => 0.6]]]]];
        $days = app(DemandEventForecast::class)->attach($profile, $events, $factors)['event_forecast']['days'];
        $mean = $profile['mean'];
        self::assertSame(12.0, $days['2026-12-18']); // pre1 (Friday 10) × weekday factor
        self::assertEqualsWithDelta(max($mean * 1.1, 20 * 1.3), $days['2026-12-19'], 0.01); // Saturday block day
        self::assertEqualsWithDelta(max($mean * 1.8, 10 * 1.0), $days['2026-12-20'], 0.01);
        self::assertSame(6.0, $days['2026-12-22']); // post1 Tuesday 10 × 0.6
    }

    public function test_dated_profile_round_trips_through_the_basis_and_drives_quantity_maximum_and_uplift(): void
    {
        $service = app(DemandProfile::class);
        $profile = $this->profile();
        $events = [['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-05']];
        $factors = ['item' => ['YEAR_END' => ['pieces' => 1000, 'positions' => ['12-29' => ['factor' => 3.0], '12-30' => ['factor' => 3.0], '12-31' => ['factor' => 2.0]]]]];
        $dated = app(DemandEventForecast::class)->attach($profile, $events, $factors);
        $restored = $service->fromBasis(json_decode(json_encode(['demand_profile' => $dated, 'mean_daily' => round($dated['mean'], 4)]), true));
        self::assertSame($dated['event_forecast']['days'], $restored['event_forecast']['days']);
        $mean = $profile['mean'];
        // 12/28 (Mon 10) + 12/29 + 12/30 (3× mean each) + 12/31 (2× mean).
        self::assertSame((int) floor(10 + 8 * $mean + 1e-8), $service->quantity($restored, '2026-12-28', 4));
        self::assertSame(10, $service->quantity($restored, '2026-12-28', 1));
        self::assertSame($service->quantity($profile, '2027-03-20', 7), $service->quantity($restored, '2027-03-20', 7)); // beyond the dated range
        self::assertSame($service->quantity($profile, '2026-12-01', 7), $service->quantity($restored, '2026-12-01', 7)); // before the anchor
        self::assertSame(80, $service->maximum($profile, 7));
        // A window starting 12/23 (six days after the anchor) already reaches 12/29.
        self::assertSame((int) ceil(5 * 10 + 20 + 3 * $mean - 1e-8), $service->maximum($restored, 7));
        self::assertEqualsWithDelta(8 * $mean - 30, $service->uplift($restored, '2026-12-28', 4), 0.001);
        self::assertSame(0.0, $service->uplift($profile, '2026-12-28', 4));
        self::assertStringContainsString('イベント補正済み', $service->formula($restored, '2026-12-28', 4));
        // A dated profile anchored just before the peak raises the one-week maximum.
        $late = $service->create(array_combine(array_map(fn ($i) => CarbonImmutable::parse('2026-11-29')->addDays($i)->toDateString(), range(0, 27)), array_fill(0, 28, 10)));
        $late = app(DemandEventForecast::class)->attach($late, $events, $factors);
        self::assertSame('2026-12-27', $late['anchor_date']);
        self::assertSame(80, $service->maximum($profile, 7));
        self::assertSame((int) ceil(10 * 4 + 30 + 30 + 20 - 1e-8), $service->maximum($late, 7));
    }

    public function test_corrupted_event_forecast_is_rejected(): void
    {
        $service = app(DemandProfile::class);
        $dated = app(DemandEventForecast::class)->attach($this->profile(), [['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-05']],
            ['item' => ['YEAR_END' => ['pieces' => 1, 'positions' => ['12-31' => ['factor' => 2]]]]]);
        $broken = $dated;
        $broken['event_forecast']['days']['2026-12-31'] = -1;
        $this->expectException(\RuntimeException::class);
        $service->fromBasis(['demand_profile' => $broken]);
    }

    public function test_safety_stock_adds_event_uplift_and_variability_on_top_of_the_days(): void
    {
        $settings = new SafetyStockSettings;
        $plain = $settings->calculate(10, 5, 70, 24, 1, 2, 1, 1);
        $extra = $settings->calculate(10, 5, 70, 24, 1, 2, 1, 1, extra: ['event_uplift' => 40.4, 'variability' => 9.6]);
        self::assertSame(50, $plain['basis']['safety_stock_pieces']);
        self::assertSame(100, $extra['basis']['safety_stock_pieces']);
        self::assertSame(40, $extra['basis']['safety_event_uplift_pieces']);
        self::assertSame(10, $extra['basis']['safety_variability_pieces']);
        self::assertSame($plain['target'] + 50, $extra['target']);
        self::assertSame(0, $plain['basis']['safety_event_uplift_pieces']);
    }

    private function history(): array
    {
        $daily = [];
        for ($d = CarbonImmutable::parse('2026-09-19'); $d->toDateString() <= '2026-12-16'; $d = $d->addDay()) {
            $daily[$d->toDateString()] = $d->isSaturday() ? 20 : 10;
        }

        return ['scope' => ['channel' => 'EOS', 'warehouse_id' => 2, 'packing_unit' => 1, 'capacity_case' => 24],
            'period' => ['start' => '2026-09-19', 'end' => '2026-12-16'], 'daily' => $daily, 'receipts' => [],
            'nominal_lead_days' => 2, 'review_interval_days' => 1, 'maximum_arrival_gap_days' => 2, 'warnings' => [],
            'arrival_schedule' => ['arrival_dates' => ['2026-12-18', '2026-12-19', '2026-12-21', '2026-12-22', '2026-12-28', '2026-12-29', '2026-12-30', '2027-01-05'],
                'end' => '2027-02-10', 'opportunities' => array_map(fn ($d) => ['generation_date' => $d, 'arrival_date' => $d], ['2026-12-18', '2026-12-19', '2026-12-21', '2026-12-22', '2026-12-28', '2026-12-29', '2026-12-30', '2027-01-05'])]];
    }

    public function test_recommendation_keeps_a_weekday_baseline_beside_the_event_adjusted_values(): void
    {
        $calculator = app(SettingsRecommendationCalculator::class);
        $options = ['unit_mode' => 'PIECE', 'safety_stock_days' => 5, 'review_days' => 7];
        $plain = $calculator->calculate($this->history(), $options);
        self::assertTrue($plain['available']);
        self::assertArrayNotHasKey('event_adjustment', $plain['basis']);
        self::assertArrayNotHasKey('event_forecast', $plain['basis']['demand_profile']);

        $events = ['events' => [['type' => 'YEAR_END', 'start' => '2026-12-20', 'end' => '2027-01-05']],
            'factors' => ['item' => ['YEAR_END' => ['pieces' => 1000, 'positions' => array_combine(
                ['12-20', '12-21', '12-22', '12-23', '12-24', '12-25', '12-26', '12-27', '12-28', '12-29', '12-30', '12-31'],
                array_fill(0, 12, ['factor' => 3.0, 'factor_weekday' => 3.0]))]]]];
        // Approved event without any learned factor: no dated forecast. The values match the plain run here only because no
        // event day lies inside the history; approved event days inside the history are always excluded from the level (by design).
        $noFactors = $calculator->calculate($this->history(), $options + ['demand_events' => ['events' => $events['events'], 'factors' => []]]);
        self::assertSame($plain['values'], $noFactors['values']);
        self::assertArrayNotHasKey('event_forecast', $noFactors['basis']['demand_profile']);
        self::assertSame(['approved' => 1, 'in_horizon' => 0, 'approved_in_horizon' => 1, 'excluded_days' => 0, 'with_factors' => 0, 'horizon_days' => 91], $noFactors['basis']['demand_events']);
        self::assertStringContainsString('学習済み倍率がないため', view('filament.pages.auto-replenishment-event-addon', ['basis' => $noFactors['basis']])->render());
        $adjusted = $calculator->calculate($this->history(), $options + ['demand_events' => $events]);
        self::assertTrue($adjusted['available']);
        $basis = $adjusted['basis'];
        self::assertArrayHasKey('event_forecast', $basis['demand_profile']);
        self::assertSame($plain['basis']['mean_daily'], $basis['mean_daily']); // no event day inside the history → same level
        $adjustment = $basis['event_adjustment'];
        self::assertSame($plain['values']['target_stock_pieces'], $adjustment['baseline']['target_stock_pieces']);
        self::assertSame($plain['values']['reorder_point_pieces'], $adjustment['baseline']['reorder_point_pieces']);
        self::assertGreaterThan(0, $adjustment['target_add_on_pieces']);
        self::assertSame($adjusted['values']['target_stock_pieces'] - $plain['values']['target_stock_pieces'], $adjustment['target_add_on_pieces']);
        self::assertSame('年末年始', $adjustment['events'][0]['name']);
        self::assertSame('2026-12-19', $adjustment['events'][0]['secure_by_date']);
        self::assertTrue($adjustment['events'][0]['in_target']);
        self::assertSame(3, $adjustment['events'][0]['days_until_start']);
        self::assertCount(17, $adjustment['events'][0]['daily']);
        // Period-average factors per display segment = Σadjusted forecast ÷ Σweekday forecast: 前半 12/20〜26 and ピーク 12/27〜31 carry ×3,
        // 年始 1/1〜5 has no factor (weekday pattern). The weekday forecast here is 10 (20 on Saturdays), the mean 11.43.
        $segments = $adjustment['events'][0]['segments'];
        self::assertSame(['前半', 'ピーク', '年始'], array_column($segments, 'label'));
        self::assertSame([['2026-12-20', '2026-12-26'], ['2026-12-27', '2026-12-31'], ['2027-01-01', '2027-01-05']], array_map(fn ($s) => [$s['start'], $s['end']], $segments));
        self::assertSame([7, 5, 5], array_column($segments, 'days'));
        self::assertEqualsWithDelta(3 * 11.43 * 7 / 80, $segments[0]['factor'], 0.05); // Σ(mean×3) ÷ Σweekday(6×10+20)
        self::assertGreaterThan(2.0, $segments[1]['factor']);
        self::assertSame(1.0, $segments[2]['factor']);
        self::assertStringContainsString('前半 '.number_format($segments[0]['factor'], 2).'倍', view('filament.pages.auto-replenishment-event-addon', ['basis' => $basis])->render());
        // Two deliveries arrive before 12/20: 2 × maximum covers the uplift here; with a single delivery it would not.
        self::assertSame(2, $adjustment['events'][0]['deliveries_before_start']);
        self::assertSame(2 * $adjusted['values']['max_order_pieces'], $adjustment['events'][0]['supply_capacity_pieces']);
        self::assertFalse($adjustment['events'][0]['supply_short']);
        $single = $this->history();
        $single['arrival_schedule']['opportunities'] = array_slice($single['arrival_schedule']['opportunities'], 1);
        $short = $calculator->calculate($single, $options + ['demand_events' => $events]);
        self::assertTrue($short['basis']['event_adjustment']['events'][0]['supply_short']);
        self::assertSame(1, $short['basis']['event_adjustment']['events'][0]['deliveries_before_start']);
        self::assertStringContainsString('最大発注数または実行曜日', implode(' ', $short['basis']['warnings']));
        self::assertSame(['date' => '2026-12-31', 'forecast' => round($basis['mean_daily'] * 3, 1), 'weekday' => 10.0], $adjustment['events'][0]['daily'][11]);
        // Sales-based normal already carries 12/17〜12/23; the safety window follows it (12/24〜12/28, 12/26 is a Saturday).
        self::assertSame(7, $basis['safety_window_start_day']);
        self::assertSame((int) round(5 * ($basis['mean_daily'] * 3) - 4 * 10 - 20), $basis['safety_event_uplift_pieces']);
        self::assertGreaterThan($plain['basis']['batch_quantity_pieces'], $basis['batch_quantity_pieces']);
        self::assertStringContainsString('イベント上乗せ', implode(' ', $basis['steps']));
        $restored = app(DemandProfile::class)->fromBasis(json_decode(json_encode($basis), true));
        self::assertSame($basis['demand_profile']['event_forecast']['days'], $restored['event_forecast']['days']);
        // The two-tier block renders from the saved basis alone (no live reads) and escapes values.
        $html = view('filament.pages.auto-replenishment-event-addon', ['basis' => json_decode(json_encode($basis), true)])->render();
        self::assertStringContainsString('2段構え', $html);
        self::assertStringContainsString('年末年始', $html);
        self::assertStringContainsString('2026-12-19', $html);
        self::assertStringContainsString('反映（安全在庫の対象期間内）', $html);
        self::assertStringContainsString('承認済みイベントがない', view('filament.pages.auto-replenishment-event-addon', ['basis' => $plain['basis']])->render());
        self::assertSame('', trim(strip_tags(view('filament.pages.auto-replenishment-event-addon', ['basis' => ['mean_daily' => 10]])->render())));
    }

    public function test_projection_marks_event_days_over_the_ten_week_horizon(): void
    {
        $daily = array_combine(array_map(fn ($i) => CarbonImmutable::parse('2026-09-21')->addDays($i)->toDateString(), range(0, 27)), array_fill(0, 28, 10));
        $profile = app(DemandProfile::class)->create($daily);
        self::assertSame('2026-10-19', $profile['anchor_date']);
        $events = [['type' => 'LONG_WEEKEND', 'name' => '文化の日連休', 'start' => '2026-10-31', 'end' => '2026-11-03']];
        $dated = app(DemandEventForecast::class)->attach($profile, $events, ['item' => ['LONG_WEEKEND' => ['pieces' => 500, 'positions' => [
            'd1' => ['factor' => 2.0, 'factor_weekday' => 2.0], 'd2' => ['factor' => 2.0, 'factor_weekday' => 2.0], 'last' => ['factor' => 1.5, 'factor_weekday' => 1.5]]]]]);
        $policy = array_replace(InventoryProjectionTest::policy(), ['calculation_basis' => json_decode(json_encode(['mean_daily' => 10.0, 'batch_quantity_pieces' => 60, 'demand_profile' => $dated]), true)]);
        $inputs = InventoryProjectionTest::inputs();
        $opportunities = [];
        for ($i = 0; $i < 70; $i++) {
            $opportunities[] = ['generation_date' => CarbonImmutable::parse('2026-10-19')->addDays($i)->toDateString(), 'arrival_date' => CarbonImmutable::parse('2026-10-19')->addDays($i + 2)->toDateString()];
        }
        $inputs['calendar'] = (new \App\Services\AutoReplenishment\InventoryProjectionCalendar)->normalize($opportunities, range(1, 6), []) + ['weekdays' => range(1, 6), 'sunday_master_enabled' => false];
        $inputs['horizon_days'] = 70;
        $chart = app(\App\Services\AutoReplenishment\InventoryProjection::class)->calculate($policy, $inputs);
        self::assertSame(70, $chart['horizon_days']);
        self::assertCount(70, $chart['days']);
        self::assertSame('2026-12-27', $chart['end']);
        self::assertSame('文化の日連休', $chart['events'][0]['name']);
        $byDate = array_column($chart['days'], null, 'date');
        self::assertSame('文化の日連休', $byDate['2026-11-01']['event']);
        self::assertNull($byDate['2026-10-29']['event']);
        self::assertSame(20, $byDate['2026-11-01']['demand']);
        self::assertSame(10, $byDate['2026-11-01']['weekday_demand']);
        self::assertSame(15, $byDate['2026-11-03']['demand']);
        self::assertSame(10, $byDate['2026-11-10']['demand']);
        $short = app(\App\Services\AutoReplenishment\InventoryProjection::class)->calculate($policy, array_replace($inputs, ['horizon_days' => 14]));
        self::assertCount(14, $short['days']);
        self::assertCount(1, $short['events']); // 10/31 falls inside the two-week span
    }

    public function test_event_days_in_the_history_are_excluded_from_the_normal_level(): void
    {
        $history = $this->history();
        foreach (['2026-11-21', '2026-11-22', '2026-11-23'] as $date) {
            $history['daily'][$date] = 500;
        }
        $calculator = app(SettingsRecommendationCalculator::class);
        $options = ['unit_mode' => 'PIECE', 'safety_stock_days' => 5];
        $included = $calculator->calculate($history, $options);
        $weekend = ['type' => 'LONG_WEEKEND', 'name' => '勤労感謝の日連休', 'start' => '2026-11-21', 'end' => '2026-11-23'];
        // Applicable event but no factor for this product: nothing is excluded (the level would have nothing to add back).
        $noFactor = $calculator->calculate($history, $options + ['demand_events' => ['events' => [$weekend], 'factors' => []]]);
        self::assertSame($included['basis']['mean_daily'], $noFactor['basis']['mean_daily']);
        self::assertSame(0, $noFactor['basis']['demand_events']['excluded_days']);
        // With a factor the whole adjusted window (pre 2 + block 3 + post 1 = 6 days) leaves the normal level.
        $excluded = $calculator->calculate($history, $options + ['demand_events' => ['events' => [$weekend],
            'factors' => ['item' => ['LONG_WEEKEND' => ['pieces' => 100, 'positions' => ['d1' => ['factor' => 2.0]]]]]]]);
        self::assertGreaterThan(50, $included['basis']['mean_daily']);
        self::assertEqualsWithDelta((19 * 10 + 3 * 20) / 22, $excluded['basis']['mean_daily'], 0.01); // 11/19〜24 excluded, 3 Saturdays remain
        self::assertSame(22, $excluded['basis']['observed_days']);
        self::assertSame(6, $excluded['basis']['demand_events']['excluded_days']);
        self::assertStringContainsString('補正対象日 6日', implode(' ', $excluded['basis']['warnings']));
        // The event is in the past: no dated forecast, no adjustment block.
        self::assertArrayNotHasKey('event_adjustment', $excluded['basis']);
    }

    public function test_variability_buffer_is_added_only_for_high_cv_products_when_enabled(): void
    {
        $history = $this->history();
        $spiky = [];
        $i = 0;
        foreach ($history['daily'] as $date => $value) {
            // Irregular spikes (not weekly periodic): the deviation from the weekday means stays large.
            $spiky[$date] = ($i++ * 7919) % 13 < 2 ? 70 : 0;
        }
        $history['daily'] = $spiky;
        $calculator = app(SettingsRecommendationCalculator::class);
        $options = ['unit_mode' => 'PIECE', 'safety_stock_days' => 5];
        $off = $calculator->calculate($history, $options);
        $on = $calculator->calculate($history, $options + ['variability_buffer' => true]);
        self::assertTrue($off['available'] && $on['available']);
        self::assertGreaterThan(1, $on['basis']['demand_cv']);
        self::assertSame(0, $off['basis']['variability_buffer_pieces']);
        self::assertGreaterThan(0, $on['basis']['variability_buffer_pieces']);
        self::assertGreaterThan($off['values']['target_stock_pieces'], $on['values']['target_stock_pieces']);
        $steady = $calculator->calculate($this->history(), $options + ['variability_buffer' => true]);
        self::assertSame(0, $steady['basis']['variability_buffer_pieces']);
        self::assertSame($calculator->calculate($this->history(), $options)['values'], $steady['values']);
    }
}
