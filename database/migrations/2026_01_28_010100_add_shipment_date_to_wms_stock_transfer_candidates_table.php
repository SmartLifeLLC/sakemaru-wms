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
        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->date('shipment_date')->nullable()->after('original_arrival_date')
                ->comment('Hub出荷日');
        });

        // 既存データの shipment_date を expected_arrival_date と同じ値で更新
        DB::connection('sakemaru')->statement('
            UPDATE wms_stock_transfer_candidates
            SET shipment_date = expected_arrival_date
            WHERE shipment_date IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->dropColumn('shipment_date');
        });
    }
};
