<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->json('result_data')->nullable()->after('error_details')->comment('処理結果データ（JSON）');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_auto_order_job_controls', function (Blueprint $table) {
            $table->dropColumn('result_data');
        });
    }
};
