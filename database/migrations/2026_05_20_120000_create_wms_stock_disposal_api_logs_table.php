<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_stock_disposal_api_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('picker_id')->comment('wms_pickers.id');
            $table->string('picker_code', 64)->nullable()->comment('操作ピッカーコード snapshot');
            $table->string('picker_name', 255)->nullable()->comment('操作ピッカー名 snapshot');
            $table->string('request_id', 255)->nullable()->comment('Android/WMS request id');
            $table->unsignedBigInteger('queue_id')->nullable()->comment('stock_disposal_queue.id');
            $table->string('warehouse_code', 64)->comment('調節倉庫コード');
            $table->string('reason', 64)->comment('調節理由');
            $table->date('process_date')->nullable()->comment('処理日');
            $table->date('disposal_date')->nullable()->comment('調節日');
            $table->string('slip_number', 255)->nullable()->comment('伝票番号');
            $table->unsignedInteger('detail_count')->default(0)->comment('明細件数');
            $table->json('request_payload')->comment('登録リクエスト payload');
            $table->string('result_status', 32)->default('REQUESTED')->comment('REQUESTED, QUEUED, DUPLICATED, FAILED');
            $table->text('error_message')->nullable()->comment('エラー内容');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('picker_id', 'idx_wms_sdal_picker');
            $table->index('request_id', 'idx_wms_sdal_request');
            $table->index('queue_id', 'idx_wms_sdal_queue');
            $table->index(['warehouse_code', 'created_at'], 'idx_wms_sdal_warehouse_created');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_stock_disposal_api_logs');
    }
};
