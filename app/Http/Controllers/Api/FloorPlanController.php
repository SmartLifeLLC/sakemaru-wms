<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsLocationLevel;
use App\Models\WmsWarehouseLayout;
use App\Models\WmsFloorObject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FloorPlanController extends Controller
{
    /**
     * Get all warehouses
     */
    public function getWarehouses()
    {
        $warehouses = Warehouse::where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return response()->json([
            'success' => true,
            'data' => $warehouses,
        ]);
    }

    /**
     * Get floors for a warehouse
     */
    public function getFloors($warehouseId)
    {
        $floors = Floor::where('warehouse_id', $warehouseId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'warehouse_id']);

        return response()->json([
            'success' => true,
            'data' => $floors,
        ]);
    }

    /**
     * Get zones (locations grouped by code1+code2) for a floor
     * Each zone represents a rack position (code1+code2), with multiple shelves (code3) as tabs
     */
    public function getZones($floorId)
    {
        // Get all locations for this floor (regardless of position)
        // Exclude default location (ZZ1100)
        $locations = Location::where('floor_id', $floorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->where('code1', '!=', 'ZZ')  // Exclude default location
            ->orderBy('code1')
            ->orderBy('code2')
            ->orderBy('code3')
            ->get();

        // Group locations by code1+code2 to form zones
        $zoneGroups = [];
        foreach ($locations as $location) {
            $zoneKey = $location->code1 . '-' . $location->code2;
            if (!isset($zoneGroups[$zoneKey])) {
                $zoneGroups[$zoneKey] = [
                    'locations' => [],
                    'first_location' => $location,
                    // Track max position (use largest position found in group)
                    'max_x1' => 0,
                    'max_y1' => 0,
                    'max_x2' => 0,
                    'max_y2' => 0,
                ];
            }
            $zoneGroups[$zoneKey]['locations'][] = $location;

            // Keep track of the best position (non-zero)
            if ($location->x1_pos > 0 || $location->y1_pos > 0) {
                $zoneGroups[$zoneKey]['max_x1'] = max($zoneGroups[$zoneKey]['max_x1'], $location->x1_pos ?? 0);
                $zoneGroups[$zoneKey]['max_y1'] = max($zoneGroups[$zoneKey]['max_y1'], $location->y1_pos ?? 0);
                $zoneGroups[$zoneKey]['max_x2'] = max($zoneGroups[$zoneKey]['max_x2'], $location->x2_pos ?? 0);
                $zoneGroups[$zoneKey]['max_y2'] = max($zoneGroups[$zoneKey]['max_y2'], $location->y2_pos ?? 0);
            }
        }

        $zones = [];
        $zoneIndex = 0;
        foreach ($zoneGroups as $zoneKey => $group) {
            $firstLoc = $group['first_location'];
            $locationIds = collect($group['locations'])->pluck('id')->toArray();

            // Use stored position or auto-generate grid position
            $x1 = $group['max_x1'];
            $y1 = $group['max_y1'];
            $x2 = $group['max_x2'];
            $y2 = $group['max_y2'];

            // Auto-generate position if none set
            if ($x1 == 0 && $y1 == 0) {
                $row = intdiv($zoneIndex, 30);
                $col = $zoneIndex % 30;
                $x1 = 50 + $col * 45;
                $y1 = 50 + $row * 35;
                $x2 = $x1 + 40;
                $y2 = $y1 + 30;
            }

            // Get total stock count for all locations in this zone
            $stockCount = DB::connection('sakemaru')
                ->table('real_stocks')
                ->whereIn('location_id', $locationIds)
                ->sum('current_quantity');

            // Collect shelf info (code3) for tabs
            $shelves = [];
            foreach ($group['locations'] as $loc) {
                $shelfStockCount = DB::connection('sakemaru')
                    ->table('real_stocks')
                    ->where('location_id', $loc->id)
                    ->sum('current_quantity');

                $shelves[] = [
                    'location_id' => $loc->id,
                    'code3' => $loc->code3,
                    'name' => $loc->name,
                    'stock_count' => $shelfStockCount ?: 0,
                ];
            }

            $zones[] = [
                'id' => $firstLoc->id,  // Use first location's ID as zone ID
                'zone_key' => $zoneKey,
                'warehouse_id' => $firstLoc->warehouse_id,
                'floor_id' => $firstLoc->floor_id,
                'code1' => $firstLoc->code1,
                'code2' => $firstLoc->code2,
                'name' => $firstLoc->code1 . $firstLoc->code2,  // Zone name = code1+code2 only
                'display_name' => $firstLoc->code1 . $firstLoc->code2,  // For zone label
                'x1_pos' => $x1,
                'y1_pos' => $y1,
                'x2_pos' => $x2,
                'y2_pos' => $y2,
                'available_quantity_flags' => $firstLoc->available_quantity_flags,
                'shelves' => $shelves,  // Array of code3 tabs with full names
                'shelf_count' => count($shelves),
                'stock_count' => $stockCount ?: 0,
                'location_ids' => $locationIds,  // All location IDs in this zone
            ];

            $zoneIndex++;
        }

        return response()->json([
            'success' => true,
            'data' => $zones,
        ]);
    }

    /**
     * Save zones for a floor
     */
    public function saveZones(Request $request, $floorId)
    {
        $validator = Validator::make($request->all(), [
            'zones' => 'required|array',
            'zones.*.code1' => 'required|string|max:10',
            'zones.*.code2' => 'required|string|max:10',
            'zones.*.name' => 'required|string|max:255',
            'zones.*.x1_pos' => 'required|integer|min:0',
            'zones.*.y1_pos' => 'required|integer|min:0',
            'zones.*.x2_pos' => 'required|integer|min:0',
            'zones.*.y2_pos' => 'required|integer|min:0',
            'zones.*.available_quantity_flags' => 'required|integer',
            'zones.*.levels' => 'integer|min:1|max:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $floor = Floor::findOrFail($floorId);
        $zones = $request->input('zones');

        DB::transaction(function () use ($floor, $zones) {
            $processedLocationIds = [];

            foreach ($zones as $zone) {
                $levels = $zone['levels'] ?? 1;

                $locationData = [
                    'client_id' => $floor->client_id,
                    'warehouse_id' => $floor->warehouse_id,
                    'floor_id' => $floor->id,
                    'code1' => $zone['code1'],
                    'code2' => $zone['code2'],
                    'code3' => null, // No longer using code3
                    'name' => $zone['name'],
                    'x1_pos' => $zone['x1_pos'],
                    'y1_pos' => $zone['y1_pos'],
                    'x2_pos' => $zone['x2_pos'],
                    'y2_pos' => $zone['y2_pos'],
                    'available_quantity_flags' => $zone['available_quantity_flags'],
                    'last_updater_id' => 0,
                    'updated_at' => now(),
                ];

                // Find or create location for this code1+code2
                $location = Location::updateOrCreate(
                    [
                        'floor_id' => $floor->id,
                        'code1' => $zone['code1'],
                        'code2' => $zone['code2'],
                    ],
                    array_merge($locationData, [
                        'creator_id' => 0,
                        'created_at' => now(),
                    ])
                );

                $processedLocationIds[] = $location->id;

                // Sync levels in wms_location_levels
                $existingLevels = WmsLocationLevel::where('location_id', $location->id)
                    ->orderBy('level_number')
                    ->get();

                // Update or create levels
                for ($level = 1; $level <= $levels; $level++) {
                    WmsLocationLevel::updateOrCreate(
                        [
                            'location_id' => $location->id,
                            'level_number' => $level,
                        ],
                        [
                            'name' => "{$zone['name']} {$level}段",
                            'available_quantity_flags' => $zone['available_quantity_flags'],
                        ]
                    );
                }

                // Remove levels that exceed the new count
                if ($levels < $existingLevels->count()) {
                    WmsLocationLevel::where('location_id', $location->id)
                        ->where('level_number', '>', $levels)
                        ->delete();
                }
            }

            // Delete locations that were removed from the layout (keep positions > 0)
            Location::where('floor_id', $floor->id)
                ->whereNotNull('code1')
                ->whereNotNull('code2')
                ->whereNotIn('id', $processedLocationIds)
                ->where(function ($query) {
                    $query->where('x1_pos', '>', 0)
                        ->orWhere('y1_pos', '>', 0)
                        ->orWhere('x2_pos', '>', 0)
                        ->orWhere('y2_pos', '>', 0);
                })
                ->each(function ($location) {
                    // Delete associated levels first
                    WmsLocationLevel::where('location_id', $location->id)->delete();
                    $location->delete();
                });
        });

        return response()->json([
            'success' => true,
            'message' => 'フロアプランを保存しました',
        ]);
    }

    /**
     * Get unpositioned locations for a floor (locations with no x/y coordinates set)
     * Returns locations GROUPED by code1+code2 (one entry per zone)
     */
    public function getUnpositionedLocations($floorId)
    {
        // Get all locations for the floor, grouped by code1+code2
        // Exclude default location (ZZ1100)
        $locations = Location::where('floor_id', $floorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->where('code1', '!=', 'ZZ')  // Exclude default location
            ->orderBy('code1')
            ->orderBy('code2')
            ->orderBy('code3')
            ->get(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'code3', 'name', 'available_quantity_flags', 'x1_pos', 'y1_pos', 'x2_pos', 'y2_pos']);

        // Group by code1+code2 to form zones
        $zoneGroups = [];
        foreach ($locations as $location) {
            $zoneKey = $location->code1 . '-' . $location->code2;
            if (!isset($zoneGroups[$zoneKey])) {
                $zoneGroups[$zoneKey] = [
                    'locations' => [],
                    'first_location' => $location,
                    'has_position' => false,
                ];
            }
            $zoneGroups[$zoneKey]['locations'][] = $location;

            // Check if any location in the group has a position
            if ($location->x1_pos > 0 || $location->y1_pos > 0) {
                $zoneGroups[$zoneKey]['has_position'] = true;
            }
        }

        $result = [];

        // Only return zones that have NO position set
        foreach ($zoneGroups as $zoneKey => $group) {
            // Skip zones that already have position
            if ($group['has_position']) {
                continue;
            }

            $firstLoc = $group['first_location'];
            $locationIds = collect($group['locations'])->pluck('id')->toArray();

            // Get stock count for all locations in zone
            $stockCount = DB::connection('sakemaru')
                ->table('real_stocks')
                ->whereIn('location_id', $locationIds)
                ->sum('current_quantity');

            // Get total levels count for all locations in zone
            $levelsCount = WmsLocationLevel::whereIn('location_id', $locationIds)->count();

            $result[] = [
                'id' => $firstLoc->id,
                'zone_key' => $zoneKey,
                'warehouse_id' => $firstLoc->warehouse_id,
                'floor_id' => $firstLoc->floor_id,
                'code1' => $firstLoc->code1,
                'code2' => $firstLoc->code2,
                'name' => $firstLoc->code1 . $firstLoc->code2,  // Zone name = code1+code2
                'available_quantity_flags' => $firstLoc->available_quantity_flags,
                'stock_count' => $stockCount ?: 0,
                'levels' => $levelsCount ?: 0,
                'shelf_count' => count($group['locations']),
                'location_ids' => $locationIds,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Get stock data for a zone (all locations with same code1+code2)
     * Returns stocks grouped by shelf (code3) as tabs
     */
    public function getZoneStocks($locationId)
    {
        $location = Location::findOrFail($locationId);

        // Get all locations with same code1+code2 (same zone/rack)
        $zoneLocations = Location::where('floor_id', $location->floor_id)
            ->where('code1', $location->code1)
            ->where('code2', $location->code2)
            ->orderBy('code3')
            ->get();

        $shelfStocks = [];
        foreach ($zoneLocations as $index => $loc) {
            // Get stock data for this shelf
            $stocks = DB::connection('sakemaru')
                ->table('real_stocks as rs')
                ->leftJoin('items as i', 'rs.item_id', '=', 'i.id')
                ->where('rs.location_id', $loc->id)
                ->where('rs.current_quantity', '>', 0)
                ->select([
                    'rs.id as real_stock_id',
                    'rs.item_id',
                    'i.name as item_name',
                    'i.capacity_case',
                    'i.volume',
                    'i.volume_unit',
                    'rs.expiration_date',
                    'rs.current_quantity as total_qty',
                ])
                ->orderBy('i.name')
                ->orderBy('rs.expiration_date')
                ->get();

            $items = [];
            foreach ($stocks as $stock) {
                $items[] = [
                    'real_stock_id' => $stock->real_stock_id,
                    'item_id' => $stock->item_id,
                    'item_name' => $stock->item_name,
                    'capacity_case' => $stock->capacity_case,
                    'volume' => $stock->volume,
                    'volume_unit_name' => \App\Enums\EVolumeUnit::tryFrom($stock->volume_unit)?->name() ?? $stock->volume_unit,
                    'expiration_date' => $stock->expiration_date,
                    'total_qty' => (int) $stock->total_qty,
                ];
            }

            // Use code3 as tab key (1-indexed for display)
            $tabKey = $index + 1;
            $shelfStocks[$tabKey] = [
                'level' => $tabKey,
                'level_id' => null,
                'location_id' => $loc->id,
                'code3' => $loc->code3,
                'shelf_name' => $loc->name,
                'items' => $items,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $shelfStocks,
        ]);
    }

    /**
     * Export floor plan as CSV (Shift_JIS for Excel compatibility)
     */
    public function exportCSV($floorId)
    {
        $floor = Floor::findOrFail($floorId);

        $locations = Location::where('floor_id', $floorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->orderBy('code1')
            ->orderBy('code2')
            ->orderBy('code3')
            ->get();

        $headers = ['ロケーションID', '通路', '棚', '段', '名称', '引当可能単位', '現在庫数', '引当可能数'];
        $rows = [$headers];

        foreach ($locations as $location) {
            $quantityType = match ($location->available_quantity_flags) {
                1 => 'ケース',
                2 => 'バラ',
                3 => 'ケース+バラ',
                4 => 'ボール',
                default => '無し',
            };

            // Get stock for this location
            $stock = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('location_id', $location->id)
                ->select([
                    DB::raw('SUM(current_quantity) as current_qty'),
                    DB::raw('SUM(available_quantity) as available_qty'),
                ])
                ->first();

            $rows[] = [
                $location->code1 . $location->code2 . $location->code3,
                $location->code1,
                $location->code2,
                $location->code3,
                $location->name,
                $quantityType,
                $stock->current_qty ?? 0,
                $stock->available_qty ?? 0,
            ];
        }

        // Convert to CSV
        $csvContent = '';
        foreach ($rows as $row) {
            $csvContent .= implode(',', array_map(function ($value) {
                $str = (string) $value;
                if (preg_match('/[",\n\r]/', $str)) {
                    return '"' . str_replace('"', '""', $str) . '"';
                }
                return $str;
            }, $row)) . "\r\n";
        }

        // Convert to Shift_JIS for Excel compatibility
        $csvContent = mb_convert_encoding($csvContent, 'SJIS-win', 'UTF-8');

        $timestamp = now()->format('Ymd_His');
        $filename = "floor_plan_{$floor->code}_{$timestamp}.csv";

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv; charset=Shift_JIS',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
