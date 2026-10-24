<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use App\Services\AutoReplenishment\ArrivalCalendar;
use App\Services\AutoReplenishment\InventoryProjectionCalendar;
use App\Services\AutoReplenishment\RecommendationArrivalSchedule;
use App\Services\AutoReplenishment\LogisticsArrivalDateService;
use App\Models\WmsItemReorderPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArrivalCalendarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE contractors (id INTEGER PRIMARY KEY, is_active INTEGER, lead_time_id INTEGER)');
        $db->statement('CREATE TABLE lead_times (id INTEGER PRIMARY KEY, is_active INTEGER, lead_time_sun INTEGER, lead_time_mon INTEGER, lead_time_tue INTEGER, lead_time_wed INTEGER, lead_time_thu INTEGER, lead_time_fri INTEGER, lead_time_sat INTEGER)');
        $db->statement('CREATE TABLE wms_contractor_holidays (id INTEGER PRIMARY KEY, contractor_id INTEGER, holiday_date TEXT)');
        $db->statement('CREATE TABLE wms_contractor_warehouse_delivery_days (contractor_id INTEGER, warehouse_id INTEGER, delivery_sun INTEGER, delivery_mon INTEGER, delivery_tue INTEGER, delivery_wed INTEGER, delivery_thu INTEGER, delivery_fri INTEGER, delivery_sat INTEGER)');
        $db->statement('CREATE TABLE wms_warehouse_calendars (warehouse_id INTEGER, is_holiday INTEGER, target_date TEXT)');
        $db->table('contractors')->insert(['id' => 1021, 'is_active' => 1, 'lead_time_id' => 1]);
        $db->table('lead_times')->insert(['id' => 1, 'is_active' => 1, 'lead_time_sun' => 2, 'lead_time_mon' => 1, 'lead_time_tue' => 0,
            'lead_time_wed' => -1, 'lead_time_thu' => 1, 'lead_time_fri' => 3, 'lead_time_sat' => null]);
        $db->table('wms_contractor_warehouse_delivery_days')->insert(['contractor_id' => 1021, 'warehouse_id' => 1,
            'delivery_sun' => 0, 'delivery_mon' => 1, 'delivery_tue' => 1, 'delivery_wed' => 1, 'delivery_thu' => 1, 'delivery_fri' => 1, 'delivery_sat' => 0]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function sundayRoute(): void
    {
        DB::connection('sakemaru')->table('lead_times')->update(array_fill_keys([
            'lead_time_sun', 'lead_time_mon', 'lead_time_tue', 'lead_time_wed', 'lead_time_thu', 'lead_time_fri', 'lead_time_sat'], 1));
        DB::connection('sakemaru')->table('wms_contractor_warehouse_delivery_days')->update([
            'delivery_sun' => 1, 'delivery_mon' => 0, 'delivery_tue' => 1, 'delivery_wed' => 0,
            'delivery_thu' => 1, 'delivery_fri' => 0, 'delivery_sat' => 0]);
    }

    public function test_sunday_master_is_skipped_consistently_without_changing_manual_jx(): void
    {
        $this->sundayRoute();
        $date = Carbon::parse('2026-10-24');
        self::assertSame('2026-10-25', app(JxOrderArrivalDateAdjustmentService::class)->earliestArrivalDate(1021, 1, $date)->toDateString());
        $calendar = app(ArrivalCalendar::class);
        self::assertSame('2026-10-27', $calendar->earliestArrivalDate(1021, 1, $date)->toDateString());
        foreach (['EOS', 'LOGISTICS', 'FAX'] as $channel) {
            $recommendation = app(RecommendationArrivalSchedule::class)->calculate(1, 1021, $channel, '2026-10-24', []);
            $graph = app(InventoryProjectionCalendar::class)->read(1, 1021, $channel, '2026-10-24', []);
            self::assertSame('2026-10-27', $graph['arrivals']['2026-10-24']);
            foreach ($recommendation['opportunities'] as $opportunity) {
                $single = $calendar->earliestArrivalDate(1021, 1, Carbon::parse($opportunity['order_base_date']));
                self::assertSame($opportunity['arrival_date'], $single->toDateString());
                self::assertSame($single->toDateString(), $graph['arrivals'][$opportunity['generation_date']]);
                self::assertFalse($single->isSunday());
            }
        }
        self::assertSame('2026-10-27', app(LogisticsArrivalDateService::class)->resolve(
            new WmsItemReorderPolicy(['contractor_id' => 1021, 'warehouse_id' => 1]), '2026-10-24')->toDateString());
        self::assertSame('2026-10-25', app(JxOrderArrivalDateAdjustmentService::class)->earliestArrivalDate(1021, 1, $date)->toDateString());
    }

    public function test_shift_rechecks_both_contractor_and_warehouse_holidays_with_bounded_selects(): void
    {
        $this->sundayRoute();
        $db = DB::connection('sakemaru');
        $db->table('wms_contractor_holidays')->insert(['contractor_id' => 1021, 'holiday_date' => '2026-10-27']);
        $db->table('wms_warehouse_calendars')->insert(['warehouse_id' => 1, 'is_holiday' => 1, 'target_date' => '2026-10-29']);
        $dates = [];
        for ($i = 0; $i < 56; $i++) {
            $day = Carbon::parse('2026-10-24')->addDays($i);
            $dates[$day->toDateString()] = $day;
        }
        $db->enableQueryLog();
        $result = app(ArrivalCalendar::class)->earliestArrivalDates(1021, 1, $dates);
        self::assertSame('2026-11-03', $result['2026-10-24']->toDateString());
        self::assertLessThanOrEqual(8, count($db->getQueryLog()));
        foreach ($db->getQueryLog() as $query) {
            self::assertStringStartsWith('select ', strtolower($query['query']));
        }
    }

    public function test_missing_delivery_row_preserves_lead_fallback_but_excludes_sunday_and_holidays(): void
    {
        $this->sundayRoute();
        $db = DB::connection('sakemaru');
        $db->table('wms_warehouse_calendars')->insert(['warehouse_id' => 2, 'is_holiday' => 1, 'target_date' => '2026-10-26']);
        self::assertSame('2026-10-27', app(ArrivalCalendar::class)->earliestArrivalDate(1021, 2, Carbon::parse('2026-10-24'))->toDateString());
    }

    public function test_only_fax_reference_can_assume_delivery_when_all_weekdays_are_off(): void
    {
        DB::connection('sakemaru')->table('wms_contractor_warehouse_delivery_days')->update(array_fill_keys([
            'delivery_sun', 'delivery_mon', 'delivery_tue', 'delivery_wed', 'delivery_thu', 'delivery_fri', 'delivery_sat'], 0));
        $calendar = app(ArrivalCalendar::class);
        self::assertNull($calendar->earliestArrivalDate(1021, 1, Carbon::parse('2026-10-24')));
        $reference = $calendar->referenceArrivalDates(1021, 1, ['saturday' => Carbon::parse('2026-10-24')]);
        self::assertSame('2026-10-26', $reference['saturday']->toDateString());
        $recommendation = app(RecommendationArrivalSchedule::class)->calculate(1, 1021, 'FAX', '2026-10-24', []);
        $graph = app(InventoryProjectionCalendar::class)->read(1, 1021, 'FAX', '2026-10-24', []);
        self::assertTrue($graph['reference_assumed']);
        self::assertSame('2026-10-26', $graph['arrivals']['2026-10-24']);
        self::assertSame($recommendation['opportunities'][0]['arrival_date'], $graph['arrivals']['2026-10-24']);
        self::assertNull($calendar->earliestArrivalDate(1021, 1, Carbon::parse('2026-10-24')), 'Reference calls must not leak fallback into execution.');
    }

    public function test_sunday_only_delivery_cannot_create_an_automatic_arrival(): void
    {
        $this->sundayRoute();
        DB::connection('sakemaru')->table('wms_contractor_warehouse_delivery_days')->update(['delivery_tue' => 0, 'delivery_thu' => 0]);
        self::assertNull(app(ArrivalCalendar::class)->earliestArrivalDate(1021, 1, Carbon::parse('2026-10-24')));
        $this->expectExceptionMessage('既存の配送設定から入荷可能日を算定できません');
        app(RecommendationArrivalSchedule::class)->calculate(1, 1021, 'EOS', '2026-10-24', []);
    }
}
