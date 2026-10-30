<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsNationalHoliday;
use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\DemandEventHistoryReader;
use App\Services\AutoReplenishment\DemandEventLearner;
use App\Services\AutoReplenishment\DemandEventLearningService;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class DemandEventLearnerTest extends TestCase
{
    /** Synthetic store-total series: 10/day (Saturday 20), ×3 on 12/29〜31, ×0.5 on 1/2〜1/3, 連休 d1 ×1.5. */
    private function synthetic(string $start, string $end, float $scale = 1.0, bool $withEvents = true): array
    {
        $series = [];
        for ($d = CarbonImmutable::parse($start); $d->toDateString() <= $end; $d = $d->addDay()) {
            $value = $d->isSaturday() ? 20 : 10;
            if ($withEvents) {
                $md = $d->format('m-d');
                if (in_array($md, ['12-29', '12-30', '12-31'], true)) {
                    $value *= 3;
                } elseif (in_array($md, ['01-02', '01-03'], true)) {
                    $value *= 0.5;
                }
            }
            $series[$d->toDateString()] = (int) round($value * $scale);
        }

        return $series;
    }

    private function holidays(int $year): array
    {
        return array_column(WmsNationalHoliday::generateJapaneseHolidays($year), 'holiday_name', 'holiday_date');
    }

    public function test_instance_ratios_compare_event_days_with_the_pre_event_baseline(): void
    {
        $calendar = app(DemandEventCalendar::class);
        $learner = app(DemandEventLearner::class);
        $events = $calendar->proposals(2025, $this->holidays(2025));
        $yearEnd = array_values(array_filter($events, fn ($e) => $e['type'] === 'YEAR_END'))[0];
        $series = $this->synthetic('2025-09-01', '2026-01-31');
        $result = $learner->instanceRatios($series, $yearEnd, $events);
        self::assertNotNull($result);
        // 11/15〜12/12 minus the adjusted days of 勤労感謝の日連休 (pre 11/20〜21, block 11/22〜24, post 11/25): 22 observed days, 3 Saturdays.
        self::assertSame(22, $result['pre_days']);
        self::assertEqualsWithDelta(250 / 22, $result['pre_mean'], 0.01);
        self::assertEqualsWithDelta(30 / $result['pre_mean'], $result['positions']['12-30']['ratio'], 0.001); // Tuesday 10 × 3
        self::assertEqualsWithDelta(3.0, $result['positions']['12-30']['ratio_weekday'], 0.001);
        self::assertEqualsWithDelta(0.5, $result['positions']['01-03']['ratio_weekday'], 0.001);
        self::assertEqualsWithDelta(1.0, $result['positions']['12-25']['ratio_weekday'], 0.001);
        self::assertArrayNotHasKey('12-19', $result['positions']);
        self::assertGreaterThan(0, $result['pieces']);
        // Unobserved days are skipped, never read as zero; too few baseline days → no ratios.
        $gaps = $series;
        $gaps['2025-12-31'] = null;
        self::assertArrayNotHasKey('12-31', $learner->instanceRatios($gaps, $yearEnd, $events)['positions']);
        $sparse = array_map(fn () => null, $series);
        self::assertNull($learner->instanceRatios($sparse, $yearEnd, $events));
    }

    public function test_baseline_window_extends_backwards_past_excluded_event_days(): void
    {
        $calendar = app(DemandEventCalendar::class);
        $learner = app(DemandEventLearner::class);
        $events = $calendar->proposals(2026, $this->holidays(2026));
        $seijin = array_values(array_filter($events, fn ($e) => $e['name'] === '成人の日連休'))[0];
        self::assertSame('2026-01-10', $seijin['start']);
        $previous = $calendar->proposals(2025, $this->holidays(2025));
        $series = $this->synthetic('2025-10-01', '2026-01-31', withEvents: false);
        $layout = $learner->layout($seijin, array_merge($previous, $events));
        // 12/20〜1/5 lie inside the 5〜1 week window and are excluded, so the window grows to keep 14 observed days.
        self::assertArrayNotHasKey('2025-12-31', $layout['pre']);
        $result = $learner->instanceRatios($series, $seijin, array_merge($previous, $events), $layout);
        self::assertNotNull($result);
        self::assertGreaterThanOrEqual(14, $result['pre_days']);
        self::assertEqualsWithDelta(1.0, $result['positions']['d1']['ratio_weekday'], 0.05);
    }

    public function test_aggregate_takes_the_median_across_years_and_category_profile_across_products(): void
    {
        $learner = app(DemandEventLearner::class);
        $instances = [
            ['positions' => ['12-31' => ['ratio' => 2.0, 'ratio_weekday' => 2.2, 'actual' => 20]], 'pre_mean' => 10, 'pre_days' => 28, 'pieces' => 20],
            ['positions' => ['12-31' => ['ratio' => 3.0, 'ratio_weekday' => 2.8, 'actual' => 30], '01-01' => ['ratio' => 0.5, 'ratio_weekday' => 0.5, 'actual' => 5]], 'pre_mean' => 10, 'pre_days' => 28, 'pieces' => 35],
            null,
            ['positions' => ['12-31' => ['ratio' => 40.0, 'ratio_weekday' => 40.0, 'actual' => 400]], 'pre_mean' => 10, 'pre_days' => 28, 'pieces' => 400],
        ];
        $profile = $learner->aggregate($instances);
        self::assertSame(3, $profile['instance_count']);
        self::assertSame(3.0, $profile['positions']['12-31']['factor']);
        self::assertSame(2.8, $profile['positions']['12-31']['factor_weekday']);
        self::assertSame(3, $profile['positions']['12-31']['years']);
        self::assertSame(0.5, $profile['positions']['01-01']['factor']);
        self::assertSame(455.0, $profile['pieces']);
        self::assertNull($learner->aggregate([null]));
        $category = $learner->categoryProfile([$profile, ['positions' => ['12-31' => ['factor' => 1.0, 'factor_weekday' => 1.0, 'years' => 2]], 'pieces' => 5, 'instance_count' => 2],
            ['positions' => ['12-31' => ['factor' => 1.5, 'factor_weekday' => 1.4, 'years' => 3]], 'pieces' => 50, 'instance_count' => 3]]);
        self::assertSame(2, $category['item_count']); // the 5-piece product is too small to vote
        self::assertSame(2.25, $category['positions']['12-31']['factor']);
        self::assertSame(['LONG_WEEKEND'], $learner->profileKeys(['type' => 'LONG_WEEKEND', 'name' => '海の日連休'], ['海の日連休' => 2]));
        self::assertSame(['海の日連休', 'LONG_WEEKEND'], $learner->profileKeys(['type' => 'LONG_WEEKEND', 'name' => '海の日連休'], ['海の日連休' => 3]));
        self::assertSame(['YEAR_END'], $learner->profileKeys(['type' => 'YEAR_END', 'name' => '年末年始'], []));
    }

    public function test_learning_service_recovers_the_year_end_factors_from_three_years_of_store_totals(): void
    {
        $reader = Mockery::mock(DemandEventHistoryReader::class);
        $reader->shouldReceive('holidays')->andReturnUsing(fn (int $year) => $this->holidays($year));
        $reader->shouldReceive('itemCategories')->andReturn([1 => 3142, 2 => 3142, 3 => 3122]);
        $reader->shouldReceive('productTotals')->andReturnUsing(function (int $client, string $start, string $end) {
            $item1 = $this->synthetic($start, $end);                       // follows the pattern
            $item2 = $this->synthetic($start, $end, 0.5);                  // same shape, half the volume
            $item3 = $this->synthetic($start, $end, 1.0, withEvents: false); // flat product
            $items = [1 => $item1, 2 => $item2, 3 => $item3];
            $observed = array_fill_keys(array_keys($item1), true);
            foreach ($items as $id => $series) {
                $items[$id] = array_filter($series, fn ($v) => $v > 0);
            }

            return ['items' => $items, 'observed' => $observed];
        });
        $service = new DemandEventLearningService($reader, app(DemandEventLearner::class), app(DemandEventCalendar::class));
        $instances = $service->trainingInstances('2026-10-29', 3);
        self::assertSame(['2023-12-20', '2024-12-20', '2025-12-20'], array_column(array_filter($instances, fn ($i) => $i['type'] === 'YEAR_END'), 'start'));
        self::assertSame(['2024-08-03', '2025-08-03', '2026-08-03'], array_column(array_filter($instances, fn ($i) => $i['type'] === 'OBON'), 'start'));
        self::assertCount(3, array_filter($instances, fn ($i) => $i['type'] === 'GW'));
        $learned = $service->learn(6, '2026-10-29', 3, [1, 3]);
        self::assertSame(3, $learned['items'][1]['YEAR_END']['instance_count']);
        self::assertEqualsWithDelta(3.0, $learned['items'][1]['YEAR_END']['positions']['12-30']['factor_weekday'], 0.01);
        // Neutral factor: 30 pieces (weekday × 3) over a baseline of about 11.2〜11.4 pieces/day.
        self::assertEqualsWithDelta(30 / 11.3, $learned['items'][1]['YEAR_END']['positions']['12-30']['factor'], 0.1);
        self::assertEqualsWithDelta(0.5, $learned['items'][1]['YEAR_END']['positions']['01-02']['factor_weekday'], 0.01);
        self::assertEqualsWithDelta(1.0, $learned['items'][3]['YEAR_END']['positions']['12-30']['factor_weekday'], 0.01);
        self::assertArrayNotHasKey(2, $learned['items']); // not requested as an ITEM profile
        self::assertEqualsWithDelta(3.0, $learned['categories'][3142]['YEAR_END']['positions']['12-30']['factor_weekday'], 0.01);
        self::assertSame(2, $learned['categories'][3142]['YEAR_END']['item_count']);
        self::assertEqualsWithDelta(1.0, $learned['categories'][3122]['YEAR_END']['positions']['12-30']['factor_weekday'], 0.01);
        self::assertArrayNotHasKey('LONG_WEEKEND', $learned['items'][1]); // only the three standard events are learned
        self::assertEqualsCanonicalizing(['GW', 'OBON', 'YEAR_END'], array_keys($learned['items'][1]));
        self::assertGreaterThan(0, $learned['stats']['ranges_read']);
    }

    public function test_training_never_reads_or_uses_days_after_the_learning_end_date(): void
    {
        $reader = Mockery::mock(DemandEventHistoryReader::class);
        $reader->shouldReceive('holidays')->andReturnUsing(fn (int $year) => $this->holidays($year));
        $reader->shouldReceive('itemCategories')->andReturn([1 => 3142]);
        $through = '2025-08-17'; // the last day of お盆 2025 (8/3〜17): complete only if its last day is not after $through
        $reader->shouldReceive('productTotals')->andReturnUsing(function (int $client, string $start, string $end) use ($through) {
            self::assertLessThanOrEqual($through, $end, 'reads must stop at the learning end date');
            $series = $this->synthetic($start, $end);

            return ['items' => [1 => $series], 'observed' => array_fill_keys(array_keys($series), true)];
        });
        $service = new DemandEventLearningService($reader, app(DemandEventLearner::class), app(DemandEventCalendar::class));
        $instances = $service->trainingInstances($through, 3);
        self::assertContains('2025-08-03', array_column($instances, 'start'));     // お盆 2025 ends on $through: complete
        self::assertNotContains('2025-12-20', array_column($instances, 'start'));  // 年末年始 2025 lies after $through
        self::assertNotContains('2025-05-03', array_column($service->trainingInstances('2025-05-07', 3), 'start')); // GW 2025 (5/3〜6 + post 5/8) is incomplete on 5/7
        $learned = $service->learn(6, $through, 3, [1]);
        self::assertArrayHasKey('YEAR_END', $learned['items'][1]);
        self::assertArrayHasKey('OBON', $learned['items'][1]);
    }
}
