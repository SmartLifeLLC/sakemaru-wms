<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // status ENUM に TRANSMITTED を追加
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN status ENUM('PENDING', 'PARTIAL', 'CONFIRMED', 'TRANSMITTED', 'CANCELLED')
            NOT NULL DEFAULT 'PENDING'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // TRANSMITTED を削除
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_order_incoming_schedules
            MODIFY COLUMN status ENUM('PENDING', 'PARTIAL', 'CONFIRMED', 'CANCELLED')
            NOT NULL DEFAULT 'PENDING'
        ");
    }
};
