<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * ユニーク制約に is_test を追加し、テスト/本番レコードが共存できるようにする
     */
    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            $table->dropUnique('uq_batch_warehouse_contractor');
            $table->unique(['batch_code', 'warehouse_id', 'contractor_id', 'is_test'], 'uq_batch_warehouse_contractor_test');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            $table->dropUnique('uq_batch_warehouse_contractor_test');
            $table->unique(['batch_code', 'warehouse_id', 'contractor_id'], 'uq_batch_warehouse_contractor');
        });
    }
};
