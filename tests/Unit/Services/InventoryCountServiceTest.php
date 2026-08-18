<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryCountLedgerBalanceService;
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

    public function test_ledger_quantity_scaling_uses_fixed_precision(): void
    {
        $service = new InventoryCountLedgerBalanceService;
        $toScaled = new \ReflectionMethod($service, 'quantityToScaled');
        $fromScaled = new \ReflectionMethod($service, 'scaledToQuantity');

        $total = $toScaled->invoke($service, '0.1')
            + $toScaled->invoke($service, '0.2')
            + $toScaled->invoke($service, '1.2344')
            - $toScaled->invoke($service, '1.2344');

        $this->assertSame(300, $total);
        $this->assertSame(-1235, $toScaled->invoke($service, '-1.2345'));
        $this->assertSame('0.300', number_format($fromScaled->invoke($service, $total), 3, '.', ''));
    }

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

    public function test_refresh_system_quantities_saves_ending_quantities_and_adds_new_rows_with_start_zero(): void
    {
        foreach ([
            'wms_inventory_counts' => 'ending_stock_taken_at',
            'wms_inventory_count_items' => 'ending_system_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        $itemIds = DB::connection('sakemaru')
            ->table('items')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($itemIds->count() < 2) {
            $this->markTestSkipped('items table does not have enough rows.');
        }

        $warehouseId = 990022;
        $existingRealStockId = $this->createRealStock($itemIds[0], 9, 1, $warehouseId);
        $newRealStockId = $this->createRealStock($itemIds[1], 12, 1, $warehouseId);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '終了時在庫テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $existingItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $existingRealStockId,
            'item_id' => $itemIds[0],
            'item_code' => '999101',
            'item_name' => '既存明細商品',
            'system_quantity' => 1,
            'final_count_quantity' => 3,
            'difference_quantity' => 2,
            'cost_price' => 10,
            'difference_amount' => 20,
        ]);

        $result = (new InventoryCountService)->refreshSystemQuantities($inventoryCount);

        $inventoryCount->refresh();
        $existingItem->refresh();
        $insertedItem = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('real_stock_id', $newRealStockId)
            ->first();

        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(1, $result['inserted_items']);
        $this->assertSame(0, $result['missing_real_stocks']);
        $this->assertNotNull($inventoryCount->ending_stock_taken_at);
        $this->assertSame(1, $existingItem->system_quantity);
        $this->assertSame(9, $existingItem->ending_system_quantity);
        $this->assertSame(3, $existingItem->final_count_quantity);
        $this->assertSame(2, $existingItem->difference_quantity);

        $this->assertNotNull($insertedItem);
        $this->assertSame(0, $insertedItem->system_quantity);
        $this->assertSame(12, $insertedItem->ending_system_quantity);
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

    public function test_refresh_ending_system_quantities_from_ledger_updates_ending_only_and_adds_missing_items(): void
    {
        foreach ([
            'wms_inventory_counts' => 'ending_stock_taken_at',
            'wms_inventory_count_items' => 'ending_system_quantity',
        ] as $table => $column) {
            if (! Schema::connection('sakemaru')->hasColumn($table, $column)) {
                $this->markTestSkipped("{$table}.{$column} is not available.");
            }
        }

        foreach ([
            'wms_inventory_count_theory_update_runs',
            'wms_inventory_count_theory_update_rows',
        ] as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("{$table} is not available.");
            }
        }

        if (! Schema::connection('sakemaru')->hasTable('stats_item_stock_opening_balances')) {
            $this->markTestSkipped('stats_item_stock_opening_balances table is not available.');
        }

        $items = $this->ledgerTestItems();
        if ($items->count() < 2) {
            $this->markTestSkipped('items table does not have enough ledger-testable rows.');
        }

        $clientId = (int) $items[0]->client_id;
        $warehouseId = 990126;
        $now = now();

        DB::connection('sakemaru')->table('stats_item_stock_opening_balances')->insert([
            [
                'client_id' => $clientId,
                'opening_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
                'source_database' => 'phpunit',
                'warehouse_id' => $warehouseId,
                'warehouse_code' => (string) $warehouseId,
                'warehouse_name' => '棚卸理論在庫テスト倉庫',
                'item_id' => $items[0]->id,
                'item_code' => (string) $items[0]->code,
                'item_name' => (string) $items[0]->name,
                'stock_allocation_id' => 0,
                'opening_quantity' => 17,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'client_id' => $clientId,
                'opening_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
                'source_database' => 'phpunit',
                'warehouse_id' => $warehouseId,
                'warehouse_code' => (string) $warehouseId,
                'warehouse_name' => '棚卸理論在庫テスト倉庫',
                'item_id' => $items[1]->id,
                'item_code' => (string) $items[1]->code,
                'item_name' => (string) $items[1]->name,
                'stock_allocation_id' => 0,
                'opening_quantity' => 8,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => $clientId,
            'warehouse_id' => $warehouseId,
            'warehouse_code' => (string) $warehouseId,
            'warehouse_name' => '棚卸理論在庫テスト倉庫',
            'count_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $existingItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $items[0]->id,
            'item_code' => (string) $items[0]->code,
            'item_name' => (string) $items[0]->name,
            'system_quantity' => 5,
            'ending_system_quantity' => 2,
            'final_count_quantity' => 6,
            'difference_quantity' => 1,
            'cost_price' => 10,
            'difference_amount' => 10,
        ]);

        $result = (new InventoryCountService)->refreshEndingSystemQuantitiesFromLedger(
            $inventoryCount,
            InventoryCountLedgerBalanceService::OPENING_DATE,
        );

        $inventoryCount->refresh();
        $existingItem->refresh();
        $insertedItem = WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->where('item_id', $items[1]->id)
            ->first();

        $this->assertSame(1, $result['updated_items']);
        $this->assertSame(1, $result['inserted_items']);
        $this->assertIsInt($result['backup_run_id']);
        $this->assertSame(1, $result['backed_up_existing_rows']);
        $this->assertSame(1, $result['backed_up_inserted_rows']);
        $this->assertNotNull($inventoryCount->ending_stock_taken_at);
        $this->assertSame(5, $existingItem->system_quantity);
        $this->assertSame(17, $existingItem->ending_system_quantity);
        $this->assertSame(6, $existingItem->final_count_quantity);
        $this->assertSame(1, $existingItem->difference_quantity);

        $this->assertNotNull($insertedItem);
        $this->assertSame(0, $insertedItem->system_quantity);
        $this->assertSame(8, $insertedItem->ending_system_quantity);

        $run = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_runs')
            ->where('id', $result['backup_run_id'])
            ->first();

        $this->assertNotNull($run);
        $this->assertSame('finished', $run->status);
        $this->assertSame($inventoryCount->id, (int) $run->inventory_count_id);
        $this->assertSame(1, (int) $run->updated_items);
        $this->assertSame(1, (int) $run->inserted_items);

        $existingBackup = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_rows')
            ->where('run_id', $result['backup_run_id'])
            ->where('inventory_count_item_id', $existingItem->id)
            ->first();

        $this->assertNotNull($existingBackup);
        $this->assertSame(1, (int) $existingBackup->was_existing);
        $this->assertSame('2.000', (string) $existingBackup->old_ending_system_quantity);
        $this->assertSame('17.000', (string) $existingBackup->new_ending_system_quantity);

        $insertedBackup = DB::connection('sakemaru')
            ->table('wms_inventory_count_theory_update_rows')
            ->where('run_id', $result['backup_run_id'])
            ->where('inventory_count_item_id', $insertedItem->id)
            ->first();

        $this->assertNotNull($insertedBackup);
        $this->assertSame(0, (int) $insertedBackup->was_existing);
        $this->assertNull($insertedBackup->old_ending_system_quantity);
        $this->assertSame('8.000', (string) $insertedBackup->new_ending_system_quantity);
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

    private function createRealStock(int $itemId, int $currentQuantity, int $clientId = 1, int $warehouseId = 22): int
    {
        return (int) DB::connection('sakemaru')
            ->table('real_stocks')
            ->insertGetId([
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
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

    private function ledgerTestItems()
    {
        $query = DB::connection('sakemaru')
            ->table('items as i')
            ->whereNotNull('i.client_id');

        $itemColumns = Schema::connection('sakemaru')->getColumnListing('items');

        if (in_array('is_active', $itemColumns, true)) {
            $query->where('i.is_active', true);
        }

        if (in_array('is_managed_stock', $itemColumns, true)) {
            $query->where('i.is_managed_stock', true);
        }

        if (in_array('type', $itemColumns, true)) {
            $query->whereRaw("COALESCE(i.type, '') <> 'CONTAINER'");
        }

        if (Schema::connection('sakemaru')->hasTable('item_sets')) {
            $query
                ->leftJoin('item_sets as item_set', function ($join) {
                    $join->on('item_set.id', '=', 'i.item_set_id')
                        ->where('item_set.is_active', true);
                })
                ->where(function ($query) {
                    $query
                        ->whereNull('item_set.id')
                        ->orWhere('item_set.set_type', '!=', 'OWNED');
                });
        }

        return $query
            ->select(['i.id', 'i.code', 'i.name', 'i.client_id'])
            ->orderBy('i.id')
            ->limit(2)
            ->get();
    }
}
