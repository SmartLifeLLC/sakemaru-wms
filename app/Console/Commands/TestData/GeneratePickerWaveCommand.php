<?php

namespace App\Console\Commands\TestData;

use App\Domains\Sakemaru\SakemaruEarning;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Item;
use App\Models\Wave;
use App\Models\WaveSetting;
use App\Models\WmsPicker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class GeneratePickerWaveCommand extends Command
{
    protected $signature = 'testdata:picker-wave
                            {picker_id : Picker ID}
                            {--warehouse-id= : Warehouse ID (defaults to picker\'s default warehouse)}
                            {--courses=* : Delivery course codes with counts (format: code:count)}
                            {--locations=* : Specific location IDs to use for stock filtering and generation}
                            {--date= : Shipping date (YYYY-MM-DD), defaults to today}
                            {--reset : Reset wave data before generation}';

    protected $description = 'Generate test earnings and waves for a specific picker with specified delivery courses';

    private int $pickerId;

    private int $warehouseId;

    private string $warehouseCode;

    private int $clientId;

    private array $buyers = [];

    private array $testItems = [];

    private array $specifiedLocations = [];

    private int $buyerIndex = 0;

    public function handle()
    {
        $this->pickerId = (int) $this->argument('picker_id');
        $courseParams = $this->option('courses');
        $shippingDate = $this->option('date') ?? ClientSetting::systemDate()->format('Y-m-d');
        $shouldReset = $this->option('reset');

        $this->info('🏗️  Generating picker-specific wave test data...');
        $this->newLine();

        // Validate picker
        $picker = WmsPicker::find($this->pickerId);
        if (! $picker) {
            $this->error("Picker with ID {$this->pickerId} not found");

            return 1;
        }

        // Set warehouse
        $this->warehouseId = $this->option('warehouse-id') ?? $picker->default_warehouse_id;
        if (! $this->warehouseId) {
            $this->error('No warehouse specified and picker has no default warehouse');

            return 1;
        }

        // Get specified locations
        $this->specifiedLocations = array_map('intval', $this->option('locations') ?: []);

        $this->line("Picker: [{$picker->code}] {$picker->name}");
        $this->line("Warehouse ID: {$this->warehouseId}");
        $this->line("Shipping Date: {$shippingDate}");
        if (! empty($this->specifiedLocations)) {
            $this->line('Locations: '.implode(', ', $this->specifiedLocations));
        }
        $this->newLine();

        // Initialize data
        $this->initializeData();

        // Ensure stock exists in specified locations
        if (! empty($this->specifiedLocations)) {
            $this->ensureStockInLocations();
        }

        // Load test items
        $this->loadTestItems();
        if (empty($this->testItems)) {
            $this->error('No items with stock found for earnings generation');

            return 1;
        }

        // Parse course parameters (format: code:count)
        $courseCounts = $this->parseCourseCounts($courseParams);
        if (empty($courseCounts)) {
            $this->error('No delivery courses specified. Use --courses=CODE:COUNT format');
            $this->line('Example: --courses=910072:3 --courses=910073:2');

            return 1;
        }

        $this->info('Delivery courses to generate:');
        foreach ($courseCounts as $code => $count) {
            $this->line("  - Course {$code}: {$count} earning(s)");
        }
        $this->newLine();

        // Reset wave data if requested
        if ($shouldReset) {
            $this->warn("⚠️  Resetting wave data for {$shippingDate}...");
            Artisan::call('wms:generate-waves', [
                '--date' => $shippingDate,
                '--reset' => true,
            ]);
            $this->info('✓ Wave data reset completed');
            $this->newLine();
        }

        // Generate earnings for each course
        $totalGenerated = 0;
        foreach ($courseCounts as $courseCode => $count) {
            $this->info("Generating {$count} earnings for course {$courseCode}...");

            $result = $this->generateEarningsForCourse($courseCode, $count, $shippingDate);

            if ($result['success']) {
                $totalGenerated += $count;
                $this->info("  ✓ Generated {$count} earnings for course {$courseCode}");
            } else {
                $this->error("  ✗ Failed to generate earnings for course {$courseCode}: ".$result['error']);
            }
        }

        if ($totalGenerated === 0) {
            $this->error('No earnings were generated');

            return 1;
        }

        $this->newLine();
        $this->info("✓ Total earnings generated: {$totalGenerated}");
        $this->newLine();

        // Ensure wave settings exist for each course
        $this->info('Checking/creating wave settings...');
        foreach (array_keys($courseCounts) as $courseCode) {
            $this->ensureWaveSetting($courseCode);
        }
        $this->newLine();

        // Generate waves
        $this->info('Generating waves...');
        $exitCode = Artisan::call('wms:generate-waves', [
            '--date' => $shippingDate,
        ]);

        if ($exitCode !== 0) {
            $this->error('Wave generation failed');
            $this->line(Artisan::output());

            return 1;
        }

        $this->info('✓ Waves generated successfully');
        $this->newLine();

        // Assign tasks to picker
        $this->info('Assigning tasks to picker...');
        $assignedCount = $this->assignTasksToPicker($shippingDate);
        $this->info("✓ Assigned {$assignedCount} tasks to picker [{$picker->code}] {$picker->name}");
        $this->newLine();

        $this->info('🎉 Picker wave generation completed successfully!');

        return 0;
    }

    private function getAdminUserId(): int
    {
        // Get first admin user from SAKEMARU database
        $user = DB::connection('sakemaru')->table('users')
            ->where('is_active', true)
            ->orderBy('id', 'asc')
            ->first();

        return $user->id ?? 0;
    }

    private function initializeData(): void
    {
        // Get client
        $client = DB::connection('sakemaru')->table('clients')->first();
        $this->clientId = $client->id;

        // Get multiple buyers with specific criteria for earnings generation
        // Each earning will use a different buyer to ensure all trades have different partners
        $buyers = DB::connection('sakemaru')->table('buyers')
            ->leftJoin('buyer_details', 'buyers.id', '=', 'buyer_details.buyer_id')
            ->leftJoin('partners', 'buyers.partner_id', '=', 'partners.id')
            ->where('partners.is_active', 1)
            ->where('partners.is_supplier', 0)
            ->where('buyer_details.is_active', 1)
            ->whereNull('partners.end_of_trade_date')
            ->where('buyer_details.can_register_earnings', 1)
            ->where('buyer_details.is_allowed_duplicated_item', true)
            ->where('buyer_details.is_allowed_case_quantity', true)
            ->select('partners.code', 'partners.id')
            ->inRandomOrder()
            ->limit(1000) // Get enough buyers for all possible earnings
            ->get();

        if ($buyers->isEmpty()) {
            $this->error('No eligible buyers found with the required criteria');
            exit(1);
        }

        // Store buyers as array with code as value
        $this->buyers = $buyers->pluck('code')->toArray();
        $this->line('Loaded '.count($this->buyers).' eligible buyers for earnings generation');

        // Get warehouse
        $warehouse = DB::connection('sakemaru')->table('warehouses')
            ->where('id', $this->warehouseId)
            ->first();
        $this->warehouseCode = $warehouse->code ?? (string) $this->warehouseId;
    }

    private function loadTestItems(): void
    {
        // IMPORTANT: Only select items that have stock in locations (not just warehouse)
        // AND include location type information to match with order types
        // This ensures stock allocation can succeed during wave generation
        // Note: location_id is now in real_stock_lots, not real_stocks

        $query = DB::connection('sakemaru')
            ->table('items as i')
            ->join('real_stocks as rs', 'i.id', '=', 'rs.item_id')
            ->join('real_stock_lots as rsl', function ($join) {
                $join->on('rsl.real_stock_id', '=', 'rs.id')
                    ->where('rsl.status', '=', 'ACTIVE');
            })
            ->join('locations as l', 'rsl.location_id', '=', 'l.id')
            ->where('i.type', 'ALCOHOL')
            ->where('i.is_active', true)
            ->whereNull('i.end_of_sale_date')
            ->where('i.is_ended', false)
            ->where('rs.warehouse_id', $this->warehouseId)
            ->whereNotNull('rsl.location_id')
            ->where('rs.available_quantity', '>', 0);

        // Filter by specified locations if provided
        if (! empty($this->specifiedLocations)) {
            $query->whereIn('rsl.location_id', $this->specifiedLocations);
        }

        $items = $query->select(
            'i.id',
            'i.code',
            'i.name',
            'l.available_quantity_flags',
            DB::raw('SUM(rs.available_quantity) as total_available')
        )
            ->groupBy('i.id', 'i.code', 'i.name', 'l.available_quantity_flags')
            ->get();

        $this->testItems = $items->map(function ($item) {
            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'supports_case' => ($item->available_quantity_flags & 1) > 0, // CASE対応
                'supports_piece' => ($item->available_quantity_flags & 2) > 0, // PIECE対応
                'total_available' => $item->total_available,
            ];
        })->toArray();

        $locationInfo = ! empty($this->specifiedLocations)
            ? ' (filtered by locations: '.implode(', ', $this->specifiedLocations).')'
            : '';
        $this->line('Loaded '.count($this->testItems)." test items with location-based stock{$locationInfo}");
    }

    private function parseCourseCounts(array $courseParams): array
    {
        $result = [];
        foreach ($courseParams as $param) {
            $parts = explode(':', $param);
            if (count($parts) === 2) {
                $code = trim($parts[0]);
                $count = (int) trim($parts[1]);
                if ($count > 0) {
                    $result[$code] = $count;
                }
            }
        }

        return $result;
    }

    private function generateEarningsForCourse(string $courseCode, int $count, string $shippingDate): array
    {
        // Verify course exists
        $course = DB::connection('sakemaru')
            ->table('delivery_courses')
            ->where('code', $courseCode)
            ->where('warehouse_id', $this->warehouseId)
            ->first();

        if (! $course) {
            return [
                'success' => false,
                'error' => "Delivery course {$courseCode} not found for warehouse {$this->warehouseId}",
            ];
        }

        $scenarios = [
            ['name' => 'ケース注文（十分な在庫）', 'qty_type' => 'CASE'],
            ['name' => 'バラ注文（十分な在庫）', 'qty_type' => 'PIECE'],
            ['name' => 'ケース注文（欠品あり）', 'qty_type' => 'CASE'],
            ['name' => 'バラ注文（欠品あり）', 'qty_type' => 'PIECE'],
            ['name' => 'ケース・バラ混在注文', 'qty_type' => 'MIXED'],
        ];

        $earnings = [];
        for ($i = 0; $i < $count; $i++) {
            $scenario = $scenarios[$i % count($scenarios)];

            // Select a unique buyer for this earning
            // Use global index to ensure unique buyers across different courses
            $buyerCode = $this->buyers[$this->buyerIndex % count($this->buyers)];
            $this->buyerIndex++;

            // Filter items based on scenario qty_type to ensure stock allocation succeeds
            $availableItems = collect($this->testItems);

            if ($scenario['qty_type'] === 'CASE') {
                // For CASE orders, only select items with CASE support
                $availableItems = $availableItems->filter(fn ($item) => $item['supports_case']);
            } elseif ($scenario['qty_type'] === 'PIECE') {
                // For PIECE orders, only select items with PIECE support
                $availableItems = $availableItems->filter(fn ($item) => $item['supports_piece']);
            }
            // For MIXED, we'll handle filtering per item below

            if ($availableItems->isEmpty()) {
                // Skip this earning if no suitable items available
                $this->warn("  No items available for {$scenario['name']}, skipping");

                continue;
            }

            // Add items to earning
            $itemCount = $scenario['qty_type'] === 'MIXED' ? rand(3, 5) : rand(2, 3);
            $itemCount = min($itemCount, $availableItems->count());
            $selectedItems = $availableItems->random($itemCount);

            $details = [];
            foreach ($selectedItems as $index => $item) {
                $qtyType = $scenario['qty_type'];

                // Generate quantity for each item (1-10)
                if ($qtyType === 'MIXED') {
                    // For MIXED orders, choose type based on what the item supports
                    if ($item['supports_case'] && $item['supports_piece']) {
                        // Both supported, alternate
                        $qtyType = $index % 2 === 0 ? 'CASE' : 'PIECE';
                    } elseif ($item['supports_case']) {
                        $qtyType = 'CASE';
                    } else {
                        $qtyType = 'PIECE';
                    }
                }

                // Generate quantity: 1-10 for all types
                $qty = rand(1, 10);

                $details[] = [
                    'item_code' => $item['code'],
                    'quantity' => $qty,
                    'quantity_type' => $qtyType,
                    'order_quantity' => $qty,
                    'order_quantity_type' => $qtyType,
                    'price' => rand(100, 5000),
                    'note' => "{$qtyType} {$qty}個",
                ];
            }

            $earnings[] = [
                'process_date' => $shippingDate,
                'delivered_date' => $shippingDate,
                'account_date' => $shippingDate,
                'buyer_code' => $buyerCode, // Use unique buyer for each earning
                'warehouse_code' => $this->warehouseCode,
                'delivery_course_code' => $courseCode,
                'note' => "WMSテスト（ピッカー専用）: {$scenario['name']}",
                'is_delivered' => false,
                'is_returned' => false,
                'details' => $details,
            ];
        }

        $requestData = ['earnings' => $earnings];

        // Debug: Show request data if verbose mode
        if ($this->output->isVerbose()) {
            $this->newLine();
            $this->line('Request data:');
            $this->line(json_encode($requestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        }

        // Call API
        $response = SakemaruEarning::postData($requestData);

        if (isset($response['success']) && $response['success']) {
            return ['success' => true];
        } else {
            // Output request data on error for debugging
            $this->newLine();
            $this->error('API Request failed. Request data:');
            $this->line(json_encode($requestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();

            return [
                'success' => false,
                'error' => $response['debug_message'] ?? $response['message'] ?? 'API request failed',
            ];
        }
    }

    private function ensureWaveSetting(string $courseCode): void
    {
        $course = DB::connection('sakemaru')
            ->table('delivery_courses')
            ->where('code', $courseCode)
            ->where('warehouse_id', $this->warehouseId)
            ->first();

        if (! $course) {
            $this->warn("  Course {$courseCode} not found, skipping wave setting");

            return;
        }

        // Set wave setting times based on current hour
        // Example: if current time is 18:55, use 18:00:00
        $currentHour = now()->format('H');
        $pickingStartTime = sprintf('%02d:00:00', $currentHour);
        $pickingDeadlineTime = '23:59:59';

        // Search for existing wave setting with the same picking_start_time
        $existing = WaveSetting::where('warehouse_id', $this->warehouseId)
            ->where('delivery_course_id', $course->id)
            ->where('picking_start_time', $pickingStartTime)
            ->first();

        $adminUserId = $this->getAdminUserId();

        if ($existing) {
            // Already exists with the same time, no update needed
            $this->line("  ✓ Wave setting already exists for course {$courseCode} (picking: {$pickingStartTime})");

            return;
        }

        // Create new wave setting
        WaveSetting::create([
            'warehouse_id' => $this->warehouseId,
            'delivery_course_id' => $course->id,
            'picking_start_time' => $pickingStartTime,
            'picking_deadline_time' => $pickingDeadlineTime,
            'creator_id' => $adminUserId,
            'last_updater_id' => $adminUserId,
        ]);

        $this->line("  ✓ Created wave setting for course {$courseCode} (picking: {$pickingStartTime} - {$pickingDeadlineTime})");
    }

    private function assignTasksToPicker(string $shippingDate): int
    {
        // Get waves for this shipping date and warehouse only (via wave_setting)
        $waves = Wave::where('shipping_date', $shippingDate)
            ->whereHas('waveSetting', function ($query) {
                $query->where('warehouse_id', $this->warehouseId);
            })
            ->get();

        if ($waves->isEmpty()) {
            $this->warn('  No waves found for this shipping date and warehouse');

            return 0;
        }

        $waveIds = $waves->pluck('id')->toArray();

        // Build query for tasks
        $query = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->whereIn('wave_id', $waveIds)
            ->whereNull('picker_id');

        // If locations were specified, only assign tasks with matching floor_id
        if (! empty($this->specifiedLocations)) {
            // Get floor_ids from specified locations
            $floorIds = DB::connection('sakemaru')
                ->table('locations')
                ->whereIn('id', $this->specifiedLocations)
                ->distinct()
                ->pluck('floor_id')
                ->filter() // Remove nulls
                ->toArray();

            if (! empty($floorIds)) {
                $query->whereIn('floor_id', $floorIds);
                $this->line('  Filtering tasks to floors: '.implode(', ', $floorIds));
            }
        }

        // Assign tasks to the picker
        $updated = $query->update([
            'picker_id' => $this->pickerId,
            'updated_at' => now(),
        ]);

        return $updated;
    }

    private function ensureStockInLocations(): void
    {
        $this->info('📦 Checking stock in specified locations...');

        // Check if stock exists in specified locations
        $existingStockCount = DB::connection('sakemaru')
            ->table('real_stocks')
            ->whereIn('location_id', $this->specifiedLocations)
            ->where('warehouse_id', $this->warehouseId)
            ->where('available_quantity', '>', 0)
            ->count();

        if ($existingStockCount > 0) {
            $this->line("  ✓ Found {$existingStockCount} existing stock records in specified locations");

            return;
        }

        // No stock found, generate it directly
        $this->warn('  ⚠ No stock found in specified locations, generating...');

        // Get locations info
        $locations = DB::connection('sakemaru')
            ->table('locations')
            ->whereIn('id', $this->specifiedLocations)
            ->where('warehouse_id', $this->warehouseId)
            ->get();

        if ($locations->isEmpty()) {
            $this->error('  ✗ No valid locations found');

            return;
        }

        // Get all active items
        $items = Item::where('type', 'ALCOHOL')
            ->where('is_active', true)
            ->whereNull('end_of_sale_date')
            ->where('is_ended', false)
            ->orderBy('code')
            ->limit(50) // Limit to 50 items for test data
            ->get();

        if ($items->isEmpty()) {
            $this->error('  ✗ No active items found');

            return;
        }

        $clientId = $locations->first()->client_id ?? 1;
        $createdCount = 0;
        $locationCount = $locations->count();
        $locationIndex = 0;

        // Distribute items evenly across specified locations
        // Note: 新スキーマでは real_stocks + real_stock_lots に分離
        foreach ($items as $item) {
            $location = $locations[$locationIndex % $locationCount];
            $locationIndex++;

            // Generate stock data
            $expirationDate = now()->addDays(rand(30, 180))->format('Y-m-d');
            $currentQuantity = rand(50, 500);
            $price = rand(100, 5000);

            // Check if real_stock already exists for this item/warehouse
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
                    continue;
                }

                // Add new lot to existing stock
                DB::connection('sakemaru')->table('real_stock_lots')->insert([
                    'real_stock_id' => $existingStock->id,
                    'floor_id' => $location->floor_id,
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
                // Create new real_stock record
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
                    'floor_id' => $location->floor_id,
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
        }

        $this->info("  ✓ Generated {$createdCount} stock records in specified locations");
    }
}
