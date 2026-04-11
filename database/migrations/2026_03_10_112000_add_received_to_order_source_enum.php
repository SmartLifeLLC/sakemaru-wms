<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_order_incoming_schedules MODIFY COLUMN order_source ENUM('AUTO', 'MANUAL', 'TRANSFER', 'RECEIVED') DEFAULT 'MANUAL'"
        );
    }

    public function down(): void
    {
        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_order_incoming_schedules MODIFY COLUMN order_source ENUM('AUTO', 'MANUAL', 'TRANSFER') DEFAULT 'MANUAL'"
        );
    }
};
