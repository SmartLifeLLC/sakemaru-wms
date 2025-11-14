<?php

namespace App\Services\Picking;

use App\Models\Sakemaru\Location;
use App\Models\WmsWarehouseLayout;
use App\Models\WmsPickingItemResult;
use Illuminate\Support\Facades\DB;

/**
 * Picking route generation service
 * Integrates A*, front point calculation, distance caching, and route optimization
 * Based on specification: 20251111-optimize-picking-order2.md Section 3D
 */
class PickRouteService
{
    private FrontPointCalculator $frontPointCalculator;
    private RouteOptimizer $routeOptimizer;

    public function __construct()
    {
        // Use smaller delta (5px) to allow paths closer to locations and prevent breaks
        $this->frontPointCalculator = new FrontPointCalculator(5);
        $this->routeOptimizer = new RouteOptimizer();
    }

    /**
     * Build optimized picking route for given locations
     *
     * @param int $warehouseId Warehouse ID
     * @param int|null $floorId Floor ID
     * @param array $locationIds Array of location IDs to visit
     * @param array|null $startPoint Start point [x, y] (default: [100, 100])
     * @param int $cellSize Grid cell size for A* (default: 25)
     * @return array Optimized route information
     */
    public function buildRoute(
        int $warehouseId,
        ?int $floorId,
        array $locationIds,
        ?array $startPoint = null,
        int $cellSize = 25
    ): array {
        // Load layout
        $layout = WmsWarehouseLayout::where('warehouse_id', $warehouseId)
            ->where('floor_id', $floorId)
            ->first();

        if (!$layout) {
            throw new \RuntimeException("Layout not found for warehouse {$warehouseId}, floor {$floorId}");
        }

        // Use layout's picking start point if not explicitly provided
        if ($startPoint === null) {
            $startPoint = [
                $layout->picking_start_x ?? 100,
                $layout->picking_start_y ?? 100,
            ];
        }

        // Get end point from layout or use default
        $endPoint = [
            $layout->picking_end_x ?? $startPoint[0],
            $layout->picking_end_y ?? $startPoint[1],
        ];

        // Prepare layout data (including picking points for cache key)
        $layoutData = [
            'width' => $layout->width,
            'height' => $layout->height,
            'walls' => $layout->walls ?? [],
            'fixed_areas' => $layout->fixed_areas ?? [],
            'picking_start_x' => $startPoint[0],
            'picking_start_y' => $startPoint[1],
            'picking_end_x' => $endPoint[0],
            'picking_end_y' => $endPoint[1],
        ];

        // Calculate layout hash
        $layoutHash = DistanceCacheService::layoutHash($layoutData);

        // Load locations
        $locations = Location::whereIn('id', $locationIds)
            ->where('floor_id', $floorId)
            ->get()
            ->keyBy('id');

        if ($locations->isEmpty()) {
            return [
                'route' => [],
                'distance' => 0,
                'start_point' => $startPoint,
                'location_count' => 0,
            ];
        }

        // Prepare blocked rectangles (walls + fixed_areas only)
        // NOTE: We don't block locations themselves since we access them via front points
        // which are outside the location boundaries
        $blockedRects = [];

        // Add walls
        foreach ($layoutData['walls'] as $wall) {
            $blockedRects[] = $wall;
        }

        // Add fixed areas
        foreach ($layoutData['fixed_areas'] as $area) {
            $blockedRects[] = $area;
        }

        // Initialize A*
        $aStar = new AStarGrid($cellSize, $blockedRects, $layoutData['width'], $layoutData['height']);

        // Initialize distance cache
        $distanceCache = new DistanceCacheService($aStar, $warehouseId, $floorId, $layoutHash);

        // Create resolver for location keys to coordinates
        $resolver = function (string $key) use ($locations, $startPoint, $endPoint) {
            if ($key === 'NODE:START') {
                return $startPoint;
            }

            if ($key === 'NODE:END') {
                return $endPoint;
            }

            if (str_starts_with($key, 'LOC:')) {
                $locId = (int) substr($key, 4);
                $location = $locations->get($locId);

                if (!$location) {
                    throw new \RuntimeException("Location {$locId} not found");
                }

                return $this->frontPointCalculator->computeFrontPoint($location);
            }

            throw new \RuntimeException("Unknown key: {$key}");
        };

        // Build keys array
        $keys = array_map(fn($id) => "LOC:{$id}", $locationIds);
        array_unshift($keys, 'NODE:START');

        // Add end point only if it's different from start point
        if ($endPoint !== $startPoint) {
            $keys[] = 'NODE:END';
        }

        // Distance function with caching
        $distFunc = function (string $a, string $b) use ($distanceCache, $resolver) {
            $result = $distanceCache->getDistance($a, $b, $resolver);
            return $result['dist'];
        };

        // Optimize route
        $optimizedRoute = $this->routeOptimizer->optimizeRoute($keys, $distFunc);

        // Remove START and END nodes from route (keep only locations)
        $route = array_values(array_filter(
            $optimizedRoute['route'],
            fn($k) => !in_array($k, ['NODE:START', 'NODE:END'])
        ));

        // Convert keys back to location IDs
        $routeIds = array_map(fn($key) => (int) substr($key, 4), $route);

        return [
            'route' => $routeIds,
            'distance' => $optimizedRoute['distance'],
            'start_point' => $startPoint,
            'end_point' => $endPoint,
            'location_count' => count($routeIds),
            'layout_hash' => $layoutHash,
        ];
    }

    /**
     * Update walking order for picking items based on optimized route
     *
     * @param array $pickingItemIds Array of picking item result IDs
     * @param int $warehouseId Warehouse ID
     * @param int|null $floorId Floor ID
     * @param int|null $pickingTaskId Picking task ID (optional, for logging)
     * @return array Update statistics
     */
    public function updateWalkingOrder(array $pickingItemIds, int $warehouseId, ?int $floorId, ?int $pickingTaskId = null): array
    {
        $startTime = microtime(true);
        // Load picking items with locations and item details for sorting
        $items = WmsPickingItemResult::with(['location', 'item:id,code'])
            ->whereIn('id', $pickingItemIds)
            ->whereNotNull('location_id')
            ->get();

        if ($items->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No picking items found',
                'updated' => 0,
            ];
        }

        // Get unique location IDs
        $locationIds = $items->pluck('location_id')->unique()->values()->toArray();

        // Build optimized route (returns optimal location visit sequence)
        $routeResult = $this->buildRoute($warehouseId, $floorId, $locationIds);

        // Group items by location
        $itemsByLocation = $items->groupBy('location_id');

        // Assign sequential walking_order values across all items
        $walkingOrder = 1;
        $updated = 0;
        $previousPoint = $routeResult['start_point']; // Start from warehouse entrance
        $previousKey = 'NODE:START';

        // Initialize A* and distance cache for calculating distances
        $layout = WmsWarehouseLayout::where('warehouse_id', $warehouseId)
            ->where('floor_id', $floorId)
            ->first();

        if (!$layout) {
            throw new \RuntimeException("Layout not found for warehouse {$warehouseId}, floor {$floorId}");
        }

        $layoutData = [
            'width' => $layout->width,
            'height' => $layout->height,
            'walls' => $layout->walls ?? [],
            'fixed_areas' => $layout->fixed_areas ?? [],
        ];

        $blockedRects = array_merge($layoutData['walls'] ?? [], $layoutData['fixed_areas'] ?? []);
        $aStar = new AStarGrid(25, $blockedRects, $layoutData['width'], $layoutData['height']);
        $layoutHash = DistanceCacheService::layoutHash($layoutData);
        $distanceCache = new DistanceCacheService($aStar, $warehouseId, $floorId, $layoutHash);

        // Load all locations for front point calculation
        $allLocations = Location::whereIn('id', $locationIds)->get()->keyBy('id');

        // Visit locations in optimized order
        foreach ($routeResult['route'] as $locationId) {
            $locationItems = $itemsByLocation->get($locationId);

            if (!$locationItems) {
                continue;
            }

            // Get current location front point
            $location = $allLocations->get($locationId);
            if (!$location) {
                continue;
            }

            $currentPoint = $this->frontPointCalculator->computeFrontPoint($location);
            $currentKey = "LOC:{$locationId}";

            // Calculate distance from previous point using A*
            $resolver = function (string $key) use ($allLocations, $routeResult) {
                if ($key === 'NODE:START') {
                    return $routeResult['start_point'];
                }
                if (str_starts_with($key, 'LOC:')) {
                    $locId = (int) substr($key, 4);
                    $loc = $allLocations->get($locId);
                    if (!$loc) {
                        return null;
                    }
                    return $this->frontPointCalculator->computeFrontPoint($loc);
                }
                return null;
            };

            $distanceResult = $distanceCache->getDistance($previousKey, $currentKey, $resolver);
            $distanceFromPrevious = $distanceResult['dist'];

            // Sort items within same location by item code for consistency
            $sortedItems = $locationItems->sortBy(function ($item) {
                return $item->item->code ?? $item->item_id;
            });

            // Assign sequential walking_order and distance to each item
            foreach ($sortedItems as $item) {
                $item->update([
                    'walking_order' => $walkingOrder,
                    'distance_from_previous' => $distanceFromPrevious,
                ]);
                $walkingOrder++;
                $updated++;
            }

            // Update previous point for next iteration
            $previousPoint = $currentPoint;
            $previousKey = $currentKey;
        }

        // Calculate elapsed time
        $elapsedTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Save calculation log
        \App\Models\WmsRouteCalculationLog::create([
            'picking_task_id' => $pickingTaskId,
            'warehouse_id' => $warehouseId,
            'floor_id' => $floorId,
            'algorithm' => 'astar',
            'cell_size' => 25, // From buildRoute
            'front_point_delta' => 5, // From constructor
            'location_count' => $routeResult['location_count'],
            'total_distance' => $routeResult['distance'],
            'calculation_time_ms' => (int) $elapsedTime,
            'location_order' => $routeResult['route'],
            'metadata' => [
                'layout_hash' => $routeResult['layout_hash'] ?? null,
                'total_items' => $items->count(),
                'updated_items' => $updated,
            ],
        ]);

        return [
            'success' => true,
            'updated' => $updated,
            'total_items' => $items->count(),
            'total_distance' => $routeResult['distance'],
            'location_count' => $routeResult['location_count'],
            'calculation_time_ms' => (int) $elapsedTime,
        ];
    }
}