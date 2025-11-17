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
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            // 欠品処理で割り当てられた数量
            $table->integer('shortage_allocated_qty')->default(0)->after('picked_qty');
            // 欠品処理で割り当てられた数量のタイプ
            $table->enum('shortage_allocated_qty_type', ['CASE', 'PIECE', 'CARTON'])->nullable()->after('shortage_allocated_qty');
            // 出荷準備完了フラグ
            $table->boolean('is_ready_to_shipment')->default(false)->after('shortage_allocated_qty_type');
            // 出荷準備完了日時
            $table->timestamp('shipment_ready_at')->nullable()->after('is_ready_to_shipment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn([
                'shortage_allocated_qty',
                'shortage_allocated_qty_type',
                'is_ready_to_shipment',
                'shipment_ready_at',
            ]);
        });
    }
};
