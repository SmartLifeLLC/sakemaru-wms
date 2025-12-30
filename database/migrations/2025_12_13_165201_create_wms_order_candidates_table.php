<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_order_candidates', function (Blueprint $table) {
            $table->id();
            $table->char('batch_code', 14)->comment('バッチ実行ID');

            // 対象情報
            $table->unsignedBigInteger('warehouse_id')->comment('発注倉庫（Hub）');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('contractor_id')->comment('発注先');

            // 計算値
            $table->integer('self_shortage_qty')->default(0)->comment('自倉庫の不足数');
            $table->integer('satellite_demand_qty')->default(0)->comment('Satellite倉庫からの需要');
            $table->integer('suggested_quantity')->comment('理論合計必要数');
            $table->integer('order_quantity')->comment('発注数量（ロット適用後）');
            $table->enum('quantity_type', ['PIECE', 'CASE'])->default('CASE');

            // 入荷予定日
            $table->date('expected_arrival_date')->nullable();
            $table->date('original_arrival_date')->nullable();

            // ステータス
            $table->enum('status', ['PENDING', 'APPROVED', 'EXCLUDED', 'EXECUTED'])->default('PENDING');

            // ロット適用状態
            $table->enum('lot_status', ['RAW', 'ADJUSTED', 'BLOCKED', 'NEED_APPROVAL'])->default('RAW');
            $table->unsignedBigInteger('lot_rule_id')->nullable();
            $table->unsignedBigInteger('lot_exception_id')->nullable();
            $table->integer('lot_before_qty')->nullable();
            $table->integer('lot_after_qty')->nullable();
            $table->string('lot_fee_type', 50)->nullable();
            $table->decimal('lot_fee_amount', 10, 2)->nullable();

            // 手動修正
            $table->boolean('is_manually_modified')->default(false);
            $table->unsignedBigInteger('modified_by')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->string('exclusion_reason', 255)->nullable();

            // 送信情報
            $table->enum('transmission_status', ['PENDING', 'SENT', 'FAILED'])->default('PENDING');
            $table->dateTime('transmitted_at')->nullable();
            $table->unsignedBigInteger('wms_order_jx_document_id')->nullable();

            $table->timestamps();

            $table->index('batch_code', 'idx_batch');
            $table->index('status', 'idx_status');
            $table->index(['warehouse_id', 'item_id'], 'idx_warehouse_item');
            $table->index('contractor_id', 'idx_contractor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_candidates');
    }
};
