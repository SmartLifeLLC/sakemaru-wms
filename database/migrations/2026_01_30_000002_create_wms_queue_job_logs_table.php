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
        Schema::connection('sakemaru')->create('wms_queue_job_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('queue_job_id')->comment('FK: wms_queue_jobs.id');
            $table->string('level', 20)->default('info')->comment('info/warning/error');
            $table->text('message')->comment('ログメッセージ');
            $table->json('context')->nullable()->comment('コンテキスト情報');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['queue_job_id', 'level']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_queue_job_logs');
    }
};
