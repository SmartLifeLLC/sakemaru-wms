<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_stock_snapshots', function (Blueprint $table) {
            $table->date('snapshot_date');
            $table->enum('snapshot_time', ['morning', 'evening']);
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->integer('current_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('available_quantity')->default(0);
            $table->integer('incoming_quantity')->default(0);
            $table->unsignedInteger('stock_count')->default(0);
            $table->dateTime('captured_at', 6);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['snapshot_date', 'snapshot_time', 'warehouse_id', 'item_id'], 'pk_wms_stock_snapshots');
            $table->index(['item_id', 'snapshot_date'], 'idx_ss_item');
            $table->index(['warehouse_id', 'snapshot_date'], 'idx_ss_warehouse');
        });

        $this->applyMonthlyPartitions('wms_stock_snapshots');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_stock_snapshots');
    }

    private function applyMonthlyPartitions(string $table): void
    {
        $partitions = [];
        $start = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i <= 16; $i++) {
            $month = $start->addMonths($i);
            $next = $month->addMonth();
            $partitions[] = sprintf(
                "PARTITION p%s VALUES LESS THAN (TO_DAYS('%s'))",
                $month->format('Ym'),
                $next->format('Y-m-d')
            );
        }

        DB::connection($this->connection)->statement(sprintf(
            'ALTER TABLE %s PARTITION BY RANGE (TO_DAYS(snapshot_date)) (%s)',
            $table,
            implode(', ', $partitions)
        ));
    }
};
