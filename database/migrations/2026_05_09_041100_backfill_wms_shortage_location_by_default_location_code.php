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
            INNER JOIN (
                SELECT
                    missing.id as shortage_id,
                    COALESCE(MIN(exact_l.id), MIN(partial_l.id)) as location_id
                FROM wms_shortages missing
                INNER JOIN item_incoming_default_locations idl
                    ON idl.item_id = missing.item_id
                INNER JOIN locations default_l
                    ON default_l.id = idl.location_id
                LEFT JOIN locations exact_l
                    ON exact_l.warehouse_id = missing.warehouse_id
                    AND exact_l.code1 <=> default_l.code1
                    AND exact_l.code2 <=> default_l.code2
                    AND exact_l.code3 <=> default_l.code3
                LEFT JOIN locations partial_l
                    ON partial_l.warehouse_id = missing.warehouse_id
                    AND partial_l.code1 <=> default_l.code1
                    AND partial_l.code2 <=> default_l.code2
                    AND partial_l.code3 IS NULL
                WHERE missing.location_id IS NULL
                GROUP BY missing.id
            ) resolved
                ON resolved.shortage_id = ws.id
            SET ws.location_id = resolved.location_id
            WHERE ws.location_id IS NULL
              AND resolved.location_id IS NOT NULL
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
