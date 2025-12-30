<?php

namespace Database\Seeders;

use App\Enums\AvailableQuantityFlag;
use App\Models\WmsLocationLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates locations per floor per warehouse for each quantity flag type:
     * - CASE only (flag: 1)
     * - PIECE only (flag: 2)
     * - CASE + PIECE (flag: 3)
     *
     * NEW: Uses wms_location_levels for shelf management instead of code3
     */
    public function run(): void
    {
        $this->command->info('Clearing existing test data...');

        // Delete existing test locations and their levels
        DB::transaction(function () {
            // Get location IDs to delete
            $locationIds = DB::connection('sakemaru')
                ->table('locations')
                ->whereNotNull('code1')
                ->whereNotNull('code2')
                ->pluck('id');

            if ($locationIds->isNotEmpty()) {
                // Delete WMS levels first
                WmsLocationLevel::whereIn('location_id', $locationIds)->delete();

                // Delete locations
                DB::connection('sakemaru')
                    ->table('locations')
                    ->whereIn('id', $locationIds)
                    ->delete();

                $this->command->info("  Deleted " . $locationIds->count() . " existing locations and their levels");
            }
        });

        $this->command->info('Generating new locations for all warehouse floors...');

        // Get all floors
        $floors = DB::connection('sakemaru')
            ->table('floors as f')
            ->join('warehouses as w', 'w.id', '=', 'f.warehouse_id')
            ->where('w.is_active', true)
            ->select('f.*', 'w.client_id', 'w.code as warehouse_code', 'w.name as warehouse_name')
            ->orderBy('f.warehouse_id')
            ->orderBy('f.code')
            ->get();

        if ($floors->isEmpty()) {
            $this->command->error('No floors found. Please run FloorSeeder first.');
            return;
        }

        $this->command->info("Found {$floors->count()} floor(s)");

        // Define location types with their flags
        $locationTypes = [
            ['code1' => 'A', 'code2' => '001', 'name' => 'ケースエリア', 'flag' => AvailableQuantityFlag::CASE->value],
            ['code1' => 'B', 'code2' => '001', 'name' => 'バラエリア', 'flag' => AvailableQuantityFlag::PIECE->value],
            ['code1' => 'D', 'code2' => '001', 'name' => 'ケース+バラエリア', 'flag' => AvailableQuantityFlag::CASE->value | AvailableQuantityFlag::PIECE->value],
        ];

        $createdCount = 0;

        DB::transaction(function () use ($floors, $locationTypes, &$createdCount) {
            foreach ($floors as $floor) {
                foreach ($locationTypes as $locType) {
                    // Create ONE location per code1+code2 (no code3)
                    $locationId = DB::connection('sakemaru')->table('locations')->insertGetId([
                        'client_id' => $floor->client_id,
                        'warehouse_id' => $floor->warehouse_id,
                        'floor_id' => $floor->id,
                        'code1' => $locType['code1'],
                        'code2' => $locType['code2'],
                        'code3' => null, // No longer using code3
                        'name' => "{$locType['name']} {$floor->code}",
                        'available_quantity_flags' => $locType['flag'],
                        'x1_pos' => 0,
                        'y1_pos' => 0,
                        'x2_pos' => 0,
                        'y2_pos' => 0,
                        'creator_id' => 0,
                        'last_updater_id' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Create ONE WMS level (level 1) for this location
                    WmsLocationLevel::create([
                        'location_id' => $locationId,
                        'level_number' => 1,
                        'name' => "{$locType['name']} 1段",
                        'available_quantity_flags' => $locType['flag'],
                    ]);

                    $createdCount++;
                    $this->command->line("  Created location {$locType['code1']}-{$locType['code2']} with 1 level ({$locType['name']}) for warehouse [{$floor->warehouse_code}] floor [{$floor->code}]");
                }
            }
        });

        $this->command->info("✓ Created {$createdCount} location(s) with levels");
    }
}

