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
        Schema::connection('sakemaru')->create('wms_queue_progress', function (Blueprint $table) {
            $table->id();
            $table->string('job_type', 100)->comment('ジョブ種別 (例: order_confirmation, csv_generation)');
            $table->string('job_id', 100)->unique()->comment('ジョブ識別子 (UUID)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('実行ユーザーID');
            $table->string('status', 20)->default('pending')->comment('pending, processing, completed, failed');
            $table->unsignedInteger('progress')->default(0)->comment('進捗率 (0-100)');
            $table->unsignedInteger('total_items')->default(0)->comment('処理対象総数');
            $table->unsignedInteger('processed_items')->default(0)->comment('処理済み数');
            $table->text('message')->nullable()->comment('現在の処理内容・エラーメッセージ');
            $table->json('result')->nullable()->comment('処理結果データ');
            $table->json('metadata')->nullable()->comment('追加メタデータ');
            $table->timestamp('started_at')->nullable()->comment('処理開始日時');
            $table->timestamp('completed_at')->nullable()->comment('処理完了日時');
            $table->timestamps();

            $table->index(['job_type', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_queue_progress');
    }
};
