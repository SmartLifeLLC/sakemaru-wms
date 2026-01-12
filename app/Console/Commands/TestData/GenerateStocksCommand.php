<?php

namespace App\Console\Commands\TestData;

use App\Models\Sakemaru\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateStocksCommand extends Command
{
    protected $signature = 'testdata:stocks
                            {--warehouse-id= : Warehouse ID}
                            {--floor-id= : Floor ID}';

    protected $description = 'Generate stock data for all items evenly distributed across all locations in the specified floor';

    private int $warehouseId;

    private int $floorId;

    public function handle()
    {
        $this->info('📦 Generating stock data for all items...');
        $this->newLine();

        // Get parameters
        $this->warehouseId = (int) $this->option('warehouse-id');
        if (! $this->warehouseId) {
            $this->error('Warehouse ID is required. Use --warehouse-id option.');

            return 1;
        }

        $this->floorId = (int) $this->option('floor-id');
        if (! $this->floorId) {
            $this->error('Floor ID is required. Use --floor-id option.');

            return 1;
        }

        $this->line('Configuration:');
        $this->line("  Warehouse ID: {$this->warehouseId}");
        $this->line("  Floor ID: {$this->floorId}");
        $this->newLine();

        try {
            // Get all locations for the specified floor
            $locations = DB::connection('sakemaru')
                ->table('locations')
                ->where('warehouse_id', $this->warehouseId)
                ->where('floor_id', $this->floorId)
                ->whereNotNull('code1')
                ->whereNotNull('code2')
                ->orderBy('code1')
                ->orderBy('code2')
                ->get();

            if ($locations->isEmpty()) {
                $this->error('No locations found for the specified warehouse and floor');

                return 1;
            }

            $this->line("Found {$locations->count()} locations in the floor");
            $this->newLine();

            // Get all active items
            $items = $this->getAllActiveItems();
            if ($items->isEmpty()) {
                $this->error('No active items found');

                return 1;
            }

            $this->line("Found {$items->count()} active items");
            $this->newLine();

            // Generate stocks evenly distributed across locations
            $createdCount = $this->generateStocksEvenly($items, $locations);

            $this->newLine();
            $this->info("✅ Created {$createdCount} stock records");
            $this->info("   Items: {$items->count()}");
            $this->info("   Locations: {$locations->count()}");

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error generating stocks: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Get all active items (ALCOHOL type)
     */
    private function getAllActiveItems()
    {
        return Item::where('type', 'ALCOHOL')
            ->where('is_active', true)
            ->whereNull('end_of_sale_date')
            ->where('is_ended', false)
            ->orderBy('code')
            ->get();
    }

    /**
     * Generate stocks evenly distributed across all locations
     * Each item is assigned to locations in a round-robin fashion
     */
    private function generateStocksEvenly($items, $locations): int
    {
        $createdCount = 0;
        $skippedCount = 0;
        $clientId = $locations->first()->client_id ?? 1;

        $locationCount = $locations->count();
        $locationIndex = 0;

        // Progress bar
        $progressBar = $this->output->createProgressBar($items->count());
        $progressBar->start();

        foreach ($items as $item) {
            // Select location in round-robin fashion for even distribution
            $location = $locations[$locationIndex % $locationCount];
            $locationIndex++;

            // Check if stock already exists
            $existing = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('warehouse_id', $this->warehouseId)
                ->where('location_id', $location->id)
                ->where('item_id', $item->id)
                ->exists();

            if ($existing) {
                $skippedCount++;
                $progressBar->advance();

                continue;
            }

            // Generate stock with expiration date (30-180 days from now)
            $expirationDate = now()->addDays(rand(30, 180))->format('Y-m-d');
            $currentQuantity = rand(50, 500);

            // Note: available_quantity is a generated column (= current_quantity - reserved_quantity)
            DB::connection('sakemaru')->table('real_stocks')->insert([
                'client_id' => $clientId,
                'stock_allocation_id' => 1,
                'warehouse_id' => $this->warehouseId,
                'location_id' => $location->id,
                'item_id' => $item->id,
                'item_management_type' => 'STANDARD',
                'expiration_date' => $expirationDate,
                'current_quantity' => $currentQuantity,
                'reserved_quantity' => 0,
                'order_rank' => 'FIFO',
                'price' => rand(100, 5000),
                'wms_lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $createdCount++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($skippedCount > 0) {
            $this->line("Skipped {$skippedCount} existing stock records");
        }

        return $createdCount;
    }
}
