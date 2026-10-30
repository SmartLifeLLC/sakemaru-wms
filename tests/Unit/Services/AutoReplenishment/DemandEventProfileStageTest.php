<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use App\Services\AutoReplenishment\DemandEventLearner;
use App\Services\AutoReplenishment\DemandEventLearningService;
use App\Services\AutoReplenishment\DemandEventRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Re-review R1: learned factors are candidates until a passed backtest or an approval puts them into use. */
class DemandEventProfileStageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app.client_id' => 6, 'wms.client' => 'hana']);
        DB::purge('sakemaru');
        DemandEventRepository::resetInstalledFlag();
        (require base_path('database/migrations/2026_10_30_000001_create_wms_demand_events.php'))->up();
        DemandEventRepository::resetInstalledFlag();
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, capacity_case INTEGER, item_category3_id INTEGER)');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, channel TEXT)');
        $db->statement('CREATE TABLE stats_item_warehouse_daily_sales (business_date TEXT, warehouse_id INTEGER, item_id INTEGER, sales_piece_qty INTEGER)');
        $db->statement('CREATE TABLE stats_sales_csv_item_lines (id INTEGER PRIMARY KEY, client_id INTEGER, run_id INTEGER, period_type TEXT, source_system TEXT, business_date TEXT, warehouse_id INTEGER, item_id INTEGER, sales_quantity REAL)');
        $db->statement('CREATE TABLE wms_national_holidays (id INTEGER PRIMARY KEY, holiday_date TEXT, holiday_name TEXT, created_at TEXT, updated_at TEXT)');
        $db->table('items')->insert([['id' => 1, 'client_id' => 6, 'code' => 1, 'name' => 'A', 'capacity_case' => 24, 'item_category3_id' => 3142]]);
        $db->table('warehouses')->insert([['id' => 2, 'client_id' => 6, 'code' => 2, 'name' => '店', 'is_active' => 1, 'is_virtual' => 0]]);
        $db->table('wms_item_reorder_policies')->insert(['id' => 1, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 1, 'contractor_id' => 10, 'channel' => 'EOS']);
        $db->table('wms_item_demand_events')->insert(['client_id' => 6, 'item_id' => 1, 'event_type' => 'YEAR_END', 'is_enabled' => 1]);
        $rows = [];
        for ($d = \Carbon\CarbonImmutable::parse('2023-09-01'); $d->toDateString() <= '2026-10-29'; $d = $d->addDay()) {
            $md = $d->format('m-d');
            $rows[] = ['business_date' => $d->toDateString(), 'warehouse_id' => 2, 'item_id' => 1, 'sales_piece_qty' => (int) round(24 * ($md >= '12-25' && $md <= '12-31' ? 3 : ($md >= '08-10' && $md <= '08-15' ? 2 : 1)))];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('stats_item_warehouse_daily_sales')->insert($chunk);
        }
    }

    protected function tearDown(): void
    {
        DemandEventRepository::resetInstalledFlag();
        parent::tearDown();
    }

    private function learned(float $factor, string ...$keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[1][$key] = ['positions' => ['12-30' => ['factor' => $factor, 'factor_weekday' => $factor, 'years' => 3]], 'pieces' => 1000, 'instance_count' => 3];
        }

        return ['items' => $items, 'categories' => [], 'instances' => [], 'stats' => []];
    }

    public function test_persist_writes_candidates_and_promote_replaces_only_the_keys_with_candidates(): void
    {
        $service = app(DemandEventLearningService::class);
        $repository = new DemandEventRepository;
        WmsDemandEvent::create(['client_id' => 6, 'year' => 2026, 'type' => 'YEAR_END', 'name' => '年末年始', 'start_date' => '2026-12-20', 'end_date' => '2027-01-07', 'status' => 'APPROVED']);
        // First learning: candidates only → nothing in use yet.
        self::assertSame(1, $service->persist(6, $this->learned(3.0, 'YEAR_END'), '2026-10-01'));
        self::assertSame([], $repository->recommendationOptions(6, 1)['factors']['item']);
        self::assertSame(1, $service->promote(6, ['YEAR_END']));
        self::assertEquals(3.0, (new DemandEventRepository)->recommendationOptions(6, 1)['factors']['item']['YEAR_END']['positions']['12-30']['factor']);
        // Re-learning writes a new candidate; the ACTIVE row is untouched until promoted.
        $service->persist(6, $this->learned(5.0, 'YEAR_END'), '2026-10-29');
        self::assertEquals(3.0, (new DemandEventRepository)->recommendationOptions(6, 1)['factors']['item']['YEAR_END']['positions']['12-30']['factor']);
        self::assertSame(2, WmsDemandEventProfile::where('profile_key', 'YEAR_END')->count());
        self::assertSame(0, $service->promote(6, ['OBON'])); // no candidate for that key: nothing happens
        self::assertSame(1, $service->promote(6, ['YEAR_END', 'OBON']));
        self::assertEquals(5.0, (new DemandEventRepository)->recommendationOptions(6, 1)['factors']['item']['YEAR_END']['positions']['12-30']['factor']);
        self::assertSame(1, WmsDemandEventProfile::where('profile_key', 'YEAR_END')->count());
        self::assertSame('2026-10-29', WmsDemandEventProfile::where('profile_key', 'YEAR_END')->firstOrFail()->trained_through->toDateString());
        // Stale model versions are never read, even when ACTIVE.
        WmsDemandEventProfile::where('profile_key', 'YEAR_END')->update(['model_version' => 'event-factors-v1']);
        self::assertSame([], (new DemandEventRepository)->recommendationOptions(6, 1)['factors']['item']);
        self::assertSame(['年末年始', 'YEAR_END'], $service->keysInUse(6));
    }

    public function test_learn_command_keeps_the_factors_in_use_unless_promote_is_requested(): void
    {
        $service = app(DemandEventLearningService::class);
        WmsDemandEvent::create(['client_id' => 6, 'year' => 2026, 'type' => 'YEAR_END', 'name' => '年末年始', 'start_date' => '2026-12-20', 'end_date' => '2027-01-07', 'status' => 'APPROVED']);
        $service->persist(6, $this->learned(9.0, 'YEAR_END'), '2026-09-30', null, WmsDemandEventProfile::ACTIVE);
        self::assertEquals(9.0, (new DemandEventRepository)->recommendationOptions(6, 1)['factors']['item']['YEAR_END']['positions']['12-30']['factor']);
        // --apply: everything is saved as candidate; keys not used by an approved event (OBON, GW) are promoted, YEAR_END stays as it is.
        $code = Artisan::call('wms:learn-demand-events', ['client' => 6, '--through' => '2026-10-29', '--apply' => true]);
        $output = Artisan::output();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('候補のまま', $output);
        self::assertStringContainsString('YEAR_END', $output);
        self::assertEquals(9.0, (new DemandEventRepository)->recommendationOptions(6, 1)['factors']['item']['YEAR_END']['positions']['12-30']['factor']);
        self::assertSame(['ACTIVE'], WmsDemandEventProfile::where(['profile_key' => 'OBON', 'scope_type' => 'ITEM'])->distinct()->pluck('stage')->all());
        self::assertSame(1, WmsDemandEventProfile::where(['profile_key' => 'YEAR_END', 'scope_type' => 'ITEM', 'stage' => 'CANDIDATE'])->count());
        self::assertSame(DemandEventLearner::MODEL_VERSION, WmsDemandEventProfile::where(['profile_key' => 'OBON', 'scope_type' => 'ITEM'])->value('model_version'));
        // --promote replaces the factors in use as well.
        self::assertSame(0, Artisan::call('wms:learn-demand-events', ['client' => 6, '--through' => '2026-10-29', '--apply' => true, '--promote' => true]), Artisan::output());
        self::assertSame(0, WmsDemandEventProfile::where('stage', 'CANDIDATE')->count());
        self::assertEqualsWithDelta(3.0, (new DemandEventRepository)->recommendationOptions(6, 1)['factors']['item']['YEAR_END']['positions']['12-30']['factor_weekday'], 0.05);
    }
}
