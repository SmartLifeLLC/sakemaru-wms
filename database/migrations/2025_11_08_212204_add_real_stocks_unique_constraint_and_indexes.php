<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        // Check if index exists helper
        $indexExists = function ($table, $indexName) {
            $indexes = DB::connection('sakemaru')
                ->select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

            return ! empty($indexes);
        };

        // 1. wms_real_stocks: lock_version for optimistic locking
        if (! $indexExists('wms_real_stocks', 'idx_wrs_real_lv')) {
            DB::connection('sakemaru')->statement('
                CREATE INDEX idx_wrs_real_lv ON wms_real_stocks(
                    real_stock_id,
                    lock_version
                )
            ');
        }

        // 2. wms_locations: walking_order for efficient sorting
        if (! $indexExists('wms_locations', 'idx_wl_loc')) {
            DB::connection('sakemaru')->statement('
                CREATE INDEX idx_wl_loc ON wms_locations(
                    location_id,
                    walking_order
                )
            ');
        }

        // 3. wms_reservations: idempotency key (prevent duplicate reservations)
        if (! $indexExists('wms_reservations', 'uniq_wres_idem')) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Check if index exists helper
        $indexExists = function ($table, $indexName) {
            $indexes = DB::connection('sakemaru')
                ->select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

            return ! empty($indexes);
        };

        // Drop indexes only if they exist
        if ($indexExists('wms_real_stocks', 'idx_wrs_real_lv')) {
            DB::connection('sakemaru')->statement('DROP INDEX idx_wrs_real_lv ON wms_real_stocks');
        }

        if ($indexExists('wms_locations', 'idx_wl_loc')) {
            DB::connection('sakemaru')->statement('DROP INDEX idx_wl_loc ON wms_locations');
        }

        if ($indexExists('wms_reservations', 'uniq_wres_idem')) {
            DB::connection('sakemaru')->statement('DROP INDEX uniq_wres_idem ON wms_reservations');
        }
    }
};
