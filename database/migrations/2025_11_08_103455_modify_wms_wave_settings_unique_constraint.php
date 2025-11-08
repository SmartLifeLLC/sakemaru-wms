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
     * Modify unique constraint to allow multiple time slots per warehouse-course combination.
     * Old: UNIQUE(warehouse_id, delivery_course_id)
     * New: UNIQUE(warehouse_id, delivery_course_id, picking_start_time)
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            // Drop old unique constraint
            $table->dropUnique(['warehouse_id', 'delivery_course_id']);

            // Add new unique constraint including picking_start_time
            // This allows multiple time slots for the same warehouse-course combination
            $table->unique(['warehouse_id', 'delivery_course_id', 'picking_start_time'], 'wms_wave_settings_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) {
            // Drop new unique constraint
            $table->dropUnique('wms_wave_settings_unique');

            // Restore old unique constraint
            $table->unique(['warehouse_id', 'delivery_course_id']);
        });
    }
};
