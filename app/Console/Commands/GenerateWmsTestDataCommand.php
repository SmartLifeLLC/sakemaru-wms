<?php

namespace App\Console\Commands;

use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Models\WmsPickingArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateWmsTestDataCommand extends Command
{
    protected $signature = 'wms:generate-test-data
                            {--clean : Clean existing test data before generating}
                            {--locations-only : Generate only location data}
                            {--stock-only : Generate only stock data}
                            {--orders-only : Generate only order data}
                            {--no-api : Skip API calls and create earnings directly in database}';

    protected $description = 'Generate WMS test data (locations, stock, orders) for testing picking scenarios';

    private $warehouseId = 991;

    private $clientId;

    private $testItems = [];

    private $testLocations = [];

    public function handle()
    {
        $this->info('🚀 WMS Test Data Generator Starting...');
        $this->newLine();

        // Get the first (and only) client ID
        $client = DB::connection('sakemaru')->table('clients')->first();
        $this->clientId = $client->id;

        if ($this->option('clean')) {
            $this->cleanTestData();
        }

        if (! $this->option('stock-only') && ! $this->option('orders-only')) {
            $this->generateLocations();
            $this->generatePickers();
        }

        if (! $this->option('locations-only') && ! $this->option('orders-only')) {
            $this->generateStock();
        }

        if (! $this->option('locations-only') && ! $this->option('stock-only')) {
            $this->generateOrders();
        }

        $this->newLine();
        $this->displaySummary();

        return 0;
    }

    private function generateOrders()
    {
        $this->info('📝 Generating test orders...');
        $this->line('Calling testdata:earnings command...');

        $exitCode = $this->call('testdata:earnings', [
            '--warehouse-id' => $this->warehouseId,
            '--count' => 5,
        ]);

        if ($exitCode === 0) {
            $this->info('  ✓ Orders generated successfully');
        } else {
            $this->error('  ✗ Failed to generate orders');
        }
    }

    private function cleanTestData()
    {
        $this->warn('🧹 Cleaning existing test data...');

        DB::connection('sakemaru')->transaction(function () {
            // Clean earnings and trades
            DB::connection('sakemaru')->table('earnings')
                ->where('warehouse_id', $this->warehouseId)
                ->where('delivered_date', '>=', now()->format('Y-m-d'))
                ->delete();

            // wms_real_stocks table has been removed - WMS columns are now in real_stocks

            // Clean real_stocks for test items
            DB::connection('sakemaru')->table('real_stocks')
                ->where('warehouse_id', $this->warehouseId)
                ->delete();

            // Clean locations for warehouse 991
            Location::where('warehouse_id', $this->warehouseId)->delete();

            // Clean wms_picking_areas for warehouse 991
            WmsPickingArea::where('warehouse_id', $this->warehouseId)->delete();

            // Clean wms_pickers for warehouse 991
            DB::connection('sakemaru')->table('wms_pickers')
                ->where('default_warehouse_id', $this->warehouseId)
                ->delete();

            $this->info('  ✓ Test data cleaned');
        });
    }

    private function generateLocations()
    {
        $this->info('📍 Generating test locations...');

        $zones = [
            ['code' => '常温', 'prefix' => 'A', 'name' => '常温エリア'],
            ['code' => '冷蔵', 'prefix' => 'B', 'name' => '冷蔵エリア'],
            ['code' => '冷凍', 'prefix' => 'C', 'name' => '冷凍エリア'],
            ['code' => 'ポークリプト', 'prefix' => 'D', 'name' => 'ポークリプトエリア'],
        ];

        $unitTypes = ['CASE', 'PIECE', 'BOTH'];
        $walkingOrder = 1000;

        DB::connection('sakemaru')->transaction(function () use ($zones, &$walkingOrder) {
            foreach ($zones as $zoneIndex => $zone) {
                // Create picking area for each zone
                $pickingArea = WmsPickingArea::create([
                    'warehouse_id' => $this->warehouseId,
                    'code' => $zone['code'],
                    'name' => $zone['name'],
                    'display_order' => ($zoneIndex + 1) * 10,
                    'is_active' => true,
                ]);

                for ($rack = 1; $rack <= 3; $rack++) {
                    for ($level = 1; $level <= 3; $level++) {
                        // Create location with zone-specific code1
                        $location = Location::create([
                            'client_id' => $this->clientId,
                            'warehouse_id' => $this->warehouseId,
                            'code1' => $zone['prefix'], // A(常温), B(冷蔵), C(冷凍)
                            'code2' => (string) $rack,
                            'code3' => (string) $level,
                            'name' => "{$zone['code']}-{$rack}棚-{$level}段",
                            'creator_id' => 0,
                            'last_updater_id' => 0,
                        ]);

                        // Assign location to picking area
                        $location->update(['wms_picking_area_id' => $pickingArea->id]);

                        $this->testLocations[] = [
                            'id' => $location->id,
                            'code' => "{$location->code1} {$location->code2} {$location->code3}",
                            'name' => $location->name,
                            'zone' => $zone['code'],
                            'picking_area' => $zone['name'],
                        ];

                        // $walkingOrder += 100; // Removed: walking_order is no longer used
                    }
                }
            }
        });

        $this->info('  ✓ Created '.count($this->testLocations).' locations with WMS attributes and picking areas');
    }

    private function generateStock()
    {
        $this->info('📦 Generating test stock data...');

        // Get sample items
        $items = Item::where('type', 'ALCOHOL')
            ->where('id', '>', 111099)
            ->inRandomOrder()
            ->limit(30)
            ->get();

        if ($items->isEmpty()) {
            $this->error('No items found for stock generation');

            return;
        }

        $stockCount = 0;

        DB::connection('sakemaru')->transaction(function () use ($items, &$stockCount) {
            $locations = Location::where('warehouse_id', $this->warehouseId)
                ->with('wmsLocation')
                ->get();

            if ($locations->isEmpty()) {
                $this->error('No locations found. Run with --locations-only first.');

                return;
            }

            // Group locations by picking area to ensure distribution across areas
            $locationsByArea = $locations->groupBy(fn ($loc) => $loc->wmsLocation->wms_picking_area_id ?? 'null');
            $pickingAreas = $locationsByArea->keys()->filter(fn ($k) => $k !== 'null')->values();

            // Base stock_allocation_id (incremented for each stock record to ensure uniqueness)
            $stockAllocationId = 100000;

            foreach ($items as $index => $item) {
                $this->testItems[] = [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                ];

                // Assign each item to a specific picking area in round-robin fashion
                // This ensures items are distributed across different areas
                $areaIndex = $index % $pickingAreas->count();
                $assignedAreaId = $pickingAreas->get($areaIndex);
                $areaLocations = $locationsByArea->get($assignedAreaId);

                // Create stock in locations from this area
                $locationsForItem = $areaLocations->shuffle()->take(min(2, $areaLocations->count()));

                foreach ($locationsForItem as $location) {
                    $expiryDate = now()->addMonths(rand(1, 12))->format('Y-m-d');
                    $quantity = rand(10, 100);
                    $price = rand(100, 5000);

                    // Each stock record gets unique stock_allocation_id to satisfy unique constraint
                    // real_stocks unique key: (item_id, warehouse_id, stock_allocation_id)
                    // Note: location_id, expiration_date, price are now in real_stock_lots
                    $realStockId = DB::connection('sakemaru')->table('real_stocks')->insertGetId([
                        'client_id' => $this->clientId,
                        'warehouse_id' => $this->warehouseId,
                        'stock_allocation_id' => $stockAllocationId++,
                        'item_id' => $item->id,
                        'current_quantity' => $quantity,
                        'reserved_quantity' => 0,
                        'order_rank' => 'A',
                        'wms_lock_version' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Create lot record with location and expiration info
                    DB::connection('sakemaru')->table('real_stock_lots')->insert([
                        'real_stock_id' => $realStockId,
                        'floor_id' => $location->floor_id,
                        'location_id' => $location->id,
                        'expiration_date' => $expiryDate,
                        'price' => $price,
                        'content_amount' => 0,
                        'container_amount' => 0,
                        'initial_quantity' => $quantity,
                        'current_quantity' => $quantity,
                        'reserved_quantity' => 0,
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $stockCount++;
                }
            }
        });

        $this->info("  ✓ Created {$stockCount} stock records for ".count($this->testItems).' items');
    }

    private function generatePickers()
    {
        $this->info('👷 Generating test pickers...');

        $pickers = [
            ['code' => 'P001', 'name' => '山田太郎'],
            ['code' => 'P002', 'name' => '佐藤花子'],
            ['code' => 'P003', 'name' => '鈴木一郎'],
            ['code' => 'P004', 'name' => '田中美咲'],
            ['code' => 'P005', 'name' => '高橋健太'],
        ];

        $count = 0;
        foreach ($pickers as $picker) {
            DB::connection('sakemaru')->table('wms_pickers')->insert([
                'code' => $picker['code'],
                'name' => $picker['name'],
                'password' => bcrypt('password'), // デフォルトパスワード
                'default_warehouse_id' => $this->warehouseId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;
        }

        $this->info("  ✓ Created {$count} pickers");
    }

    private function displaySummary()
    {
        $this->info('📊 Test Data Summary');
        $this->info('═══════════════════════════════════════════════════');

        // Picking Areas
        $pickingAreaCount = WmsPickingArea::where('warehouse_id', $this->warehouseId)->count();
        $this->line("🏢 Picking Areas: {$pickingAreaCount}");

        // Locations
        $locationCount = Location::where('warehouse_id', $this->warehouseId)->count();
        $locationsWithArea = Location::where('warehouse_id', $this->warehouseId)
            ->whereNotNull('wms_picking_area_id')
            ->count();

        $this->line("\n📍 Locations: {$locationCount}");
        $this->line("   With Picking Area: {$locationsWithArea}");

        // Pickers
        $pickerCount = DB::connection('sakemaru')->table('wms_pickers')
            ->where('default_warehouse_id', $this->warehouseId)
            ->count();
        $this->line("\n👷 Pickers: {$pickerCount}");

        if (! empty($this->testLocations)) {
            $this->line("\n   Sample locations:");
            foreach (array_slice($this->testLocations, 0, 5) as $loc) {
                $this->line("   - {$loc['code']} | {$loc['zone']}");
            }
        }

        // Stock
        $stockCount = DB::connection('sakemaru')->table('real_stocks')
            ->where('warehouse_id', $this->warehouseId)
            ->count();

        $this->line("\n📦 Stock Records: {$stockCount}");

        if (! empty($this->testItems)) {
            $this->line("\n   Test items (".count($this->testItems).' items):');
            foreach (array_slice($this->testItems, 0, 10) as $item) {
                $this->line("   - {$item['code']} | {$item['name']}");
            }
        }

        // Orders
        $orderCount = Earning::where('warehouse_id', $this->warehouseId)
            ->where('delivered_date', '>=', now()->format('Y-m-d'))
            ->count();

        $this->line("\n📝 Test Orders (Earnings): {$orderCount}");

        $this->info('═══════════════════════════════════════════════════');
        $this->newLine();
        $this->info('✅ Test data generation completed!');
        $this->info('💡 Run: php artisan wms:generate-waves --reset to test wave generation');
    }
}
