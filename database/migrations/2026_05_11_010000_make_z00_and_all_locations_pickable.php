<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GENERAL_ALLOCATABLE_QUANTITY_FLAGS = 3; // CASE | PIECE

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('locations')) {
            return;
        }

        if (! Schema::connection('sakemaru')->hasColumn('locations', 'available_quantity_flags')) {
            return;
        }

        DB::connection('sakemaru')->statement(<<<'SQL'
            UPDATE locations
            SET available_quantity_flags = 3,
                updated_at = NOW()
            WHERE available_quantity_flags IS NULL
               OR available_quantity_flags <> 3
        SQL);

        if (! Schema::connection('sakemaru')->hasTable('floors')) {
            return;
        }

        if (! Schema::connection('sakemaru')->hasColumn('locations', 'floor_id')) {
            return;
        }

        DB::connection('sakemaru')->statement(<<<'SQL'
            UPDATE locations z00
            INNER JOIN (
                SELECT f.warehouse_id, MIN(f.id) AS floor_id
                FROM floors f
                INNER JOIN (
                    SELECT warehouse_id, MIN(code) AS min_code
                    FROM floors
                    GROUP BY warehouse_id
                ) first_floor
                    ON first_floor.warehouse_id = f.warehouse_id
                   AND first_floor.min_code = f.code
                GROUP BY f.warehouse_id
            ) resolved
                ON resolved.warehouse_id = z00.warehouse_id
            SET z00.floor_id = resolved.floor_id,
                z00.updated_at = NOW()
            WHERE z00.code1 = 'Z'
              AND z00.code2 = '0'
              AND z00.code3 = '0'
              AND (z00.floor_id IS NULL OR z00.floor_id <> resolved.floor_id)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-time data correction. Previous per-location flags and Z-0-0 floors are not restored.
    }
};
