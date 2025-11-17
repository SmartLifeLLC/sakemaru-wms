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
        Schema::connection('sakemaru')->create('wms_shortage_allocations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('shortage_id')->comment('欠品ID');
            $table->unsignedBigInteger('from_warehouse_id')->comment('代理出荷倉庫');

            // 代理出荷数量（PIECE換算）
            $table->integer('assign_qty_each')->comment('代理出荷数量(PIECE換算)');
            $table->enum('assign_qty_type', ['CASE', 'PIECE', 'CARTON'])
                ->comment('代理出荷指定単位');

            // ステータス
            $table->enum('status', [
                'PENDING',      // 代理出荷指示待ち
                'RESERVED',     // 引当済み
                'PICKING',      // ピッキング中
                'FULFILLED',    // 完了
                'SHORTAGE',     // 代理側でも欠品
                'CANCELLED'     // キャンセル
            ])->default('PENDING')->comment('代理出荷ステータス');

            $table->unsignedBigInteger('created_by')->default(0)->comment('作成者');
            $table->timestamps();

            // インデックス
            $table->index(['shortage_id', 'status'], 'idx_shortage_alloc');
            $table->index('from_warehouse_id', 'idx_alloc_warehouse');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_shortage_allocations');
    }
};
