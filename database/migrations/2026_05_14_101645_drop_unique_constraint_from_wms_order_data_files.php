<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::table('wms_order_data_files', function (Blueprint $table) {
            $table->dropUnique('uq_batch_warehouse_contractor_test');
            $table->index(['batch_code', 'warehouse_id', 'contractor_id'], 'idx_batch_warehouse_contractor');
        });
    }

    public function down(): void
    {
        Schema::table('wms_order_data_files', function (Blueprint $table) {
            $table->dropIndex('idx_batch_warehouse_contractor');
            $table->unique(['batch_code', 'warehouse_id', 'contractor_id', 'is_test'], 'uq_batch_warehouse_contractor_test');
        });
    }
};
