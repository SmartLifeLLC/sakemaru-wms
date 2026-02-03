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
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->boolean('auto_transmit_on_confirm')->default(false)->after('is_active')
                ->comment('発注確定時に自動送信するかどうか');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropColumn('auto_transmit_on_confirm');
        });
    }
};
