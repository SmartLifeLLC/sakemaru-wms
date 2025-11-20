<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 移動出荷数量を受注単位ベースに変更
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            // カラム名を変更（_eachサフィックスを削除）
            $table->renameColumn('assign_qty_each', 'assign_qty');
        });

        // コメントを更新
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->integer('assign_qty')->comment('移動出荷数量（受注単位ベース）')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            // カラム名を戻す
            $table->renameColumn('assign_qty', 'assign_qty_each');
        });

        // コメントを戻す
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->integer('assign_qty_each')->comment('移動出荷数量(PIECE換算)')->change();
        });
    }
};
