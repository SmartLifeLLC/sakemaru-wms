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
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'warehouse_id']);

        return response()->json([
            'success' => true,
            'data' => $floors,
        ]);
    }

    /**
     * Get zones (locations with WMS levels) for a floor
     */
    public function getZones($floorId)
    {
        // Get locations that have position set (x1_pos, y1_pos, x2_pos, y2_pos are not null/0)
        $locations = Location::where('floor_id', $floorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->where(function ($query) {
                $query->where('x1_pos', '>', 0)
                    ->orWhere('y1_pos', '>', 0)
                    ->orWhere('x2_pos', '>', 0)
                    ->orWhere('y2_pos', '>', 0);
            })
            ->orderBy('code1')
            ->orderBy('code2')
            ->get();

        $zones = [];

        foreach ($locations as $location) {
            // Count levels from wms_location_levels
            $levelsCount = WmsLocationLevel::where('location_id', $location->id)->count();

            // Get stock count for this location
            $stockCount = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('location_id', $location->id)
                ->sum('current_quantity');

            $zones[] = [
                'id' => $location->id,
                'warehouse_id' => $location->warehouse_id,
                'floor_id' => $location->floor_id,
                'code1' => $location->code1,
                'code2' => $location->code2,
                'name' => $location->name,
                'x1_pos' => $location->x1_pos,
                'y1_pos' => $location->y1_pos,
                'x2_pos' => $location->x2_pos,
                'y2_pos' => $location->y2_pos,
                'available_quantity_flags' => $location->available_quantity_flags,
                'levels' => $levelsCount ?: 1,
                'stock_count' => $stockCount ?: 0,
            ];
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
     */
    public function getUnpositionedLocations($floorId)
    {
        $locations = Location::where('floor_id', $floorId)
            ->whereNotNull('code1')
            ->whereNotNull('code2')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('x1_pos')->whereNull('y1_pos')
                      ->whereNull('x2_pos')->whereNull('y2_pos');
                })
                ->orWhere(function ($q) {
                    $q->where('x1_pos', 0)->where('y1_pos', 0)
                      ->where('x2_pos', 0)->where('y2_pos', 0);
                });
            })
            ->orderBy('code1')
            ->orderBy('code2')
            ->get(['id', 'warehouse_id', 'floor_id', 'code1', 'code2', 'name', 'available_quantity_flags']);

        $result = [];

        // Get stock counts and level counts for each location
        foreach ($locations as $location) {
            $stockCount = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('location_id', $location->id)
                ->sum('current_quantity');

            $levelsCount = WmsLocationLevel::where('location_id', $location->id)->count();

            $result[] = [
                'id' => $location->id,
                'warehouse_id' => $location->warehouse_id,
                'floor_id' => $location->floor_id,
                'code1' => $location->code1,
                'code2' => $location->code2,
                'name' => $location->name,
                'available_quantity_flags' => $location->available_quantity_flags,
                'stock_count' => $stockCount ?: 0,
                'levels' => $levelsCount ?: 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Get stock data for a location (grouped by expiration date and item details)
     */
     public function getZoneStocks($locationId)
     {
         $location = Location::findOrFail($locationId);

         // Get stock data for this location, joining items to get details and grouping by item and expiration date
         $stocks = DB::connection('sakemaru')
             ->table('real_stocks as rs')
             ->leftJoin('items as i', 'rs.item_id', '=', 'i.id')
             ->where('rs.location_id', $location->id)
             ->select([
                 'rs.item_id',
                 'i.name as item_name',
                 'i.capacity_case',
                 'i.volume',
                 'i.volume_unit',
                 'rs.expiration_date',
                 DB::raw('SUM(rs.current_quantity) as total_qty'),
             ])
             ->groupBy('rs.item_id', 'i.name', 'i.capacity_case', 'i.volume', 'i.volume_unit', 'rs.expiration_date')
             ->orderBy('i.name')
             ->orderBy('rs.expiration_date')
             ->get();

         $items = [];
         foreach ($stocks as $stock) {
             $items[] = [
                 'item_id' => $stock->item_id,
                 'item_name' => $stock->item_name,
                 'capacity_case' => $stock->capacity_case,
                 'volume' => $stock->volume,
                 'volume_unit_name' => \App\Enums\EVolumeUnit::tryFrom($stock->volume_unit)?->name() ?? $stock->volume_unit,
                 'expiration_date' => $stock->expiration_date,
                 'total_qty' => (int) $stock->total_qty,
             ];
         }

         // Return as a single level (level 1) since we are not using WMS levels for stock now
         $levelStocks = [
             1 => [
                 'level' => 1,
                 'level_id' => null,
                 'location_id' => $location->id,
                 'items' => $items,
             ],
         ];

         return response()->json([
             'success' => true,
             'data' => $levelStocks,
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
