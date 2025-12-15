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
        Schema::connection($this->connection)->create('wms_order_calculation_logs', function (Blueprint $table) {
            $table->id();
            $table->char('batch_code', 14)->comment('バッチ実行ID');

            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->enum('calculation_type', ['SATELLITE', 'HUB']);

            // 計算入力値
            $table->integer('current_effective_stock')->comment('有効在庫');
            $table->integer('incoming_quantity')->default(0)->comment('入荷予定数');
            $table->integer('safety_stock_setting')->comment('安全在庫設定値');
            $table->integer('lead_time_days');

            // 計算結果
            $table->integer('calculated_shortage_qty')->comment('不足数');
            $table->integer('calculated_order_quantity')->comment('発注推奨数');

            // 詳細情報
            $table->json('calculation_details')->nullable()->comment('計算詳細');

            $table->timestamp('created_at')->useCurrent();

            $table->index('batch_code', 'idx_batch');
            $table->index(['warehouse_id', 'item_id'], 'idx_warehouse_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_calculation_logs');
    }
};
