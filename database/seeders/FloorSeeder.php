<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FloorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates floors for all warehouses:
     * - All warehouses: floor code = warehouse_code + 001 (e.g., 991 → 991001)
     * - Warehouse 991: floor code = 991001 and 991002
     */
    public function run(): void
    {
        $this->command->info('Generating floors for all warehouses...');

        // Get all active warehouses
        $warehouses = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($warehouses->isEmpty()) {
            $this->command->error('No active warehouses found');

            return;
        }

        $this->command->info("Found {$warehouses->count()} active warehouses");

        $createdCount = 0;

        foreach ($warehouses as $warehouse) {
            // Create floor 1 for all warehouses (warehouse_code + 001)
            $floor1Code = (int) ($warehouse->code.'001');
            $exists1F = DB::connection('sakemaru')
                ->table('floors')
                ->where('warehouse_id', $warehouse->id)
                ->where('code', $floor1Code)
                ->exists();

            if (! $exists1F) {
                DB::connection('sakemaru')->table('floors')->insert([
                    'client_id' => $warehouse->client_id ?? 1,
                    'warehouse_id' => $warehouse->id,
                    'code' => $floor1Code,
                    'name' => '1階',
                    'creator_id' => 0,
                    'last_updater_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdCount++;
                $this->command->line("  Created floor {$floor1Code} for warehouse [{$warehouse->code}] {$warehouse->name}");
            } else {
                $this->command->line("  Skipped floor {$floor1Code} for warehouse [{$warehouse->code}] {$warehouse->name} (already exists)");
            }

            // Create floor 2 only for warehouse 991 (warehouse_code + 002)
            if ($warehouse->id == 991) {
                $floor2Code = (int) ($warehouse->code.'002');
                $exists2F = DB::connection('sakemaru')
                    ->table('floors')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('code', $floor2Code)
                    ->exists();

                if (! $exists2F) {
                    DB::connection('sakemaru')->table('floors')->insert([
                        'client_id' => $warehouse->client_id ?? 1,
                        'warehouse_id' => $warehouse->id,
                        'code' => $floor2Code,
                        'name' => '2階',
                        'creator_id' => 0,
                        'last_updater_id' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $createdCount++;
                    $this->command->line("  Created floor {$floor2Code} for warehouse [{$warehouse->code}] {$warehouse->name}");
                } else {
                    $this->command->line("  Skipped floor {$floor2Code} for warehouse [{$warehouse->code}] {$warehouse->name} (already exists)");
                }
            }
        }

        $this->command->info("✓ Created {$createdCount} floor(s)");
    }
}
