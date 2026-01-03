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
        // Update wms_picking_areas temperature_type enum
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_areas
            MODIFY COLUMN temperature_type ENUM('NORMAL', 'CONSTANT', 'CHILLED', 'FROZEN') NULL
        ");

        // Update wms_picking_tasks temperature_type enum
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_tasks
            MODIFY COLUMN temperature_type ENUM('NORMAL', 'CONSTANT', 'CHILLED', 'FROZEN') NULL
            COMMENT '温度帯 (NORMAL, CONSTANT, CHILLED, FROZEN)'
        ");

        // Update locations temperature_type enum (if it exists)
        if(Schema::connection('sakemaru')->hasTable('locations')){
            DB::connection('sakemaru')->statement("
                ALTER TABLE locations
                MODIFY COLUMN temperature_type ENUM('NORMAL', 'CONSTANT', 'CHILLED', 'FROZEN') NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reverting enum changes may fail if CONSTANT values exist
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_areas
            MODIFY COLUMN temperature_type ENUM('NORMAL', 'CHILLED', 'FROZEN') NULL
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_tasks
            MODIFY COLUMN temperature_type ENUM('NORMAL', 'CHILLED', 'FROZEN') NULL
            COMMENT '温度帯 (NORMAL, CHILLED, FROZEN)'
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE locations
            MODIFY COLUMN temperature_type ENUM('NORMAL', 'CHILLED', 'FROZEN') NULL
        ");
    }
};
