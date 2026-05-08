<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_waves', function (Blueprint $table) {
            $table->dropUnique('wms_waves_wms_wave_setting_id_shipping_date_unique');
            $table->index(['wms_wave_setting_id', 'shipping_date'], 'wms_waves_wms_wave_setting_id_shipping_date_index');
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
