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
        Schema::connection('sakemaru')->create('wms_picking_logs', function (Blueprint $table) {
            $table->id();

            // Who
            $table->unsignedBigInteger('picker_id')->nullable()->comment('ピッカーID');
            $table->string('picker_code', 50)->nullable()->comment('ピッカーコード');
            $table->string('picker_name', 100)->nullable()->comment('ピッカー名');

            // What action
            $table->string('action_type', 50)->comment('操作種類: START, PICK, COMPLETE, LOGIN, LOGOUT');
            $table->string('endpoint', 255)->nullable()->comment('APIエンドポイント');
            $table->string('http_method', 10)->nullable()->comment('HTTPメソッド: GET, POST, PUT, DELETE');

            // Related entities
            $table->unsignedBigInteger('picking_task_id')->nullable()->comment('ピッキングタスクID');
            $table->unsignedBigInteger('picking_item_result_id')->nullable()->comment('ピッキング品目結果ID');
            $table->unsignedBigInteger('wave_id')->nullable()->comment('WaveID');
            $table->unsignedBigInteger('earning_id')->nullable()->comment('売上ID（伝票番号）');

            // Item and stock info
            $table->unsignedBigInteger('item_id')->nullable()->comment('商品ID');
            $table->string('item_code', 50)->nullable()->comment('商品コード');
            $table->string('item_name', 255)->nullable()->comment('商品名');
            $table->unsignedBigInteger('real_stock_id')->nullable()->comment('実在庫ID');
            $table->unsignedBigInteger('location_id')->nullable()->comment('ロケーションID');

            // Quantity changes
            $table->decimal('planned_qty', 10, 2)->nullable()->comment('予定数量');
            $table->string('planned_qty_type', 20)->nullable()->comment('予定数量タイプ: CASE, PIECE');
            $table->decimal('picked_qty', 10, 2)->nullable()->comment('ピッキング数量');
            $table->string('picked_qty_type', 20)->nullable()->comment('ピッキング数量タイプ');
            $table->decimal('shortage_qty', 10, 2)->nullable()->comment('欠品数量');

            // Stock changes tracking
            $table->decimal('stock_qty_before', 10, 2)->nullable()->comment('在庫数量（変更前）');
            $table->decimal('stock_qty_after', 10, 2)->nullable()->comment('在庫数量（変更後）');
            $table->decimal('reserved_qty_before', 10, 2)->nullable()->comment('引当数量（変更前）');
            $table->decimal('reserved_qty_after', 10, 2)->nullable()->comment('引当数量（変更後）');
            $table->decimal('picking_qty_before', 10, 2)->nullable()->comment('ピッキング中数量（変更前）');
            $table->decimal('picking_qty_after', 10, 2)->nullable()->comment('ピッキング中数量（変更後）');

            // Status changes
            $table->string('status_before', 50)->nullable()->comment('ステータス（変更前）');
            $table->string('status_after', 50)->nullable()->comment('ステータス（変更後）');

            // Request/Response data
            $table->json('request_data')->nullable()->comment('リクエストデータ');
            $table->json('response_data')->nullable()->comment('レスポンスデータ');
            $table->integer('response_status_code')->nullable()->comment('HTTPレスポンスコード');

            // Client info
            $table->string('ip_address', 45)->nullable()->comment('IPアドレス');
            $table->text('user_agent')->nullable()->comment('ユーザーエージェント');
            $table->string('device_id', 100)->nullable()->comment('デバイスID');

            // Timestamps
            $table->timestamp('created_at')->useCurrent()->comment('作成日時');

            // Indexes
            $table->index('picker_id');
            $table->index('picking_task_id');
            $table->index('picking_item_result_id');
            $table->index('action_type');
            $table->index('created_at');
            $table->index(['picker_id', 'created_at']);
            $table->index(['picking_task_id', 'action_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_picking_logs');
    }
};
