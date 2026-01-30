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
        Schema::connection('sakemaru')->create('wms_queue_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_type', 50)->comment('ジョブ種別 (order_create, demand_distribution, etc)');
            $table->json('payload')->comment('ジョブパラメータ');
            $table->string('status', 20)->default('pending')->comment('pending/processing/completed/failed');
            $table->unsignedInteger('priority')->default(10)->comment('優先度（0=最高）');
            $table->unsignedInteger('attempts')->default(0)->comment('試行回数');
            $table->unsignedInteger('max_attempts')->default(3)->comment('最大試行回数');
            $table->string('source_system', 20)->nullable()->comment('依頼元システム (trade/wms/batch)');
            $table->unsignedBigInteger('source_user_id')->nullable()->comment('依頼元ユーザーID');
            $table->string('source_reference_type', 50)->nullable()->comment('依頼元参照テーブル');
            $table->unsignedBigInteger('source_reference_id')->nullable()->comment('依頼元参照ID');
            $table->json('result')->nullable()->comment('処理結果');
            $table->text('error_message')->nullable()->comment('エラーメッセージ');
            $table->timestamp('started_at')->nullable()->comment('処理開始日時');
            $table->timestamp('completed_at')->nullable()->comment('処理完了日時');
            $table->timestamps();

            $table->index(['job_type', 'status']);
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['source_system', 'source_reference_type', 'source_reference_id'], 'wms_queue_jobs_source_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_queue_jobs');
    }
};
