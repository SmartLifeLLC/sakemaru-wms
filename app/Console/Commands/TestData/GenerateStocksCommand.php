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
     *
     * Note: 新スキーマでは real_stocks に基本情報、real_stock_lots にロット情報（location, expiration_date等）を格納
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

            // Generate stock with expiration date (30-180 days from now)
            $expirationDate = now()->addDays(rand(30, 180))->format('Y-m-d');
            $currentQuantity = rand(50, 500);
            $price = rand(100, 5000);

            // Check if real_stock already exists for this item/warehouse/stock_allocation
            $existingStock = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('warehouse_id', $this->warehouseId)
                ->where('item_id', $item->id)
                ->where('stock_allocation_id', 1)
                ->first();

            if ($existingStock) {
                // Check if lot already exists at this location
                $existingLot = DB::connection('sakemaru')
                    ->table('real_stock_lots')
                    ->where('real_stock_id', $existingStock->id)
                    ->where('location_id', $location->id)
                    ->where('status', 'ACTIVE')
                    ->exists();

                if ($existingLot) {
                    $skippedCount++;
                    $progressBar->advance();

                    continue;
                }

                // Add new lot to existing stock
                DB::connection('sakemaru')->table('real_stock_lots')->insert([
                    'real_stock_id' => $existingStock->id,
                    'floor_id' => $this->floorId,
                    'location_id' => $location->id,
                    'expiration_date' => $expirationDate,
                    'price' => $price,
                    'content_amount' => 0,
                    'container_amount' => 0,
                    'initial_quantity' => $currentQuantity,
                    'current_quantity' => $currentQuantity,
                    'reserved_quantity' => 0,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update real_stocks total quantity
                DB::connection('sakemaru')
                    ->table('real_stocks')
                    ->where('id', $existingStock->id)
                    ->increment('current_quantity', $currentQuantity);
            } else {
                // Create new real_stock record (without location/expiration - those go to lots)
                $realStockId = DB::connection('sakemaru')->table('real_stocks')->insertGetId([
                    'client_id' => $clientId,
                    'stock_allocation_id' => 1,
                    'warehouse_id' => $this->warehouseId,
                    'item_id' => $item->id,
                    'item_management_type' => 'STANDARD',
                    'current_quantity' => $currentQuantity,
                    'reserved_quantity' => 0,
                    'order_rank' => 'FIFO',
                    'wms_lock_version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create lot record with location and expiration info
                DB::connection('sakemaru')->table('real_stock_lots')->insert([
                    'real_stock_id' => $realStockId,
                    'floor_id' => $this->floorId,
                    'location_id' => $location->id,
                    'expiration_date' => $expirationDate,
                    'price' => $price,
                    'content_amount' => 0,
                    'container_amount' => 0,
                    'initial_quantity' => $currentQuantity,
                    'current_quantity' => $currentQuantity,
                    'reserved_quantity' => 0,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $createdCount++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($skippedCount > 0) {
            $this->line("Skipped {$skippedCount} existing lot records");
        }

        return $createdCount;
    }
}
