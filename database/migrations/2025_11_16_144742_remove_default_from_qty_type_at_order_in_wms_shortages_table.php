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
        // Remove default value from qty_type_at_order - must be explicitly specified
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortages
            MODIFY COLUMN qty_type_at_order ENUM('CASE', 'PIECE', 'CARTON') NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore default value 'PIECE'
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_shortages
            MODIFY COLUMN qty_type_at_order ENUM('CASE', 'PIECE', 'CARTON') NOT NULL DEFAULT 'PIECE'
        ");
    }
};
