<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Add unique constraint and performance indexes for optimized allocation
     * Per specification: 02_picking_3.md
     */
    public function up(): void
    {
        // 1. Add unique constraint to real_stocks
        // Combination: warehouse_id, stock_allocation_id, floor_id, location_id, purchase_id, item_id, item_management_type, expiration_date
        DB::connection('sakemaru')->statement('
            ALTER TABLE real_stocks
            ADD UNIQUE INDEX uniq_real_stocks_composite (
                warehouse_id,
                stock_allocation_id,
                floor_id,
                location_id,
                purchase_id,
                item_id,
                item_management_type,
                expiration_date
            )
        ');

        // 2. Performance indexes for allocation queries
        // FEFO/FIFO lookup: warehouse_id + item_id + expiration_date + location_id + purchase_id
        DB::connection('sakemaru')->statement('
            CREATE INDEX idx_rs_wh_item_exp_loc ON real_stocks(
                warehouse_id,
                item_id,
                expiration_date,
                location_id,
                purchase_id
            )
        ');

        // 3. wms_real_stocks: lock_version for optimistic locking
        DB::connection('sakemaru')->statement('
            CREATE INDEX idx_wrs_real_lv ON wms_real_stocks(
                real_stock_id,
                lock_version
            )
        ');

        // 4. wms_locations: walking_order for efficient sorting
        DB::connection('sakemaru')->statement('
            CREATE INDEX idx_wl_loc ON wms_locations(
                location_id,
                walking_order
            )
        ');

        // 5. locations: available_quantity_flags for bitmask filtering
        DB::connection('sakemaru')->statement('
            CREATE INDEX idx_loc_flags ON locations(
                id,
                available_quantity_flags
            )
        ');

        // 6. wms_reservations: idempotency key (prevent duplicate reservations)
        DB::connection('sakemaru')->statement('
            CREATE UNIQUE INDEX uniq_wres_idem ON wms_reservations(
                wave_id,
                item_id,
                real_stock_id,
                source_id,
                status
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('sakemaru')->statement('ALTER TABLE real_stocks DROP INDEX uniq_real_stocks_composite');
        DB::connection('sakemaru')->statement('DROP INDEX idx_rs_wh_item_exp_loc ON real_stocks');
        DB::connection('sakemaru')->statement('DROP INDEX idx_wrs_real_lv ON wms_real_stocks');
        DB::connection('sakemaru')->statement('DROP INDEX idx_wl_loc ON wms_locations');
        DB::connection('sakemaru')->statement('DROP INDEX idx_loc_flags ON locations');
        DB::connection('sakemaru')->statement('DROP INDEX uniq_wres_idem ON wms_reservations');
    }
};
