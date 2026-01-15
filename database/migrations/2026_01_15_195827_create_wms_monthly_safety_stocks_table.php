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
        Schema::connection($this->connection)->create('wms_monthly_safety_stocks', function (Blueprint $table) {
            $table->id();

            // 対象商品・倉庫・発注先
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID');

            // 月別設定
            $table->unsignedTinyInteger('month')->comment('月 (1-12)');
            $table->unsignedInteger('safety_stock')->default(0)->comment('安全在庫数（発注点）');

            $table->timestamps();

            // ユニーク制約: 商品×倉庫×発注先×月で一意
            $table->unique(
                ['item_id', 'warehouse_id', 'contractor_id', 'month'],
                'uk_item_warehouse_contractor_month'
            );

            // インデックス
            $table->index('month', 'idx_month');
            $table->index('warehouse_id', 'idx_warehouse');
            $table->index(['warehouse_id', 'item_id'], 'idx_warehouse_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_monthly_safety_stocks');
    }
};
