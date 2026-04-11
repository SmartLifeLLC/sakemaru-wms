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
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->string('item_code', 20)->nullable()->after('item_id');
            $table->index('item_code', 'idx_wms_incoming_item_code');
        });

        // Backfill item_code from items table
        DB::connection('sakemaru')->statement('
            UPDATE wms_order_incoming_schedules c
            JOIN items i ON c.item_id = i.id
            SET c.item_code = i.code
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropIndex('idx_wms_incoming_item_code');
            $table->dropColumn('item_code');
        });
    }
};
