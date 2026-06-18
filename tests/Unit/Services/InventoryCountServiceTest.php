<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryCountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryCountServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_save_current_stock_only_marks_status_and_does_not_update_count_items(): void
    {
        $realStockId = $this->createRealStock(999001, 99);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999001,
            'item_code' => '999001',
            'item_name' => 'テスト商品',
            'system_quantity' => 1,
            'final_count_quantity' => 3,
            'difference_quantity' => 2,
            'cost_price' => 10,
            'difference_amount' => 20,
        ]);

        (new InventoryCountService)->saveCurrentStock($inventoryCount);

        $inventoryCount->refresh();
        $item->refresh();

        $this->assertTrue($inventoryCount->isCurrentStockSaved());
        $this->assertSame(WmsInventoryCount::STATUS_CURRENT_STOCK_SAVED, $inventoryCount->display_status);
        $this->assertSame(1, $item->system_quantity);
        $this->assertSame(3, $item->final_count_quantity);
        $this->assertSame(2, $item->difference_quantity);
        $this->assertSame('20.00', $item->difference_amount);
    }

    public function test_resume_current_stock_saved_for_counting_only_clears_saved_flag(): void
    {
        $realStockId = $this->createRealStock(999003, 99);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'started_at' => now()->subHour(),
            'current_stock_saved_at' => now(),
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999003,
            'item_code' => '999003',
            'item_name' => 'テスト商品3',
            'system_quantity' => 1,
            'final_count_quantity' => 3,
            'difference_quantity' => 2,
            'cost_price' => 10,
            'difference_amount' => 20,
        ]);

        (new InventoryCountService)->resumeCurrentStockSavedForCounting($inventoryCount);

        $inventoryCount->refresh();
        $item->refresh();

        $this->assertSame(WmsInventoryCount::STATUS_COUNTING, $inventoryCount->status);
        $this->assertFalse($inventoryCount->isCurrentStockSaved());
        $this->assertTrue($inventoryCount->canRefreshSystemQuantities());
        $this->assertNull($inventoryCount->current_stock_saved_at);
        $this->assertSame(1, $item->system_quantity);
        $this->assertSame(3, $item->final_count_quantity);
        $this->assertSame(2, $item->difference_quantity);
        $this->assertSame('20.00', $item->difference_amount);
    }

    public function test_refresh_system_quantities_from_daily_snapshot_uses_latest_snapshot_on_or_before_selected_date(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('real_stock_daily_snapshots')) {
            $this->markTestSkipped('real_stock_daily_snapshots table is not available.');
        }

        $realStockId = $this->createRealStock(999002, 99);
        $now = now();

        DB::connection('sakemaru')->table('real_stock_daily_snapshots')->insert([
            [
                'snapshot_date' => '2026-06-15',
                'snapshot_at' => '2026-06-15 02:00:00',
                'real_stock_id' => $realStockId,
                'warehouse_id' => 22,
                'warehouse_code' => '22',
                'stock_allocation_id' => 0,
                'item_id' => 999002,
                'item_code' => '999002',
                'current_quantity' => 7,
                'reserved_quantity' => 0,
                'available_quantity' => 7,
                'real_stock_updated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'snapshot_date' => '2026-06-17',
                'snapshot_at' => '2026-06-17 02:00:00',
                'real_stock_id' => $realStockId + 1000000000,
                'warehouse_id' => 22,
                'warehouse_code' => '22',
                'stock_allocation_id' => 0,
                'item_id' => 999999,
                'item_code' => '999999',
                'current_quantity' => 123,
                'reserved_quantity' => 0,
                'available_quantity' => 123,
                'real_stock_updated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => '2026-06-17',
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999002,
            'item_code' => '999002',
            'item_name' => 'テスト商品2',
            'system_quantity' => 1,
            'final_count_quantity' => 10,
            'difference_quantity' => 9,
            'cost_price' => 2,
            'difference_amount' => 18,
        ]);

        $result = (new InventoryCountService)->refreshSystemQuantitiesFromDailySnapshot($inventoryCount, '2026-06-17');

        $item->refresh();

        $this->assertSame('2026-06-17', $result['snapshot_date']);
        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(1, $result['updated_differences']);
        $this->assertSame(0, $result['missing_snapshot_rows']);
        $this->assertSame(7, $item->system_quantity);
        $this->assertSame(10, $item->final_count_quantity);
        $this->assertSame(3, $item->difference_quantity);
        $this->assertSame('6.00', $item->difference_amount);
    }

    public function test_post_count_movement_calculation_only_updates_counted_rows(): void
    {
        foreach ([
            'wms_inventory_counts' => 'stock_movement_from_at',
            'wms_inventory_count_items' => 'post_count_movement_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $countedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999005,
            'item_code' => '999005',
            'item_name' => '入力あり商品',
            'system_quantity' => 10,
            'final_count_quantity' => 10,
            'post_count_movement_quantity' => 99,
            'cost_price' => 1,
        ]);

        $uncountedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999006,
            'item_code' => '999006',
            'item_name' => '未入力商品',
            'system_quantity' => 10,
            'post_count_movement_quantity' => 99,
            'cost_price' => 1,
        ]);

        $result = (new InventoryCountService)->calculatePostCountMovements($inventoryCount, now()->subDay()->format('Y-m-d H:i:s'));

        $countedItem->refresh();
        $uncountedItem->refresh();
        $inventoryCount->refresh();

        $this->assertSame(1, $result['counted_item_count']);
        $this->assertSame(0, $countedItem->post_count_movement_quantity);
        $this->assertNull($uncountedItem->post_count_movement_quantity);
        $this->assertNotNull($inventoryCount->stock_movement_from_at);
        $this->assertNotNull($inventoryCount->stock_movement_calculated_at);
    }

    public function test_confirm_uses_count_execution_date_and_movement_adjusted_before_after_quantities(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('inventory_adjustment_queue')) {
            $this->markTestSkipped('inventory_adjustment_queue table is not available.');
        }

        foreach ([
            'wms_inventory_counts' => 'stock_movement_from_at',
            'wms_inventory_count_items' => 'post_count_movement_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        $clientId = (int) DB::connection('sakemaru')->table('clients')->value('id');
        if ($clientId <= 0) {
            $this->markTestSkipped('clients table does not have testable rows.');
        }

        $realStockId = $this->createRealStock(999004, 99, $clientId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '小浜店',
            'count_date' => '2026-06-19',
            'stock_movement_from_at' => '2026-06-17 14:30:00',
            'status' => WmsInventoryCount::STATUS_CHECKED,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $realStockId,
            'item_id' => 999004,
            'item_code' => '1999004',
            'item_name' => 'テスト商品4',
            'system_quantity' => 10,
            'post_count_movement_quantity' => -4,
            'final_count_quantity' => 12,
            'difference_quantity' => 2,
            'cost_price' => 5,
            'difference_amount' => 10,
        ]);

        (new InventoryCountService)->confirm($inventoryCount, 1);

        $queue = DB::connection('sakemaru')
            ->table('inventory_adjustment_queue')
            ->where('source_type', 'WMS_INVENTORY_COUNT')
            ->where('source_id', $inventoryCount->id)
            ->first();

        $this->assertNotNull($queue);
        $this->assertSame('2026-06-17', (string) $queue->process_date);
        $this->assertSame('2026-06-17', (string) $queue->adjustment_date);

        $queueItems = json_decode((string) $queue->items, true);
        $this->assertIsArray($queueItems);
        $this->assertCount(1, $queueItems);
        $this->assertSame(6, $queueItems[0]['stock_quantity_before']);
        $this->assertSame(8, $queueItems[0]['stock_quantity_after']);
        $this->assertSame(2, $queueItems[0]['inventory_adjustment_quantity']);
        $this->assertEquals(10.0, $queueItems[0]['amount']);
    }

    private function createRealStock(int $itemId, int $currentQuantity, int $clientId = 1): int
    {
        return (int) DB::connection('sakemaru')
            ->table('real_stocks')
            ->insertGetId([
                'client_id' => $clientId,
                'warehouse_id' => 22,
                'stock_allocation_id' => 0,
                'item_id' => $itemId,
                'current_quantity' => $currentQuantity,
                'order_rank' => '',
                'reserved_quantity' => 0,
                'picking_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
