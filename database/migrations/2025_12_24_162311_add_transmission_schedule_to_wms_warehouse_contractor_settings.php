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
        Schema::connection($this->connection)->table('wms_warehouse_contractor_settings', function (Blueprint $table) {
            $table->time('transmission_time')->nullable()->after('format_strategy_class')->comment('送信時刻');
            $table->json('transmission_days')->nullable()->after('transmission_time')->comment('送信曜日 (0=日, 1=月, 2=火, 3=水, 4=木, 5=金, 6=土)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_warehouse_contractor_settings', function (Blueprint $table) {
            $table->dropColumn(['transmission_time', 'transmission_days']);
        });
    }
};
