<?php

namespace App\Console\Commands\TestData;

use App\Domains\Sakemaru\SakemaruEarning;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateTestEarningsCommand extends Command
{
    protected $signature = 'testdata:earnings
                            {--count=5 : Number of test earnings to generate}
                            {--warehouse-id= : Warehouse ID}
                            {--courses=* : Specific delivery course codes to use (leave empty for all)}
                            {--locations=* : Specific location IDs to use for stock filtering (leave empty for all)}';

    protected $description = 'Generate test earnings data via BoozeCore API';

    private int $warehouseId;
    private int $clientId;
    private int $buyerId;
    private string $buyerCode;
    private string $warehouseCode;
    private array $deliveryCourses = [];
    private array $specifiedCourses = [];
    private array $specifiedLocations = [];
    private array $testItems = [];

    public function handle()
    {
        $this->info('📝 Generating test earnings via API...');
        $this->newLine();

        $this->warehouseId = (int) $this->option('warehouse-id');
        if (!$this->warehouseId) {
            $this->error('Warehouse ID is required. Use --warehouse-id option.');
            return 1;
        }

        $count = (int) $this->option('count');

        // Get specified courses and locations
        $this->specifiedCourses = $this->option('courses') ?: [];
        $this->specifiedLocations = array_map('intval', $this->option('locations') ?: []);

        // Initialize client, buyer, and warehouse data
        $this->initializeData();

        // Load test items
        $this->loadTestItems();

        if (empty($this->testItems)) {
            $this->error('No items with stock found for earnings generation');
            return 1;
        }

        // Generate earnings
        $result = $this->generateEarnings($count);

        if ($result['success']) {
            $this->info("✓ Successfully created {$count} test earnings via API");
            return 0;
        } else {
            $this->error("Failed to create earnings: " . ($result['error'] ?? 'Unknown error'));
            return 1;
        }
    }

    private function initializeData(): void
    {
        // Get client ID
        $client = DB::connection('sakemaru')->table('clients')->first();
        $this->clientId = $client->id;

        // Get buyer with specific criteria for earnings generation
        $buyer = DB::connection('sakemaru')->table('buyers')
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
            ->first();

        if (!$buyer) {
            $this->error('No eligible buyer found with the required criteria');
            exit(1);
        }

        $this->buyerId = $buyer->id;
        $this->buyerCode = $buyer->code;


        // Get warehouse
        $warehouse = DB::connection('sakemaru')->table('warehouses')
            ->where('id', $this->warehouseId)
            ->first();
        $this->warehouseCode = $warehouse->code ?? (string) $this->warehouseId;

        // Get delivery courses for this warehouse
        $query = DB::connection('sakemaru')->table('delivery_courses')
            ->where('warehouse_id', $this->warehouseId);

        // Filter by specified courses if provided
        if (!empty($this->specifiedCourses)) {
            $query->whereIn('code', $this->specifiedCourses);
        }

        $this->deliveryCourses = $query->pluck('code')->toArray();

        if (empty($this->deliveryCourses)) {
            $this->warn("No delivery courses found for warehouse {$this->warehouseId}");
        }

        $this->line("Using buyer: {$this->buyerId} (code: {$this->buyerCode}), warehouse: {$this->warehouseId} (code: {$this->warehouseCode})");
        $this->line("Delivery courses: " . (!empty($this->specifiedCourses)
            ? implode(', ', $this->specifiedCourses) . ' (specified)'
            : count($this->deliveryCourses) . ' (all)'));
        if (!empty($this->specifiedLocations)) {
            $this->line("Stock locations filter: " . implode(', ', $this->specifiedLocations));
        }
        $this->newLine();
    }

    private function loadTestItems(): void
    {
        // IMPORTANT: Only select items that have stock in locations (not just warehouse)
        // AND include location type information to match with order types
        // This ensures stock allocation can succeed during wave generation

        $query = DB::connection('sakemaru')
            ->table('items as i')
            ->join('real_stocks as rs', 'i.id', '=', 'rs.item_id')
            ->join('locations as l', 'rs.location_id', '=', 'l.id')
            ->where('i.type', 'ALCOHOL')
            ->where('i.is_active', true)
            ->whereNull('i.end_of_sale_date')
            ->where('i.is_ended', false)
            ->where('rs.warehouse_id', $this->warehouseId)
            ->whereNotNull('rs.location_id')
            ->where('rs.available_quantity', '>', 0);

        // Filter by specified locations if provided
        if (!empty($this->specifiedLocations)) {
            $query->whereIn('rs.location_id', $this->specifiedLocations);
        }

        $items = $query->select(
                'i.id',
                'i.code',
                'i.name',
                'l.available_quantity_flags',
                DB::raw('SUM(rs.available_quantity) as total_available')
            )
            ->groupBy('i.id', 'i.code', 'i.name', 'l.available_quantity_flags')
            ->limit(30)
            ->get();

        $this->testItems = $items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'supports_case' => ($item->available_quantity_flags & 1) > 0, // CASE対応
                    'supports_piece' => ($item->available_quantity_flags & 2) > 0, // PIECE対応
                    'total_available' => $item->total_available,
                    'case_quantity' => 1, // Default case quantity
                ];
            })
            ->toArray();

        $this->line("Loaded " . count($this->testItems) . " test items with location-based stock"
            . (!empty($this->specifiedLocations) ? " (filtered by locations)" : ""));
    }

    private function generateEarnings(int $count): array
    {
        $processDate = ClientSetting::systemDate()->format('Y-m-d');
        $shippingDate = $processDate;

        $scenarios = [
            ['name' => 'ケースのみ（在庫十分）', 'qty_type' => 'CASE', 'qty' => 2],
            ['name' => 'バラのみ（在庫十分）', 'qty_type' => 'PIECE', 'qty' => 15],
            ['name' => 'ケース・バラ混在（通常注文）', 'qty_type' => 'MIXED_CASE_PIECE', 'qty' => 0],
            ['name' => 'ケースのみ（欠品発生）', 'qty_type' => 'CASE', 'qty' => 200],
            ['name' => 'バラのみ（欠品発生）', 'qty_type' => 'PIECE', 'qty' => 500],
            ['name' => 'ケース・バラ混在（欠品あり）', 'qty_type' => 'MIXED_CASE_PIECE_SHORTAGE', 'qty' => 0],
        ];

        $earnings = [];

        for ($i = 0; $i < $count; $i++) {
            $scenario = $scenarios[$i % count($scenarios)];

            // Filter items based on scenario qty_type to ensure stock allocation succeeds
            $availableItems = collect($this->testItems);

            if ($scenario['qty_type'] === 'CASE') {
                // For CASE orders, only select items with CASE support
                $availableItems = $availableItems->filter(fn($item) => $item['supports_case']);
            } elseif ($scenario['qty_type'] === 'PIECE') {
                // For PIECE orders, only select items with PIECE support
                $availableItems = $availableItems->filter(fn($item) => $item['supports_piece']);
            }
            // For MIXED, we'll handle filtering per item below

            if ($availableItems->isEmpty()) {
                // Skip this earning if no suitable items available
                $this->warn("  No items available for {$scenario['name']}, skipping");
                continue;
            }

            // Determine item count based on scenario
            $itemCount = match($scenario['qty_type']) {
                'MIXED_CASE_PIECE', 'MIXED_ALL', 'MIXED_CASE_PIECE_SHORTAGE' => rand(4, 6),
                default => rand(2, 4),
            };

            $itemCount = min($itemCount, $availableItems->count());
            $selectedItems = $availableItems->random($itemCount);

            $details = [];
            foreach ($selectedItems as $index => $item) {
                $qtyType = $scenario['qty_type'];
                $qty = $scenario['qty'];
                $caseQuantity = $item['case_quantity'];

                // Handle mixed scenarios
                if ($qtyType === 'MIXED_CASE_PIECE') {
                    // For MIXED orders, choose type based on what the item supports
                    if ($item['supports_case'] && $item['supports_piece']) {
                        // Both supported, alternate
                        $qtyType = $index % 2 === 0 ? 'CASE' : 'PIECE';
                    } elseif ($item['supports_case']) {
                        $qtyType = 'CASE';
                    } else {
                        $qtyType = 'PIECE';
                    }
                    $qty = $qtyType === 'CASE' ? rand(1, 3) : rand(10, 30);
                } elseif ($qtyType === 'MIXED_CASE_PIECE_SHORTAGE') {
                    // Mix with some shortage items
                    if ($item['supports_case'] && $item['supports_piece']) {
                        $qtyType = $index % 2 === 0 ? 'CASE' : 'PIECE';
                    } elseif ($item['supports_case']) {
                        $qtyType = 'CASE';
                    } else {
                        $qtyType = 'PIECE';
                    }
                    if ($index < 2) {
                        // First 2 items: normal quantity
                        $qty = $qtyType === 'CASE' ? rand(1, 3) : rand(10, 30);
                    } else {
                        // Remaining items: shortage quantity
                        $qty = $qtyType === 'CASE' ? rand(50, 100) : rand(200, 500);
                    }
                }

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

            // Get a random delivery course code
            $deliveryCourseCode = !empty($this->deliveryCourses)
                ? $this->deliveryCourses[array_rand($this->deliveryCourses)]
                : null;

            $earnings[] = [
                'process_date' => $processDate,
                'delivered_date' => $shippingDate,
                'account_date' => $shippingDate,
                'buyer_code' => $this->buyerCode,
                'warehouse_code' => $this->warehouseCode,
                'delivery_course_code' => $deliveryCourseCode,
                'note' => "WMSテスト: {$scenario['name']}",
                'is_delivered' => false,
                'is_returned' => false,
                'details' => $details,
            ];
        }

        $this->line("Sending {$count} earnings to API...");

        $requestData = ['earnings' => $earnings];

        // Debug: Show request data if verbose mode
        if ($this->output->isVerbose()) {
            $this->newLine();
            $this->line('Request data:');
            $this->line(json_encode($requestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        }

        // Call API using SakemaruEarning
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
}
