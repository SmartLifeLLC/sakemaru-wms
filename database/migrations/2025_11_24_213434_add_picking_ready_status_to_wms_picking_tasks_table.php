<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add PICKING_READY status to wms_picking_tasks.status enum
     * Status flow: PENDING → PICKING_READY → PICKING → COMPLETED
     */
    public function up(): void
    {
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_tasks
            MODIFY COLUMN status ENUM('PENDING', 'PICKING_READY', 'PICKING', 'SHORTAGE', 'COMPLETED')
            NOT NULL
            DEFAULT 'PENDING'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, revert any PICKING_READY statuses back to PENDING
        DB::connection('sakemaru')->statement("
            UPDATE wms_picking_tasks
            SET status = 'PENDING'
            WHERE status = 'PICKING_READY'
        ");

        // Then remove PICKING_READY from the enum
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_tasks
            MODIFY COLUMN status ENUM('PENDING', 'PICKING', 'SHORTAGE', 'COMPLETED')
            NOT NULL
            DEFAULT 'PENDING'
        ");
    }
};
