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
     * - All warehouses: 1F
     * - Warehouse 991: 1F and 2F
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
            // Create 1F for all warehouses
            $exists1F = DB::connection('sakemaru')
                ->table('floors')
                ->where('warehouse_id', $warehouse->id)
                ->where('code', '1F')
                ->exists();

            if (!$exists1F) {
                DB::connection('sakemaru')->table('floors')->insert([
                    'warehouse_id' => $warehouse->id,
                    'code' => '1F',
                    'name' => '1階',
                    'display_order' => 1,
                    'creator_id' => 0,
                    'last_updater_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdCount++;
                $this->command->line("  Created 1F for warehouse [{$warehouse->code}] {$warehouse->name}");
            } else {
                $this->command->line("  Skipped 1F for warehouse [{$warehouse->code}] {$warehouse->name} (already exists)");
            }

            // Create 2F only for warehouse 991
            if ($warehouse->id == 991) {
                $exists2F = DB::connection('sakemaru')
                    ->table('floors')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('code', '2F')
                    ->exists();

                if (!$exists2F) {
                    DB::connection('sakemaru')->table('floors')->insert([
                        'warehouse_id' => $warehouse->id,
                        'code' => '2F',
                        'name' => '2階',
                        'display_order' => 2,
                        'creator_id' => 0,
                        'last_updater_id' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $createdCount++;
                    $this->command->line("  Created 2F for warehouse [{$warehouse->code}] {$warehouse->name}");
                } else {
                    $this->command->line("  Skipped 2F for warehouse [{$warehouse->code}] {$warehouse->name} (already exists)");
                }
            }
        }

        $this->command->info("✓ Created {$createdCount} floor(s)");
    }
}
