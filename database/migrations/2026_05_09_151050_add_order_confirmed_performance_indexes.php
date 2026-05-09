<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const ORDER_CANDIDATES_INDEX = 'idx_wms_order_confirmed_sort';

    private const ITEM_CONTRACTORS_INDEX = 'idx_item_contractors_order_settings';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->indexExists('wms_order_candidates', self::ORDER_CANDIDATES_INDEX)) {
            Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
                $table->index(
                    ['status', 'warehouse_id', 'batch_code', 'item_id'],
                    self::ORDER_CANDIDATES_INDEX
                );
            });
        }

        if (! $this->indexExists('item_contractors', self::ITEM_CONTRACTORS_INDEX)) {
            Schema::connection($this->connection)->table('item_contractors', function (Blueprint $table) {
                $table->index(
                    ['warehouse_id', 'item_id', 'contractor_id'],
                    self::ITEM_CONTRACTORS_INDEX
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->indexExists('item_contractors', self::ITEM_CONTRACTORS_INDEX)) {
            Schema::connection($this->connection)->table('item_contractors', function (Blueprint $table) {
                $table->dropIndex(self::ITEM_CONTRACTORS_INDEX);
            });
        }

        if ($this->indexExists('wms_order_candidates', self::ORDER_CANDIDATES_INDEX)) {
            Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
                $table->dropIndex(self::ORDER_CANDIDATES_INDEX);
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
