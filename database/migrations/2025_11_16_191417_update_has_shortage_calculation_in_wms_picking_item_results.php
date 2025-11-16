<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Update has_shortage calculation to consider shortage_allocated_qty:
     * has_shortage = (picked_qty + shortage_allocated_qty) < ordered_qty
     */
    public function up(): void
    {
        // Drop existing has_shortage column
        Schema::connection('sakemaru')->table('wms_picking_item_results', function ($table) {
            $table->dropColumn('has_shortage');
        });

        // Recreate has_shortage with new calculation
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN has_shortage BOOLEAN
            GENERATED ALWAYS AS (
                (picked_qty + shortage_allocated_qty) < ordered_qty
            ) STORED
            COMMENT '欠品フラグ（(picked_qty + shortage_allocated_qty) < ordered_qty の場合 TRUE）'
            AFTER has_soft_shortage
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop updated has_shortage column
        Schema::connection('sakemaru')->table('wms_picking_item_results', function ($table) {
            $table->dropColumn('has_shortage');
        });

        // Restore original has_shortage calculation
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN has_shortage BOOLEAN
            GENERATED ALWAYS AS (
                (status = 'COMPLETED' AND planned_qty > picked_qty) OR (ordered_qty > planned_qty)
            ) STORED
            COMMENT '欠品フラグ（has_physical_shortage OR has_soft_shortage）'
            AFTER has_soft_shortage
        ");
    }
};
