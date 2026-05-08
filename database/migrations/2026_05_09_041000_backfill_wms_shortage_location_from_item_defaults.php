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
        DB::connection('sakemaru')->statement(<<<'SQL'
            UPDATE wms_shortages ws
            INNER JOIN item_incoming_default_locations idl
                ON idl.item_id = ws.item_id
                AND idl.warehouse_id = ws.warehouse_id
            SET ws.location_id = idl.location_id
            WHERE ws.location_id IS NULL
              AND idl.location_id IS NOT NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data backfill only. Do not clear location_id on rollback.
    }
};
