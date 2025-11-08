<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Add denormalized code columns for efficient searching and filtering.
     * These avoid joins to warehouses and delivery_courses tables.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->string('warehouse_code', 20)
                ->after('warehouse_id')
                ->nullable()
                ->comment('倉庫コード (denormalized from warehouses.code)');

            $table->string('delivery_course_code', 20)
                ->after('delivery_course_id')
                ->nullable()
                ->comment('配送コースコード (denormalized from delivery_courses.code)');

            // Add indexes for filtering
            $table->index('warehouse_code', 'idx_wms_picking_tasks_warehouse_code');
            $table->index('delivery_course_code', 'idx_wms_picking_tasks_delivery_course_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropIndex('idx_wms_picking_tasks_warehouse_code');
            $table->dropIndex('idx_wms_picking_tasks_delivery_course_code');
            $table->dropColumn(['warehouse_code', 'delivery_course_code']);
        });
    }
};
