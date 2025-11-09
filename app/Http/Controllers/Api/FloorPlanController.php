<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsLocationLevel;
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
     * Get stock data for a location (grouped by WMS shelf level)
     */
    public function getZoneStocks($locationId)
    {
        $location = Location::findOrFail($locationId);

        // Get WMS levels for this location
        $wmsLevels = WmsLocationLevel::where('location_id', $location->id)
            ->orderBy('level_number')
            ->get();

        $levelStocks = [];

        // If no WMS levels exist, show location-level stock as level 1
        if ($wmsLevels->isEmpty()) {
            // Get stock data for this location
            $stocks = DB::connection('sakemaru')
                ->table('real_stocks as rs')
                ->leftJoin('wms_real_stocks as wrs', 'rs.id', '=', 'wrs.real_stock_id')
                ->leftJoin('items as i', 'rs.item_id', '=', 'i.id')
                ->where('rs.location_id', $location->id)
                ->select([
                    'rs.item_id',
                    'i.code as item_code',
                    'i.name as item_name',
                    DB::raw('SUM(rs.current_quantity) as current_qty'),
                    DB::raw('SUM(COALESCE(wrs.reserved_quantity, 0)) as reserved_qty'),
                    DB::raw('SUM(rs.available_quantity) as available_qty'),
                ])
                ->groupBy('rs.item_id', 'i.code', 'i.name')
                ->get();

            $currentTotal = 0;
            $reservedTotal = 0;
            $availableTotal = 0;
            $items = [];

            foreach ($stocks as $stock) {
                $currentTotal += $stock->current_qty;
                $reservedTotal += $stock->reserved_qty;
                $availableTotal += $stock->available_qty;

                $items[] = [
                    'item_id' => $stock->item_id,
                    'item_code' => $stock->item_code,
                    'item_name' => $stock->item_name,
                    'current_qty' => (int) $stock->current_qty,
                    'reserved_qty' => (int) $stock->reserved_qty,
                    'available_qty' => (int) $stock->available_qty,
                ];
            }

            $levelStocks[1] = [
                'level' => 1,
                'level_id' => null,
                'location_id' => $location->id,
                'current_qty' => $currentTotal,
                'reserved_qty' => $reservedTotal,
                'available_qty' => $availableTotal,
                'items' => $items,
            ];
        } else {
            // Show WMS levels (currently all stock is at location level, not level-specific)
            foreach ($wmsLevels as $wmsLevel) {
                // For now, show stock at location level for each WMS level
                // In the future, you might track stock per WMS level
                $levelStocks[$wmsLevel->level_number] = [
                    'level' => $wmsLevel->level_number,
                    'level_id' => $wmsLevel->id,
                    'location_id' => $location->id,
                    'current_qty' => 0,
                    'reserved_qty' => 0,
                    'available_qty' => 0,
                    'items' => [],
                ];
            }
        }

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
