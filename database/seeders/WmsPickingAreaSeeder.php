<?php

namespace Database\Seeders;

use App\Models\Sakemaru\Warehouse;
use App\Models\WmsPickingArea;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WmsPickingAreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouseId = $this->command->option('warehouse-id') ?? 991;

        $this->command->info("Generating WMS picking areas for warehouse {$warehouseId}");

        // Get warehouse
        $warehouse = Warehouse::find($warehouseId);
        if (!$warehouse) {
            $this->command->error("Warehouse {$warehouseId} not found");
            return;
        }

        // Clear existing picking areas for this warehouse
        DB::connection('sakemaru')
            ->table('wms_picking_areas')
            ->where('warehouse_id', $warehouseId)
            ->delete();

        $this->command->info('Cleared existing picking areas');

        // Create sample picking areas
        $areas = [
            ['code' => 'A', 'name' => 'エリアA（ケース）', 'display_order' => 1],
            ['code' => 'B', 'name' => 'エリアB（バラ）', 'display_order' => 2],
            ['code' => 'C', 'name' => 'エリアC（冷蔵）', 'display_order' => 3],
            ['code' => 'D', 'name' => 'エリアD（冷凍）', 'display_order' => 4],
        ];

        $createdCount = 0;

        foreach ($areas as $area) {
            WmsPickingArea::create([
                'warehouse_id' => $warehouseId,
                'code' => $area['code'],
                'name' => $area['name'],
                'display_order' => $area['display_order'],
                'is_active' => true,
            ]);

            $createdCount++;
        }

        $this->command->info("Created {$createdCount} picking areas");
    }
}
