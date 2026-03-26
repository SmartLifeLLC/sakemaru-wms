<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_picking_tasks MODIFY COLUMN status ENUM('PENDING','PICKING_READY','PICKING','SHORTAGE','COMPLETED','SHIPPED') NOT NULL DEFAULT 'PENDING'"
        );
    }

    public function down(): void
    {
        DB::connection('sakemaru')->statement(
            "ALTER TABLE wms_picking_tasks MODIFY COLUMN status ENUM('PENDING','PICKING_READY','PICKING','SHORTAGE','COMPLETED') NOT NULL DEFAULT 'PENDING'"
        );
    }
};
