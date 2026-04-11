<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $indexExists = function ($table, $indexName) {
            $indexes = DB::connection('sakemaru')
                ->select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

            return ! empty($indexes);
        };

        if ($indexExists('wms_reservations', 'uniq_wres_idem')) {
            DB::connection('sakemaru')->statement('DROP INDEX uniq_wres_idem ON wms_reservations');
        }

        DB::connection('sakemaru')->statement('
            CREATE UNIQUE INDEX uniq_wres_idem ON wms_reservations(
                wave_id,
                item_id,
                real_stock_id,
                location_id,
                source_id,
                source_line_id,
                status
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexExists = function ($table, $indexName) {
            $indexes = DB::connection('sakemaru')
                ->select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);

            return ! empty($indexes);
        };

        if ($indexExists('wms_reservations', 'uniq_wres_idem')) {
            DB::connection('sakemaru')->statement('DROP INDEX uniq_wres_idem ON wms_reservations');
        }

        DB::connection('sakemaru')->statement('
            CREATE UNIQUE INDEX uniq_wres_idem ON wms_reservations(
                wave_id,
                item_id,
                real_stock_id,
                location_id,
                source_id,
                status
            )
        ');
    }
};
