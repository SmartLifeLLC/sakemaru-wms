<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_order_transmission_logs', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 20)->index();
            $table->unsignedBigInteger('wms_order_jx_document_id')->nullable();
            $table->string('transmission_type', 20)->comment('JX_FINET, FTP');
            $table->string('action', 30)->comment('TRANSMIT, RETRY, CANCEL, CONFIRM');
            $table->string('status', 20)->comment('SUCCESS, FAILED');
            $table->text('request_data')->nullable();
            $table->text('response_data')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedBigInteger('executed_by')->nullable();
            $table->timestamps();

            $table->index(['wms_order_jx_document_id', 'created_at']);
            $table->index(['transmission_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_transmission_logs');
    }
};
