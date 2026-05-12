<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN status ENUM(
                'PENDING',
                'PICKING',
                'COMPLETED',
                'SHORTAGE',
                'CANCELLED'
            ) DEFAULT 'PENDING'
        ");

        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }

    public function down(): void
    {
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN status ENUM(
                'PENDING',
                'PICKING',
                'COMPLETED',
                'SHORTAGE'
            ) DEFAULT 'PENDING'
        ");

        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }
};
