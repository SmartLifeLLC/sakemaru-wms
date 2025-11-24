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
        Schema::connection('sakemaru')->create('wms_admin_operation_logs', function (Blueprint $table) {
            $table->id();

            // 管理者情報
            $table->unsignedBigInteger('user_id')->nullable()->comment('管理者ユーザーID');

            // 操作種類
            $table->enum('operation_type', [
                'ASSIGN_PICKER',
                'UNASSIGN_PICKER',
                'CHANGE_DELIVERY_COURSE',
                'CHANGE_WAREHOUSE',
                'ADJUST_PICKING_QTY',
                'REVERT_PICKING',
                'PRINT_SHIPMENT_SLIP',
                'FORCE_PRINT_SHIPMENT_SLIP',
                'FORCE_SHIP',
            ])->comment('操作種類');

            // 対象エンティティ
            $table->enum('target_type', [
                'picking_task',
                'picking_item',
                'wave',
                'earning',
            ])->nullable()->comment('対象タイプ');
            $table->unsignedBigInteger('target_id')->nullable()->comment('対象ID');

            // 関連エンティティ
            $table->unsignedBigInteger('picking_task_id')->nullable()->comment('ピッキングタスクID');
            $table->unsignedBigInteger('picking_item_result_id')->nullable()->comment('ピッキング明細結果ID');
            $table->unsignedBigInteger('wave_id')->nullable()->comment('WaveID');
            $table->unsignedBigInteger('earning_id')->nullable()->comment('売上ID（伝票）');

            // ピッカー関連
            $table->unsignedBigInteger('picker_id_before')->nullable()->comment('ピッカーID（変更前）');
            $table->unsignedBigInteger('picker_id_after')->nullable()->comment('ピッカーID（変更後）');

            // 配送コース関連
            $table->unsignedBigInteger('delivery_course_id_before')->nullable()->comment('配送コースID（変更前）');
            $table->unsignedBigInteger('delivery_course_id_after')->nullable()->comment('配送コースID（変更後）');

            // 倉庫関連
            $table->unsignedBigInteger('warehouse_id_before')->nullable()->comment('倉庫ID（変更前）');
            $table->unsignedBigInteger('warehouse_id_after')->nullable()->comment('倉庫ID（変更後）');

            // 数量関連
            $table->integer('qty_before')->nullable()->comment('数量（変更前）');
            $table->integer('qty_after')->nullable()->comment('数量（変更後）');
            $table->string('qty_type', 20)->nullable()->comment('数量タイプ: CASE, PIECE');

            // ステータス関連
            $table->string('status_before', 50)->nullable()->comment('ステータス（変更前）');
            $table->string('status_after', 50)->nullable()->comment('ステータス（変更後）');

            // 詳細情報
            $table->json('operation_details')->nullable()->comment('操作詳細（JSON）');
            $table->text('operation_note')->nullable()->comment('操作の説明・理由');

            // 一括操作の場合
            $table->integer('affected_count')->nullable()->comment('影響を受けたレコード数（一括操作時）');
            $table->json('affected_ids')->nullable()->comment('影響を受けたID一覧（一括操作時）');

            // クライアント情報
            $table->string('ip_address', 45)->nullable()->comment('IPアドレス');
            $table->text('user_agent')->nullable()->comment('ユーザーエージェント');

            // タイムスタンプ
            $table->timestamp('created_at')->useCurrent()->comment('作成日時');

            // インデックス
            $table->index('user_id');
            $table->index('operation_type');
            $table->index('target_type');
            $table->index('target_id');
            $table->index('picking_task_id');
            $table->index('wave_id');
            $table->index('earning_id');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['operation_type', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_admin_operation_logs');
    }
};
