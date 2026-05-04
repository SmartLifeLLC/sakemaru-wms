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
CREATE TABLE wms_stock_snapshot_lots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning', 'evening') NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    real_stock_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    floor_id BIGINT UNSIGNED NULL,
    expiration_date DATE NULL,
    purchase_id BIGINT UNSIGNED NULL,
    current_quantity INT NOT NULL DEFAULT 0,
    reserved_quantity INT NOT NULL DEFAULT 0,
    price DECIMAL(10, 2) NULL,
    real_stock_received_at DATETIME NULL,
    lot_created_at DATETIME NULL,
    captured_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_wms_stock_snapshot_lots PRIMARY KEY (snapshot_date, id),
    UNIQUE KEY uk_ssl_snapshot_lot (snapshot_date, snapshot_time, lot_id),
    KEY idx_ssl_lookup (snapshot_date, snapshot_time, warehouse_id, item_id),
    KEY idx_ssl_location (snapshot_date, snapshot_time, location_id),
    KEY idx_ssl_id (id)
) ENGINE=InnoDB
SQL);

        $this->applyMonthlyPartitions('wms_stock_snapshot_lots');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_stock_snapshot_lots');
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
