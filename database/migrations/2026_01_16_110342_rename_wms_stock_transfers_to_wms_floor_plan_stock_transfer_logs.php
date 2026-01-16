<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->rename('wms_stock_transfers', 'wms_floor_plan_stock_transfer_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->rename('wms_floor_plan_stock_transfer_logs', 'wms_stock_transfers');
    }
};
