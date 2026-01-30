<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sakemaru\Location;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsWarehouseLayout;
use App\Services\Picking\AStarGrid;
use App\Services\Picking\FrontPointCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PickingRouteController extends Controller
{
    /**
     * Get picking route data for visualization
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPickingRoute(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'floor_id' => 'required|integer',
            'date' => 'required|date',
            'delivery_course_id' => 'required|integer',
            'task_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        $warehouseId = $request->input('warehouse_id');
        $floorId = $request->input('floor_id');
        $date = $request->input('date');
        $deliveryCourseId = $request->input('delivery_course_id');
        $taskId = $request->input('task_id');

        // Get picking tasks for the specified criteria with picker information
        $query = WmsPickingTask::where('warehouse_id', $warehouseId)
            ->whereDate('shipment_date', $date)
            ->where('delivery_course_id', $deliveryCourseId)
            ->with('picker:id,code,name');

        // Filter by specific task if provided
        if ($taskId) {
            $query->where('id', $taskId);
        }

        $pickingTasks = $query->get();

        if ($pickingTasks->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'task_info' => null,
                'tasks' => [],
                'message' => 'No picking tasks found for the specified criteria',
            ]);
        }

        $pickingTaskIds = $pickingTasks->pluck('id');

        // Get picking item results with location information
        $pickingItems = WmsPickingItemResult::whereIn('picking_task_id', $pickingTaskIds)
            ->whereNotNull('location_id')
            ->with([
                'location' => function ($query) use ($floorId) {
                    $query->where('floor_id', $floorId)
                        ->whereNotNull('x1_pos')
                        ->whereNotNull('y1_pos')
                        ->whereNotNull('x2_pos')
                        ->whereNotNull('y2_pos');
                },
                'item:id,code,name',
            ])
            ->orderByRaw('walking_order IS NULL, walking_order ASC')
            ->orderBy('item_id', 'asc')
            ->get();

        // Filter out items where location is null (not on this floor)
        $pickingItems = $pickingItems->filter(function ($item) {
            return $item->location !== null;
        });

        // Format the response
        $walkingOrderCounter = 1;
        $data = $pickingItems->map(function ($item) use (&$walkingOrderCounter) {
            // If walking_order is null, assign a sequential number
            $walkingOrder = $item->walking_order ?? $walkingOrderCounter++;

            return [
                'id' => $item->id,
                'walking_order' => $walkingOrder,
                'distance_from_previous' => $item->distance_from_previous,
                'location_id' => $item->location_id,
                'location_display' => $item->location_display,
                'item_id' => $item->item_id,
                'item_name' => $item->item_name_with_code,
                'planned_qty' => $item->planned_qty,
                'qty_type' => $item->planned_qty_type ?? 'PIECE',
                'status' => $item->status,
            ];
        })->values();

        // Aggregate task information
        $firstTask = $pickingTasks->first();
        $taskInfo = [
            'task_id' => $firstTask->id,
            'status' => $firstTask->status,
            'picker_id' => $firstTask->picker_id,
            'picker_name' => $firstTask->picker ? "{$firstTask->picker->code} - {$firstTask->picker->name}" : null,
            'started_at' => $firstTask->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $firstTask->completed_at?->format('Y-m-d H:i:s'),
            'task_count' => $pickingTasks->count(),
        ];

        // List all tasks for dropdown selection
        $tasks = $pickingTasks->map(function ($task) {
            return [
                'id' => $task->id,
                'status' => $task->status,
                'picker_name' => $task->picker ? "{$task->picker->code} - {$task->picker->name}" : null,
            ];
        });

        // Calculate route paths using A*
        $routePaths = $this->calculateRoutePaths($warehouseId, $floorId, $pickingItems);

        // Debug: log the result
        \Log::info('API route paths', [
            'count' => count($routePaths),
            'has_items' => $pickingItems->count() > 0,
            'floor_id' => $floorId,
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
            'task_info' => $taskInfo,
            'tasks' => $tasks,
            'route_paths' => $routePaths,
            'meta' => [
                'total_items' => $data->count(),
                'warehouse_id' => $warehouseId,
                'floor_id' => $floorId,
                'date' => $date,
                'delivery_course_id' => $deliveryCourseId,
            ],
        ]);
    }

    /**
     * Calculate route paths between locations using A* algorithm
     *
     * @param  \Illuminate\Support\Collection  $pickingItems
     * @return array Array of path segments with coordinates
     */
    private function calculateRoutePaths(int $warehouseId, int $floorId, $pickingItems): array
    {
        // Load layout
        $layout = WmsWarehouseLayout::where('warehouse_id', $warehouseId)
            ->where('floor_id', $floorId)
            ->first();

        if (! $layout) {
            return [];
        }

        // Prepare layout data
        $layoutData = [
            'width' => $layout->width,
            'height' => $layout->height,
            'walls' => $layout->walls ?? [],
            'fixed_areas' => $layout->fixed_areas ?? [],
        ];

        // Get ordered unique location IDs first
        $orderedLocationIds = [];
        $prevLocationId = null;
        foreach ($pickingItems as $item) {
            if ($item->location_id && $item->location_id !== $prevLocationId) {
                $orderedLocationIds[] = $item->location_id;
                $prevLocationId = $item->location_id;
            }
        }

        if (empty($orderedLocationIds)) {
            \Log::warning('Empty location IDs in calculateRoutePaths');

            return [];
        }

        \Log::info('calculateRoutePaths starting', [
            'location_ids' => $orderedLocationIds,
            'start_point' => [$layout->picking_start_x ?? 0, $layout->picking_start_y ?? 0],
        ]);

        // Load all locations
        $locations = Location::whereIn('id', $orderedLocationIds)->get()->keyBy('id');

        // Prepare blocked rectangles: only walls + fixed_areas
        // Do NOT block locations to allow paths to go near/through them
        $blockedRects = array_merge($layoutData['walls'], $layoutData['fixed_areas']);

        // Create Walkable object from walkable_areas if available
        $walkable = null;
        if (! empty($layout->walkable_areas)) {
            $walkable = new \App\Services\Picking\Walkable($layout->walkable_areas);
            \Log::info('Using walkable_areas for pathfinding', [
                'polygon_count' => count($layout->walkable_areas),
            ]);
        } else {
            \Log::warning('No walkable_areas defined, using legacy blocking method');
        }

        // Initialize A* with walkable areas
        // If walkable is null, it will fall back to using blockedRects only
        $aStar = new AStarGrid(10, $blockedRects, $layoutData['width'], $layoutData['height'], $walkable);
        // Use smaller delta (5px) to allow paths closer to locations and prevent breaks
        $frontPointCalculator = new FrontPointCalculator(5);

        // Get start and end points
        $startPoint = [
            $layout->picking_start_x ?? 0,
            $layout->picking_start_y ?? 0,
        ];
        $endPoint = [
            $layout->picking_end_x ?? $startPoint[0],
            $layout->picking_end_y ?? $startPoint[1],
        ];

        // Calculate paths
        $paths = [];
        $previousPoint = null;

        // 1. Path from START to first location
        // Always calculate START path if start point is defined (even if 0,0)
        $firstLocationId = $orderedLocationIds[0];
        $firstLocation = $locations->get($firstLocationId);

        if ($firstLocation) {
            $firstPoint = $frontPointCalculator->computeFrontPoint($firstLocation);
            $result = $aStar->shortest($startPoint, $firstPoint);

            if (! empty($result['path'])) {
                $paths[] = [
                    'from' => 'START',
                    'to' => $firstLocationId,
                    'path' => $result['path'],
                    'distance' => $result['dist'],
                ];
            } else {
                // No path found - use straight line as fallback
                \Log::warning('No path found from START to first location', [
                    'start' => $startPoint,
                    'first_location_id' => $firstLocationId,
                    'first_point' => $firstPoint,
                ]);
                $paths[] = [
                    'from' => 'START',
                    'to' => $firstLocationId,
                    'path' => [$startPoint, $firstPoint],
                    'distance' => $result['dist'],
                ];
            }

            $previousPoint = $firstPoint;
        }

        // 2. Paths between consecutive locations
        for ($i = 1; $i < count($orderedLocationIds); $i++) {
            $fromLocationId = $orderedLocationIds[$i - 1];
            $toLocationId = $orderedLocationIds[$i];

            $fromLocation = $locations->get($fromLocationId);
            $toLocation = $locations->get($toLocationId);

            if ($fromLocation && $toLocation) {
                $fromPoint = $frontPointCalculator->computeFrontPoint($fromLocation);
                $toPoint = $frontPointCalculator->computeFrontPoint($toLocation);

                $result = $aStar->shortest($fromPoint, $toPoint);

                if (! empty($result['path'])) {
                    $paths[] = [
                        'from' => $fromLocationId,
                        'to' => $toLocationId,
                        'path' => $result['path'],
                        'distance' => $result['dist'],
                    ];
                } else {
                    // No path found - use straight line as fallback
                    \Log::warning('No path found between locations', [
                        'from_location_id' => $fromLocationId,
                        'to_location_id' => $toLocationId,
                        'from_point' => $fromPoint,
                        'to_point' => $toPoint,
                    ]);
                    $paths[] = [
                        'from' => $fromLocationId,
                        'to' => $toLocationId,
                        'path' => [$fromPoint, $toPoint],
                        'distance' => $result['dist'],
                    ];
                }

                $previousPoint = $toPoint;
            }
        }

        // 3. Path from last location to END
        if (($endPoint[0] > 0 || $endPoint[1] > 0) &&
            ($endPoint[0] !== $startPoint[0] || $endPoint[1] !== $startPoint[1]) &&
            $previousPoint) {
            $result = $aStar->shortest($previousPoint, $endPoint);

            if (! empty($result['path'])) {
                $lastLocationId = $orderedLocationIds[count($orderedLocationIds) - 1];
                $paths[] = [
                    'from' => $lastLocationId,
                    'to' => 'END',
                    'path' => $result['path'],
                    'distance' => $result['dist'],
                ];
            } else {
                // No path found - use straight line as fallback
                \Log::warning('No path found from last location to END', [
                    'last_location_id' => $orderedLocationIds[count($orderedLocationIds) - 1],
                    'previous_point' => $previousPoint,
                    'end_point' => $endPoint,
                ]);
                $lastLocationId = $orderedLocationIds[count($orderedLocationIds) - 1];
                $paths[] = [
                    'from' => $lastLocationId,
                    'to' => 'END',
                    'path' => [$previousPoint, $endPoint],
                    'distance' => $result['dist'],
                ];
            }
        }

        return $paths;
    }

    /**
     * Get walkable areas for visualization
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWalkableAreas(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'floor_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 400);
        }

        $warehouseId = $request->input('warehouse_id');
        $floorId = $request->input('floor_id');

        // Get layout from database
        $layout = WmsWarehouseLayout::where('warehouse_id', $warehouseId)
            ->where('floor_id', $floorId)
            ->first();

        if (! $layout || empty($layout->walkable_areas)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'walkable_areas' => [],
                    'navmeta' => null,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'walkable_areas' => $layout->walkable_areas,
                'navmeta' => $layout->navmeta,
            ],
        ]);
    }
}
