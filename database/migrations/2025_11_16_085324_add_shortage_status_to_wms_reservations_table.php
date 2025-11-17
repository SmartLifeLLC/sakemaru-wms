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
        // Temporarily disable strict mode to allow ENUM modification
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_reservations
            MODIFY COLUMN status ENUM(
                'RESERVED',
                'RELEASED',
                'CONSUMED',
                'CANCELLED',
                'SHORTAGE'
            ) DEFAULT 'RESERVED'
        ");

        // Restore strict mode
        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Temporarily disable strict mode
        DB::connection('sakemaru')->statement("SET SESSION sql_mode = ''");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_reservations
            MODIFY COLUMN status ENUM(
                'RESERVED',
                'RELEASED',
                'CONSUMED',
                'CANCELLED'
            ) DEFAULT 'RESERVED'
        ");

        // Restore strict mode
        DB::connection('sakemaru')->statement("
            SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ");
    }
};
