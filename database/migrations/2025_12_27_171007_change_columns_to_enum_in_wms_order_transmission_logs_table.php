<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            ALTER TABLE wms_order_transmission_logs
            MODIFY COLUMN transmission_type ENUM('JX_FINET', 'FTP', 'MANUAL_CSV') NOT NULL COMMENT '送信方式'
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE wms_order_transmission_logs
            MODIFY COLUMN action ENUM('TRANSMIT', 'RETRY', 'CANCEL', 'CONFIRM') NOT NULL COMMENT 'アクション'
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE wms_order_transmission_logs
            MODIFY COLUMN status ENUM('SUCCESS', 'FAILED', 'PENDING') NOT NULL COMMENT 'ステータス'
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            ALTER TABLE wms_order_transmission_logs
            MODIFY COLUMN transmission_type VARCHAR(20) NOT NULL COMMENT 'JX_FINET, FTP'
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE wms_order_transmission_logs
            MODIFY COLUMN action VARCHAR(30) NOT NULL COMMENT 'TRANSMIT, RETRY, CANCEL, CONFIRM'
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE wms_order_transmission_logs
            MODIFY COLUMN status VARCHAR(20) NOT NULL COMMENT 'SUCCESS, FAILED'
        ");
    }
};
