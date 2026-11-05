<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\SettingsRecommendationHistory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferDemandHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'replenishment.demand_exclusions' => []]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        foreach ([
            'ret_pos_stock_applications (id INTEGER, warehouse_id INTEGER, item_id INTEGER, business_date TEXT, quantity INTEGER)',
            'ret_pos_stock_application_set_items (ret_pos_stock_application_id INTEGER, warehouse_id INTEGER, item_id INTEGER, stock_applied INTEGER, quantity INTEGER)',
            'trades (id INTEGER, client_id INTEGER, is_active INTEGER, is_latest INTEGER, trade_category TEXT, is_returned INTEGER, trade_direction TEXT, process_date TEXT)',
            'trade_items (id INTEGER, trade_id INTEGER, client_id INTEGER, item_id INTEGER, is_active INTEGER, stock_allocation_id INTEGER, total_piece_quantity INTEGER)',
            'earnings (id INTEGER, trade_id INTEGER, warehouse_id INTEGER, is_active INTEGER, is_direct_delivery INTEGER, is_delivered INTEGER, delivered_date TEXT)',
            'stock_transfers (id INTEGER, trade_id INTEGER, client_id INTEGER, from_warehouse_id INTEGER, to_warehouse_id INTEGER, is_active INTEGER, is_delivered INTEGER, picking_status TEXT, picking_date TEXT)',
            'wms_order_incoming_schedules (stock_transfer_id INTEGER, transfer_candidate_id INTEGER, item_id INTEGER, order_source TEXT, warehouse_id INTEGER)',
            'wms_stock_transfer_candidates (id INTEGER, item_id INTEGER, hub_warehouse_id INTEGER, satellite_warehouse_id INTEGER)',
        ] as $schema) {
            $db->statement('CREATE TABLE '.$schema);
        }
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function trade(int $id, string $category, int $pieces, array $changes = []): void
    {
        $db = DB::connection('sakemaru');
        $db->table('trades')->insert(array_replace(['id' => $id, 'client_id' => 6, 'is_active' => 1, 'is_latest' => 1,
            'trade_category' => $category, 'is_returned' => 0, 'trade_direction' => 'NORMAL', 'process_date' => '2026-10-20'], $changes));
        $db->table('trade_items')->insert(['id' => $id, 'trade_id' => $id, 'client_id' => 6, 'item_id' => 211264, 'is_active' => 1,
            'stock_allocation_id' => 1, 'total_piece_quantity' => $pieces]);
    }

    private function transfer(int $id, int $from, int $to, int $pieces, array $changes = [], array $tradeChanges = []): void
    {
        $this->trade($id, 'STOCK_TRANSFER', $pieces, $tradeChanges);
        DB::connection('sakemaru')->table('stock_transfers')->insert(array_replace(['id' => $id, 'trade_id' => $id, 'client_id' => 6,
            'from_warehouse_id' => $from, 'to_warehouse_id' => $to, 'is_active' => 1, 'is_delivered' => 1, 'picking_status' => 'SHIPPED', 'picking_date' => '2026-10-20'], $changes));
    }

    private function demand(int $warehouse): array
    {
        return (new SettingsRecommendationHistory)->dailyDemand(6, $warehouse, 211264, 1, ['start' => '2026-10-20', 'end' => '2026-10-21'])['daily'];
    }

    public function test_manual_transfer_and_wholesale_sale_are_counted_at_their_own_stock_warehouses_once(): void
    {
        $db = DB::connection('sakemaru');
        $this->transfer(1, 22, 91, 120); // Normal core transfer; no WMS candidate is required.
        $this->trade(2, 'EARNING', 120); // The same wholesale shipment is sold by warehouse 91.
        $db->table('earnings')->insert(['id' => 2, 'trade_id' => 2, 'warehouse_id' => 91, 'is_active' => 1, 'is_direct_delivery' => 0, 'is_delivered' => 1, 'delivered_date' => '2026-10-20']);
        $db->table('ret_pos_stock_applications')->insert(['id' => 1, 'warehouse_id' => 22, 'item_id' => 211264, 'business_date' => '2026-10-20', 'quantity' => 5]);
        $this->transfer(3, 91, 22, 240); // Incoming to 22 is supply, never its demand.
        $this->transfer(4, 22, 22, 480); // Same warehouse changes no stock.
        self::assertSame(125, $this->demand(22)['2026-10-20']);
        self::assertSame(360, $this->demand(91)['2026-10-20']); // 120 sale + separate 240 outbound.
    }

    public function test_multiple_incoming_links_do_not_multiply_a_shipped_transfer(): void
    {
        $this->transfer(1, 22, 91, 48, ['is_delivered' => 0]); // Shipped but not received is outbound demand.
        $db = DB::connection('sakemaru');
        $db->table('wms_stock_transfer_candidates')->insert(['id' => 1, 'item_id' => 211264, 'hub_warehouse_id' => 22, 'satellite_warehouse_id' => 91]);
        foreach ([1, 2] as $_) {
            $db->table('wms_order_incoming_schedules')->insert(['stock_transfer_id' => 1, 'transfer_candidate_id' => 1, 'item_id' => 211264, 'order_source' => 'TRANSFER', 'warehouse_id' => 91]);
        }
        self::assertSame(48, $this->demand(22)['2026-10-20']);
    }

    public function test_pending_cancelled_old_other_allocation_and_return_transfers_are_excluded(): void
    {
        $this->transfer(1, 22, 91, 100, ['is_delivered' => 0, 'picking_status' => 'PENDING']);
        $this->transfer(2, 22, 91, 100, ['is_active' => 0]);
        $this->transfer(3, 22, 91, 100, [], ['is_latest' => 0]);
        $this->transfer(4, 22, 91, 100, [], ['is_returned' => 1]);
        $this->transfer(5, 22, 91, 100, [], ['trade_direction' => 'RETURN']);
        $this->transfer(6, 22, 91, 100);
        $this->transfer(7, 22, 91, 100, [], ['client_id' => 7]);
        $this->transfer(8, 22, 91, 100, ['picking_date' => '2026-10-19']);
        DB::connection('sakemaru')->table('trade_items')->where('id', 6)->update(['stock_allocation_id' => 2]);
        self::assertSame(0, $this->demand(22)['2026-10-20']);
    }

    public function test_observation_exclusion_and_unknown_pos_days_remain_unobserved(): void
    {
        $this->transfer(1, 22, 91, 48);
        DB::connection('sakemaru')->table('ret_pos_stock_applications')->insert(['id' => 1, 'warehouse_id' => 22, 'item_id' => 999, 'business_date' => '2026-10-21', 'quantity' => 1]);
        self::assertNull($this->demand(22)['2026-10-20']);
        config(['replenishment.demand_exclusions' => [['client_id' => 6, 'warehouse_id' => 22, 'start' => '2026-10-21', 'end' => '2026-10-21']]]);
        self::assertNull($this->demand(22)['2026-10-21']);
    }
}
