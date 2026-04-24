<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->string('ordering_code', 13)->nullable()->after('search_code');
        });

        DB::connection('sakemaru')->statement('
            UPDATE wms_stock_transfer_candidates c
            JOIN item_search_information si ON c.item_id = si.item_id
              AND si.is_used_for_ordering = 1
              AND si.is_active = 1
            SET c.ordering_code = LPAD(si.search_string, 13, \'0\')
            WHERE c.ordering_code IS NULL
        ');
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->dropColumn('ordering_code');
        });
    }
};
