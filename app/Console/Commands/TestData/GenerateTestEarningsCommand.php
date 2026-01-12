<?php

namespace App\Console\Commands\TestData;

use App\Domains\Sakemaru\SakemaruEarning;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Item;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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

    private string $warehouseCode;

    private array $specifiedCourses = [];

    private array $specifiedLocations = [];

    private array $testItems = [];

    private Collection $eligibleBuyers;

    private array $usedBuyerCodes = [];

    private array $deliveryCourseCodes = [];

    public function handle()
    {
        $this->info('📝 Generating test earnings via API...');
        $this->newLine();

        $this->warehouseId = (int) $this->option('warehouse-id');
        if (! $this->warehouseId) {
            $this->error('Warehouse ID is required. Use --warehouse-id option.');

            return 1;
        }

        $count = (int) $this->option('count');

        // Get specified courses and locations
        $this->specifiedCourses = $this->option('courses') ?: [];
        $this->specifiedLocations = array_map('intval', $this->option('locations') ?: []);

        // Initialize warehouse and buyer data
        $this->initializeData();

        // Check if we have enough buyers
        if ($this->eligibleBuyers->count() < $count) {
            $this->error("Not enough buyers available. Found {$this->eligibleBuyers->count()} buyers, but need {$count}.");

            return 1;
        }

        // Load test items
        $this->loadTestItems();

        if (empty($this->testItems)) {
            $this->error('No items with stock found for earnings generation');

            return 1;
        }

        // Generate earnings
        $result = $this->generateEarnings($count);

        if ($result['success']) {
            $this->info("✓ Successfully created {$result['count']} test earnings via API");

            return 0;
        } else {
            $this->error('Failed to create earnings: '.($result['error'] ?? 'Unknown error'));

            return 1;
        }
    }

    private function initializeData(): void
    {
        // Get warehouse
        $warehouse = DB::connection('sakemaru')->table('warehouses')
            ->where('id', $this->warehouseId)
            ->first();
        $this->warehouseCode = $warehouse->code ?? (string) $this->warehouseId;

        // Get delivery courses for this warehouse
        $courseQuery = DB::connection('sakemaru')->table('delivery_courses')
            ->where('warehouse_id', $this->warehouseId);

        // Filter by specified courses if provided
        if (! empty($this->specifiedCourses)) {
            $courseQuery->whereIn('code', $this->specifiedCourses);
        }

        $deliveryCourses = $courseQuery->get(['id', 'code']);
        $deliveryCourseIds = $deliveryCourses->pluck('id')->toArray();
        $this->deliveryCourseCodes = $deliveryCourses->pluck('code')->toArray();

        if (empty($deliveryCourseIds)) {
            $this->warn("No delivery courses found for warehouse {$this->warehouseId}");
            $this->eligibleBuyers = collect();

            return;
        }

        // Get eligible buyers (no longer filtered by delivery course)
        // Just need active buyers that can register earnings
        $this->eligibleBuyers = DB::connection('sakemaru')
            ->table('buyer_details as bd')
            ->join('buyers as b', 'bd.buyer_id', '=', 'b.id')
            ->join('partners as p', 'b.partner_id', '=', 'p.id')
            ->where('bd.is_active', 1)
            ->where('bd.can_register_earnings', 1)
            ->where('bd.is_allowed_case_quantity', 1)
            ->where('p.is_active', 1)
            ->where('p.is_supplier', 0)
            ->whereNull('p.end_of_trade_date')
            ->select(['p.code as buyer_code'])
            ->distinct()
            ->get();

        $this->line("Warehouse: {$this->warehouseId} (code: {$this->warehouseCode})");
        $this->line('Delivery courses: '.(! empty($this->specifiedCourses)
            ? implode(', ', $this->specifiedCourses).' (specified)'
            : count($deliveryCourseIds).' (all)'));
        $this->line("Eligible buyers: {$this->eligibleBuyers->count()}");
        if (! empty($this->specifiedLocations)) {
            $this->line('Stock locations filter: '.implode(', ', $this->specifiedLocations));
        }
        $this->newLine();
    }

    /**
     * Get a unique buyer for earning generation
     */
    private function getUniqueBuyer(): ?object
    {
        $available = $this->eligibleBuyers->filter(
            fn ($buyer) => ! in_array($buyer->buyer_code, $this->usedBuyerCodes)
        );

        if ($available->isEmpty()) {
            return null;
        }

        $buyer = $available->random();
        $this->usedBuyerCodes[] = $buyer->buyer_code;

        return $buyer;
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
        if (! empty($this->specifiedLocations)) {
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

        $this->line('Loaded '.count($this->testItems).' test items with location-based stock'
            .(! empty($this->specifiedLocations) ? ' (filtered by locations)' : ''));
    }

    private function generateEarnings(int $count): array
    {
        $processDate = ClientSetting::systemDate()->format('Y-m-d');
        $shippingDate = $processDate;

        $earnings = [];
        $createdCount = 0;

        for ($i = 0; $i < $count; $i++) {
            // Get a unique buyer for each earning
            $buyer = $this->getUniqueBuyer();
            if (! $buyer) {
                $this->warn("  No more unique buyers available, stopping at {$createdCount} earnings");
                break;
            }

            // Filter items that support both CASE and PIECE for mixed ordering
            // Also include items that support only CASE or only PIECE
            $availableItems = collect($this->testItems);

            if ($availableItems->isEmpty()) {
                $this->warn('  No items available, skipping');

                continue;
            }

            // Determine item count: 4-6 items per earning to ensure mix
            $itemCount = rand(4, 6);
            $itemCount = min($itemCount, $availableItems->count());
            $selectedItems = $availableItems->random($itemCount)->values();

            // Ensure at least one CASE and one PIECE item
            $details = [];
            $hasCaseItem = false;
            $hasPieceItem = false;

            foreach ($selectedItems as $index => $item) {
                // Determine quantity type to ensure mix
                $qtyType = $this->determineQuantityType($item, $index, $hasCaseItem, $hasPieceItem);

                if ($qtyType === 'CASE') {
                    $hasCaseItem = true;
                    $qty = rand(1, 5);
                } else {
                    $hasPieceItem = true;
                    $qty = rand(5, 30);
                }

                $details[] = [
                    'item_code' => $item['code'],
                    'quantity' => $qty,
                    'quantity_type' => $qtyType,
                    'order_quantity' => $qty,
                    'order_quantity_type' => $qtyType,
                    'price' => rand(100, 5000),
                    'note' => "{$qtyType} {$qty}",
                ];
            }

            // Use a random delivery course from the specified/available courses (ignore buyer's default)
            $deliveryCourseCode = $this->deliveryCourseCodes[array_rand($this->deliveryCourseCodes)];

            $earnings[] = [
                'process_date' => $processDate,
                'delivered_date' => $shippingDate,
                'account_date' => $shippingDate,
                'buyer_code' => $buyer->buyer_code,
                'warehouse_code' => $this->warehouseCode,
                'delivery_course_code' => $deliveryCourseCode,
                'note' => "WMSテスト: 伝票#{$i} (得意先:{$buyer->buyer_code})",
                'is_delivered' => false,
                'is_returned' => false,
                'details' => $details,
            ];

            $this->line("  [{$i}] Buyer: {$buyer->buyer_code}, Course: {$deliveryCourseCode}, Items: ".count($details));
            $createdCount++;
        }

        if (empty($earnings)) {
            return [
                'success' => false,
                'error' => 'No earnings could be generated',
                'count' => 0,
            ];
        }

        $this->newLine();
        $this->line("Sending {$createdCount} earnings to API...");

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
            return ['success' => true, 'count' => $createdCount];
        } else {
            // Output request data on error for debugging
            $this->newLine();
            $this->error('API Request failed. Request data:');
            $this->line(json_encode($requestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();

            return [
                'success' => false,
                'error' => $response['debug_message'] ?? $response['message'] ?? 'API request failed',
                'count' => 0,
            ];
        }
    }

    /**
     * Determine quantity type ensuring mix of CASE and PIECE
     */
    private function determineQuantityType(array $item, int $index, bool $hasCaseItem, bool $hasPieceItem): string
    {
        $supportsBoth = $item['supports_case'] && $item['supports_piece'];
        $supportsCase = $item['supports_case'];
        $supportsPiece = $item['supports_piece'];

        // If we don't have CASE yet and item supports CASE, use CASE
        if (! $hasCaseItem && $supportsCase) {
            return 'CASE';
        }

        // If we don't have PIECE yet and item supports PIECE, use PIECE
        if (! $hasPieceItem && $supportsPiece) {
            return 'PIECE';
        }

        // Both types satisfied, alternate or use what's supported
        if ($supportsBoth) {
            return $index % 2 === 0 ? 'CASE' : 'PIECE';
        }

        return $supportsCase ? 'CASE' : 'PIECE';
    }
}
