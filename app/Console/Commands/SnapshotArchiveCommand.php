<?php

namespace App\Console\Commands;

use App\Services\StockSnapshotService;
use Illuminate\Console\Command;

class SnapshotArchiveCommand extends Command
{
    protected $signature = 'wms:snapshot-archive
                            {--dry-run : Show archive targets without uploading or dropping partitions}
                            {--disk=s3 : Filesystem disk for archive output}';

    protected $description = 'Archive old WMS stock snapshot lot details and clean up expired partitions';

    public function handle(StockSnapshotService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = (string) $this->option('disk');

        $this->info('['.now()->format('Y-m-d H:i:s')."] Starting stock snapshot archive (disk={$disk}, dry_run=".($dryRun ? 'yes' : 'no').')...');

        try {
            $result = $service->archiveAndCleanup($disk, $dryRun);
        } catch (\Throwable $e) {
            $this->error('Stock snapshot archive failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Lot cutoff: {$result['lot_cutoff']}");
        $this->line("Summary cutoff: {$result['summary_cutoff']}");

        if (empty($result['archived_months'])) {
            $this->info('No lot snapshot partitions require archiving.');
        }

        foreach ($result['archived_months'] as $month) {
            $this->line("Month {$month['month']}: {$month['db_rows']} rows, verified=".($month['verified'] ? 'yes' : 'no'));
            if (! empty($month['manifest_path'])) {
                $this->line("  manifest: {$month['manifest_path']}");
            }
        }

        foreach ($result['dropped_partitions'] as $partition) {
            $this->warn("Dropped partition: {$partition}");
        }

        $this->info('['.now()->format('Y-m-d H:i:s').'] Stock snapshot archive completed.');

        return self::SUCCESS;
    }
}
