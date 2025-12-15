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
        Schema::connection($this->connection)->create('wms_item_order_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('contractor_id')->nullable()->comment('発注先ID');
            $table->integer('safety_stock')->default(0)->comment('安全在庫数（バラ）');
            $table->integer('max_stock')->nullable()->comment('最大在庫数（バラ）');
            $table->integer('lead_time_days')->default(1)->comment('リードタイム日数');
            $table->boolean('is_auto_order_enabled')->default(true)->comment('自動発注有効フラグ');
            $table->boolean('is_holiday_delivery_available')->default(false)->comment('休日配送可否');
            $table->integer('daily_consumption_rate')->nullable()->comment('日次消費予測数（バラ）');
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id'], 'uk_item_warehouse');
            $table->index('warehouse_id', 'idx_warehouse');
            $table->index('contractor_id', 'idx_contractor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_item_order_settings');
    }
};
