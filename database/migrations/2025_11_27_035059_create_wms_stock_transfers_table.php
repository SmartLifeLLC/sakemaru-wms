<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_stock_transfers', function (Blueprint $table) {
            $table->id();

            // 商品・在庫参照
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->unsignedBigInteger('real_stock_id')->comment('実在庫ID');

            // 移動数量
            $table->integer('transfer_qty')->comment('移動在庫数（バラ換算）');

            // 倉庫情報
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');

            // 商品管理区分
            $table->enum('item_management_type', ['LOT', 'EXPIRATION', 'NONE'])
                ->default('NONE')
                ->comment('商品管理区分');

            // ロケーション（移動元・移動先）
            $table->unsignedBigInteger('source_location_id')->comment('移動元ロケーションID');
            $table->unsignedBigInteger('target_location_id')->comment('移動先ロケーションID');

            // 作業者情報
            $table->unsignedBigInteger('worker_id')->nullable()->comment('作業者ID');
            $table->string('worker_name', 100)->nullable()->comment('作業者名');

            // 作業時刻
            $table->timestamp('transferred_at')->useCurrent()->comment('作業時刻');

            // 備考
            $table->string('note', 500)->nullable()->comment('備考');

            $table->timestamps();

            // インデックス
            $table->index(['warehouse_id', 'transferred_at'], 'idx_transfer_warehouse_time');
            $table->index(['item_id', 'transferred_at'], 'idx_transfer_item_time');
            $table->index('real_stock_id', 'idx_transfer_real_stock');
            $table->index('source_location_id', 'idx_transfer_source_loc');
            $table->index('target_location_id', 'idx_transfer_target_loc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_stock_transfers');
    }
};
