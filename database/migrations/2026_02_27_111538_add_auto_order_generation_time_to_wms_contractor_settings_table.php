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
        Schema::table('wms_contractor_settings', function (Blueprint $table) {
            $table->string('auto_order_generation_time', 5)->nullable()
                ->after('transmission_time')
                ->comment('自動発注生成時刻 (HH:MM形式)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_contractor_settings', function (Blueprint $table) {
            $table->dropColumn('auto_order_generation_time');
        });
    }
};
