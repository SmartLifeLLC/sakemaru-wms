<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            // 新しいカラムを追加
            $table->integer('allocation_shortage_qty')->default(0)->comment('引当時欠品数(PIECE)')->after('shortage_qty_each');
            $table->integer('picking_shortage_qty')->default(0)->comment('ピッキング時欠品数(PIECE)')->after('allocation_shortage_qty');
        });

        // 既存データを移行: typeに基づいて適切なカラムに値を設定
        DB::connection('sakemaru')->statement("
            UPDATE wms_shortages
            SET allocation_shortage_qty = CASE WHEN type = 'ALLOCATION' THEN shortage_qty_each ELSE 0 END,
                picking_shortage_qty = CASE WHEN type = 'PICKING' THEN shortage_qty_each ELSE 0 END
        ");

        // shortage_qty_eachを合計値に更新
        DB::connection('sakemaru')->statement('
            UPDATE wms_shortages
            SET shortage_qty_each = allocation_shortage_qty + picking_shortage_qty
        ');

        // typeカラムを削除
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            // typeカラムを復元
            $table->enum('type', ['ALLOCATION', 'PICKING'])
                ->default('ALLOCATION')
                ->comment('欠品の種類: ALLOCATION=引当時欠品, PICKING=ピッキング時欠品')
                ->after('id');
        });

        // データを復元（allocation_shortage_qtyがあればALLOCATION、なければPICKING）
        DB::connection('sakemaru')->statement("
            UPDATE wms_shortages
            SET type = CASE WHEN allocation_shortage_qty > 0 THEN 'ALLOCATION' ELSE 'PICKING' END
        ");

        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropColumn(['allocation_shortage_qty', 'picking_shortage_qty']);
        });
    }
};
