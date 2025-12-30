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
        Schema::connection('sakemaru')->create('wms_shortages', function (Blueprint $table) {
            $table->id();

            // 欠品の種類
            $table->enum('type', ['ALLOCATION', 'PICKING'])
                ->comment('欠品の種類: ALLOCATION=引当時欠品, PICKING=ピッキング時欠品');

            // 関連ID
            $table->unsignedBigInteger('wave_id')->comment('Wave ID');
            $table->unsignedBigInteger('warehouse_id')->comment('欠品が発生した倉庫');
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->unsignedBigInteger('trade_id')->comment('取引ID');
            $table->unsignedBigInteger('trade_item_id')->comment('取引明細ID');

            // 数量（すべてPIECE換算）
            $table->integer('order_qty_each')->comment('受注数量(PIECE換算)');
            $table->integer('planned_qty_each')->default(0)->comment('引当数量(PIECE)');
            $table->integer('picked_qty_each')->default(0)->comment('ピッキング数量(PIECE)');
            $table->integer('shortage_qty_each')->comment('不足数量(PIECE)');

            // 受注単位のスナップショット
            $table->enum('qty_type_at_order', ['CASE', 'PIECE', 'CARTON'])
                ->comment('受注単位のスナップショット');
            $table->integer('case_size_snap')->default(1)->comment('当時のケース入数');

            // 元引当・ピッキング結果参照（トレーサビリティ）
            $table->unsignedBigInteger('source_reservation_id')->nullable()
                ->comment('元引当レコード参照');
            $table->unsignedBigInteger('source_pick_result_id')->nullable()
                ->comment('元ピッキング結果参照');

            // 連鎖欠品管理（代理出荷側での再欠品）
            $table->unsignedBigInteger('parent_shortage_id')->nullable()
                ->comment('親欠品ID（代理出荷側での再欠品の場合）');

            // ステータス
            $table->enum('status', ['OPEN', 'REALLOCATING', 'FULFILLED', 'CANCELLED'])
                ->default('OPEN')
                ->comment('欠品ステータス');

            // 理由コード
            $table->enum('reason_code', ['NONE', 'NO_STOCK', 'DAMAGED', 'MISSING_LOC', 'OTHER'])
                ->default('NONE')
                ->comment('欠品理由コード');

            $table->string('note', 255)->nullable()->comment('備考');

            $table->timestamps();

            // インデックス
            $table->index(['wave_id', 'status'], 'idx_shortage_wave');
            $table->index(['item_id', 'status'], 'idx_shortage_item');
            $table->index('parent_shortage_id', 'idx_shortage_parent');
            $table->index(['warehouse_id', 'status'], 'idx_shortage_warehouse');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_shortages');
    }
};
