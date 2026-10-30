<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\DemandProfile;
use App\Services\AutoReplenishment\InventoryProjection;
use App\Services\AutoReplenishment\InventoryProjectionCalendar;
use App\Services\AutoReplenishment\InventoryProjectionInputs;
use App\Services\AutoReplenishment\PolicySimulationService;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use App\Services\AutoReplenishment\SnapshotReader;
use App\Services\AutoReplenishment\StrategyCalculator;
use App\Services\AutoReplenishment\WeekdayDemand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DemandProfileTest extends TestCase
{
    public function test_legacy_receipt_period_is_not_presented_as_a_known_demand_period(): void
    {
        $basis = ['mean_daily' => 10, 'period' => ['start' => '2026-08-01', 'end' => '2026-10-23']];
        $profile = app(DemandProfile::class)->fromBasis($basis);
        self::assertTrue($profile['legacy']);
        self::assertNull($profile['period']);
        self::assertSame('2026-10-24', $profile['anchor_date']);
        $profile = app(DemandProfile::class)->fromBasis($basis + ['demand_start' => '2026-09-26']);
        self::assertSame(['start' => '2026-09-26', 'end' => '2026-10-23'], $profile['period']);
    }

    public function test_recommendation_persists_the_selected_period_total_and_weekdays_as_one_basis(): void
    {
        $history = ['scope' => ['channel' => 'EOS', 'warehouse_id' => 2, 'packing_unit' => 1, 'capacity_case' => 24],
            'period' => ['start' => '2026-08-01', 'end' => '2026-10-23'], 'daily' => $this->daily(),
            'nominal_lead_days' => 1, 'review_interval_days' => 1, 'maximum_arrival_gap_days' => 1, 'warnings' => ['テスト用の連携状況'],
            'receipts' => array_map(fn ($i) => ['warehouse_id' => 2, 'pieces' => 50, 'lead_days' => 1,
                'date' => CarbonImmutable::parse('2026-08-01')->addDays($i)->toDateString()], range(0, 11))];
        $result = app(SettingsRecommendationCalculator::class)->calculate($history, ['unit_mode' => 'PIECE', 'safety_stock_days' => 5]);
        self::assertTrue($result['available']);
        $basis = $result['basis'];
        self::assertSame(['start' => '2026-09-26', 'end' => '2026-10-23'], $basis['demand_period']);
        self::assertSame(294, $basis['demand_total_pieces']);
        self::assertSame(56294, $basis['history_demand_total_pieces']);
        self::assertSame(294, $basis['demand_profile']['total_pieces']);
        self::assertSame(10.5, $basis['mean_daily']);
        self::assertContains('テスト用の連携状況', $basis['demand_profile']['warnings']);
        self::assertCount(7, $basis['demand_profile']['weekdays']);
        self::assertArrayNotHasKey('daily', $basis['demand_profile']);
        self::assertLessThan(4096, strlen(json_encode($basis['demand_profile'])));
        $profile = app(DemandProfile::class)->fromBasis(json_decode(json_encode($basis), true));
        self::assertSame(294, app(DemandProfile::class)->quantity($profile, '2026-10-24', 28));
    }

    private function daily(): array
    {
        $daily = [];
        for ($i = 0; $i < 84; $i++) {
            $date = CarbonImmutable::parse('2026-08-01')->addDays($i);
            $daily[$date->toDateString()] = $i < 56 ? 1000 : $date->dayOfWeek * 3 + intdiv($i - 56, 7);
        }

        return $daily;
    }

    public function test_selection_uses_recent_28_days_and_shared_completed_history_boundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24', 'Asia/Tokyo'));
        try {
            $service = new DemandProfile;
            self::assertSame(['start' => '2026-08-01', 'end' => '2026-10-23'], $service->historyPeriod('2026-11-01'));
            self::assertSame('2026-10-13', $service->historyPeriod('2026-10-14')['end']);
            $selection = $service->select($this->daily(), $service->historyPeriod('2026-10-24'));
            self::assertFalse($selection['fallback']);
            self::assertCount(28, $selection['daily']);
            $profile = $service->create($selection['daily']);
            self::assertSame(['start' => '2026-09-26', 'end' => '2026-10-23'], $profile['period']);
            self::assertSame(28, $profile['observed_days']);
            self::assertEqualsWithDelta(array_sum($selection['daily']) / 28, $profile['mean'], 1e-9);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_sparse_recent_data_expands_once_and_missing_days_are_excluded_not_zeroed(): void
    {
        $daily = $this->daily();
        foreach ($daily as $date => &$quantity) {
            if ($date >= '2026-09-26') {
                $quantity = $date === '2026-10-23' ? 0 : null;
            }
        }
        unset($quantity);
        $service = new DemandProfile;
        $selection = $service->select($daily, ['start' => '2026-08-01', 'end' => '2026-10-23']);
        self::assertTrue($selection['fallback']);
        $profile = $service->create($selection['daily'], $selection['fallback']);
        self::assertSame('2026-08-01', $profile['period']['start']);
        self::assertSame(57, $profile['observed_days']);
        self::assertSame(27, $profile['missing_days']);
        self::assertEqualsWithDelta(56000 / 57, $profile['mean'], 1e-9);
        self::assertSame('history_fallback', $profile['selection']);
    }

    public function test_fractional_consumption_is_additive_on_every_weekday_and_does_not_inflate_slow_items(): void
    {
        $service = new DemandProfile;
        foreach ([$service->create(array_slice($this->daily(), -28, null, true)), $service->fromBasis(['mean_daily' => 0.1])] as $profile) {
            foreach (range(-7, 14) as $offset) {
                $start = CarbonImmutable::parse('2026-10-24')->addDays($offset);
                foreach ([0, 1, 2, 7, 14, 21, 28] as $days) {
                    $sum = 0;
                    for ($i = 0; $i < $days; $i++) {
                        $sum += $service->quantity($profile, $start->addDays($i)->toDateString(), 1);
                    }
                    self::assertSame($sum, $service->quantity($profile, $start->toDateString(), $days));
                    self::assertLessThanOrEqual($service->maximum($profile, $days), $sum);
                }
            }
        }
        $slow = $service->fromBasis(['mean_daily' => 0.1, 'period' => ['start' => '2026-09-26', 'end' => '2026-10-23']]);
        self::assertSame(1, $service->quantity($slow, '2026-10-24', 14));
    }

    public function test_slow_movers_use_84_days_instead_of_28(): void
    {
        $service = new DemandProfile;
        $period = ['start' => '2026-08-01', 'end' => '2026-10-29'];
        $daily = [];
        for ($i = 0, $d = CarbonImmutable::parse('2026-08-01'); $d->toDateString() <= '2026-10-29'; $d = $d->addDay(), $i++) {
            $daily[$d->toDateString()] = $i % 2; // 0 and 1 alternating: 0.5 pieces a day
        }
        $selection = $service->select($daily, $period);
        self::assertFalse($selection['fallback']);
        self::assertTrue($selection['slow']);
        self::assertCount(84, $selection['daily']);
        self::assertSame('2026-08-07', array_key_first($selection['daily']));
        $profile = $service->create($selection['daily'], $selection['fallback'], $selection['slow']);
        self::assertSame('slow_84_days', $profile['selection']);
        self::assertSame(84, $profile['observed_days']);
        self::assertStringContainsString('84日', $profile['warnings'][0]);
        // One piece a day or more keeps the 28-day window.
        $busy = $service->select(array_map(fn ($v) => $v + 1, $daily), $period);
        self::assertFalse($busy['slow']);
        self::assertCount(28, $busy['daily']);
    }

    public function test_corrupt_profiles_do_not_silently_use_another_demand_window(): void
    {
        $service = new DemandProfile;
        $profile = $service->create(array_slice($this->daily(), -28, null, true));
        foreach ([['version' => 'unknown'], ['weekdays' => []], ['anchor_date' => '2026-02-30'],
            ['period' => ['start' => '2026-10-24', 'end' => '2026-10-23']]] as $changes) {
            try {
                $service->fromBasis(['mean_daily' => 10, 'demand_profile' => array_replace($profile, $changes)]);
                self::fail('Invalid evidence must require recalculation.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('再計算', $e->getMessage());
            }
        }
        $this->expectException(\RuntimeException::class);
        $service->quantity($profile, '2026-10-24', 57);
    }

    public function test_saved_json_drives_graph_and_test_without_new_history_reads_or_changed_forecast(): void
    {
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $service = new DemandProfile;
        $profile = $service->create(array_slice($this->daily(), -28, null, true));
        $basis = json_decode(json_encode(['mean_daily' => round($profile['mean'], 4), 'batch_quantity_pieces' => 60, 'demand_profile' => $profile]), true);
        $policy = array_replace(InventoryProjectionTest::policy(), ['calculation_basis' => $basis]);
        $input = InventoryProjectionTest::inputs();
        $snapshot = $input['snapshot'] + ['warehouse_config' => [], 'route_snapshot' => ['effective_picking_lead_days' => 1]];
        $snapshot['arrival_date'] = $input['calendar']['arrivals'][$snapshot['business_date']];
        $snapshot['next_arrival_date'] = $input['calendar']['arrivals']['2026-10-20'];
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('readForSimulation')->andReturn($snapshot);
        $this->app->instance(SnapshotReader::class, $reader);
        $calendar = Mockery::mock(InventoryProjectionCalendar::class);
        $calendar->shouldReceive('read')->once()->andReturn($input['calendar']);
        $this->app->instance(InventoryProjectionCalendar::class, $calendar);
        $history = Mockery::mock(WeekdayDemand::class);
        $history->shouldNotReceive('read');
        $this->app->instance(WeekdayDemand::class, $history);
        DB::connection('sakemaru')->enableQueryLog();
        $sharedInput = app(InventoryProjectionInputs::class)->read(6, 1, $policy + ['item_id' => 123, 'contractor_id' => 10], $basis);
        self::assertSame($profile, $sharedInput['demand']);
        // Deliberately stale/mismatched chart input cannot replace the saved evidence.
        $sharedInput['demand']['weekdays'] = array_fill(0, 7, ['mean' => 1000]);
        $graph = app(InventoryProjection::class)->calculate($policy, $sharedInput);
        self::assertSame($profile, $graph['demand']);
        foreach ($graph['days'] as $day) {
            self::assertSame($service->quantity($profile, $day['date'], 1), $day['demand']);
        }
        self::assertSame(InventoryProjection::horizonDays(), $graph['horizon_days']);
        self::assertCount($graph['horizon_days'], $graph['days']);
        self::assertSame($service->quantity($profile, $graph['start'], 14), array_sum(array_column(array_slice($graph['days'], 0, 14), 'demand')));
        $preview = app(PolicySimulationService::class)->simulate(new WmsItemReorderPolicy($policy));
        $batch = app(StrategyCalculator::class)->calculate($policy, $snapshot);
        self::assertSame($graph['days'][0]['phase'], $preview['phase']);
        self::assertSame($batch['phase'], $preview['phase']);
        self::assertSame($graph['days'][0]['ordered'], $preview['quantity_pieces']);
        self::assertSame($profile, $preview['demand_profile']);
        self::assertSame([], DB::connection('sakemaru')->getQueryLog());
        DB::disconnect('sakemaru');
    }
}
