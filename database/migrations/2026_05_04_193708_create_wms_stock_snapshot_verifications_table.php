<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        DB::connection($this->connection)->statement(<<<'SQL'
CREATE TABLE wms_stock_snapshot_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning', 'evening') NOT NULL,
    summary_rows INT NOT NULL,
    lot_rows INT NOT NULL,
    summary_lot_mismatches INT NOT NULL DEFAULT 0,
    realtime_mismatches INT NOT NULL DEFAULT 0,
    realtime_total_diff BIGINT NOT NULL DEFAULT 0,
    row_count_ratio DECIMAL(5, 2) NULL,
    is_healthy BOOLEAN NOT NULL DEFAULT TRUE,
    details JSON NULL,
    captured_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_wms_stock_snapshot_verifications PRIMARY KEY (snapshot_date, id),
    UNIQUE KEY uk_sv_date_time (snapshot_date, snapshot_time),
    KEY idx_sv_id (id)
) ENGINE=InnoDB
SQL);

        $this->applyMonthlyPartitions('wms_stock_snapshot_verifications');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_stock_snapshot_verifications');
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
