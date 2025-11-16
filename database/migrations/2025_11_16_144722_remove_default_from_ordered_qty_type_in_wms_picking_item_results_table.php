<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove default value from ordered_qty_type - must be explicitly specified
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN ordered_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL
        ");

        // Also remove default from planned_qty_type and picked_qty_type for consistency
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN planned_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN picked_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore default value 'PIECE'
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN ordered_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN planned_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN picked_qty_type ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
        ");
    }
};
