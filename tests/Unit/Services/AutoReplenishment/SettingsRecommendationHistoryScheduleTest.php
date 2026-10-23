<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use App\Services\AutoReplenishment\RecommendationArrivalSchedule;
use App\Services\AutoReplenishment\SettingsRecommendationHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SettingsRecommendationHistoryScheduleTest extends TestCase
{
    private function calculate(string $channel, array $config, callable $arrival, string $date = '2026-10-18'): array
    {
        $resolver = Mockery::mock(JxOrderArrivalDateAdjustmentService::class);
        $resolver->shouldReceive('earliestArrivalDates')->once()->with(1021, 1, Mockery::type('array'))
            ->andReturnUsing(fn (int $contractor, int $warehouse, array $dates): array => array_map($arrival, $dates));
        $this->app->instance(JxOrderArrivalDateAdjustmentService::class, $resolver);

        return (new RecommendationArrivalSchedule)->calculate(1, 1021, $channel, $date, $config);
    }

    public function test_only_enabled_generation_dates_are_used_and_weekday_cutoff_counts_from_generation_day(): void
    {
        $result = $this->calculate('EOS', ['weekdays' => [1, 4], 'execution_time' => '14:00'], function (Carbon $date) {
            self::assertContains($date->dayOfWeek, [2, 5]);

            return $date->copy()->addDays($date->isFriday() ? 3 : 1);
        });
        self::assertSame(4, $result['nominal_lead_days']);
        self::assertSame(5, $result['maximum_gap_days']);
        self::assertSame(16, $result['generation_count']);
        self::assertSame('2026-10-20', $result['opportunities'][0]['order_base_date']);
    }

    public function test_sunday_evening_keeps_existing_sunday_order_base_and_weekly_delivery_gap(): void
    {
        $result = $this->calculate('EOS', ['weekdays' => [0], 'sunday_execution_time' => '22:00'], function (Carbon $date) {
            self::assertTrue($date->isSunday());

            return $date->copy()->addDay();
        });
        self::assertSame(1, $result['nominal_lead_days']);
        self::assertSame(7, $result['maximum_gap_days']);
        self::assertSame(8, $result['distinct_arrival_count']);
    }

    public function test_logistics_does_not_apply_eos_cutoff(): void
    {
        $result = $this->calculate('LOGISTICS', ['weekdays' => [1], 'execution_time' => '14:00'], function (Carbon $date) {
            self::assertTrue($date->isMonday());

            return $date->copy()->addDays(2);
        });
        self::assertSame(2, $result['nominal_lead_days']);
        self::assertSame(7, $result['maximum_gap_days']);
    }

    public function test_missing_schedule_uses_56_days_but_nominal_lead_keeps_first_seven_days(): void
    {
        $result = $this->calculate('EOS', [], fn (Carbon $date): Carbon => $date->copy()->addDays($date->lt(Carbon::parse('2026-10-25')) ? 1 : 4));
        self::assertSame(1, $result['nominal_lead_days']);
        self::assertSame(4, $result['maximum_gap_days']);
        self::assertSame(56, $result['generation_count']);
        self::assertSame('2026-12-12', $result['end']);
    }

    public function test_same_arrival_from_multiple_generation_days_is_counted_once(): void
    {
        $result = $this->calculate('LOGISTICS', [], fn (Carbon $date): Carbon => $date->copy()->next(Carbon::MONDAY));
        self::assertSame(56, $result['generation_count']);
        self::assertSame(9, $result['distinct_arrival_count']);
        self::assertSame(7, $result['maximum_gap_days']);
    }

    public function test_missing_arrival_on_enabled_day_is_reported_in_japanese(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('既存の配送設定から入荷可能日を算定できません。');
        $this->calculate('EOS', ['weekdays' => [1]], fn () => null);
    }

    public function test_single_distinct_arrival_is_not_interpreted_as_zero_gap(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('今後56日の設定から入荷間隔を算定できません。');
        $this->calculate('EOS', [], fn () => Carbon::parse('2026-12-20'));
    }

    public function test_extreme_arrival_gap_is_not_used_for_recommendations(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('入荷間隔が56日を超えるため');
        $this->calculate('EOS', [], fn (Carbon $date) => $date->isSameDay('2026-10-18')
            ? Carbon::parse('2026-10-19') : Carbon::parse('2027-03-01'));
    }

    public function test_history_shares_schedule_by_route_and_invalidates_for_schedule_date_and_route_changes(): void
    {
        $service = Mockery::mock(RecommendationArrivalSchedule::class);
        $service->shouldReceive('calculate')->times(5)->andReturn(['nominal_lead_days' => 2, 'maximum_gap_days' => 3]);
        $this->app->instance(RecommendationArrivalSchedule::class, $service);
        $reader = new SettingsRecommendationHistory;
        $method = new \ReflectionMethod(SettingsRecommendationHistory::class, 'arrivalSchedule');
        $route = ['item_id' => 1, 'channel' => 'LOGISTICS', 'contractor_id' => 1021, 'source_warehouse_id' => 91, 'delivery_course_id' => 5];
        $read = fn ($r, $asOf = '2026-10-18', $config = []) => $method->invoke($reader, 6, 1, $r, $asOf, $config);
        $read($route);
        $read(array_replace($route, ['item_id' => 2])); // same route, another item: no extra read
        $read(array_replace($route, ['delivery_course_id' => 6]));
        $read(array_replace($route, ['source_warehouse_id' => 92]));
        $read($route, '2026-10-19');
        $read($route, '2026-10-18', ['weekdays' => [1]]);
    }

    public function test_schedule_lookup_is_cached_once_per_warehouse_including_missing_settings(): void
    {
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sakemaru');
        try {
            $db = DB::connection('sakemaru');
            $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER, replenishment_config TEXT)');
            $db->table('wms_warehouse_auto_order_settings')->insert(['warehouse_id' => 1, 'replenishment_config' => json_encode(['weekdays' => [1, 4]])]);
            $db->enableQueryLog();
            $reader = new SettingsRecommendationHistory;
            $method = new \ReflectionMethod(SettingsRecommendationHistory::class, 'warehouseScheduleConfig');
            self::assertSame(['weekdays' => [1, 4]], $method->invoke($reader, 1));
            self::assertSame(['weekdays' => [1, 4]], $method->invoke($reader, 1));
            self::assertSame([], $method->invoke($reader, 2));
            self::assertSame([], $method->invoke($reader, 2));
            self::assertCount(2, $db->getQueryLog());
            $db->table('wms_warehouse_auto_order_settings')->insert(['warehouse_id' => 3, 'replenishment_config' => 'broken']);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('倉庫の自動発注設定を読み込めません。');
            $method->invoke($reader, 3);
        } finally {
            DB::disconnect('sakemaru');
        }
    }
}
