<?php

namespace App\Console\Commands\TestData;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Services\StockAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadTestStockAllocationCommand extends Command
{
    protected $signature = 'loadtest:allocation
                            {--warehouse-id=991 : Warehouse ID}
                            {--items=100 : Number of items to create}
                            {--stocks-per-item=10 : Number of stock records per item}
                            {--allocations=1000 : Number of allocation requests to test}
                            {--concurrent=10 : Number of concurrent allocation requests}
                            {--cleanup : Clean up test data after completion}';

    protected $description = 'Load test stock allocation with large dataset';

    private int $warehouseId;
    private int $itemCount;
    private int $stocksPerItem;
    private int $allocationCount;
    private int $concurrentCount;
    private bool $cleanup;

    private array $testItemIds = [];
    private array $testStockIds = [];
    private array $metrics = [
        'total_allocations' => 0,
        'successful_allocations' => 0,
        'failed_allocations' => 0,
        'lock_failures' => 0,
        'shortages' => 0,
        'total_requested_qty' => 0,
        'total_allocated_qty' => 0,
        'total_shortage_qty' => 0,
        'total_time_ms' => 0,
        'min_time_ms' => PHP_FLOAT_MAX,
        'max_time_ms' => 0,
        'allocation_times' => [],
    ];

    public function handle()
    {
        $this->warehouseId = (int) $this->option('warehouse-id');
        $this->itemCount = (int) $this->option('items');
        $this->stocksPerItem = (int) $this->option('stocks-per-item');
        $this->allocationCount = (int) $this->option('allocations');
        $this->concurrentCount = (int) $this->option('concurrent');
        $this->cleanup = $this->option('cleanup');

        $this->info('🔥 Stock Allocation Load Test');
        $this->newLine();
        $this->line("Warehouse: {$this->warehouseId}");
        $this->line("Items: {$this->itemCount}");
        $this->line("Stocks per item: {$this->stocksPerItem}");
        $this->line("Total stock records: " . ($this->itemCount * $this->stocksPerItem));
        $this->line("Allocation requests: {$this->allocationCount}");
        $this->line("Concurrent requests: {$this->concurrentCount}");
        $this->newLine();

        try {
            // Step 1: Generate test data
            $this->info('📊 Step 1: Generating test data...');
            $this->generateTestData();
            $this->newLine();

            // Step 2: Run allocation load test
            $this->info('⚡ Step 2: Running allocation load test...');
            $this->runLoadTest();
            $this->newLine();

            // Step 3: Display results
            $this->info('📈 Step 3: Performance Results');
            $this->displayResults();
            $this->newLine();

            // Step 4: Cleanup (if requested)
            if ($this->cleanup) {
                $this->info('🧹 Step 4: Cleaning up test data...');
                $this->cleanupTestData();
                $this->newLine();
            }

            $this->info('✅ Load test completed!');
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Load test failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    private function generateTestData(): void
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $this->warehouseId)
            ->first();

        if (!$warehouse) {
            throw new \Exception("Warehouse {$this->warehouseId} not found");
        }

        $clientId = $warehouse->client_id ?? 1;

        // Get or create locations
        $locations = Location::where('warehouse_id', $this->warehouseId)->get();
        if ($locations->isEmpty()) {
            throw new \Exception("No locations found for warehouse {$this->warehouseId}. Run testdata:wms first.");
        }

        $this->line("Found {$locations->count()} locations");

        // Get base items for cloning
        $baseItems = Item::where('type', 'ALCOHOL')
            ->where('is_active', true)
            ->whereNull('end_of_sale_date')
            ->where('is_ended', false)
            ->limit(10)
            ->get();

        if ($baseItems->isEmpty()) {
            throw new \Exception('No base items found for cloning');
        }

        $progressBar = $this->output->createProgressBar($this->itemCount);
        $progressBar->setFormat('Creating items: %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        // Create test items (using existing items as templates)
        for ($i = 0; $i < $this->itemCount; $i++) {
            $baseItem = $baseItems->random();

            // Find next available ID (use 900000+ range for load test items)
            $maxId = DB::connection('sakemaru')
                ->table('items')
                ->where('id', '>=', 900000)
                ->where('id', '<', 910000)
                ->max('id') ?? 899999;

            $itemId = $maxId + 1;
            $itemCode = (string) $itemId; // Code is same as ID

            // Clone the base item with new ID and code
            $itemData = $baseItem->getAttributes();
            unset($itemData['id'], $itemData['code'], $itemData['name'], $itemData['created_at'], $itemData['updated_at']);

            $itemData['id'] = $itemId;
            $itemData['code'] = $itemCode;
            $itemData['name_main'] = "負荷テスト商品 {$itemId}";
            $itemData['name_symbol'] = '';
            $itemData['is_active'] = true;
            $itemData['is_ended'] = false;
            $itemData['creator_id'] = 0;
            $itemData['last_updater_id'] = 0;
            $itemData['created_at'] = now();
            $itemData['updated_at'] = now();

            DB::connection('sakemaru')->table('items')->insert($itemData);

            $this->testItemIds[] = $itemId;

            // Create stock records for this item
            for ($s = 0; $s < $this->stocksPerItem; $s++) {
                $location = $locations->random();

                // Vary expiration dates (70% have expiry dates)
                $expirationDate = null;
                if (rand(0, 100) > 30) {
                    $expirationDate = now()->addDays(rand(30, 365))->format('Y-m-d');
                }

                // Vary quantities (1000-5000 per stock) - larger quantities for load testing
                $currentQuantity = rand(1000, 5000);

                $realStockId = DB::connection('sakemaru')->table('real_stocks')->insertGetId([
                    'client_id' => $clientId,
                    'stock_allocation_id' => 1,
                    'floor_id' => null,
                    'warehouse_id' => $this->warehouseId,
                    'location_id' => $location->id,
                    'purchase_id' => null,
                    'item_id' => $itemId,
                    'item_management_type' => 'STANDARD',
                    'expiration_date' => $expirationDate,
                    'current_quantity' => $currentQuantity,
                    'available_quantity' => $currentQuantity,
                    'order_rank' => 'FIFO',
                    'price' => rand(100, 5000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create corresponding wms_real_stocks
                DB::connection('sakemaru')->table('wms_real_stocks')->insert([
                    'real_stock_id' => $realStockId,
                    'reserved_quantity' => 0,
                    'picking_quantity' => 0,
                    'lock_version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->testStockIds[] = $realStockId;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("Created {$this->itemCount} items with " . count($this->testStockIds) . " stock records");
    }

    private function runLoadTest(): void
    {
        $service = new StockAllocationService();

        // Create a test wave setting first (if not exists)
        $waveSetting = DB::connection('sakemaru')->table('wms_wave_settings')
            ->where('warehouse_id', $this->warehouseId)
            ->first();

        if (!$waveSetting) {
            throw new \Exception("No wave settings found for warehouse {$this->warehouseId}. Run testdata:wave-settings first.");
        }

        // Create a test wave for allocations
        $waveId = DB::connection('sakemaru')->table('wms_waves')->insertGetId([
            'wms_wave_setting_id' => $waveSetting->id,
            'wave_no' => 9999,
            'shipping_date' => now()->format('Y-m-d'),
            'status' => 'PICKING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $progressBar = $this->output->createProgressBar($this->allocationCount);
        $progressBar->setFormat('Allocations: %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        for ($i = 0; $i < $this->allocationCount; $i++) {
            // Random item from test items
            $itemId = $this->testItemIds[array_rand($this->testItemIds)];

            // Random quantity (1-100)
            $needQty = rand(1, 100);

            // Random quantity type (70% PIECE, 30% CASE)
            $quantityType = rand(0, 100) > 30 ? 'PIECE' : 'CASE';

            // Measure allocation time
            $startTime = microtime(true);

            try {
                $result = $service->allocateForItem(
                    waveId: $waveId,
                    warehouseId: $this->warehouseId,
                    itemId: $itemId,
                    needQty: $needQty,
                    quantityType: $quantityType,
                    sourceId: 999999 + $i,
                    sourceType: 'EARNING'
                );

                $endTime = microtime(true);
                $elapsedMs = ($endTime - $startTime) * 1000;

                // Record metrics
                $this->metrics['total_allocations']++;
                $this->metrics['allocation_times'][] = $elapsedMs;
                $this->metrics['total_time_ms'] += $elapsedMs;
                $this->metrics['min_time_ms'] = min($this->metrics['min_time_ms'], $elapsedMs);
                $this->metrics['max_time_ms'] = max($this->metrics['max_time_ms'], $elapsedMs);

                // Track quantities
                $this->metrics['total_requested_qty'] += $needQty;
                $this->metrics['total_allocated_qty'] += $result['allocated'] ?? 0;
                $this->metrics['total_shortage_qty'] += $result['shortage'] ?? 0;

                if (!empty($result['lock_failed'])) {
                    $this->metrics['lock_failures']++;
                    $this->metrics['failed_allocations']++;
                } else {
                    $this->metrics['successful_allocations']++;
                    // Count shortages separately (can still be successful allocation)
                    if ($result['shortage'] > 0) {
                        $this->metrics['shortages']++;
                    }
                }
            } catch (\Exception $e) {
                $this->metrics['failed_allocations']++;
                // Log first error for debugging
                if ($this->metrics['failed_allocations'] === 1) {
                    $this->newLine();
                    $this->warn("First allocation error: " . $e->getMessage());
                }
                // Continue testing even on errors
            }

            $progressBar->advance();

            // Simulate concurrent requests by sleeping randomly
            if ($this->concurrentCount > 1 && $i % $this->concurrentCount === 0) {
                usleep(rand(10, 100)); // 0.01-0.1ms
            }
        }

        $progressBar->finish();
        $this->newLine();

        // Cleanup test wave
        DB::connection('sakemaru')->table('wms_waves')->where('id', $waveId)->delete();
        DB::connection('sakemaru')->table('wms_reservations')->where('wave_id', $waveId)->delete();
    }

    private function displayResults(): void
    {
        $total = $this->metrics['total_allocations'];
        $successful = $this->metrics['successful_allocations'];
        $failed = $this->metrics['failed_allocations'];
        $lockFailures = $this->metrics['lock_failures'];
        $shortages = $this->metrics['shortages'];

        $avgTimeMs = $total > 0 ? $this->metrics['total_time_ms'] / $total : 0;
        $minTimeMs = $this->metrics['min_time_ms'] === PHP_FLOAT_MAX ? 0 : $this->metrics['min_time_ms'];
        $maxTimeMs = $this->metrics['max_time_ms'];

        // Calculate percentiles
        sort($this->metrics['allocation_times']);
        $p50 = $this->getPercentile($this->metrics['allocation_times'], 50);
        $p95 = $this->getPercentile($this->metrics['allocation_times'], 95);
        $p99 = $this->getPercentile($this->metrics['allocation_times'], 99);

        // Calculate throughput
        $totalTimeSec = $this->metrics['total_time_ms'] / 1000;
        $throughput = $totalTimeSec > 0 ? $total / $totalTimeSec : 0;

        $totalRequested = $this->metrics['total_requested_qty'];
        $totalAllocated = $this->metrics['total_allocated_qty'];
        $totalShortage = $this->metrics['total_shortage_qty'];
        $fillRate = $totalRequested > 0 ? ($totalAllocated / $totalRequested * 100) : 0;

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Allocations', number_format($total)],
                ['Successful', number_format($successful) . ' (' . number_format($successful / max($total, 1) * 100, 2) . '%)'],
                ['Failed', number_format($failed) . ' (' . number_format($failed / max($total, 1) * 100, 2) . '%)'],
                ['Lock Failures', number_format($lockFailures)],
                ['With Shortages', number_format($shortages) . ' (' . number_format($shortages / max($total, 1) * 100, 2) . '%)'],
                ['---', '---'],
                ['Total Requested Qty', number_format($totalRequested)],
                ['Total Allocated Qty', number_format($totalAllocated)],
                ['Total Shortage Qty', number_format($totalShortage)],
                ['Fill Rate', number_format($fillRate, 2) . '%'],
                ['---', '---'],
                ['Avg Time (ms)', number_format($avgTimeMs, 2)],
                ['Min Time (ms)', number_format($minTimeMs, 2)],
                ['Max Time (ms)', number_format($maxTimeMs, 2)],
                ['P50 Time (ms)', number_format($p50, 2)],
                ['P95 Time (ms)', number_format($p95, 2)],
                ['P99 Time (ms)', number_format($p99, 2)],
                ['---', '---'],
                ['Total Time (sec)', number_format($totalTimeSec, 2)],
                ['Throughput (req/sec)', number_format($throughput, 2)],
            ]
        );

        // Performance assessment
        $this->newLine();
        if ($avgTimeMs < 50) {
            $this->info('🚀 Excellent performance! Average allocation time under 50ms.');
        } elseif ($avgTimeMs < 100) {
            $this->info('✅ Good performance. Average allocation time under 100ms.');
        } elseif ($avgTimeMs < 200) {
            $this->warn('⚠️  Moderate performance. Consider optimization if load increases.');
        } else {
            $this->error('⛔ Poor performance. Optimization recommended.');
        }

        if ($lockFailures > 0) {
            $lockFailureRate = $lockFailures / max($total, 1) * 100;
            if ($lockFailureRate > 5) {
                $this->warn("⚠️  High lock failure rate: {$lockFailureRate}%. Consider increasing lock timeout.");
            }
        }
    }

    private function getPercentile(array $sorted, int $percentile): float
    {
        if (empty($sorted)) {
            return 0;
        }

        $index = (int) ceil(count($sorted) * $percentile / 100) - 1;
        $index = max(0, min($index, count($sorted) - 1));

        return $sorted[$index];
    }

    private function cleanupTestData(): void
    {
        $deletedStocks = DB::connection('sakemaru')
            ->table('real_stocks')
            ->whereIn('id', $this->testStockIds)
            ->delete();

        $deletedWmsStocks = DB::connection('sakemaru')
            ->table('wms_real_stocks')
            ->whereIn('real_stock_id', $this->testStockIds)
            ->delete();

        $deletedItems = DB::connection('sakemaru')
            ->table('items')
            ->whereIn('id', $this->testItemIds)
            ->delete();

        $this->line("Deleted {$deletedStocks} stock records");
        $this->line("Deleted {$deletedWmsStocks} wms_real_stocks records");
        $this->line("Deleted {$deletedItems} test items");
    }
}