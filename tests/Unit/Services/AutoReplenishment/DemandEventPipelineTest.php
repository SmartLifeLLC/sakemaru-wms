<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use App\Services\AutoReplenishment\DemandEventPipelineService;
use App\Services\AutoReplenishment\DemandEventRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Weekly event preparation on SQLite fixtures: proposals, factor learning, backtest, automatic approval. */
class DemandEventPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app.client_id' => 6, 'wms.client' => 'hana']);
        DB::purge('sakemaru');
        DemandEventRepository::resetInstalledFlag();
        $db = DB::connection('sakemaru');
        (require base_path('database/migrations/2026_10_30_000001_create_wms_demand_events.php'))->up();
        DemandEventRepository::resetInstalledFlag();
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, capacity_case INTEGER, item_category3_id INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, channel TEXT, strategy_key TEXT, is_configured INTEGER, reorder_point_pieces INTEGER, target_stock_pieces INTEGER, min_order_pieces INTEGER, max_order_pieces INTEGER, order_multiple_pieces INTEGER, calculation_basis TEXT)');
        $db->statement('CREATE TABLE stats_item_warehouse_daily_sales (business_date TEXT, warehouse_id INTEGER, item_id INTEGER, sales_piece_qty INTEGER)');
        $db->statement('CREATE TABLE stats_sales_csv_item_lines (id INTEGER PRIMARY KEY, client_id INTEGER, run_id INTEGER, period_type TEXT, source_system TEXT, business_date TEXT, warehouse_id INTEGER, item_id INTEGER, sales_quantity REAL)');
        $db->statement('CREATE TABLE wms_national_holidays (id INTEGER PRIMARY KEY, holiday_date TEXT, holiday_name TEXT, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER, replenishment_config TEXT)');
        $db->table('warehouses')->insert([['id' => 2, 'client_id' => 6, 'code' => 2, 'name' => '二の宮店', 'is_active' => 1, 'is_virtual' => 0]]);
        $db->table('items')->insert([['id' => 143024, 'client_id' => 6, 'code' => 143024, 'name' => 'スーパードライ350', 'capacity_case' => 24, 'item_category3_id' => 3142]]);
        $db->table('wms_item_reorder_policies')->insert(['id' => 1, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 143024, 'contractor_id' => 10, 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1', 'is_configured' => 0]);
        // Three years of store sales with a strong, repeatable year-end peak and a flat 連休.
        $rows = [];
        for ($d = CarbonImmutable::parse('2023-09-01'); $d->toDateString() <= '2026-11-15'; $d = $d->addDay()) {
            $md = $d->format('m-d');
            $peak = $md >= '12-25' && $md <= '12-31' ? 3 : ($md >= '01-02' && $md <= '01-04' ? 0.5 : 1);
            $rows[] = ['business_date' => $d->toDateString(), 'warehouse_id' => 2, 'item_id' => 143024, 'sales_piece_qty' => (int) round(($d->isSaturday() ? 48 : 24) * $peak)];
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

    public function test_weekly_preparation_proposes_learns_backtests_and_auto_approves_standard_events(): void
    {
        config(['replenishment.demand_events.weekly_prepare.auto_approve_types' => ['YEAR_END', 'OBON', 'GW', 'LONG_WEEKEND']]);
        $pipeline = app(DemandEventPipelineService::class);
        $asOf = '2026-11-16'; // Monday; only 年末年始 (12/20) starts within 63 days — 勤労感謝の日・成人の日の連休 are not standard events and are not proposed
        $upcoming = $pipeline->upcoming(6, $asOf, 63);
        self::assertSame(['YEAR_END'], array_column($upcoming['proposals'], 'type'));
        self::assertSame(['2026-12-20'], array_column($upcoming['proposals'], 'start'));
        self::assertSame(['GW'], array_column($pipeline->upcoming(6, '2027-03-01', 63)['proposals'], 'type'));
        self::assertSame(['OBON'], array_column($pipeline->upcoming(6, '2027-07-01', 63)['proposals'], 'type'));
        // Dry run: nothing written.
        $dry = $pipeline->run(6, $asOf, 63, 1, false);
        self::assertSame(0, WmsDemandEvent::count());
        self::assertSame(['WOULD_PROPOSE'], array_column($dry['events'], 'action'));
        // Apply: rows created, factors learned for those keys only, backtest stored, year-end approved automatically (types listed in config only).
        $result = $pipeline->run(6, $asOf, 63, 1, true);
        $yearEnd = WmsDemandEvent::where('type', 'YEAR_END')->firstOrFail();
        self::assertSame('APPROVED', $yearEnd->status);
        self::assertTrue($yearEnd->auto_approved);
        self::assertSame('PASS', $yearEnd->backtest['verdict']);
        self::assertCount(3, $yearEnd->backtest['instances']);
        self::assertSame(['2023-12-20', '2024-12-20', '2025-12-20'], array_column($yearEnd->backtest['instances'], 'start'));
        self::assertLessThan($yearEnd->backtest['pooled']['base']['wape'], $yearEnd->backtest['pooled']['event']['wape']);
        self::assertSame('2026-11-15', $yearEnd->factor_trained_through->toDateString());
        // v2 scoring: three windows pooled, per-series worsening and the replay conditions are stored with the verdict.
        self::assertSame('event-backtest-v2', $yearEnd->backtest['version']);
        self::assertSame(['pre', 'block', 'post'], array_keys($yearEnd->backtest['pooled']['base']['windows']));
        self::assertSame(['2025-12-06', '2025-12-19'], [$yearEnd->backtest['instances'][2]['windows']['pre']['start'], $yearEnd->backtest['instances'][2]['windows']['pre']['end']]);
        self::assertSame(['2026-01-08', '2026-01-21'], [$yearEnd->backtest['instances'][2]['windows']['post']['start'], $yearEnd->backtest['instances'][2]['windows']['post']['end']]);
        self::assertGreaterThan(0, $yearEnd->backtest['pooled']['base']['windows']['post']['days']);
        self::assertSame(1, $yearEnd->backtest['series']['scored']);
        self::assertSame(0, $yearEnd->backtest['series']['worsened']);
        self::assertSame(3, $yearEnd->backtest['conditions']['calendars']['simple'] + $yearEnd->backtest['conditions']['calendars']['scheduled']); // one series × three instances
        self::assertStringContainsString('終了後', $yearEnd->backtest['reason']);
        self::assertStringContainsString('商品×店舗', $yearEnd->backtest['reason']);
        foreach ($yearEnd->backtest['instances'] as $instance) {
            self::assertLessThan($instance['start'], $instance['learn_through']); // leave-one-out
        }
        // A custom event is never approved automatically.
        $custom = app(\App\Services\AutoReplenishment\DemandEventService::class)->save(6, ['type' => 'CUSTOM', 'name' => '歳末セール', 'start_date' => '2026-12-05', 'end_date' => '2026-12-07'], 1);
        $withCustom = $pipeline->run(6, $asOf, 63, 1, true);
        $customEntry = array_values(array_filter($withCustom['events'], fn ($e) => ($e['event']['type'] ?? '') === 'CUSTOM'))[0];
        self::assertSame('NEEDS_REVIEW', $customEntry['action']);
        self::assertSame('DRAFT', $custom->fresh()->status);
        self::assertStringContainsString('担当者の承認', (string) $custom->fresh()->review_note);
        self::assertSame(['YEAR_END'], WmsDemandEventProfile::where('scope_type', 'ITEM')->where('profile_key', 'YEAR_END')->pluck('profile_key')->unique()->values()->all());
        self::assertGreaterThanOrEqual(1, $result['summary']['approved']);
        // The recommendation sees the approved event only for products it is applied to.
        self::assertNull((new DemandEventRepository)->recommendationOptions(6, 143024));
        \App\Models\WmsItemDemandEvent::create(['client_id' => 6, 'item_id' => 143024, 'event_type' => 'YEAR_END', 'is_enabled' => true]);
        $options = (new DemandEventRepository)->recommendationOptions(6, 143024);
        self::assertSame(['YEAR_END'], array_column($options['events'], 'type')); // approved rows only, by start date
        self::assertEqualsWithDelta(3.0, $options['factors']['item']['YEAR_END']['positions']['12-30']['factor_weekday'], 0.05);
        // A second run keeps the approval; a re-run after a manual revert keeps DRAFT→AUTO again only on PASS.
        $again = $pipeline->run(6, $asOf, 63, 1, true);
        self::assertSame('KEPT_APPROVED', array_values(array_filter($again['events'], fn ($e) => ($e['event']['type'] ?? '') === 'YEAR_END'))[0]['action']);
        self::assertSame(2, WmsDemandEvent::count());
        // Command output.
        $code = Artisan::call('wms:prepare-demand-events', ['client' => 6, '--as-of' => $asOf]);
        $output = Artisan::output();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('年末年始', $output);
        self::assertStringContainsString('KEPT_APPROVED', $output);
    }
}
