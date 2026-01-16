<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * wms_reservations.source_type に STOCK_TRANSFER を追加
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement(
            "ALTER TABLE wms_reservations MODIFY COLUMN source_type ENUM('EARNING', 'PURCHASE', 'REPLENISH', 'COUNT', 'MOVE', 'STOCK_TRANSFER') NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reverting this could cause data loss if STOCK_TRANSFER values exist
        DB::connection($this->connection)->statement(
            "ALTER TABLE wms_reservations MODIFY COLUMN source_type ENUM('EARNING', 'PURCHASE', 'REPLENISH', 'COUNT', 'MOVE') NOT NULL"
        );
    }
};
