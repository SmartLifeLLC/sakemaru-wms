<?php

namespace Database\Seeders;

use App\Models\Sakemaru\Location;
use App\Models\WmsLocation;
use App\Models\WmsPickingArea;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WmsLocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouseId = $this->command->option('warehouse-id') ?? 991;

        $this->command->info("Generating WMS locations for warehouse {$warehouseId}");

        // Get picking areas for this warehouse
        $pickingAreas = WmsPickingArea::where('warehouse_id', $warehouseId)->get();

        if ($pickingAreas->isEmpty()) {
            $this->command->error("No picking areas found for warehouse {$warehouseId}. Please run WmsPickingAreaSeeder first.");
            return;
        }

        $this->command->info("Found {$pickingAreas->count()} picking areas");

        // Get locations for this warehouse
        $locations = Location::where('warehouse_id', $warehouseId)->get();

        if ($locations->isEmpty()) {
            $this->command->error("No locations found for warehouse {$warehouseId}. Please run RealStockSeeder first.");
            return;
        }

        $this->command->info("Found {$locations->count()} locations");

        // Clear existing wms_locations for this warehouse's locations
        $locationIds = $locations->pluck('id')->toArray();
        DB::connection('sakemaru')
            ->table('wms_locations')
            ->whereIn('location_id', $locationIds)
            ->delete();

        $this->command->info('Cleared existing WMS locations');

        // Assign locations to picking areas
        $createdCount = 0;
        $walkingOrder = 1;

        // Distribute locations among picking areas
        $locationsPerArea = ceil($locations->count() / $pickingAreas->count());

        foreach ($pickingAreas as $index => $area) {
            $areaLocations = $locations->slice($index * $locationsPerArea, $locationsPerArea);

            foreach ($areaLocations as $locationIndex => $location) {
                // Use code1, code2, code3 from location
                $aisle = $location->code1 ?? null;
                $rack = $location->code2 ?? null;
                $level = $location->code3 ?? null;

                // Determine picking_unit_type based on area
                $pickingUnitType = match($area->code) {
                    'A' => 'CASE',
                    'B' => 'PIECE',
                    default => 'BOTH',
                };

                WmsLocation::create([
                    'location_id' => $location->id,
                    'wms_picking_area_id' => $area->id,
                    'picking_unit_type' => $pickingUnitType,
                    'walking_order' => $walkingOrder++,
                    'aisle' => $aisle,
                    'rack' => $rack,
                    'level' => $level,
                ]);

                $createdCount++;
            }
        }

        $this->command->info("Created {$createdCount} WMS location mappings");
    }
}
