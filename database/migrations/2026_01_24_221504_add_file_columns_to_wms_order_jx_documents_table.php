<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * wms_order_jx_documents にファイル関連カラムを追加
 */
return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('wms_order_jx_setting_id')->nullable()->after('batch_code')->comment('JX設定ID');
            $table->string('file_path', 500)->nullable()->after('status')->comment('S3ファイルパス');
            $table->unsignedInteger('file_size')->nullable()->after('file_path')->comment('ファイルサイズ（bytes）');
            $table->unsignedInteger('record_count')->nullable()->after('file_size')->comment('レコード数');
            $table->unsignedInteger('order_count')->nullable()->after('record_count')->comment('発注件数');
            $table->string('encoding', 20)->nullable()->after('order_count')->comment('文字コード');
            $table->string('jx_message_id', 100)->nullable()->after('jx_document_no')->comment('JXメッセージID');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
            $table->dropColumn([
                'wms_order_jx_setting_id',
                'file_path',
                'file_size',
                'record_count',
                'order_count',
                'encoding',
                'jx_message_id',
            ]);
        });
    }
};
