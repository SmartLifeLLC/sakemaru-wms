<?php

namespace Database\Seeders;

use App\Enums\AvailableQuantityFlag;
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
     * - CARTON only (flag: 4)
     * - CASE + PIECE (flag: 3)
     * - ALL types (flag: 7)
     */
    public function run(): void
    {
        $this->command->info('Generating locations for all warehouse floors...');

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
        // Note: CARTON is not used in testing, only CASE and PIECE
        $locationTypes = [
            ['code1' => 'A', 'code2' => '001', 'name' => 'ケースエリア', 'flag' => AvailableQuantityFlag::CASE->value], // 1
            ['code1' => 'B', 'code2' => '001', 'name' => 'バラエリア', 'flag' => AvailableQuantityFlag::PIECE->value], // 2
            ['code1' => 'D', 'code2' => '001', 'name' => 'ケース+バラエリア', 'flag' => AvailableQuantityFlag::CASE->value | AvailableQuantityFlag::PIECE->value], // 3
        ];

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($floors as $floor) {
            foreach ($locationTypes as $locType) {
                // Create locations for each shelf level (code3: 1-3)
                for ($shelfLevel = 1; $shelfLevel <= 3; $shelfLevel++) {
                    $code3 = (string) $shelfLevel;

                    // Check if location already exists
                    $exists = DB::connection('sakemaru')
                        ->table('locations')
                        ->where('warehouse_id', $floor->warehouse_id)
                        ->where('floor_id', $floor->id)
                        ->where('code1', $locType['code1'])
                        ->where('code2', $locType['code2'])
                        ->where('code3', $code3)
                        ->exists();

                    if ($exists) {
                        $skippedCount++;
                        continue;
                    }

                    // Create location
                    DB::connection('sakemaru')->table('locations')->insert([
                        'client_id' => $floor->client_id,
                        'warehouse_id' => $floor->warehouse_id,
                        'floor_id' => $floor->id,
                        'code1' => $locType['code1'],
                        'code2' => $locType['code2'],
                        'code3' => $code3,
                        'name' => "{$locType['name']} {$floor->code} {$locType['code1']}{$locType['code2']}{$code3}",
                        'available_quantity_flags' => $locType['flag'],
                        'creator_id' => 0,
                        'last_updater_id' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $createdCount++;
                    $this->command->line("  Created location {$locType['code1']}-{$locType['code2']}-{$code3} ({$locType['name']}, flag:{$locType['flag']}) for warehouse [{$floor->warehouse_code}] floor [{$floor->code}]");
                }
            }
        }

        $this->command->info("✓ Created {$createdCount} location(s), Skipped {$skippedCount}");
    }
}

