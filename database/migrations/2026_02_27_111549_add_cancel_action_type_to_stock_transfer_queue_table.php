<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE stock_transfer_queue
            MODIFY COLUMN action_type ENUM('CREATE','UPDATE','DELIVER','CANCEL')
                NOT NULL DEFAULT 'CREATE'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE stock_transfer_queue
            MODIFY COLUMN action_type ENUM('CREATE','UPDATE','DELIVER')
                NOT NULL DEFAULT 'CREATE'
        ");
    }
};
