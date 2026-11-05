<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\OrderRegistrationSearchService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderRegistrationAutoOrderStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.client_id' => 6, 'replenishment.enabled' => true,
            'database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER PRIMARY KEY, client_id INTEGER, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE wms_auto_order_controls (client_id INTEGER PRIMARY KEY, enabled INTEGER, reset_state TEXT)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER PRIMARY KEY, is_auto_order_enabled INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, is_enabled INTEGER, is_configured INTEGER, UNIQUE(client_id, warehouse_id, item_id))');
        $db->table('warehouses')->insert(['id' => 2, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0]);
        $db->table('wms_auto_order_controls')->insert(['client_id' => 6, 'enabled' => 1, 'reset_state' => 'COMPLETE']);
        $db->table('wms_warehouse_auto_order_settings')->insert(['warehouse_id' => 2, 'is_auto_order_enabled' => 1]);
        $db->table('wms_item_reorder_policies')->insert(array_map(fn ($id) => [
            'client_id' => 6, 'warehouse_id' => 2, 'item_id' => $id, 'is_enabled' => 1, 'is_configured' => 1,
        ], range(1, 100)));
    }

    public function test_visible_items_are_loaded_in_one_read_only_query_for_one_or_one_hundred_rows(): void
    {
        $db = DB::connection('sakemaru');
        $service = new OrderRegistrationSearchService;
        foreach ([[1], range(1, 100)] as $ids) {
            $db->flushQueryLog();
            $db->enableQueryLog();
            self::assertSame(array_fill_keys($ids, true), $service->activeAutoOrderItems(2, $ids));
            self::assertCount(1, $db->getQueryLog());
            self::assertStringStartsWith('select ', strtolower($db->getQueryLog()[0]['query']));
        }
        self::assertSame([1 => true], $service->activeAutoOrderItems(2, [1, '1', 0, -1, 999]));
        self::assertSame([], $service->activeAutoOrderItems(3, [1]));
        config(['app.client_id' => 7]);
        self::assertSame([], $service->activeAutoOrderItems(2, [1]));
    }

    #[DataProvider('inactiveSettings')]
    public function test_every_enable_gate_is_required_and_changes_are_not_cached(string $table, string $column, mixed $value): void
    {
        $service = new OrderRegistrationSearchService;
        self::assertSame([1 => true], $service->activeAutoOrderItems(2, [1]));
        DB::connection('sakemaru')->table($table)->update([$column => $value]);
        self::assertSame([], $service->activeAutoOrderItems(2, [1]));
    }

    public static function inactiveSettings(): array
    {
        return [
            'global off' => ['wms_auto_order_controls', 'enabled', 0],
            'reset incomplete' => ['wms_auto_order_controls', 'reset_state', 'IN_PROGRESS'],
            'warehouse off' => ['wms_warehouse_auto_order_settings', 'is_auto_order_enabled', 0],
            'product off' => ['wms_item_reorder_policies', 'is_enabled', 0],
            'unconfigured' => ['wms_item_reorder_policies', 'is_configured', 0],
            'inactive warehouse' => ['warehouses', 'is_active', 0],
            'virtual warehouse' => ['warehouses', 'is_virtual', 1],
            'wrong warehouse client' => ['warehouses', 'client_id', 7],
        ];
    }

    public function test_disabled_runtime_or_empty_scope_does_not_query(): void
    {
        $db = DB::connection('sakemaru');
        $service = new OrderRegistrationSearchService;
        $db->enableQueryLog();
        self::assertSame([], $service->activeAutoOrderItems(2, []));
        self::assertSame([], $service->activeAutoOrderItems(0, [1]));
        config(['replenishment.enabled' => false]);
        self::assertSame([], $service->activeAutoOrderItems(2, [1]));
        config(['replenishment.enabled' => true, 'app.client_id' => null]);
        self::assertSame([], $service->activeAutoOrderItems(2, [1]));
        self::assertSame([], $db->getQueryLog());
    }
}
