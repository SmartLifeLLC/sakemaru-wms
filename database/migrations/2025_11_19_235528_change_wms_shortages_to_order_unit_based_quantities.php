<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 欠品数量を受注単位ベースに変更
     * - 従来: 全てPIECE(バラ)単位で保存
     * - 変更後: 受注単位(qty_type_at_order)に応じた数量で保存
     *
     * 例:
     * - 受注単位がCASEの場合: order_qty, shortage_qty等はケース数
     * - 受注単位がPIECEの場合: order_qty, shortage_qty等はバラ数
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            // カラム名を変更（_eachサフィックスを削除）
            $table->renameColumn('order_qty_each', 'order_qty');
            $table->renameColumn('planned_qty_each', 'planned_qty');
            $table->renameColumn('picked_qty_each', 'picked_qty');
            $table->renameColumn('shortage_qty_each', 'shortage_qty');
        });

        // コメントを更新
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->integer('order_qty')->comment('受注数量（受注単位ベース）')->change();
            $table->integer('planned_qty')->default(0)->comment('引当数量（受注単位ベース）')->change();
            $table->integer('picked_qty')->default(0)->comment('ピッキング数量（受注単位ベース）')->change();
            $table->integer('shortage_qty')->comment('不足数量（受注単位ベース）')->change();
            $table->integer('allocation_shortage_qty')->default(0)->comment('引当時欠品数（受注単位ベース）')->change();
            $table->integer('picking_shortage_qty')->default(0)->comment('ピッキング時欠品数（受注単位ベース）')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            // カラム名を戻す
            $table->renameColumn('order_qty', 'order_qty_each');
            $table->renameColumn('planned_qty', 'planned_qty_each');
            $table->renameColumn('picked_qty', 'picked_qty_each');
            $table->renameColumn('shortage_qty', 'shortage_qty_each');
        });

        // コメントを戻す
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->integer('order_qty_each')->comment('受注数量(PIECE換算)')->change();
            $table->integer('planned_qty_each')->default(0)->comment('引当数量(PIECE)')->change();
            $table->integer('picked_qty_each')->default(0)->comment('ピッキング数量(PIECE)')->change();
            $table->integer('shortage_qty_each')->comment('不足数量(PIECE)')->change();
            $table->integer('allocation_shortage_qty')->default(0)->comment('引当時欠品数(PIECE)')->change();
            $table->integer('picking_shortage_qty')->default(0)->comment('ピッキング時欠品数(PIECE)')->change();
        });
    }
};
