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
        Schema::connection($this->connection)->create('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->id();
            $table->string('process_name', 50)->comment('SATELLITE_CALC, HUB_CALC, ORDER_TRANSMISSION等');
            $table->char('batch_code', 14)->comment('バッチ実行ID (YYYYMMDDHHMMSS)');
            $table->enum('status', ['PENDING', 'RUNNING', 'SUCCESS', 'FAILED'])->default('PENDING');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->json('target_scope')->nullable()->comment('対象倉庫や期間などのパラメータ');
            $table->integer('total_records')->nullable();
            $table->integer('processed_records')->nullable();
            $table->text('error_details')->nullable();
            $table->timestamps();

            $table->index('batch_code', 'idx_batch_code');
            $table->index('status', 'idx_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_auto_order_job_controls');
    }
};
