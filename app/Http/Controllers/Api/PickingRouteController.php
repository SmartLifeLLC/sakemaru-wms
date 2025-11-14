<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WmsPickingTask;
use App\Models\WmsPickingItemResult;
use App\Models\WmsWarehouseLayout;
use App\Models\Sakemaru\Location;
use App\Services\Picking\AStarGrid;
use App\Services\Picking\FrontPointCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PickingRouteController extends Controller
{
    /**
     * Get picking route data for visualization
     *
     * @param Request $request
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
     * @param int $warehouseId
     * @param int $floorId
     * @param \Illuminate\Support\Collection $pickingItems
     * @return array Array of path segments with coordinates
     */
    private function calculateRoutePaths(int $warehouseId, int $floorId, $pickingItems): array
    {
        // Load layout
        $layout = WmsWarehouseLayout::where('warehouse_id', $warehouseId)
            ->where('floor_id', $floorId)
            ->first();

        if (!$layout) {
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
            return [];
        }

        // Load all locations
        $locations = Location::whereIn('id', $orderedLocationIds)->get()->keyBy('id');

        // Load ALL locations on this floor for blocking
        $allFloorLocations = Location::where('floor_id', $floorId)
            ->whereNotNull('x1_pos')
            ->whereNotNull('y1_pos')
            ->whereNotNull('x2_pos')
            ->whereNotNull('y2_pos')
            ->get();

        // Prepare blocked rectangles: walls + fixed_areas + all locations
        $blockedRects = array_merge($layoutData['walls'], $layoutData['fixed_areas']);

        // Add all locations as blocked rectangles
        foreach ($allFloorLocations as $loc) {
            $blockedRects[] = [
                'x1' => $loc->x1_pos,
                'y1' => $loc->y1_pos,
                'x2' => $loc->x2_pos,
                'y2' => $loc->y2_pos,
            ];
        }

        // Initialize A*
        $aStar = new AStarGrid(25, $blockedRects, $layoutData['width'], $layoutData['height']);
        $frontPointCalculator = new FrontPointCalculator();

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
        if ($startPoint[0] > 0 || $startPoint[1] > 0) {
            $firstLocationId = $orderedLocationIds[0];
            $firstLocation = $locations->get($firstLocationId);

            if ($firstLocation) {
                $firstPoint = $frontPointCalculator->computeFrontPoint($firstLocation);
                $result = $aStar->shortest($startPoint, $firstPoint);

                if (!empty($result['path'])) {
                    $paths[] = [
                        'from' => 'START',
                        'to' => $firstLocationId,
                        'path' => $result['path'],
                        'distance' => $result['dist'],
                    ];
                }

                $previousPoint = $firstPoint;
            }
        } else {
            // No start point, use first location as starting point
            $firstLocationId = $orderedLocationIds[0];
            $firstLocation = $locations->get($firstLocationId);
            if ($firstLocation) {
                $previousPoint = $frontPointCalculator->computeFrontPoint($firstLocation);
            }
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

                if (!empty($result['path'])) {
                    $paths[] = [
                        'from' => $fromLocationId,
                        'to' => $toLocationId,
                        'path' => $result['path'],
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

            if (!empty($result['path'])) {
                $lastLocationId = $orderedLocationIds[count($orderedLocationIds) - 1];
                $paths[] = [
                    'from' => $lastLocationId,
                    'to' => 'END',
                    'path' => $result['path'],
                    'distance' => $result['dist'],
                ];
            }
        }

        return $paths;
    }
}