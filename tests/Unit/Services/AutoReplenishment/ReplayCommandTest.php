<?php

namespace Tests\Unit\Services\AutoReplenishment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** End-to-end run of the replay command on SQLite fixtures: reads only, writes CSV/JSON files. */
class ReplayCommandTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app.client_id' => 6, 'wms.client' => 'hana']);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, capacity_case INTEGER, item_category3_id INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER)');
        $db->statement('CREATE TABLE stats_item_warehouse_daily_sales (business_date TEXT, warehouse_id INTEGER, item_id INTEGER, sales_piece_qty INTEGER)');
        $db->statement('CREATE TABLE stats_sales_csv_item_lines (id INTEGER PRIMARY KEY, client_id INTEGER, run_id INTEGER, period_type TEXT, source_system TEXT, business_date TEXT, warehouse_id INTEGER, item_id INTEGER, sales_quantity REAL)');
        $db->statement('CREATE TABLE wms_national_holidays (id INTEGER PRIMARY KEY, holiday_date TEXT, holiday_name TEXT, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER, replenishment_config TEXT)');
        $db->table('warehouses')->insert([['id' => 2, 'client_id' => 6, 'code' => 2, 'name' => '二の宮店', 'is_active' => 1, 'is_virtual' => 0], ['id' => 91, 'client_id' => 6, 'code' => 91, 'name' => 'センター', 'is_active' => 1, 'is_virtual' => 0]]);
        $db->table('items')->insert([['id' => 143024, 'client_id' => 6, 'code' => 143024, 'name' => 'スーパードライ350', 'capacity_case' => 24, 'item_category3_id' => 3142],
            ['id' => 222, 'client_id' => 6, 'code' => 222, 'name' => '上位商品', 'capacity_case' => 6, 'item_category3_id' => 3142]]);
        $db->table('wms_item_reorder_policies')->insert(['id' => 1, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 143024, 'contractor_id' => 10]);
        // 年末年始 is applied to both products (the replay's eventA follows the same per-product rule as the recommendation).
        (require base_path('database/migrations/2026_10_30_000001_create_wms_demand_events.php'))->up();
        \App\Services\AutoReplenishment\DemandEventRepository::resetInstalledFlag();
        $db->table('wms_item_demand_events')->insert([['client_id' => 6, 'item_id' => 143024, 'event_type' => 'YEAR_END', 'is_enabled' => 1], ['client_id' => 6, 'item_id' => 222, 'event_type' => 'YEAR_END', 'is_enabled' => 1]]);
        $rows = [];
        for ($d = CarbonImmutable::parse('2024-05-05'); $d->toDateString() <= '2026-01-31'; $d = $d->addDay()) {
            $md = $d->format('m-d');
            $peak = $md >= '12-25' && $md <= '12-31' ? 3 : 1;
            $rows[] = ['business_date' => $d->toDateString(), 'warehouse_id' => 2, 'item_id' => 143024, 'sales_piece_qty' => ($d->isSaturday() ? 48 : 24) * $peak];
            $rows[] = ['business_date' => $d->toDateString(), 'warehouse_id' => 2, 'item_id' => 222, 'sales_piece_qty' => 6 * $peak];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('stats_item_warehouse_daily_sales')->insert($chunk);
        }
        $this->out = sys_get_temp_dir().'/replay-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        \App\Services\AutoReplenishment\DemandEventRepository::resetInstalledFlag();
        if (isset($this->out) && is_dir($this->out)) {
            array_map('unlink', glob($this->out.'/*') ?: []);
            rmdir($this->out);
        }
        parent::tearDown();
    }

    public function test_command_replays_policies_and_top_sellers_and_writes_files_without_touching_the_database(): void
    {
        $db = DB::connection('sakemaru');
        $db->enableQueryLog();
        $code = Artisan::call('wms:replay-replenishment', ['client' => 6, '--from' => '2025-10-06', '--to' => '2026-01-20', '--scenarios' => 'fixed,base,eventA',
            '--extra-items' => 1, '--lead' => 2, '--out' => $this->out, '--warmup' => 14]);
        $output = Artisan::output();
        self::assertSame(0, $code, $output);
        foreach ($db->getQueryLog() as $query) {
            self::assertMatchesRegularExpression('/^\s*select/i', $query['query'], 'replay must only read');
        }
        self::assertStringContainsString('2系列', $output);
        foreach (['fixed', 'base', 'eventA'] as $name) {
            self::assertFileExists("{$this->out}/series_{$name}.csv");
            self::assertFileExists("{$this->out}/days_{$name}.csv");
            self::assertFileExists("{$this->out}/recalcs_{$name}.csv");
            self::assertStringContainsString("【{$name}】", $output);
        }
        $summary = json_decode(file_get_contents($this->out.'/summary.json'), true);
        self::assertSame(2, $summary['base']['series']);
        self::assertArrayHasKey('年末年始', $summary['base']['segments']);
        self::assertGreaterThan($summary['eventA']['segments']['年末年始']['unmet'], $summary['base']['segments']['年末年始']['unmet']);
        $conditions = json_decode(file_get_contents($this->out.'/conditions.json'), true);
        self::assertSame('2025-01-08', $conditions['learn_through']);
        self::assertSame(['applied', 'saved', false], [$conditions['events_scope'], $conditions['conditions'], $conditions['fixed_bounds']]);
        self::assertNotEmpty($conditions['not_reproduced']);
        self::assertCount(9, $conditions['events']); // the three standard events of 2024〜2026 only (no 連休)
        self::assertContains('年末年始 2025-12-20〜2026-01-07', $conditions['events']);
        self::assertContains('ゴールデンウィーク 2025-05-03〜2025-05-06', $conditions['events']);
        self::assertEmpty(array_filter($conditions['events'], fn ($e) => str_contains($e, '連休')));
        self::assertContains(['2025-12-31', '2026-01-03'], $conditions['stops']);
        $series = array_map('str_getcsv', file("{$this->out}/series_eventA.csv"));
        self::assertCount(3, $series);
        self::assertSame(['policy', 'top_seller'], [$series[1][5], $series[2][5]]);
        $days = file("{$this->out}/days_eventA.csv");
        self::assertCount(1 + 2 * 107, $days);
        if (getenv('REPLAY_DEBUG')) {
            fwrite(STDERR, $output.implode('', array_filter($days, fn ($l) => str_starts_with($l, '2,143024,2025-12-2') || str_starts_with($l, '2,143024,2025-12-3'))));
        }
    }

    public function test_learning_end_date_must_precede_the_replay_start_and_saved_scenario_skips_unconfigured_policies(): void
    {
        $code = Artisan::call('wms:replay-replenishment', ['client' => 6, '--from' => '2025-10-06', '--to' => '2025-11-30', '--scenarios' => 'eventA',
            '--extra-items' => 0, '--learn-through' => '2025-10-06', '--out' => $this->out]);
        self::assertSame(1, $code);
        self::assertStringContainsString('再生開始日', Artisan::output());
        $code = Artisan::call('wms:replay-replenishment', ['client' => 6, '--from' => '2025-10-06', '--to' => '2025-11-30', '--scenarios' => 'saved',
            '--extra-items' => 0, '--out' => $this->out]);
        self::assertSame(0, $code, Artisan::output());
        $summary = json_decode(file_get_contents($this->out.'/summary.json'), true);
        self::assertSame(0, $summary['saved']['series']);
        self::assertSame(1, $summary['saved']['skipped_series']);
    }
}
