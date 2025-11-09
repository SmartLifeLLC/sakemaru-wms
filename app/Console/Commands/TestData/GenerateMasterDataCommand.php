<?php

namespace App\Console\Commands\TestData;

use App\Models\WmsLocationLevel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateMasterDataCommand extends Command
{
    protected $signature = 'testdata:master
                            {--warehouse-id= : Warehouse ID to generate data for}
                            {--floors=* : Floor configurations in format: number|name (e.g., 1|1F or -1|B1F)}
                            {--case-locations=10 : Number of CASE-only locations to create per floor}
                            {--piece-locations=10 : Number of PIECE-only locations to create per floor}
                            {--both-locations=5 : Number of CASE+PIECE locations to create per floor}';

    protected $description = 'Generate WMS master data (Floors and Locations) without stock data';

    private int $warehouseId;
    private int $clientId;
    private string $warehouseCode;

    public function handle()
    {
        $this->info('🏗️  Generating WMS Master Data...');
        $this->newLine();

        // Get warehouse ID
        $this->warehouseId = (int) $this->option('warehouse-id');
        if (!$this->warehouseId) {
            $this->error('Warehouse ID is required. Use --warehouse-id option.');
            return 1;
        }

        // Initialize warehouse data
        if (!$this->initializeWarehouse()) {
            return 1;
        }

        // Parse floor configurations
        $floorConfigs = $this->parseFloorConfigurations();
        if (empty($floorConfigs)) {
            // Default: create 1F and 2F
            $floorConfigs = [
                ['number' => 1, 'name' => '1F'],
                ['number' => 2, 'name' => '2F'],
            ];
        }

        // Get location counts
        $caseCount = (int) $this->option('case-locations');
        $pieceCount = (int) $this->option('piece-locations');
        $bothCount = (int) $this->option('both-locations');

        $this->line("Configuration:");
        $this->line("  Warehouse: {$this->warehouseId} (code: {$this->warehouseCode})");
        $this->line("  Floors: " . count($floorConfigs));
        $this->line("  CASE locations per floor: {$caseCount}");
        $this->line("  PIECE locations per floor: {$pieceCount}");
        $this->line("  CASE+PIECE locations per floor: {$bothCount}");
        $this->newLine();

        try {
            // Generate floors
            $floors = $this->generateFloors($floorConfigs);
            $this->info("✓ Created " . count($floors) . " floors");

            // Generate locations for each floor
            $totalLocations = 0;
            foreach ($floors as $floor) {
                $locationCount = $this->generateLocationsForFloor($floor, $caseCount, $pieceCount, $bothCount);
                $totalLocations += $locationCount;
            }
            $this->info("✓ Created {$totalLocations} locations");

            $this->newLine();
            $this->info('✅ WMS Master Data generation completed!');
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error generating master data: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    private function initializeWarehouse(): bool
    {
        $warehouse = DB::connection('sakemaru')->table('warehouses')
            ->where('id', $this->warehouseId)
            ->first();

        if (!$warehouse) {
            $this->error("Warehouse with ID {$this->warehouseId} not found");
            return false;
        }

        $this->warehouseCode = (string) $warehouse->code;
        $this->clientId = $warehouse->client_id ?? 1;

        return true;
    }

    private function parseFloorConfigurations(): array
    {
        $floors = $this->option('floors');
        $configs = [];

        foreach ($floors as $floorConfig) {
            // Format: number|name (e.g., 1|1F or -1|B1F)
            $parts = explode('|', $floorConfig);
            if (count($parts) !== 2) {
                $this->warn("Invalid floor configuration: {$floorConfig}. Expected format: number|name (e.g., 1|1F)");
                continue;
            }

            $configs[] = [
                'number' => (int) $parts[0],
                'name' => $parts[1],
            ];
        }

        return $configs;
    }

    private function generateFloors(array $floorConfigs): array
    {
        $floors = [];

        foreach ($floorConfigs as $config) {
            $floorNumber = $config['number'];
            $floorName = $config['name'];

            // Floor code: warehouse_code + floor number padded to 3 digits
            // Positive floors: 001, 002, 003
            // Negative floors (basement): 901, 902, 903
            if ($floorNumber > 0) {
                $floorCode = (int) ($this->warehouseCode . str_pad($floorNumber, 3, '0', STR_PAD_LEFT));
            } else {
                // Basement floors: use 900 + abs(floor_number)
                $floorCode = (int) ($this->warehouseCode . (900 + abs($floorNumber)));
            }

            // Check if floor already exists
            $existing = DB::connection('sakemaru')
                ->table('floors')
                ->where('warehouse_id', $this->warehouseId)
                ->where('code', $floorCode)
                ->first();

            if ($existing) {
                $this->line("  Floor {$floorName} (code: {$floorCode}) already exists, skipping");
                $floors[] = $existing;
                continue;
            }

            // Create floor
            $floorId = DB::connection('sakemaru')->table('floors')->insertGetId([
                'client_id' => $this->clientId,
                'warehouse_id' => $this->warehouseId,
                'code' => $floorCode,
                'name' => $floorName,
                'is_active' => true,
                'creator_id' => 0,
                'last_updater_id' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $floor = (object) [
                'id' => $floorId,
                'code' => $floorCode,
                'name' => $floorName,
            ];

            $floors[] = $floor;
            $this->line("  Created floor: {$floorName} (code: {$floorCode})");
        }

        return $floors;
    }

    private function generateLocationsForFloor(object $floor, int $caseCount, int $pieceCount, int $bothCount): int
    {
        $locationTypes = [
            ['prefix' => 'A', 'count' => $caseCount, 'flag' => 1, 'name' => 'ケース'],
            ['prefix' => 'B', 'count' => $pieceCount, 'flag' => 2, 'name' => 'バラ'],
            ['prefix' => 'D', 'count' => $bothCount, 'flag' => 3, 'name' => 'ケース+バラ'],
        ];

        $totalCreated = 0;
        $totalLevelsCreated = 0;

        // Get the next available code2 number for each prefix in this warehouse
        foreach ($locationTypes as $type) {
            if ($type['count'] <= 0) {
                continue;
            }

            // Find the highest existing code2 for this prefix in this warehouse
            $maxCode2 = DB::connection('sakemaru')
                ->table('locations')
                ->where('warehouse_id', $this->warehouseId)
                ->where('code1', $type['prefix'])
                ->max('code2');

            $startNumber = $maxCode2 ? (int)$maxCode2 + 1 : 1;

            for ($i = 0; $i < $type['count']; $i++) {
                $code1 = $type['prefix'];
                $code2 = str_pad($startNumber + $i, 3, '0', STR_PAD_LEFT); // 3-digit code2 (001-999)

                // Check if location already exists (unique constraint is on warehouse_id, code1, code2)
                $existing = DB::connection('sakemaru')
                    ->table('locations')
                    ->where('warehouse_id', $this->warehouseId)
                    ->where('code1', $code1)
                    ->where('code2', $code2)
                    ->whereNull('code3')
                    ->first();

                if ($existing) {
                    $this->line("    Location {$code1}{$code2} already exists, skipping");
                    continue;
                }

                // Create location with code3 = null
                $locationId = DB::connection('sakemaru')->table('locations')->insertGetId([
                    'client_id' => $this->clientId,
                    'warehouse_id' => $this->warehouseId,
                    'floor_id' => $floor->id,
                    'code1' => $code1,
                    'code2' => $code2,
                    'code3' => null,
                    'name' => "{$type['name']}エリア {$floor->name} {$code1}{$code2}",
                    'available_quantity_flags' => $type['flag'],
                    'creator_id' => 0,
                    'last_updater_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create ONE WMS level (level 1) for this location
                WmsLocationLevel::create([
                    'location_id' => $locationId,
                    'level_number' => 1,
                    'name' => "{$type['name']}エリア {$floor->name} {$code1}{$code2} 1段",
                    'available_quantity_flags' => $type['flag'],
                ]);

                $totalCreated++;
                $totalLevelsCreated++;
            }
        }

        $this->line("  Created {$totalCreated} locations with {$totalLevelsCreated} WMS levels for floor {$floor->name}");
        return $totalCreated;
    }
}
