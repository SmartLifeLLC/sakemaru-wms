<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WmsPickingTask;
use App\Models\WmsPickingItemResult;
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

        // Get picking tasks for the specified criteria with picker information
        $pickingTasks = WmsPickingTask::where('warehouse_id', $warehouseId)
            ->whereDate('shipment_date', $date)
            ->where('delivery_course_id', $deliveryCourseId)
            ->with('picker:id,code,name')
            ->get();

        if ($pickingTasks->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
                'task_info' => null,
                'message' => 'No picking tasks found for the specified criteria',
            ]);
        }

        $pickingTaskIds = $pickingTasks->pluck('id');

        // Get picking item results with location information
        $pickingItems = WmsPickingItemResult::whereIn('picking_task_id', $pickingTaskIds)
            ->whereNotNull('location_id')
            ->whereNotNull('walking_order')
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
            ->orderBy('walking_order', 'asc')
            ->orderBy('item_id', 'asc')
            ->get();

        // Filter out items where location is null (not on this floor)
        $pickingItems = $pickingItems->filter(function ($item) {
            return $item->location !== null;
        });

        // Format the response
        $data = $pickingItems->map(function ($item) {
            return [
                'id' => $item->id,
                'walking_order' => $item->walking_order,
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
            'status' => $firstTask->status,
            'picker_id' => $firstTask->picker_id,
            'picker_name' => $firstTask->picker ? "{$firstTask->picker->code} - {$firstTask->picker->name}" : null,
            'started_at' => $firstTask->started_at?->format('Y-m-d H:i:s'),
            'completed_at' => $firstTask->completed_at?->format('Y-m-d H:i:s'),
            'task_count' => $pickingTasks->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'task_info' => $taskInfo,
            'meta' => [
                'total_items' => $data->count(),
                'warehouse_id' => $warehouseId,
                'floor_id' => $floorId,
                'date' => $date,
                'delivery_course_id' => $deliveryCourseId,
            ],
        ]);
    }
}