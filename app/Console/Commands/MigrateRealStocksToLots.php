<?php

namespace App\Console\Commands;

use App\Models\Sakemaru\RealStockLot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateRealStocksToLots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:migrate-to-lots
                            {--dry-run : Run without actually creating records}
                            {--batch-size=500 : Number of records to process per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing real_stocks records to real_stock_lots (initial inventory lots)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No records will be created');
        }

        $this->info('Starting migration of real_stocks to real_stock_lots...');

        // Get count of real_stocks with current_quantity > 0
        $totalCount = DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('current_quantity', '>', 0)
            ->count();

        $this->info("Found {$totalCount} real_stocks records with current_quantity > 0");

        // Check for existing lots
        $existingLotCount = RealStockLot::count();
        if ($existingLotCount > 0) {
            $this->warn("Found {$existingLotCount} existing lots in real_stock_lots table");
            if (! $this->confirm('Do you want to continue? Existing lots will not be duplicated.')) {
                return self::FAILURE;
            }
        }

        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->start();

        $createdCount = 0;
        $skippedCount = 0;

        // Process in batches
        DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('current_quantity', '>', 0)
            ->orderBy('id')
            ->chunk($batchSize, function ($stocks) use ($dryRun, &$createdCount, &$skippedCount, $progressBar) {
                foreach ($stocks as $stock) {
                    // Check if lot already exists for this real_stock
                    $existingLot = RealStockLot::where('real_stock_id', $stock->id)
                        ->whereNull('purchase_id')
                        ->first();

                    if ($existingLot) {
                        $skippedCount++;
                        $progressBar->advance();

                        continue;
                    }

                    if (! $dryRun) {
                        // Create initial inventory lot
                        RealStockLot::create([
                            'real_stock_id' => $stock->id,
                            'purchase_id' => null, // Initial inventory - no purchase
                            'trade_item_id' => null, // Initial inventory - no trade item
                            'price' => $stock->price,
                            'content_amount' => $stock->content_amount ?? 0,
                            'container_amount' => $stock->container_amount ?? 0,
                            'expiration_date' => $stock->expiration_date,
                            'initial_quantity' => $stock->current_quantity,
                            'current_quantity' => $stock->current_quantity,
                            'reserved_quantity' => $stock->reserved_quantity ?? 0,
                            'status' => RealStockLot::STATUS_ACTIVE,
                        ]);
                    }

                    $createdCount++;
                    $progressBar->advance();
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("DRY RUN COMPLETE: Would create {$createdCount} lots, skip {$skippedCount} existing");
        } else {
            $this->info("Migration complete: Created {$createdCount} lots, skipped {$skippedCount} existing");
        }

        // Verify totals match
        if (! $dryRun) {
            $this->verifyMigration();
        }

        return self::SUCCESS;
    }

    /**
     * Verify that migration was successful
     */
    protected function verifyMigration(): void
    {
        $this->info('Verifying migration...');

        // Check that sum of lot quantities matches sum of real_stock quantities
        $realStockTotal = DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('current_quantity', '>', 0)
            ->sum('current_quantity');

        $lotTotal = DB::connection('sakemaru')
            ->table('real_stock_lots')
            ->where('status', RealStockLot::STATUS_ACTIVE)
            ->sum('current_quantity');

        if ($realStockTotal == $lotTotal) {
            $this->info("Verification PASSED: real_stocks total ({$realStockTotal}) = lots total ({$lotTotal})");
        } else {
            $this->error("Verification FAILED: real_stocks total ({$realStockTotal}) != lots total ({$lotTotal})");
        }

        // Check for real_stocks without corresponding lots
        $missingLots = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->leftJoin('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
            ->where('rs.current_quantity', '>', 0)
            ->whereNull('rsl.id')
            ->count();

        if ($missingLots > 0) {
            $this->warn("Found {$missingLots} real_stocks without corresponding lots");
        } else {
            $this->info('All real_stocks have corresponding lots');
        }
    }
}
