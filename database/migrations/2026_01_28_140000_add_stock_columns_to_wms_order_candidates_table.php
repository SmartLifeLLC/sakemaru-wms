<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 発注候補テーブルに在庫関連カラムを追加
     * - current_effective_stock: 有効在庫
     * - incoming_quantity: 入庫予定数（計算ログからの元値）
     * - safety_stock: 発注点（安全在庫）
     * - calculated_shortage_qty: 計算された不足数
     * - purchase_unit: 最小仕入単位
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->integer('current_effective_stock')->nullable()->after('order_quantity')->comment('有効在庫');
            $table->integer('incoming_quantity')->nullable()->after('current_effective_stock')->comment('入庫予定数');
            $table->integer('safety_stock')->nullable()->after('incoming_quantity')->comment('発注点（安全在庫）');
            $table->integer('calculated_shortage_qty')->nullable()->after('safety_stock')->comment('計算された不足数');
            $table->integer('purchase_unit')->default(1)->after('calculated_shortage_qty')->comment('最小仕入単位');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
            $table->dropColumn([
                'current_effective_stock',
                'incoming_quantity',
                'safety_stock',
                'calculated_shortage_qty',
                'purchase_unit',
            ]);
        });
    }
};
