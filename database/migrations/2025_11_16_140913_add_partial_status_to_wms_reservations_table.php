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
        // Add PARTIAL status to wms_reservations.status enum
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_reservations
            MODIFY COLUMN status ENUM(
                'RESERVED',
                'PARTIAL',
                'RELEASED',
                'CONSUMED',
                'CANCELLED',
                'SHORTAGE'
            ) DEFAULT 'RESERVED'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove PARTIAL status from enum (reset PARTIAL records to RESERVED first)
        DB::connection('sakemaru')->statement("
            UPDATE wms_reservations
            SET status = 'RESERVED'
            WHERE status = 'PARTIAL'
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_reservations
            MODIFY COLUMN status ENUM(
                'RESERVED',
                'RELEASED',
                'CONSUMED',
                'CANCELLED',
                'SHORTAGE'
            ) DEFAULT 'RESERVED'
        ");
    }
};
