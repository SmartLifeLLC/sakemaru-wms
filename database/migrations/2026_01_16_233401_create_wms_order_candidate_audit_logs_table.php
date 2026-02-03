<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 発注候補の変更履歴を記録するテーブル
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_order_candidate_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_candidate_id')->index();
            $table->string('batch_code', 20)->index();
            $table->string('action', 50); // created, status_changed, quantity_changed, excluded, confirmed, transmitted
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->integer('old_quantity')->nullable();
            $table->integer('new_quantity')->nullable();
            $table->json('changes')->nullable(); // その他の変更内容をJSON形式で保存
            $table->text('reason')->nullable(); // 変更理由（除外理由など）
            $table->unsignedBigInteger('performed_by')->nullable()->index(); // 実行者
            $table->string('performed_by_name')->nullable(); // 実行者名（スナップショット）
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['order_candidate_id', 'created_at'], 'wms_oca_logs_candidate_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_order_candidate_audit_logs');
    }
};
