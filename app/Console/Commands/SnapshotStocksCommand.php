<?php

namespace App\Console\Commands;

use App\Services\StockSnapshotService;
use Illuminate\Console\Command;

class SnapshotStocksCommand extends Command
{
    protected $signature = 'wms:snapshot-stocks {--time= : Snapshot time (morning or evening)}';

    protected $description = 'Capture periodic WMS stock snapshots';

    public function handle(StockSnapshotService $service): int
    {
        $time = $this->option('time') ?: (now()->hour < 12 ? 'morning' : 'evening');

        if (! in_array($time, ['morning', 'evening'], true)) {
            $this->error('--time must be morning or evening');

            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $this->info('['.now()->format('Y-m-d H:i:s')."] Starting stock snapshot ({$time})...");

        try {
            $result = $service->capture($time);
        } catch (\Throwable $e) {
            $this->error('Stock snapshot failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $verification = $result['verification'];

        $this->info('['.now()->format('Y-m-d H:i:s')."] Summary: {$result['summary_rows']} rows");
        $this->info('['.now()->format('Y-m-d H:i:s')."] Lot details: {$result['lot_rows']} rows");
        $this->line('['.now()->format('Y-m-d H:i:s').'] Verification:');
        $this->line("  - Summary <-> Lot: {$verification['summary_lot_mismatches']} mismatches");
        $this->line("  - Realtime drift: {$verification['realtime_mismatches']} mismatches (total diff: {$verification['realtime_total_diff']})");
        $ratio = $verification['row_count_ratio'] ?? 'N/A';
        $this->line("  - Row count ratio: {$ratio}");
        $this->line('  - Healthy: '.($verification['is_healthy'] ? 'yes' : 'no'));

        $elapsed = round(microtime(true) - $startedAt, 2);
        $this->info('['.now()->format('Y-m-d H:i:s')."] Snapshot completed in {$elapsed}s");

        return self::SUCCESS;
    }
}
