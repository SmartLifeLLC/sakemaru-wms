<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->rename(
            'wms_warehouse_item_total_stocks',
            'wms_item_stock_snapshots'
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection)->rename(
            'wms_item_stock_snapshots',
            'wms_warehouse_item_total_stocks'
        );
    }
};
