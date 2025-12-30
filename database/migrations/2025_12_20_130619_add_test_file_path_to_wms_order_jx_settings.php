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
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->string('test_file_path', 500)->nullable()->after('ssl_certification_file')->comment('テスト送信用ファイルパス（S3）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropColumn('test_file_path');
        });
    }
};
