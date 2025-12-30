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
        Schema::connection($this->connection)->table('wms_item_supply_settings', function (Blueprint $table) {
            $table->dropColumn('safety_stock_qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_item_supply_settings', function (Blueprint $table) {
            $table->integer('safety_stock_qty')->default(0)->after('lead_time_days')->comment('安全在庫数');
        });
    }
};
