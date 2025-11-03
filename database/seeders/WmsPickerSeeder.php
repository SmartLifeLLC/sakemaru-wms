<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WmsPickerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouseId = $this->command->option('warehouse-id') ?? 991;

        $this->command->info("Generating WMS pickers for warehouse {$warehouseId}");

        // Clear existing test pickers
        DB::connection('sakemaru')
            ->table('wms_pickers')
            ->where('code', 'LIKE', 'TEST%')
            ->delete();

        $this->command->info('Cleared existing test pickers');

        // Create test pickers
        $pickers = [
            ['code' => 'TEST001', 'name' => 'テストピッカー１', 'password' => 'password123'],
            ['code' => 'TEST002', 'name' => 'テストピッカー２', 'password' => 'password123'],
            ['code' => 'TEST003', 'name' => 'テストピッカー３', 'password' => 'password123'],
        ];

        $createdCount = 0;

        foreach ($pickers as $picker) {
            DB::connection('sakemaru')->table('wms_pickers')->insert([
                'code' => $picker['code'],
                'name' => $picker['name'],
                'password' => Hash::make($picker['password']),
                'default_warehouse_id' => $warehouseId,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $createdCount++;
        }

        $this->command->info("Created {$createdCount} test pickers");
    }
}
