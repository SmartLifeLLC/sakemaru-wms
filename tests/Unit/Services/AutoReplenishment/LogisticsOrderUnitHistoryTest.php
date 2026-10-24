<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\LogisticsOrderUnitHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogisticsOrderUnitHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.stores.file' => ['driver' => 'array']]);
        DB::purge('sakemaru');
        Cache::forgetDriver('file');
        CarbonImmutable::setTestNow('2026-10-23 12:00:00');
        $tables = [
            'items' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,capacity_case INTEGER DEFAULT 15',
            'stock_allocations' => 'id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,code INTEGER DEFAULT 1',
            'trades' => "id INTEGER PRIMARY KEY,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,is_latest INTEGER DEFAULT 1,trade_category TEXT DEFAULT 'STOCK_TRANSFER',is_returned INTEGER DEFAULT 0,trade_direction TEXT DEFAULT 'NORMAL'",
            'stock_transfers' => "id INTEGER PRIMARY KEY,trade_id INTEGER,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,from_warehouse_id INTEGER DEFAULT 91,to_warehouse_id INTEGER DEFAULT 2,is_delivered INTEGER DEFAULT 1,picking_status TEXT DEFAULT 'SHIPPED',picking_date TEXT DEFAULT '2026-10-22'",
            'trade_items' => "id INTEGER PRIMARY KEY,trade_id INTEGER,item_id INTEGER DEFAULT 1,client_id INTEGER DEFAULT 6,is_active INTEGER DEFAULT 1,stock_allocation_id INTEGER DEFAULT 1,quantity INTEGER DEFAULT 90,quantity_type TEXT DEFAULT 'PIECE',capacity_case INTEGER DEFAULT 15,capacity_carton INTEGER DEFAULT 5,total_piece_quantity INTEGER DEFAULT 90,has_shortage INTEGER DEFAULT 0",
            'wms_order_incoming_schedules' => "id INTEGER PRIMARY KEY,transfer_candidate_id INTEGER,stock_transfer_id INTEGER,item_id INTEGER DEFAULT 1,warehouse_id INTEGER DEFAULT 2,order_source TEXT DEFAULT 'TRANSFER'",
            'wms_stock_transfer_candidates' => 'id INTEGER PRIMARY KEY,item_id INTEGER DEFAULT 1,hub_warehouse_id INTEGER DEFAULT 91,satellite_warehouse_id INTEGER DEFAULT 2',
        ];
        foreach ($tables as $table => $columns) {
            DB::connection('sakemaru')->statement("CREATE TABLE $table ($columns)");
        }
        DB::connection('sakemaru')->table('items')->insert([['id' => 1], ['id' => 2]]);
        DB::connection('sakemaru')->table('stock_allocations')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function shipment(int $id, array $line = [], array $header = [], array $trade = [], bool $linked = true): void
    {
        $db = DB::connection('sakemaru');
        $db->table('trades')->insert($trade + ['id' => $id]);
        $db->table('stock_transfers')->insert($header + ['id' => $id, 'trade_id' => $id]);
        $db->table('trade_items')->insert($line + ['id' => $id, 'trade_id' => $id]);
        if ($linked) {
            $db->table('wms_stock_transfer_candidates')->insert(['id' => $id, 'item_id' => $line['item_id'] ?? 1]);
            $db->table('wms_order_incoming_schedules')->insert(['id' => $id, 'transfer_candidate_id' => $id, 'stock_transfer_id' => $id, 'item_id' => $line['item_id'] ?? 1]);
        }
    }

    public function test_piece_labelled_whole_cases_and_case_labels_are_counted_by_shipments_not_links_or_lines(): void
    {
        $this->shipment(1);
        $this->shipment(2, ['quantity' => 6, 'quantity_type' => 'CASE']);
        $this->shipment(3, ['quantity' => 7, 'total_piece_quantity' => 7]);
        $this->shipment(4, ['quantity' => 10, 'total_piece_quantity' => 10]);
        $db = DB::connection('sakemaru');
        $db->table('trade_items')->insert(['id' => 5, 'trade_id' => 4, 'quantity' => 5, 'total_piece_quantity' => 5]);
        $db->table('wms_order_incoming_schedules')->insert(['id' => 9, 'transfer_candidate_id' => 1, 'stock_transfer_id' => 1]);
        $history = (new LogisticsOrderUnitHistory)->read(6, 91, [1 => 15], '2026-10-23')[1];
        self::assertSame(3, $history['case_count']);
        self::assertSame(1, $history['piece_count']);
    }

    public function test_incomplete_unlinked_returned_short_and_inconsistent_shipments_do_not_influence_default(): void
    {
        $this->shipment(1);
        $this->shipment(2, linked: false);
        $this->shipment(3, header: ['is_delivered' => 0, 'picking_status' => 'PENDING']);
        $this->shipment(4, trade: ['is_latest' => 0]);
        $this->shipment(5, trade: ['is_returned' => 1]);
        $this->shipment(6, ['has_shortage' => 1]);
        $this->shipment(7, ['quantity' => 89]);
        $this->shipment(8, ['capacity_case' => 12]);
        $this->shipment(9, header: ['picking_date' => '2026-07-31']);
        $this->shipment(10, header: ['picking_date' => '2026-10-23']);
        $this->shipment(11, header: ['from_warehouse_id' => 92]);
        $this->shipment(12, trade: ['client_id' => 7]);
        $this->shipment(13, ['capacity_case' => null]);
        $this->shipment(14, ['total_piece_quantity' => null]);
        $this->shipment(15, ['quantity_type' => 'UNKNOWN']);
        $this->shipment(16);
        DB::connection('sakemaru')->table('trade_items')->insert(['id' => 17, 'trade_id' => 16, 'has_shortage' => 1]);
        $history = (new LogisticsOrderUnitHistory)->read(6, 91, [1 => 15], '2026-10-23')[1];
        self::assertSame(1, $history['case_count']);
        self::assertSame(0, $history['piece_count']);
    }

    public function test_batched_select_populates_per_item_cache_and_capacity_and_period_changes_refresh_it(): void
    {
        $this->shipment(1);
        $this->shipment(2, ['item_id' => 2]);
        $db = DB::connection('sakemaru');
        $db->enableQueryLog();
        $service = new LogisticsOrderUnitHistory;
        self::assertSame(1, $service->read(6, 91, [1 => 15, 2 => 15], '2026-10-23')[2]['case_count']);
        self::assertCount(1, $db->getQueryLog());
        self::assertSame(1, $service->read(6, 91, [1 => 15], '2026-10-23')[1]['case_count']);
        self::assertCount(1, $db->getQueryLog());
        self::assertSame(0, $service->read(6, 91, [1 => 15], '2026-10-22')[1]['case_count']);
        self::assertCount(2, $db->getQueryLog());
        $db->table('items')->where('id', 1)->update(['capacity_case' => 12]);
        $db->flushQueryLog();
        self::assertSame(0, $service->read(6, 91, [1 => 12], '2026-10-23')[1]['case_count']);
        self::assertCount(1, $db->getQueryLog());
        self::assertStringStartsWith('select', $db->getQueryLog()[0]['query']);
    }
}
