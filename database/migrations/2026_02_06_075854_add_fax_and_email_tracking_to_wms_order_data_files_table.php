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
        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            // Rename existing columns for clarity (CSV tracking)
            $table->renameColumn('downloaded_at', 'csv_downloaded_at');
            $table->renameColumn('downloaded_by', 'csv_downloaded_by');
        });

        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            // FAX PDF tracking
            $table->string('fax_file_path')->nullable()->after('file_size')->comment('FAX PDFファイルパス');
            $table->timestamp('fax_downloaded_at')->nullable()->after('fax_file_path')->comment('FAXダウンロード日時');
            $table->unsignedBigInteger('fax_downloaded_by')->nullable()->after('fax_downloaded_at')->comment('FAXダウンロードユーザーID');

            // Email tracking
            $table->timestamp('mail_sent_at')->nullable()->after('fax_downloaded_by')->comment('メール送信日時');
            $table->unsignedBigInteger('mail_sent_by')->nullable()->after('mail_sent_at')->comment('メール送信ユーザーID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            $table->dropColumn([
                'fax_file_path',
                'fax_downloaded_at',
                'fax_downloaded_by',
                'mail_sent_at',
                'mail_sent_by',
            ]);
        });

        Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
            $table->renameColumn('csv_downloaded_at', 'downloaded_at');
            $table->renameColumn('csv_downloaded_by', 'downloaded_by');
        });
    }
};
