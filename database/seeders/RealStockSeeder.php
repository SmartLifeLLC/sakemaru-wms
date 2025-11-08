<?php

namespace Database\Seeders;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RealStockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouseId = $this->command->option('warehouse-id') ?? 991;
        $itemCount = $this->command->option('item-count') ?? 30;

        $this->command->info("Generating test real_stocks for warehouse {$warehouseId}");

        // Get warehouse
        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) {
            $this->command->error("Warehouse {$warehouseId} not found");
            return;
        }

        // Get or create locations for this warehouse
        $locations = Location::where('warehouse_id', $warehouseId)->get();

        if ($locations->isEmpty()) {
            $this->command->warn("No locations found for warehouse {$warehouseId}. Creating sample locations...");
            $locations = $this->createSampleLocations($warehouseId);
        }

        $this->command->info("Found {$locations->count()} locations");

        // Get items (prefer items > 111099 as in GenerateTestEarningsCommand)
        $items = Item::where('type', 'ALCOHOL')
            ->where('id', '>', 111099)
            ->limit($itemCount * 2) // Get more items to have variety
            ->get();

        if ($items->isEmpty()) {
            $this->command->error('No items found for stock generation');
            return;
        }

        $this->command->info("Found {$items->count()} items");

        // Clear existing real_stocks for test items in this warehouse
        $itemIds = $items->pluck('id')->toArray();
        DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->delete();

        $this->command->info('Cleared existing test stocks');

        // Generate real stocks
        $createdCount = 0;
        $wmsRealStockCount = 0;
        $selectedItems = $items->random(min($itemCount, $items->count()));

        foreach ($selectedItems as $item) {
            // Create multiple stock records per item in different locations
            $stocksPerItem = rand(1, 3); // 1-3 locations per item

            for ($s = 0; $s < $stocksPerItem; $s++) {
                $location = $locations->random();

                // Vary expiry dates (some null, some future dates)
                $expirationDate = null;
                if (rand(0, 100) > 30) { // 70% have expiry dates
                    $expirationDate = now()->addDays(rand(30, 365))->format('Y-m-d');
                }

                // Vary quantities
                $currentQuantity = rand(10, 200);
                $availableQuantity = $currentQuantity; // Initially all available

                $realStockId = DB::connection('sakemaru')->table('real_stocks')->insertGetId([
                    'client_id' => $warehouse->client_id ?? 1,
                    'stock_allocation_id' => 1, // Default allocation
                    'floor_id' => null,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $location->id,
                    'purchase_id' => null,
                    'item_id' => $item->id,
                    'item_management_type' => 'NORMAL',
                    'expiration_date' => $expirationDate,
                    'received_at' => now()->subDays(rand(1, 90)),
                    'current_quantity' => $currentQuantity,
                    'available_quantity' => $availableQuantity,
                    'order_rank' => 'FIFO', // Required field
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
                $wmsRealStockCount++;
            }
        }

        $this->command->info("Created {$createdCount} real_stock records for {$selectedItems->count()} items");
        $this->command->info("Created {$wmsRealStockCount} wms_real_stocks records with lock_version initialized");
    }

    /**
     * Create sample locations if none exist
     */
    private function createSampleLocations(int $warehouseId): \Illuminate\Support\Collection
    {
        $locations = [];

        // Get client_id from warehouse
        $warehouse = Warehouse::find($warehouseId);
        $clientId = $warehouse->client_id ?? 1;

        // Create 20 sample locations using code1, code2, code3 format
        for ($i = 1; $i <= 20; $i++) {
            $code1 = chr(65 + ($i - 1) / 5); // A, B, C, D
            $code2 = (string)(($i - 1) % 5 + 1); // 1-5
            $code3 = '1'; // Level 1

            $location = Location::create([
                'client_id' => $clientId,
                'warehouse_id' => $warehouseId,
                'code1' => $code1,
                'code2' => $code2,
                'code3' => $code3,
                'name' => "テストロケーション {$code1}-{$code2}-{$code3}",
                'creator_id' => 1,
                'last_updater_id' => 1,
            ]);

            $locations[] = $location;
        }

        return collect($locations);
    }
}
