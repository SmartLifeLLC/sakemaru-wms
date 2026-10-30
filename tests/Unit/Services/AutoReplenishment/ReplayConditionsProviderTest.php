<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayDataReader;
use App\Services\AutoReplenishment\Replay\ReplayEngine;
use App\Services\AutoReplenishment\SettingsRecommendationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * The replay's conditions provider reads the production inputs through the production extraction itself
 * (SettingsRecommendationHistory::linkedReceipts / orderUnit, SettingsRecommendationService::unitConstraints) — SQLite fixture, SELECT only.
 */
class ReplayConditionsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'app.client_id' => 6, 'wms.client' => 'hana']);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE items (id INTEGER, client_id INTEGER, code INTEGER, name TEXT, capacity_case INTEGER, capacity_carton INTEGER, item_category3_id INTEGER, is_active INTEGER, is_ended INTEGER, end_of_sale_type TEXT, start_of_sale_date TEXT, end_of_sale_date TEXT)');
        $db->statement('CREATE TABLE item_contractors (client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER)');
        $db->statement('CREATE TABLE stock_allocations (id INTEGER, client_id INTEGER, code INTEGER, is_active INTEGER)');
        $db->statement('CREATE TABLE item_quantity_information (item_id INTEGER, quantity INTEGER, can_order INTEGER)');
        $db->statement('CREATE TABLE client_settings (client_id INTEGER, system_date TEXT)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, source_warehouse_id INTEGER, delivery_course_id INTEGER, order_unit_mode TEXT, channel TEXT, strategy_key TEXT, is_configured INTEGER, reorder_point_pieces INTEGER, target_stock_pieces INTEGER, min_order_pieces INTEGER, max_order_pieces INTEGER, order_multiple_pieces INTEGER, calculation_basis TEXT)');
        $db->statement('CREATE TABLE wms_order_incoming_schedules (id INTEGER, item_id INTEGER, warehouse_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, order_candidate_id INTEGER, purchase_queue_id INTEGER, received_quantity INTEGER, actual_arrival_date TEXT, order_date TEXT)');
        $db->statement('CREATE TABLE wms_order_candidates (id INTEGER, item_id INTEGER, warehouse_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, order_channel TEXT, wms_order_jx_document_id INTEGER)');
        $db->statement('CREATE TABLE purchase_create_queue (id INTEGER, status TEXT, is_success INTEGER, slip_number TEXT, items TEXT)');
        $db->statement('CREATE TABLE wms_order_jx_documents (id INTEGER, document_type TEXT)');
        $db->statement('CREATE TABLE trades (id INTEGER, client_id INTEGER, is_active INTEGER, is_latest INTEGER, trade_category TEXT, is_returned INTEGER, trade_direction TEXT, serial_id TEXT, process_date TEXT)');
        $db->statement('CREATE TABLE trade_items (id INTEGER, trade_id INTEGER, client_id INTEGER, item_id INTEGER, stock_allocation_id INTEGER, is_active INTEGER, quantity INTEGER, quantity_type TEXT, capacity_case INTEGER, capacity_carton INTEGER, total_piece_quantity INTEGER)');
        $db->statement('CREATE TABLE purchases (id INTEGER, trade_id INTEGER, client_id INTEGER, is_active INTEGER, is_direct_delivery INTEGER, supplier_id INTEGER, warehouse_id INTEGER, delivered_date TEXT)');
        $db->statement('CREATE TABLE stats_item_warehouse_daily_sales (business_date TEXT, warehouse_id INTEGER, item_id INTEGER, sales_piece_qty INTEGER)');
        $db->table('warehouses')->insert([['id' => 2, 'client_id' => 6, 'code' => 2, 'name' => '二の宮店', 'is_active' => 1, 'is_virtual' => 0]]);
        $db->table('items')->insert(['id' => 143024, 'client_id' => 6, 'code' => 143024, 'name' => 'スーパードライ350', 'capacity_case' => 24, 'capacity_carton' => 6, 'item_category3_id' => 3142, 'is_active' => 1, 'is_ended' => 0, 'end_of_sale_type' => 'NORMAL']);
        $db->table('item_contractors')->insert(['client_id' => 6, 'warehouse_id' => 2, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 20]);
        $db->table('stock_allocations')->insert(['id' => 1, 'client_id' => 6, 'code' => 1, 'is_active' => 1]);
        $db->table('item_quantity_information')->insert([['item_id' => 143024, 'quantity' => 1, 'can_order' => 1], ['item_id' => 143024, 'quantity' => 24, 'can_order' => 1]]);
        $db->table('client_settings')->insert(['client_id' => 6, 'system_date' => '2026-10-14']);
        $db->table('wms_item_reorder_policies')->insert(['id' => 1, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 143024, 'contractor_id' => 10, 'supplier_id' => 20, 'order_unit_mode' => 'AUTO', 'channel' => 'EOS', 'strategy_key' => 'arrival_adaptive_v1', 'is_configured' => 0]);
        // Linked receipts: six Mondays of 3 cases inside the window ending 2025-10-05, one on the calculation date (future), one before the window, one cancelled trade.
        $dates = ['2025-09-29', '2025-09-22', '2025-09-15', '2025-09-08', '2025-09-01', '2025-08-25', '2025-10-06', '2025-07-07', '2025-09-10'];
        foreach ($dates as $i => $date) {
            $id = 100 + $i;
            $cancelled = $date === '2025-09-10';
            $db->table('wms_order_candidates')->insert(['id' => $id, 'item_id' => 143024, 'warehouse_id' => 2, 'contractor_id' => 10, 'supplier_id' => 20, 'order_channel' => 'EOS', 'wms_order_jx_document_id' => null]);
            $db->table('purchase_create_queue')->insert(['id' => $id, 'status' => 'FINISHED', 'is_success' => 1, 'slip_number' => (string) (5000 + $i), 'items' => json_encode(['created_purchase_serial_ids' => [(string) (5000 + $i)]])]);
            $db->table('wms_order_incoming_schedules')->insert(['id' => $id, 'item_id' => 143024, 'warehouse_id' => 2, 'contractor_id' => 10, 'supplier_id' => 20, 'order_candidate_id' => $id, 'purchase_queue_id' => $id,
                'received_quantity' => 3, 'actual_arrival_date' => $date, 'order_date' => CarbonImmutable::parse($date)->subDays(2)->toDateString()]);
            $db->table('trades')->insert(['id' => $id, 'client_id' => 6, 'is_active' => $cancelled ? 0 : 1, 'is_latest' => 1, 'trade_category' => 'PURCHASE', 'is_returned' => 0, 'trade_direction' => 'NORMAL', 'serial_id' => (string) (5000 + $i), 'process_date' => $date]);
            $db->table('trade_items')->insert(['id' => $id, 'trade_id' => $id, 'client_id' => 6, 'item_id' => 143024, 'stock_allocation_id' => 1, 'is_active' => 1, 'quantity' => 3, 'quantity_type' => 'CASE', 'capacity_case' => 24, 'capacity_carton' => 6, 'total_piece_quantity' => 72]);
            $db->table('purchases')->insert(['id' => $id, 'trade_id' => $id, 'client_id' => 6, 'is_active' => 1, 'is_direct_delivery' => 0, 'supplier_id' => 20, 'warehouse_id' => 2, 'delivered_date' => $date]);
        }
        $rows = [];
        for ($d = CarbonImmutable::parse('2025-06-01'); $d->toDateString() <= '2025-11-16'; $d = $d->addDay()) {
            $rows[] = ['business_date' => $d->toDateString(), 'warehouse_id' => 2, 'item_id' => 143024, 'sales_piece_qty' => $d->isSaturday() ? 20 : 10];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table('stats_item_warehouse_daily_sales')->insert($chunk);
        }
        // The ordering code lookup uses MySQL REGEXP; the output condition reads the JX master. Both are production services mocked here.
        $search = Mockery::mock(OrderRegistrationSearchService::class);
        $search->shouldReceive('orderingCodeForItem')->with(143024)->andReturn('4901234567890');
        $this->app->instance(OrderRegistrationSearchService::class, $search);
        $resolver = Mockery::mock(OrderOutputQuantityResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['ordering_unit_quantity' => 24]);
        $this->app->instance(OrderOutputQuantityResolver::class, $resolver);
    }

    public function test_provider_returns_the_production_receipts_unit_and_constraint_for_a_past_date(): void
    {
        $db = DB::connection('sakemaru');
        $reader = app(ReplayDataReader::class);
        $list = $reader->seriesList(6, '2025-10-06', '2025-11-16', 0);
        self::assertCount(1, $list);
        self::assertSame(['item_id' => 143024, 'channel' => 'EOS', 'contractor_id' => 10, 'supplier_id' => 20, 'source_warehouse_id' => null, 'delivery_course_id' => null, 'order_unit_mode' => 'AUTO', 'shipping_unit_mode' => 'STANDARD', 'strategy_key' => 'arrival_adaptive_v1'], $list[0]['route']);
        $db->enableQueryLog();
        $provider = $reader->conditionsProvider(6);
        $series = $list[0] + ['client_id' => 6, 'capacity_case' => 24];
        $cond = $provider($series, '2025-10-06', ['start' => '2025-07-08', 'end' => '2025-10-05']);
        foreach ($db->getQueryLog() as $query) {
            self::assertMatchesRegularExpression('/^\s*select/i', $query['query'], 'the provider must only read');
        }
        self::assertCount(6, $cond['receipts'], '窓内の連携実績のみ（算定日当日・窓前・取消伝票は除外）');
        self::assertSame(['2025-08-25', '2025-09-01', '2025-09-08', '2025-09-15', '2025-09-22', '2025-09-29'], collect($cond['receipts'])->pluck('date')->sort()->values()->all());
        self::assertSame([72], array_values(array_unique(array_column($cond['receipts'], 'pieces'))));
        self::assertSame('purchase', $cond['receipts'][0]['source']['type']);
        self::assertSame(24, $cond['packing_unit']); // piece-orderable by master but the ordering code outputs cases → ケース発注 (OrderUnitService)
        self::assertSame('CASE', $cond['order_unit']['quantity_type']);
        self::assertSame(['mode' => 'STANDARD'], $cond['shipping_unit']);
        self::assertSame([], $cond['options']);
        self::assertSame(['channel' => 'EOS', 'contractor_id' => 10, 'supplier_id' => 20, 'source_warehouse_id' => null, 'delivery_course_id' => null, 'order_unit_mode' => 'AUTO'], $cond['scope']);
        // The same extraction the editor and the weekly refresh use.
        $history = app(SettingsRecommendationHistory::class);
        self::assertEquals($cond['receipts'], $history->linkedReceipts(6, 143024, 'EOS', 10, 20, 0, 1, ['start' => '2025-07-08', 'end' => '2025-10-05']));
        // Fed to the engine, the recommendation is receipt-based with the observed usual batch (3 cases = 72), as production would compute.
        $run = app(ReplayEngine::class)->run($series + ['demand' => $reader->demand(6, [2], [143024], '2025-06-01', '2025-11-16')[2][143024]],
            ['name' => 'base', 'start' => '2025-10-06', 'end' => '2025-11-16', 'mode' => 'fixed', 'unit_mode' => 'CASE', 'safety_stock_days' => 5, 'review_days' => 7, 'events' => [], 'segment_events' => [], 'warmup_days' => 0, 'conditions_provider' => $provider],
            ReplayCalendar::simple(2));
        $recalc = $run['recalcs'][0];
        self::assertSame('INITIAL', $recalc['outcome'], $recalc['reason'] ?? '');
        self::assertSame('linked_receipts', $recalc['conditions']['basis_type']);
        self::assertSame(6, $recalc['conditions']['receipts']);
        self::assertSame(72, $recalc['values']['min_order_pieces']);
        self::assertSame(24, $recalc['values']['order_multiple_pieces']);
        // A route without a contractor cannot be given production conditions: the production message, not a silent EOS/STANDARD default.
        $this->expectExceptionMessage('発注経路（方式・発注先）が未設定のため、本番と同じ条件で算定できません。');
        $provider(['warehouse_id' => 2, 'item_id' => 143024, 'route' => null], '2025-10-06', ['start' => '2025-07-08', 'end' => '2025-10-05']);
    }
}
