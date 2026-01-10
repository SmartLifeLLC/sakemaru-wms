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
        Schema::connection($this->connection)->create('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->id();

            // 倉庫・商品情報
            $table->unsignedBigInteger('warehouse_id')->comment('入庫倉庫');
            $table->unsignedBigInteger('item_id')->comment('商品ID');
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID');
            $table->unsignedBigInteger('supplier_id')->nullable()->comment('仕入先ID');

            // 発注元情報
            $table->unsignedBigInteger('order_candidate_id')->nullable()->comment('自動発注: wms_order_candidates.id');
            $table->string('manual_order_number', 50)->nullable()->comment('手動発注: 発注番号');
            $table->enum('order_source', ['AUTO', 'MANUAL'])->default('MANUAL')->comment('発注元: AUTO=自動発注, MANUAL=手動発注');

            // 数量情報
            $table->integer('expected_quantity')->comment('予定数量');
            $table->integer('received_quantity')->default(0)->comment('入庫済み数量');
            $table->enum('quantity_type', ['PIECE', 'CASE', 'CARTON'])->default('PIECE')->comment('数量タイプ');

            // 日付情報
            $table->date('order_date')->comment('発注日');
            $table->date('expected_arrival_date')->comment('入庫予定日');
            $table->date('actual_arrival_date')->nullable()->comment('実際の入庫日');

            // ステータス
            $table->enum('status', ['PENDING', 'PARTIAL', 'CONFIRMED', 'CANCELLED'])
                ->default('PENDING')
                ->comment('PENDING=未入庫, PARTIAL=一部入庫, CONFIRMED=入庫完了, CANCELLED=キャンセル');

            // 確定情報
            $table->dateTime('confirmed_at')->nullable()->comment('入庫確定日時');
            $table->unsignedBigInteger('confirmed_by')->nullable()->comment('入庫確定者ID');

            // 仕入れデータ作成
            $table->unsignedBigInteger('purchase_queue_id')->nullable()->comment('purchase_create_queue.id');
            $table->string('purchase_slip_number', 50)->nullable()->comment('生成された仕入伝票番号');

            // 備考
            $table->text('note')->nullable()->comment('備考');

            $table->timestamps();

            // インデックス
            $table->index('warehouse_id', 'idx_warehouse');
            $table->index('item_id', 'idx_item');
            $table->index(['warehouse_id', 'item_id'], 'idx_warehouse_item');
            $table->index('order_candidate_id', 'idx_order_candidate');
            $table->index('status', 'idx_status');
            $table->index('expected_arrival_date', 'idx_expected_date');
            $table->index(['status', 'expected_arrival_date'], 'idx_status_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_incoming_schedules');
    }
};
