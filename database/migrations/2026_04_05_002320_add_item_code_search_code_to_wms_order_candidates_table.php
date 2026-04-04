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
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->string('item_code', 20)->nullable()->after('item_id');
            $table->string('search_code', 30)->nullable()->after('item_code');
            $table->index('item_code', 'idx_wms_order_cand_item_code');
            $table->index('search_code', 'idx_wms_order_cand_search_code');
        });

        // Backfill item_code from items table
        DB::connection('sakemaru')->statement('
            UPDATE wms_order_candidates c
            JOIN items i ON c.item_id = i.id
            SET c.item_code = i.code
        ');

        // Backfill search_code from item_search_information
        DB::connection('sakemaru')->statement('
            UPDATE wms_order_candidates c
            JOIN item_search_information si ON c.item_id = si.item_id
              AND si.is_used_for_ordering = 1
              AND si.is_active = 1
            SET c.search_code = si.search_string
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropIndex('idx_wms_order_cand_item_code');
            $table->dropIndex('idx_wms_order_cand_search_code');
            $table->dropColumn(['item_code', 'search_code']);
        });
    }
};
