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
        Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
            $table->integer('incoming_quantity_override')->nullable()->after('satellite_demand_qty')->comment('入庫予定数オーバーライド');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropColumn('incoming_quantity_override');
        });
    }
};
