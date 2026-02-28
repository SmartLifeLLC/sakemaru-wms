<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE wms_auto_order_execution_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contractor_id BIGINT UNSIGNED NOT NULL COMMENT '仕入先ID',
                executed_date DATE NOT NULL COMMENT '実行日',
                job_control_id BIGINT UNSIGNED NULL COMMENT '関連するwms_auto_order_job_controls.id',
                status ENUM('RUNNING', 'SUCCESS', 'FAILED') NOT NULL DEFAULT 'RUNNING' COMMENT '実行結果',
                error_details TEXT NULL COMMENT 'FAILED時の原因',
                started_at DATETIME NOT NULL COMMENT '実行開始日時',
                finished_at DATETIME NULL COMMENT '実行完了日時',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,

                INDEX idx_contractor_date (contractor_id, executed_date),
                INDEX idx_executed_date (executed_date),
                INDEX idx_status (status)
            ) COMMENT '仕入先別自動発注実行ログ'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS wms_auto_order_execution_log');
    }
};
