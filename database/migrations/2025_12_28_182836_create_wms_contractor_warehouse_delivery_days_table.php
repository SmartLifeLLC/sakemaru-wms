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
     * 発注先×倉庫ごとの納品可能曜日設定
     * Oracle「Ｍ３仕入先店舗納品曜日」からの同期先テーブル
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_contractor_warehouse_delivery_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID (contractors.id)');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID (warehouses.id)');

            // 納品可能曜日フラグ (0=不可, 1=可能)
            $table->boolean('delivery_mon')->default(false)->comment('月曜納品可能');
            $table->boolean('delivery_tue')->default(false)->comment('火曜納品可能');
            $table->boolean('delivery_wed')->default(false)->comment('水曜納品可能');
            $table->boolean('delivery_thu')->default(false)->comment('木曜納品可能');
            $table->boolean('delivery_fri')->default(false)->comment('金曜納品可能');
            $table->boolean('delivery_sat')->default(false)->comment('土曜納品可能');
            $table->boolean('delivery_sun')->default(false)->comment('日曜納品可能');

            $table->timestamps();

            // ユニーク制約: 発注先×倉庫の組み合わせ
            $table->unique(['contractor_id', 'warehouse_id'], 'uq_contractor_warehouse');
            $table->index('warehouse_id', 'idx_warehouse');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_contractor_warehouse_delivery_days');
    }
};
