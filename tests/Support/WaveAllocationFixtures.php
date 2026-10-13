<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait WaveAllocationFixtures
{
    protected int $fixtureWarehouseId = 91;

    protected function setUpAllocationDatabase(): void
    {
        $mysqlDatabase = getenv('WMS_REVIEW_MYSQL_DATABASE');
        if ($mysqlDatabase) {
            $connection = config('database.connections.sakemaru');
            if (! preg_match('/^wms_wave_review_[0-9]{8}_[0-9]{6}_test$/', $mysqlDatabase)
                || ! in_array($connection['host'], ['127.0.0.1', 'localhost'], true)
                || ! str_contains($connection['database'], 'test')) {
                throw new \RuntimeException('MySQL review fixtures require a new isolated local test database');
            }
            DB::connection('sakemaru')->getPdo()->exec("CREATE DATABASE IF NOT EXISTS `{$mysqlDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            config(['database.connections.sakemaru.database' => $mysqlDatabase]);
        } else {
            config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        }
        DB::purge('sakemaru');
        $pdo = DB::connection('sakemaru')->getPdo();
        if (! $mysqlDatabase) {
            $pdo->sqliteCreateFunction('GREATEST', fn (...$values) => max($values));
            $pdo->sqliteCreateFunction('LEAST', fn (...$values) => min($values));
            $pdo->sqliteCreateFunction('GET_LOCK', fn () => 1);
            $pdo->sqliteCreateFunction('RELEASE_LOCK', fn () => 1);
        }
        // Isolated fixtures using the production columns confirmed
        // via information_schema. No migrations or external database writes.
        foreach ([
            'items' => 'client_id INTEGER, name_main TEXT, nickname TEXT, code INTEGER, type TEXT, manufacturer_id INTEGER, volume INTEGER, capacity_case INTEGER, capacity_carton INTEGER, creator_id INTEGER, last_updater_id INTEGER, packaging TEXT, item_category1_id INTEGER, item_category2_id INTEGER, container_type_id INTEGER, manufacture_type_id INTEGER, storage_type_id INTEGER, measurement_unit_weight REAL, measurement_case_weight REAL, order_rank TEXT, is_managed_stock INTEGER, uses_expiration_date INTEGER',
            'real_stocks' => 'client_id INTEGER, warehouse_id INTEGER, stock_allocation_id INTEGER, item_id INTEGER, current_quantity INTEGER, reserved_quantity INTEGER, available_quantity INTEGER GENERATED ALWAYS AS (current_quantity - reserved_quantity) VIRTUAL, picking_quantity INTEGER, order_rank TEXT',
            'locations' => 'client_id INTEGER, warehouse_id INTEGER, code1 TEXT, name TEXT, is_disabled INTEGER, floor_id INTEGER, wms_picking_area_id INTEGER',
            'real_stock_lots' => 'real_stock_id INTEGER, location_id INTEGER, initial_quantity INTEGER, current_quantity INTEGER, reserved_quantity INTEGER, status TEXT, purchase_id INTEGER, expiration_date TEXT, price REAL, created_at TEXT',
            'real_stock_lot_earnings' => 'real_stock_lot_id INTEGER, earning_id INTEGER, trade_item_id INTEGER, quantity INTEGER, status TEXT',
            'trade_items' => "client_id INTEGER, trade_id INTEGER, item_id INTEGER, stock_allocation_id INTEGER, quantity INTEGER, quantity_type TEXT, order_quantity INTEGER, order_quantity_type TEXT, capacity_case INTEGER, capacity_carton INTEGER, total_piece_quantity INTEGER GENERATED ALWAYS AS (quantity * CASE quantity_type WHEN 'CASE' THEN capacity_case WHEN 'CARTON' THEN capacity_carton ELSE 1 END) VIRTUAL, price_category TEXT, is_active INTEGER",
            'stock_transfer_lot_allocations' => 'stock_transfer_id INTEGER, trade_item_id INTEGER, from_real_stock_lot_id INTEGER, quantity INTEGER, is_set_component INTEGER DEFAULT 0',
            'wms_reservations' => 'warehouse_id INTEGER, location_id INTEGER, real_stock_id INTEGER, item_id INTEGER, expiry_date TEXT, received_at TEXT, purchase_id INTEGER, unit_cost REAL, qty_each INTEGER, qty_type TEXT, shortage_qty INTEGER, source_type TEXT, source_id INTEGER, source_line_id INTEGER, wave_id INTEGER, status TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT',
            'real_stock_lot_buyer_restrictions' => 'real_stock_lot_id INTEGER, buyer_id INTEGER',
            'earnings' => 'trade_id INTEGER, is_active INTEGER, is_delivered INTEGER, picking_status TEXT',
            'stock_transfers' => 'trade_id INTEGER, is_active INTEGER, is_delivered INTEGER, is_confirmed INTEGER, picking_status TEXT, updated_at TEXT',
            'trades' => 'serial_id INTEGER',
            'wms_picking_tasks' => 'wave_id INTEGER, wms_picking_area_id INTEGER, warehouse_id INTEGER, warehouse_code INTEGER, floor_id INTEGER, delivery_course_id INTEGER, delivery_course_code INTEGER, shipment_date TEXT, status TEXT, task_type TEXT, picker_id INTEGER, created_at TEXT, updated_at TEXT',
            'wms_picking_item_results' => 'picking_task_id INTEGER, earning_id INTEGER, source_type TEXT, stock_transfer_id INTEGER, trade_id INTEGER, trade_item_id INTEGER, item_id INTEGER, real_stock_id INTEGER, location_id INTEGER, walking_order INTEGER, ordered_qty INTEGER, ordered_qty_type TEXT, planned_qty INTEGER, planned_qty_type TEXT, picked_qty INTEGER, picked_qty_type TEXT, shortage_qty INTEGER, status TEXT, picker_id INTEGER, created_at TEXT, updated_at TEXT',
        ] as $table => $columns) {
            if ($mysqlDatabase) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (id BIGINT PRIMARY KEY AUTO_INCREMENT, {$columns}) ENGINE=InnoDB");
                $pdo->exec("TRUNCATE TABLE {$table}");
            } else {
                $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, {$columns})");
            }
        }
        if (! $mysqlDatabase) {
            DB::connection('sakemaru')->beginTransaction();
        }
    }

    protected function stock(int $current, int $reserved, int $caseSize = 12, bool $managed = true): array
    {
        $db = DB::connection('sakemaru');
        $item = (int) $db->table('items')->insertGetId([
            'client_id' => 1, 'name_main' => 'Wave budget test', 'nickname' => 'Wave budget test',
            'code' => random_int(800000000, 899999999), 'type' => 'NOT_ALCOHOL', 'manufacturer_id' => 0,
            'volume' => 1, 'capacity_case' => $caseSize, 'creator_id' => 1, 'last_updater_id' => 1,
            'packaging' => '1', 'item_category1_id' => 0, 'item_category2_id' => 0, 'container_type_id' => 0,
            'manufacture_type_id' => 0, 'storage_type_id' => 0, 'measurement_unit_weight' => 0,
            'measurement_case_weight' => 0, 'order_rank' => 'ORDER_MANUAL',
            'is_managed_stock' => $managed, 'uses_expiration_date' => false,
        ]);
        $stock = (int) $db->table('real_stocks')->insertGetId([
            'client_id' => 1, 'warehouse_id' => $this->fixtureWarehouseId, 'stock_allocation_id' => 1, 'item_id' => $item,
            'current_quantity' => $current, 'reserved_quantity' => $reserved, 'picking_quantity' => 0, 'order_rank' => '',
        ]);
        $location = (int) $db->table('locations')->insertGetId([
            'client_id' => 1, 'warehouse_id' => $this->fixtureWarehouseId, 'code1' => 'BUDGET', 'name' => 'Wave budget test', 'is_disabled' => false,
        ]);

        return [$item, $stock, $location];
    }

    protected function lot(int $stock, int $location, int $current, int $reserved = 0, string $status = 'ACTIVE'): int
    {
        return (int) DB::connection('sakemaru')->table('real_stock_lots')->insertGetId([
            'real_stock_id' => $stock, 'location_id' => $location, 'initial_quantity' => max(0, $current),
            'current_quantity' => $current, 'reserved_quantity' => $reserved, 'status' => $status,
        ]);
    }

    protected function line(int $item, int $qty, string $type = 'PIECE', int $caseSize = 12): object
    {
        $db = DB::connection('sakemaru');
        $id = (int) $db->table('trade_items')->insertGetId([
            'client_id' => 1, 'trade_id' => random_int(8000000, 8999999), 'item_id' => $item, 'stock_allocation_id' => 1,
            'quantity' => $qty, 'quantity_type' => $type, 'order_quantity' => $qty, 'order_quantity_type' => $type,
            'capacity_case' => $caseSize,
            'price_category' => 'UNKNOWN', 'is_active' => true,
        ]);

        return $db->table('trade_items')->where('id', $id)->first();
    }

    protected function rsle(int $lot, int $earning, int $line, int $qty): void
    {
        DB::connection('sakemaru')->table('real_stock_lot_earnings')->insert([
            'real_stock_lot_id' => $lot, 'earning_id' => $earning, 'trade_item_id' => $line,
            'quantity' => $qty, 'status' => 'RESERVED',
        ]);
    }

    protected function stla(int $transfer, int $line, int $lot, int $qty): void
    {
        DB::connection('sakemaru')->table('stock_transfer_lot_allocations')->insert([
            'stock_transfer_id' => $transfer, 'trade_item_id' => $line, 'from_real_stock_lot_id' => $lot, 'quantity' => $qty,
        ]);
    }
}
