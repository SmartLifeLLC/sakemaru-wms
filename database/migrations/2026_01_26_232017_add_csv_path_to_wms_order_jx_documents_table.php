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
        Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
            $table->string('csv_path')->nullable()->after('file_path')->comment('確認用CSVファイルパス');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
            $table->dropColumn('csv_path');
        });
    }
};
