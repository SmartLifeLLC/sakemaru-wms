<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->create('wms_incoming_import_errors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('received_file_id')->comment('取込ファイルID');
            $table->unsignedBigInteger('received_slip_id')->nullable()->comment('伝票ID');
            $table->unsignedBigInteger('received_detail_id')->nullable()->comment('明細ID');
            $table->enum('error_type', ['ERROR', 'WARNING'])->comment('エラー種別');
            $table->string('error_code', 50)->comment('エラーコード');
            $table->text('error_message')->comment('エラーメッセージ');
            $table->json('raw_data')->nullable()->comment('元データ（全項目）');
            $table->string('item_code', 20)->nullable()->comment('照合試行した商品コード');
            $table->decimal('expected_price', 12, 2)->nullable()->comment('自社単価');
            $table->decimal('actual_price', 12, 2)->nullable()->comment('仕入先単価');
            $table->boolean('is_resolved')->default(false)->comment('解決済みフラグ');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->datetime('resolved_at')->nullable();
            $table->timestamps();

            $table->index('received_file_id');
            $table->index(['error_type', 'is_resolved']);
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_incoming_import_errors');
    }
};
