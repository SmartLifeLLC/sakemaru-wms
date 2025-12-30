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
        Schema::connection($this->connection)->create('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->id();
            $table->char('batch_code', 14)->comment('バッチ実行ID');

            // 対象情報
            $table->unsignedBigInteger('satellite_warehouse_id')->comment('移動先倉庫（Satellite）');
            $table->unsignedBigInteger('hub_warehouse_id')->comment('移動元倉庫（Hub）');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('contractor_id')->nullable()->comment('発注先');

            // 計算値
            $table->integer('suggested_quantity')->comment('理論必要数（バラ）');
            $table->integer('transfer_quantity')->comment('移動数量（ロット適用後）');
            $table->enum('quantity_type', ['PIECE', 'CASE'])->default('CASE');

            // 入荷予定日
            $table->date('expected_arrival_date')->nullable();
            $table->date('original_arrival_date')->nullable()->comment('休日シフト前の日付');

            // ステータス
            $table->enum('status', ['PENDING', 'APPROVED', 'EXCLUDED', 'EXECUTED'])->default('PENDING');

            // ロット適用状態
            $table->enum('lot_status', ['RAW', 'ADJUSTED', 'BLOCKED', 'NEED_APPROVAL'])->default('RAW');
            $table->unsignedBigInteger('lot_rule_id')->nullable();
            $table->unsignedBigInteger('lot_exception_id')->nullable();
            $table->integer('lot_before_qty')->nullable()->comment('ロット適用前の数量');
            $table->integer('lot_after_qty')->nullable()->comment('ロット適用後の数量');
            $table->string('lot_fee_type', 50)->nullable();
            $table->decimal('lot_fee_amount', 10, 2)->nullable();

            // 手動修正
            $table->boolean('is_manually_modified')->default(false);
            $table->unsignedBigInteger('modified_by')->nullable();
            $table->dateTime('modified_at')->nullable();
            $table->string('exclusion_reason', 255)->nullable();

            $table->timestamps();

            $table->index('batch_code', 'idx_batch');
            $table->index('status', 'idx_status');
            $table->index(['satellite_warehouse_id', 'item_id'], 'idx_satellite');
            $table->index(['hub_warehouse_id', 'item_id'], 'idx_hub');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_stock_transfer_candidates');
    }
};
