<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'created_by_name')) {
            Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
                $table->string('created_by_name')->nullable()->comment('作成者名');
            });
        }

        DB::connection($this->connection)->statement('
            UPDATE wms_order_data_files f
            JOIN (
                SELECT batch_code, MAX(id) AS job_control_id
                FROM wms_auto_order_job_controls
                WHERE created_by IS NOT NULL
                GROUP BY batch_code
            ) latest ON latest.batch_code = f.batch_code
            JOIN wms_auto_order_job_controls j ON j.id = latest.job_control_id
            JOIN users u ON u.id = j.created_by
            SET f.created_by_name = u.name
            WHERE f.created_by_name IS NULL
        ');
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('wms_order_data_files', 'created_by_name')) {
            Schema::connection($this->connection)->table('wms_order_data_files', function (Blueprint $table) {
                $table->dropColumn('created_by_name');
            });
        }
    }
};
