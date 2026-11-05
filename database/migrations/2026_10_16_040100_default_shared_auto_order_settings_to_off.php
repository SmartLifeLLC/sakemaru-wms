<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // Only metadata defaults change. Existing rows are reset by the resumable
        // release initializer, with backups and client-specific count guards.
        DB::connection($this->connection)->statement('ALTER TABLE item_contractors
            ALTER COLUMN is_auto_order SET DEFAULT 0,
            ALTER COLUMN use_safety_stock_auto_update SET DEFAULT 0,
            ALTER COLUMN safety_stock SET DEFAULT 0,
            ALTER COLUMN max_stock SET DEFAULT 0,
            ALTER COLUMN purchase_unit SET DEFAULT 0');
    }

    public function down(): void
    {
        // Never reactivate old defaults as part of rolling the application back.
    }
};
