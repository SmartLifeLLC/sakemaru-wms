<?php

namespace App\Console\Commands\TestData;

use App\Models\Sakemaru\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateStocksCommand extends Command
{
    protected $signature = 'testdata:stocks
                            {--warehouse-id= : Warehouse ID}
                            {--locations=* : Location IDs to generate stock for}
                            {--item-count=30 : Number of items to generate stock for}
                            {--stocks-per-item=2 : Number of stock records per item}';

    protected $description = 'Generate stock data for specified locations';

    private int $warehouseId;
    private array $locationIds;
    private int $itemCount;
    private int $stocksPerItem;

    public function handle()
    {
        $this->info('📦 Generating stock data...');
        $this->newLine();

        // Get parameters
        $this->warehouseId = (int) $this->option('warehouse-id');
        if (!$this->warehouseId) {
            $this->error('Warehouse ID is required. Use --warehouse-id option.');
            return 1;
        }

        $this->locationIds = array_map('intval', $this->option('locations') ?: []);
        if (empty($this->locationIds)) {
            $this->error('At least one location is required. Use --locations option.');
            return 1;
        }

        $this->itemCount = (int) $this->option('item-count');
        $this->stocksPerItem = (int) $this->option('stocks-per-item');

        $this->line("Configuration:");
        $this->line("  Warehouse: {$this->warehouseId}");
        $this->line("  Locations: " . implode(', ', $this->locationIds));
        $this->line("  Item count: {$this->itemCount}");
        $this->line("  Stocks per item: {$this->stocksPerItem}");
        $this->newLine();

        try {
            // Validate locations exist
            $locations = DB::connection('sakemaru')
                ->table('locations')
                ->whereIn('id', $this->locationIds)
                ->where('warehouse_id', $this->warehouseId)
                ->get();

            if ($locations->count() !== count($this->locationIds)) {
                $this->error('Some specified locations do not exist or do not belong to the warehouse');
                return 1;
            }

            $this->line("Found {$locations->count()} valid locations");
            $this->newLine();

            // Get random items
            $items = $this->getRandomItems();
            if ($items->isEmpty()) {
                $this->error('No active items found');
                return 1;
            }

            $this->line("Found {$items->count()} items to generate stock for");
            $this->newLine();

            // Generate stocks
            $createdCount = $this->generateStocks($items, $locations);

            $this->newLine();
            $this->info("✅ Created {$createdCount} stock records");
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error generating stocks: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    private function getRandomItems()
    {
        return Item::where('type', 'ALCOHOL')
            ->where('is_active', true)
            ->whereNull('end_of_sale_date')
            ->where('is_ended', false)
            ->inRandomOrder()
            ->limit($this->itemCount)
            ->get();
    }

    private function generateStocks($items, $locations): int
    {
        $createdCount = 0;
        $clientId = $locations->first()->client_id ?? 1;

        foreach ($items as $item) {
            // Randomly select locations for this item
            $selectedLocations = $locations->random(min($this->stocksPerItem, $locations->count()));

            foreach ($selectedLocations as $location) {
                // Check if stock already exists
                $existing = DB::connection('sakemaru')
                    ->table('real_stocks')
                    ->where('warehouse_id', $this->warehouseId)
                    ->where('location_id', $location->id)
                    ->where('item_id', $item->id)
                    ->exists();

                if ($existing) {
                    $this->line("  Stock for item {$item->code} at location {$location->code1}{$location->code2}{$location->code3} already exists, skipping");
                    continue;
                }

                // Generate stock with expiration date (30-180 days from now)
                $expirationDate = now()->addDays(rand(30, 180))->format('Y-m-d');
                $currentQuantity = rand(50, 500);

                $realStockId = DB::connection('sakemaru')->table('real_stocks')->insertGetId([
                    'client_id' => $clientId,
                    'stock_allocation_id' => 1,
                    'warehouse_id' => $this->warehouseId,
                    'location_id' => $location->id,
                    'item_id' => $item->id,
                    'item_management_type' => 'STANDARD',
                    'expiration_date' => $expirationDate,
                    'current_quantity' => $currentQuantity,
                    'available_quantity' => $currentQuantity,
                    'order_rank' => 'FIFO',
                    'price' => rand(100, 5000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create corresponding wms_real_stocks record
                DB::connection('sakemaru')->table('wms_real_stocks')->insert([
                    'real_stock_id' => $realStockId,
                    'reserved_quantity' => 0,
                    'picking_quantity' => 0,
                    'lock_version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $createdCount++;
                $this->line("  Created stock: Item {$item->code} at location {$location->code1}{$location->code2}{$location->code3} (qty: {$currentQuantity})");
            }
        }

        return $createdCount;
    }
}
