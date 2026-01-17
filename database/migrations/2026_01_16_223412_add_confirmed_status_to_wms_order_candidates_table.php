<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * wms_order_candidates.status ENUMに CONFIRMED を追加
     * APPROVED と EXCLUDED の間に配置
     */
    public function up(): void
    {
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_candidates
            MODIFY COLUMN status ENUM('PENDING','APPROVED','CONFIRMED','EXCLUDED','EXECUTED')
            NOT NULL DEFAULT 'PENDING'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // CONFIRMEDステータスのレコードをAPPROVEDに戻す
        DB::connection('sakemaru')->statement("
            UPDATE wms_order_candidates SET status = 'APPROVED' WHERE status = 'CONFIRMED'
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_candidates
            MODIFY COLUMN status ENUM('PENDING','APPROVED','EXCLUDED','EXECUTED')
            NOT NULL DEFAULT 'PENDING'
        ");
    }
};
