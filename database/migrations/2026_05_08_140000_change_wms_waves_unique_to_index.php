<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_waves', function (Blueprint $table) {
            $indexes = collect(DB::select("SHOW INDEX FROM wms_waves WHERE Key_name = 'wms_waves_wms_wave_setting_id_shipping_date_unique'"));
            if ($indexes->isNotEmpty()) {
                $table->dropUnique('wms_waves_wms_wave_setting_id_shipping_date_unique');
            }

            $normalIndexes = collect(DB::select("SHOW INDEX FROM wms_waves WHERE Key_name = 'wms_waves_wms_wave_setting_id_shipping_date_index'"));
            if ($normalIndexes->isEmpty()) {
                $table->index(['wms_wave_setting_id', 'shipping_date'], 'wms_waves_wms_wave_setting_id_shipping_date_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wms_waves', function (Blueprint $table) {
            $table->dropIndex('wms_waves_wms_wave_setting_id_shipping_date_index');
            $table->unique(['wms_wave_setting_id', 'shipping_date'], 'wms_waves_wms_wave_setting_id_shipping_date_unique');
        });
    }
};
