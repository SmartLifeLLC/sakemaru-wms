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
        if (Schema::connection($this->connection)->hasTable('wms_distribution_revisions')) {
            $this->assertExistingTableIsCompatible();

            return;
        }

        Schema::connection($this->connection)->create('wms_distribution_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('mode', 20);
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(
                ['client_id', 'mode', 'warehouse_id'],
                'uniq_wms_distribution_revisions_scope'
            );
        });
    }

    private function assertExistingTableIsCompatible(): void
    {
        $requiredColumns = [
            'id',
            'client_id',
            'mode',
            'warehouse_id',
            'revision',
            'created_at',
            'updated_at',
        ];
        $columns = Schema::connection($this->connection)->getColumnListing('wms_distribution_revisions');
        $missingColumns = array_values(array_diff($requiredColumns, $columns));

        if ($missingColumns !== []) {
            throw new RuntimeException(
                'Existing wms_distribution_revisions table is incompatible. Missing columns: '.implode(', ', $missingColumns)
            );
        }

        $uniqueIndex = collect(DB::connection($this->connection)->select(
            "SHOW INDEX FROM wms_distribution_revisions WHERE Key_name = 'uniq_wms_distribution_revisions_scope'"
        ));
        $uniqueIndexColumns = $uniqueIndex
            ->sortBy('Seq_in_index')
            ->pluck('Column_name')
            ->values()
            ->all();
        $isUniqueBtree = $uniqueIndex->isNotEmpty()
            && $uniqueIndex->every(fn (object $index): bool => (int) $index->Non_unique === 0
                && strtoupper((string) $index->Index_type) === 'BTREE');

        if (! $isUniqueBtree || $uniqueIndexColumns !== ['client_id', 'mode', 'warehouse_id']) {
            throw new RuntimeException(
                'Existing wms_distribution_revisions table is incompatible. Required UNIQUE BTREE index uniq_wms_distribution_revisions_scope is missing or invalid.'
            );
        }
    }

    public function down(): void
    {
        // Preserve optimistic-lock state during rollback.
    }
};
