<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_order_jx_documents', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code', 20)->index();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('contractor_id');
            $table->string('document_type', 20)->comment('PURCHASE, TRANSFER');
            $table->string('jx_document_no', 50)->nullable()->unique()->comment('JX伝票番号');
            $table->string('status', 20)->default('PENDING')->comment('PENDING, TRANSMITTED, CONFIRMED, ERROR');
            $table->integer('total_items')->default(0);
            $table->integer('total_quantity')->default(0);
            $table->text('jx_request_data')->nullable();
            $table->text('jx_response_data')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('transmitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('transmitted_by')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'contractor_id']);
            $table->index(['status', 'batch_code']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_order_jx_documents');
    }
};
