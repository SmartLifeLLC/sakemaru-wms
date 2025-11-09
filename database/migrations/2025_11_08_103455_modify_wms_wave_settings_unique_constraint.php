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
     * Modify unique constraint to allow multiple time slots per warehouse-course combination.
     * Old: UNIQUE(warehouse_id, delivery_course_id)
     * New: UNIQUE(warehouse_id, delivery_course_id, picking_start_time)
     */
    public function up(): void
    {
        // Check if the old unique constraint exists before dropping
        $indexes = DB::connection('sakemaru')
            ->select("SHOW INDEX FROM wms_wave_settings WHERE Key_name = 'wms_wave_settings_warehouse_id_delivery_course_id_unique'");

        Schema::connection('sakemaru')->table('wms_wave_settings', function (Blueprint $table) use ($indexes) {
            // Drop old unique constraint only if it exists
            if (!empty($indexes)) {
                $table->dropUnique(['warehouse_id', 'delivery_course_id']);
            }

            // Check if new unique constraint already exists
            $newIndexes = DB::connection('sakemaru')
                ->select("SHOW INDEX FROM wms_wave_settings WHERE Key_name = 'wms_wave_settings_unique'");

            // Add new unique constraint including picking_start_time only if it doesn't exist
            if (empty($newIndexes)) {
                $table->unique(['warehouse_id', 'delivery_course_id', 'picking_start_time'], 'wms_wave_settings_unique');
            }
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
