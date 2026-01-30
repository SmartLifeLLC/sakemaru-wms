<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::table('wms_order_calculation_logs', function (Blueprint $table) {
            // batch_code, warehouse_id, item_id の複合インデックス
            // プリロードクエリの高速化用
            $table->index(
                ['batch_code', 'warehouse_id', 'item_id'],
                'idx_batch_warehouse_item'
            );
        });
    }

    public function down(): void
    {
        Schema::table('wms_order_calculation_logs', function (Blueprint $table) {
            $table->dropIndex('idx_batch_warehouse_item');
        });
    }
};
