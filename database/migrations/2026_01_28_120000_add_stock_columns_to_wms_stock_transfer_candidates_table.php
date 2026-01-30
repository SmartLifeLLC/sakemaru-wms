<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 移動候補テーブルに在庫関連カラムを追加
     * - current_effective_stock: 有効在庫
     * - incoming_quantity: 入庫予定数
     * - calculated_available: 計算後在庫
     * - shortage_qty: 不足数
     * - purchase_unit: 最小仕入単位
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->integer('current_effective_stock')->nullable()->after('transfer_quantity')->comment('有効在庫');
            $table->integer('incoming_quantity')->nullable()->after('current_effective_stock')->comment('入庫予定数');
            $table->integer('calculated_available')->nullable()->after('incoming_quantity')->comment('計算後在庫');
            $table->integer('shortage_qty')->nullable()->after('calculated_available')->comment('不足数');
            $table->integer('purchase_unit')->default(1)->after('shortage_qty')->comment('最小仕入単位');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->dropColumn([
                'current_effective_stock',
                'incoming_quantity',
                'calculated_available',
                'shortage_qty',
                'purchase_unit',
            ]);
        });
    }
};
