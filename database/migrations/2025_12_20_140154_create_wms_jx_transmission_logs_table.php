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
        Schema::connection('sakemaru')->create('wms_jx_transmission_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jx_setting_id')->nullable()->comment('JX接続設定ID');
            $table->string('direction', 10)->comment('送受信方向: send/receive');
            $table->string('operation_type', 20)->comment('操作タイプ: PutDocument/GetDocument/ConfirmDocument');
            $table->string('message_id', 255)->comment('メッセージID');
            $table->string('document_type', 10)->nullable()->comment('ドキュメントタイプ');
            $table->string('format_type', 50)->nullable()->comment('フォーマットタイプ');
            $table->string('sender_id', 100)->nullable()->comment('送信者ID');
            $table->string('receiver_id', 100)->nullable()->comment('受信者ID');
            $table->string('status', 20)->comment('ステータス: success/failure');
            $table->text('error_message')->nullable()->comment('エラーメッセージ');
            $table->unsignedInteger('data_size')->nullable()->comment('データサイズ（バイト）');
            $table->string('file_path', 500)->nullable()->comment('保存先ファイルパス');
            $table->integer('http_code')->nullable()->comment('HTTPステータスコード');
            $table->timestamp('transmitted_at')->comment('送受信日時');
            $table->timestamps();

            $table->index('jx_setting_id');
            $table->index('direction');
            $table->index('status');
            $table->index('transmitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_jx_transmission_logs');
    }
};
