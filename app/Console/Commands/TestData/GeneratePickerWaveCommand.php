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
                            {--date= : Shipping date (YYYY-MM-DD), defaults to today}
                            {--reset : Reset wave data before generation}';

    protected $description = 'Generate test earnings and waves for a specific picker with specified delivery courses';

    private int $pickerId;
    private int $warehouseId;
    private string $warehouseCode;
    private int $clientId;
    private int $buyerId;
    private string $buyerCode;
    private array $testItems = [];

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
        if (!$picker) {
            $this->error("Picker with ID {$this->pickerId} not found");
            return 1;
        }

        // Set warehouse
        $this->warehouseId = $this->option('warehouse-id') ?? $picker->default_warehouse_id;
        if (!$this->warehouseId) {
            $this->error("No warehouse specified and picker has no default warehouse");
            return 1;
        }

        $this->line("Picker: [{$picker->code}] {$picker->name}");
        $this->line("Warehouse ID: {$this->warehouseId}");
        $this->line("Shipping Date: {$shippingDate}");
        $this->newLine();

        // Initialize data
        $this->initializeData();

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
            $this->info("✓ Wave data reset completed");
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
                $this->error("  ✗ Failed to generate earnings for course {$courseCode}: " . $result['error']);
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

    private function initializeData(): void
    {
        // Get client
        $client = DB::connection('sakemaru')->table('clients')->first();
        $this->clientId = $client->id;

        // Get buyer
        $buyer = DB::connection('sakemaru')->table('partners')
            ->where('is_supplier', 0)
            ->where('is_active', 1)
            ->first();
        $this->buyerId = $buyer->id ?? 1;
        $this->buyerCode = $buyer->code ?? '1';

        // Get warehouse
        $warehouse = DB::connection('sakemaru')->table('warehouses')
            ->where('id', $this->warehouseId)
            ->first();
        $this->warehouseCode = $warehouse->code ?? (string) $this->warehouseId;
    }

    private function loadTestItems(): void
    {
        $this->testItems = Item::where('type', 'ALCOHOL')
            ->where('id', '>', 111099)
            ->whereIn('id', function ($query) {
                $query->select('item_id')
                    ->from('real_stocks')
                    ->where('warehouse_id', $this->warehouseId);
            })
            ->limit(50)
            ->get()
            ->map(fn($item) => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name])
            ->toArray();
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

        if (!$course) {
            return [
                'success' => false,
                'error' => "Delivery course {$courseCode} not found for warehouse {$this->warehouseId}"
            ];
        }

        $scenarios = [
            ['name' => 'ケース注文（十分な在庫）', 'qty_type' => 'CASE', 'qty' => 2],
            ['name' => 'バラ注文（十分な在庫）', 'qty_type' => 'PIECE', 'qty' => 15],
            ['name' => 'ケース注文（欠品あり）', 'qty_type' => 'CASE', 'qty' => 200],
            ['name' => 'バラ注文（欠品あり）', 'qty_type' => 'PIECE', 'qty' => 500],
            ['name' => 'ケース・バラ混在注文', 'qty_type' => 'MIXED', 'qty' => 0],
        ];

        $earnings = [];

        for ($i = 0; $i < $count; $i++) {
            $scenario = $scenarios[$i % count($scenarios)];

            // Add items to earning
            $itemCount = $scenario['qty_type'] === 'MIXED' ? rand(3, 5) : rand(2, 3);
            $selectedItems = collect($this->testItems)->random($itemCount);

            $details = [];
            foreach ($selectedItems as $index => $item) {
                $qtyType = $scenario['qty_type'];
                $qty = $scenario['qty'];

                if ($qtyType === 'MIXED') {
                    $qtyType = $index % 2 === 0 ? 'CASE' : 'PIECE';
                    $qty = $qtyType === 'CASE' ? rand(1, 5) : rand(5, 20);
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

            $earnings[] = [
                'process_date' => $shippingDate,
                'delivered_date' => $shippingDate,
                'account_date' => $shippingDate,
                'buyer_code' => $this->buyerCode,
                'warehouse_code' => $this->warehouseCode,
                'delivery_course_code' => $courseCode,
                'note' => "WMSテスト（ピッカー専用）: {$scenario['name']}",
                'is_delivered' => false,
                'is_returned' => false,
                'details' => $details,
            ];
        }

        // Call API
        $response = SakemaruEarning::postData([
            'earnings' => $earnings
        ]);

        if (isset($response['success']) && $response['success']) {
            return ['success' => true];
        } else {
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

        if (!$course) {
            $this->warn("  Course {$courseCode} not found, skipping wave setting");
            return;
        }

        $existing = WaveSetting::where('warehouse_id', $this->warehouseId)
            ->where('delivery_course_id', $course->id)
            ->first();

        if ($existing) {
            $this->line("  ✓ Wave setting exists for course {$courseCode}");
            return;
        }

        // Create wave setting
        WaveSetting::create([
            'warehouse_id' => $this->warehouseId,
            'delivery_course_id' => $course->id,
            'picking_start_time' => '09:00:00',
            'picking_end_time' => '17:00:00',
            'is_active' => true,
        ]);

        $this->line("  ✓ Created wave setting for course {$courseCode}");
    }

    private function assignTasksToPicker(string $shippingDate): int
    {
        // Get waves for this shipping date
        $waves = Wave::where('shipping_date', $shippingDate)->get();

        if ($waves->isEmpty()) {
            $this->warn('  No waves found for this shipping date');
            return 0;
        }

        $waveIds = $waves->pluck('id')->toArray();

        // Assign all tasks in these waves to the picker
        $updated = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->whereIn('wave_id', $waveIds)
            ->whereNull('picker_id')
            ->update([
                'picker_id' => $this->pickerId,
                'updated_at' => now(),
            ]);

        return $updated;
    }
}
