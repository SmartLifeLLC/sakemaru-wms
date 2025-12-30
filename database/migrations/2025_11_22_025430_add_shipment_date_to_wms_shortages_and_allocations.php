<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add shipment_date to wms_shortages
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->date('shipment_date')->nullable()->after('wave_id')->comment('出荷予定日');
            $table->index('shipment_date');
        });

        // Add shipment_date to wms_shortage_allocations
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->date('shipment_date')->nullable()->after('shortage_id')->comment('出荷予定日');
            $table->index('shipment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropIndex(['shipment_date']);
            $table->dropColumn('shipment_date');
        });

        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropIndex(['shipment_date']);
            $table->dropColumn('shipment_date');
        });
    }
};
