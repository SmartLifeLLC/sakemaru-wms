<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropColumn('add_zero_record');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->boolean('add_zero_record')
                ->default(true)
                ->after('auto_transmit_on_confirm')
                ->comment('データなし時にAレコード（ゼロレコード）を送信するか');
        });
    }
};
