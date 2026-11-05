<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JxArrivalDateBatchTest extends TestCase
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

    private function dates(int $count = 56): array
    {
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $day = Carbon::parse('2026-10-18', 'Asia/Tokyo')->addDays($i);
            $dates[$day->toDateString()] = $day;
        }

        return $dates;
    }

    private function assertMatchesSingle(int $contractor = 1021, int $warehouse = 1): array
    {
        $service = new JxOrderArrivalDateAdjustmentService;
        $dates = $this->dates();
        $batch = $service->earliestArrivalDates($contractor, $warehouse, $dates);
        self::assertSame(array_keys($dates), array_keys($batch));
        foreach ($dates as $key => $day) {
            self::assertSame($service->earliestArrivalDate($contractor, $warehouse, $day)?->toDateString(), $batch[$key]?->toDateString(), $key);
            self::assertSame($key, $day->toDateString(), 'Input dates must remain unchanged.');
        }

        return $batch;
    }

    public function test_batch_matches_single_for_weekdays_leads_holidays_and_unregistered_calendar_dates(): void
    {
        $db = DB::connection('sakemaru');
        $db->table('wms_contractor_holidays')->insert([
            ['contractor_id' => 1021, 'holiday_date' => '2026-10-20'],
            ['contractor_id' => 1021, 'holiday_date' => '2026-10-21'],
            ['contractor_id' => 999, 'holiday_date' => '2026-10-22'],
        ]);
        $db->table('wms_warehouse_calendars')->insert([
            ['warehouse_id' => 1, 'is_holiday' => 1, 'target_date' => '2026-10-22'],
            ['warehouse_id' => 2, 'is_holiday' => 1, 'target_date' => '2026-10-23'],
        ]);
        $batch = $this->assertMatchesSingle();
        self::assertSame('2026-10-23', $batch['2026-10-18']->toDateString());
        $db->flushQueryLog();
        $db->enableQueryLog();
        (new JxOrderArrivalDateAdjustmentService)->earliestArrivalDates(1021, 1, $this->dates());
        self::assertCount(5, $db->getQueryLog(), '56 day opportunities must use only five master/holiday reads.');
        foreach ($db->getQueryLog() as $query) {
            self::assertStringStartsWith('select ', strtolower($query['query']));
        }
    }

    public function test_missing_delivery_setting_keeps_existing_holiday_behavior(): void
    {
        DB::connection('sakemaru')->table('wms_warehouse_calendars')->insert(['warehouse_id' => 2, 'is_holiday' => 1, 'target_date' => '2026-10-20']);
        $batch = $this->assertMatchesSingle(1021, 2);
        self::assertSame('2026-10-20', $batch['2026-10-18']->toDateString());
    }

    public function test_missing_contractor_or_missing_lead_time_matches_existing_defaults(): void
    {
        $this->assertMatchesSingle(999);
        DB::connection('sakemaru')->table('contractors')->where('id', 1021)->update(['lead_time_id' => null]);
        $this->assertMatchesSingle();
    }

    public function test_all_delivery_weekdays_disabled_returns_null_for_every_day(): void
    {
        DB::connection('sakemaru')->table('wms_contractor_warehouse_delivery_days')->update([
            'delivery_mon' => 0, 'delivery_tue' => 0, 'delivery_wed' => 0, 'delivery_thu' => 0, 'delivery_fri' => 0,
        ]);
        self::assertSame([null], array_values(array_unique($this->assertMatchesSingle())));
    }

    public function test_consecutive_holidays_keep_existing_bounded_search_behavior(): void
    {
        $holidays = [];
        $warehouseHolidays = [];
        for ($i = 0; $i < 40; $i++) {
            $day = Carbon::parse('2026-10-18')->addDays($i)->toDateString();
            $holidays[] = ['contractor_id' => 1021, 'holiday_date' => $day];
            $warehouseHolidays[] = ['warehouse_id' => 1, 'is_holiday' => 1, 'target_date' => $day];
        }
        DB::connection('sakemaru')->table('wms_contractor_holidays')->insert($holidays);
        DB::connection('sakemaru')->table('wms_warehouse_calendars')->insert($warehouseHolidays);
        $this->assertMatchesSingle();
    }

    public function test_empty_batch_reads_no_database_rows_and_large_batch_is_rejected(): void
    {
        $db = DB::connection('sakemaru');
        $db->enableQueryLog();
        self::assertSame([], (new JxOrderArrivalDateAdjustmentService)->earliestArrivalDates(1021, 1, []));
        self::assertCount(0, $db->getQueryLog());
        $this->expectException(\InvalidArgumentException::class);
        (new JxOrderArrivalDateAdjustmentService)->earliestArrivalDates(1021, 1, $this->dates(57));
    }

    public function test_sparse_dates_cannot_expand_the_query_beyond_the_bound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('対象期間は56日以内');
        (new JxOrderArrivalDateAdjustmentService)->earliestArrivalDates(1021, 1, [Carbon::parse('2026-10-18'), Carbon::parse('2027-10-18')]);
    }
}
