<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            // Add temperature_type column (matches locations table)
            $table->string('temperature_type', 20)
                ->nullable()
                ->after('floor_id')
                ->comment('温度帯 (NORMAL, CHILLED, FROZEN)');

            // Add is_restricted_area column (matches locations table)
            $table->boolean('is_restricted_area')
                ->default(false)
                ->after('temperature_type')
                ->comment('制限エリアフラグ (0: 通常, 1: 制限エリア)');

            // Add index for grouping tasks by temperature and restricted area
            $table->index(['warehouse_id', 'floor_id', 'temperature_type', 'is_restricted_area'], 'idx_task_grouping');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropIndex('idx_task_grouping');
            $table->dropColumn(['temperature_type', 'is_restricted_area']);
        });
    }
};
