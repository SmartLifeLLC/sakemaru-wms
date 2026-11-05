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
        if (Schema::connection($this->connection)->hasTable('wms_distribution_rows')) {
            $this->assertExistingTableIsCompatible();

            return;
        }

        Schema::connection($this->connection)->create('wms_distribution_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('mode', 20)->comment('allocation or direct');
            $table->unsignedBigInteger('warehouse_id')->default(0);
            $table->string('row_id', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->char('business_key', 40)->nullable();
            $table->string('source', 50)->nullable()->index();
            $table->string('source_key', 255)->nullable();
            $table->string('product_code', 100)->nullable()->index();
            $table->unsignedBigInteger('item_id')->nullable()->index();
            $table->json('row_data');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_id', 'mode', 'warehouse_id', 'row_id'], 'uniq_wms_distribution_rows_scope_row');
            $table->index(['client_id', 'mode', 'warehouse_id', 'sort_order'], 'idx_wms_distribution_rows_scope_order');
            $table->index(['client_id', 'mode', 'business_key'], 'idx_wms_distribution_rows_business_key');
        });
    }

    private function assertExistingTableIsCompatible(): void
    {
        $requiredColumns = [
            'id',
            'client_id',
            'mode',
            'warehouse_id',
            'row_id',
            'sort_order',
            'business_key',
            'source',
            'source_key',
            'product_code',
            'item_id',
            'row_data',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
        $columns = Schema::connection($this->connection)->getColumnListing('wms_distribution_rows');
        $missingColumns = array_values(array_diff($requiredColumns, $columns));

        if ($missingColumns !== []) {
            throw new RuntimeException(
                'Existing wms_distribution_rows table is incompatible. Missing columns: '.implode(', ', $missingColumns)
            );
        }

        $uniqueIndex = collect(DB::connection($this->connection)->select(
            "SHOW INDEX FROM wms_distribution_rows WHERE Key_name = 'uniq_wms_distribution_rows_scope_row'"
        ));
        $uniqueIndexColumns = $uniqueIndex
            ->sortBy('Seq_in_index')
            ->pluck('Column_name')
            ->values()
            ->all();
        $isUniqueBtree = $uniqueIndex->isNotEmpty()
            && $uniqueIndex->every(fn (object $index): bool => (int) $index->Non_unique === 0
                && strtoupper((string) $index->Index_type) === 'BTREE');

        if (! $isUniqueBtree || $uniqueIndexColumns !== ['client_id', 'mode', 'warehouse_id', 'row_id']) {
            throw new RuntimeException(
                'Existing wms_distribution_rows table is incompatible. Required UNIQUE BTREE index uniq_wms_distribution_rows_scope_row is missing or invalid.'
            );
        }
    }

    public function down(): void
    {
        // This migration may adopt a table created before migration tracking.
        // Never drop persisted distribution data during rollback.
    }
};
