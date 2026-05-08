<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_shortages', 'location_id')) {
            Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
                $table->unsignedBigInteger('location_id')
                    ->nullable()
                    ->after('warehouse_id')
                    ->comment('欠品発生棚番ID');
            });
        }

        DB::connection('sakemaru')->statement(<<<'SQL'
            UPDATE wms_shortages ws
            LEFT JOIN wms_picking_item_results pir
                ON pir.id = ws.source_pick_result_id
            LEFT JOIN wms_reservations wr
                ON wr.id = ws.source_reservation_id
            SET ws.location_id = COALESCE(pir.location_id, wr.location_id)
            WHERE ws.location_id IS NULL
              AND COALESCE(pir.location_id, wr.location_id) IS NOT NULL
        SQL);

        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->index('location_id', 'idx_wms_shortages_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_shortages', 'location_id')) {
            return;
        }

        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropIndex('idx_wms_shortages_location');
            $table->dropColumn('location_id');
        });
    }
};
