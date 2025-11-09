<?php

namespace Database\Seeders;

use App\Enums\AvailableQuantityFlag;
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
        // $walkingOrder = 1; // Removed: walking_order is no longer used

        // Distribute locations among picking areas
        $locationsPerArea = ceil($locations->count() / $pickingAreas->count());

        foreach ($pickingAreas as $index => $area) {
            $areaLocations = $locations->slice($index * $locationsPerArea, $locationsPerArea);

            foreach ($areaLocations as $locationIndex => $location) {
                // Use code1, code2, code3 from location
                $aisle = $location->code1 ?? null;
                $rack = $location->code2 ?? null;
                $level = $location->code3 ?? null;

                // Determine picking_unit_type and available_quantity_flags based on area
                [$pickingUnitType, $availableFlags] = match($area->code) {
                    'A' => ['CASE', AvailableQuantityFlag::CASE->value], // Only CASE
                    'B' => ['PIECE', AvailableQuantityFlag::PIECE->value], // Only PIECE
                    'C' => ['BOTH', AvailableQuantityFlag::CASE->value | AvailableQuantityFlag::PIECE->value], // CASE + PIECE
                    default => ['BOTH', AvailableQuantityFlag::CASE->value | AvailableQuantityFlag::PIECE->value | AvailableQuantityFlag::CARTON->value], // All types
                };

                // Update location with available_quantity_flags
                DB::connection('sakemaru')
                    ->table('locations')
                    ->where('id', $location->id)
                    ->update(['available_quantity_flags' => $availableFlags]);

                WmsLocation::create([
                    'location_id' => $location->id,
                    'wms_picking_area_id' => $area->id,
                    'picking_unit_type' => $pickingUnitType,
                    // 'walking_order' => $walkingOrder++, // Removed: walking_order is no longer used
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
