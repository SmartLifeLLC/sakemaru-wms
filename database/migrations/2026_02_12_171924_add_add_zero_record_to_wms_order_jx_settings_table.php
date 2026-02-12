<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wms_order_jx_settings', function (Blueprint $table) {
            $table->boolean('add_zero_record')
                ->default(true)
                ->after('auto_transmit_on_confirm')
                ->comment('データなし時にAレコードのみの空ファイルを送信するか');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropColumn('add_zero_record');
        });
    }
};
