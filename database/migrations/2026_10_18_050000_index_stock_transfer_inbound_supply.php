<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasIndex('stock_transfers', 'stock_transfers_inbound_scope_idx')) {
            return;
        }
        $db = DB::connection($this->connection);
        if ($db->getDriverName() === 'mysql') {
            // Never fall back to a blocking table copy on the shared business table.
            $timeout = (int) $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
            try {
                $db->statement('SET SESSION lock_wait_timeout = 5');
                $db->statement('ALTER TABLE stock_transfers ADD INDEX stock_transfers_inbound_scope_idx (client_id, to_warehouse_id, is_active, is_delivered), ALGORITHM=INPLACE, LOCK=NONE');
            } finally {
                $db->statement('SET SESSION lock_wait_timeout = '.$timeout);
            }

            return;
        }
        $schema->table('stock_transfers', fn (Blueprint $table) => $table->index(
            ['client_id', 'to_warehouse_id', 'is_active', 'is_delivered'], 'stock_transfers_inbound_scope_idx'));
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasIndex('stock_transfers', 'stock_transfers_inbound_scope_idx')) {
            $schema->table('stock_transfers', fn (Blueprint $table) => $table->dropIndex('stock_transfers_inbound_scope_idx'));
        }
    }
};
