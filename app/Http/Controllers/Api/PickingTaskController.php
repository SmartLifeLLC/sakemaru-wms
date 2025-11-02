<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WmsPickingTask;
use App\Services\PickingLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PickingTaskController extends Controller
{
    /**
     * GET /api/picking/tasks
     *
     * タスク一覧取得（配送コース別、ピッキング順最適化）
     *
     * @OA\Get(
     *     path="/api/picking/tasks",
     *     tags={"Picking Tasks"},
     *     summary="Get picking task list",
     *     description="Retrieve picking tasks grouped by delivery course and picking area, optimized by walking order",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *     @OA\Parameter(
     *         name="warehouse_id",
     *         in="query",
     *         description="Warehouse ID (required)",
     *         required=true,
     *         @OA\Schema(type="integer", example=991)
     *     ),
     *     @OA\Parameter(
     *         name="picker_id",
     *         in="query",
     *         description="Picker ID (optional, filter tasks by specific picker)",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="picking_area_id",
     *         in="query",
     *         description="Picking Area ID (optional, filter tasks by specific area)",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(
     *                         property="course",
     *                         type="object",
     *                         @OA\Property(property="code", type="string", example="910072"),
     *                         @OA\Property(property="name", type="string", example="佐藤　尚紀")
     *                     ),
     *                     @OA\Property(
     *                         property="picking_area",
     *                         type="object",
     *                         @OA\Property(property="code", type="string", example="B"),
     *                         @OA\Property(property="name", type="string", example="エリアB（バラ）")
     *                     ),
     *                     @OA\Property(
     *                         property="wave",
     *                         type="object",
     *                         @OA\Property(property="wms_picking_task_id", type="integer", example=1, description="Picking task ID"),
     *                         @OA\Property(property="wms_wave_id", type="integer", example=5, description="Wave ID")
     *                     ),
     *                     @OA\Property(
     *                         property="picking_list",
     *                         type="array",
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="wms_picking_item_result_id", type="integer", example=1, description="Picking item result ID"),
     *                             @OA\Property(property="item_id", type="integer", example=111110),
     *                             @OA\Property(property="item_name", type="string", example="×白鶴特撰　本醸造生貯蔵酒７２０ｍｌ（ギフト）"),
     *                             @OA\Property(property="planned_qty_type", type="string", example="CASE", description="Quantity type: CASE or PIECE"),
     *                             @OA\Property(property="planned_qty", type="string", example="2.00"),
     *                             @OA\Property(property="picked_qty", type="string", example="0.00"),
     *                             @OA\Property(property="walking_order", type="integer", example=15, description="Optimized walking order for picking"),
     *                             @OA\Property(property="slip_number", type="integer", example=1, description="Earning ID used as slip number")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The warehouse id field is required."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="warehouse_id",
     *                     type="array",
     *                     @OA\Items(type="string", example="The warehouse id field is required.")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token"
     *     )
     * )
     *
     * クエリパラメータ:
     * - warehouse_id (required): 倉庫ID
     * - picker_id (optional): ピッカーID
     * - picking_area_id (optional): ピッキングエリアID
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:sakemaru.warehouses,id',
            'picker_id' => 'nullable|integer|exists:sakemaru.wms_pickers,id',
            'picking_area_id' => 'nullable|integer|exists:sakemaru.wms_picking_areas,id',
        ]);

        $warehouseId = $validated['warehouse_id'];
        $pickerId = $validated['picker_id'] ?? null;
        $pickingAreaId = $validated['picking_area_id'] ?? null;

        // Build query for picking tasks
        $query = WmsPickingTask::with([
            'earning',
            'earning.delivery_course',
            'pickingArea',
            'pickingItemResults.item',
        ])
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['PENDING', 'PICKING', 'SHORTAGE']);

        if ($pickerId) {
            $query->where('picker_id', $pickerId);
        }

        if ($pickingAreaId) {
            $query->where('wms_picking_area_id', $pickingAreaId);
        }

        $tasks = $query->get();

        // Group tasks by delivery course and picking area
        $groupedData = [];

        foreach ($tasks as $task) {
            if (!$task->earning || !$task->earning->delivery_course) {
                continue; // Skip tasks without delivery course
            }

            $deliveryCourseCode = $task->earning->delivery_course->code;
            $deliveryCourseName = $task->earning->delivery_course->name;
            $pickingAreaCode = $task->pickingArea->code ?? 'UNKNOWN';
            $pickingAreaName = $task->pickingArea->name ?? 'Unknown Area';

            // Create unique key for course + area combination
            $groupKey = "{$deliveryCourseCode}_{$pickingAreaCode}";

            if (!isset($groupedData[$groupKey])) {
                $groupedData[$groupKey] = [
                    'course' => [
                        'code' => $deliveryCourseCode,
                        'name' => $deliveryCourseName,
                    ],
                    'picking_area' => [
                        'code' => $pickingAreaCode,
                        'name' => $pickingAreaName,
                    ],
                    'wave' => [
                        'wms_picking_task_id' => $task->id,
                        'wms_wave_id' => $task->wave_id,
                    ],
                    'picking_list' => [],
                ];
            }

            // Add item results to picking list, sorted by walking_order, item_id
            $itemResults = $task->pickingItemResults()
                ->with('item')
                ->orderBy('walking_order', 'asc')
                ->orderBy('item_id', 'asc')
                ->get();

            foreach ($itemResults as $itemResult) {
                $groupedData[$groupKey]['picking_list'][] = [
                    'wms_picking_item_result_id' => $itemResult->id,
                    'item_id' => $itemResult->item_id,
                    'item_name' => $itemResult->item->name ?? 'Unknown Item',
                    'planned_qty_type' => $itemResult->planned_qty_type,
                    'planned_qty' => $itemResult->planned_qty,
                    'picked_qty' => $itemResult->picked_qty ?? 0,
                    'walking_order' => $itemResult->walking_order,
                    'slip_number' => $task->earning_id, // Use earning_id as slip number
                ];
            }
        }

        // Convert to array indexed from 1 (as per spec example)
        $data = array_values($groupedData);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * POST /api/picking/tasks/{id}/start
     *
     * タスク開始
     *
     * @OA\Post(
     *     path="/api/picking/tasks/{id}/start",
     *     tags={"Picking Tasks"},
     *     summary="Start picking task",
     *     description="Change task status to PICKING and set started_at timestamp",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Picking Task ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task started successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="status", type="string", example="PICKING"),
     *                     @OA\Property(property="started_at", type="string", example="2025-11-02 10:30:00")
     *                 ),
     *                 @OA\Property(property="message", type="string", example="Picking task started"),
     *                 @OA\Property(property="debug_message", type="string", example=null, nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Task already started or completed")
     * )
     */
    public function start(Request $request, $id)
    {
        $task = WmsPickingTask::find($id);

        if (!$task) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking task not found',
                ],
            ], 404);
        }

        // Validate task can be started
        if (!in_array($task->status, ['PENDING', 'PICKING'])) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'result' => [
                    'data' => null,
                    'error_message' => 'Task cannot be started',
                    'errors' => [
                        'status' => ["Task status must be PENDING or PICKING, current status is {$task->status}"],
                    ],
                ],
            ], 422);
        }

        // Update task status
        $task->update([
            'status' => 'PICKING',
            'started_at' => $task->started_at ?? now(),
            'picker_id' => $request->user()->id,
        ]);

        $response = [
            'code' => 'SUCCESS',
            'result' => [
                'data' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'started_at' => $task->started_at,
                ],
                'message' => 'Picking task started',
                'debug_message' => null,
            ],
        ];

        // Log the action
        PickingLogService::logTaskStart($request, $task, $response, 200);

        return response()->json($response);
    }

    /**
     * POST /api/picking/tasks/{item_result_id}/update
     *
     * ピッキング実績登録
     *
     * @OA\Post(
     *     path="/api/picking/tasks/{item_result_id}/update",
     *     tags={"Picking Tasks"},
     *     summary="Update picking result",
     *     description="Update picked quantity for a specific item in the picking task",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *     @OA\Parameter(
     *         name="item_result_id",
     *         in="path",
     *         description="Picking Item Result ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"picked_qty"},
     *             @OA\Property(property="picked_qty", type="number", example=5, description="Picked quantity"),
     *             @OA\Property(property="picked_qty_type", type="string", example="PIECE", description="Quantity type (CASE/PIECE)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Picking result updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="picked_qty", type="number", example=5),
     *                     @OA\Property(property="shortage_qty", type="number", example=0),
     *                     @OA\Property(property="status", type="string", example="COMPLETED")
     *                 ),
     *                 @OA\Property(property="message", type="string", example="Picking result updated"),
     *                 @OA\Property(property="debug_message", type="string", example=null, nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Item result not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function updateItemResult(Request $request, $itemResultId)
    {
        $validated = $request->validate([
            'picked_qty' => 'required|numeric|min:0',
            'picked_qty_type' => 'nullable|string|in:CASE,PIECE',
        ]);

        $itemResult = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('id', $itemResultId)
            ->first();

        if (!$itemResult) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking item result not found',
                ],
            ], 404);
        }

        // Capture state before update
        $itemResultBefore = (array) $itemResult;
        $stockBefore = null;
        $wmsStockBefore = null;

        if ($itemResult->real_stock_id) {
            $stockBefore = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('id', $itemResult->real_stock_id)
                ->first();

            $wmsStockBefore = DB::connection('sakemaru')
                ->table('wms_real_stocks')
                ->where('real_stock_id', $itemResult->real_stock_id)
                ->first();
        }

        $pickedQty = $validated['picked_qty'];
        $pickedQtyType = $validated['picked_qty_type'] ?? $itemResult->picked_qty_type;

        // Calculate shortage
        $shortageQty = max(0, $itemResult->planned_qty - $pickedQty);

        // Determine status
        if ($pickedQty >= $itemResult->planned_qty) {
            $status = 'COMPLETED';
        } elseif ($pickedQty > 0) {
            $status = 'PICKING';
        } else {
            $status = 'SHORTAGE';
        }

        // Update picking item result
        DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('id', $itemResultId)
            ->update([
                'picked_qty' => $pickedQty,
                'picked_qty_type' => $pickedQtyType,
                'shortage_qty' => $shortageQty,
                'status' => $status,
                'picked_at' => now(),
                'picker_id' => $request->user()->id,
                'updated_at' => now(),
            ]);

        // Update real_stocks: decrease current_quantity and available_quantity
        if ($pickedQty > 0 && $itemResult->real_stock_id) {
            DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('id', $itemResult->real_stock_id)
                ->update([
                    'current_quantity' => DB::raw("current_quantity - {$pickedQty}"),
                    'available_quantity' => DB::raw("available_quantity - {$pickedQty}"),
                    'updated_at' => now(),
                ]);

            // Update wms_real_stocks: decrease reserved_quantity, increase picking_quantity
            $wmsRealStock = DB::connection('sakemaru')
                ->table('wms_real_stocks')
                ->where('real_stock_id', $itemResult->real_stock_id)
                ->first();

            if ($wmsRealStock) {
                DB::connection('sakemaru')
                    ->table('wms_real_stocks')
                    ->where('real_stock_id', $itemResult->real_stock_id)
                    ->update([
                        'reserved_quantity' => DB::raw("GREATEST(reserved_quantity - {$pickedQty}, 0)"),
                        'picking_quantity' => DB::raw("picking_quantity + {$pickedQty}"),
                        'updated_at' => now(),
                    ]);
            }
        }

        // Capture state after update
        $itemResultAfter = [
            'picking_task_id' => $itemResult->picking_task_id,
            'item_id' => $itemResult->item_id,
            'real_stock_id' => $itemResult->real_stock_id,
            'location_id' => $itemResult->location_id,
            'planned_qty' => $itemResult->planned_qty,
            'planned_qty_type' => $itemResult->planned_qty_type,
            'picked_qty' => $pickedQty,
            'picked_qty_type' => $pickedQtyType,
            'shortage_qty' => $shortageQty,
            'status' => $status,
        ];

        $stockAfter = null;
        if ($itemResult->real_stock_id && $stockBefore) {
            $stockAfter = [
                'current_quantity' => $stockBefore->current_quantity - $pickedQty,
                'reserved_quantity' => max(0, ($wmsStockBefore->reserved_quantity ?? 0) - $pickedQty),
                'picking_quantity' => ($wmsStockBefore->picking_quantity ?? 0) + $pickedQty,
            ];
        }

        $response = [
            'code' => 'SUCCESS',
            'result' => [
                'data' => [
                    'id' => $itemResultId,
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'status' => $status,
                ],
                'message' => 'Picking result updated',
                'debug_message' => null,
            ],
        ];

        // Log the action
        PickingLogService::logItemPick(
            $request,
            $itemResultId,
            $itemResultBefore,
            $itemResultAfter,
            $stockBefore ? [
                'current_quantity' => $stockBefore->current_quantity,
                'reserved_quantity' => $wmsStockBefore->reserved_quantity ?? 0,
                'picking_quantity' => $wmsStockBefore->picking_quantity ?? 0,
            ] : null,
            $stockAfter,
            $response,
            200
        );

        return response()->json($response);
    }

    /**
     * POST /api/picking/tasks/{id}/complete
     *
     * タスク完了
     *
     * @OA\Post(
     *     path="/api/picking/tasks/{id}/complete",
     *     tags={"Picking Tasks"},
     *     summary="Complete picking task",
     *     description="Mark task as completed. Status will be COMPLETED if all items are picked, SHORTAGE if any shortages exist",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Picking Task ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task completed",
     *         @OA\JsonContent(
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="status", type="string", example="COMPLETED"),
     *                     @OA\Property(property="completed_at", type="string", example="2025-11-02 11:00:00")
     *                 ),
     *                 @OA\Property(property="message", type="string", example="Picking task completed"),
     *                 @OA\Property(property="debug_message", type="string", example=null, nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Task cannot be completed")
     * )
     */
    public function complete(Request $request, $id)
    {
        $task = WmsPickingTask::with('pickingItemResults')->find($id);

        if (!$task) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking task not found',
                ],
            ], 404);
        }

        // Check if task can be completed
        if (!in_array($task->status, ['PICKING', 'SHORTAGE', 'PENDING'])) {
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'result' => [
                    'data' => null,
                    'error_message' => 'Task cannot be completed',
                    'errors' => [
                        'status' => ["Task is already {$task->status}"],
                    ],
                ],
            ], 422);
        }

        // Capture status before update
        $statusBefore = $task->status;

        // Determine final status based on item results
        $hasShortage = $task->pickingItemResults()
            ->where('status', 'SHORTAGE')
            ->orWhere('shortage_qty', '>', 0)
            ->exists();

        $finalStatus = $hasShortage ? 'SHORTAGE' : 'COMPLETED';

        // Update task
        $task->update([
            'status' => $finalStatus,
            'completed_at' => now(),
        ]);

        // Update wms_real_stocks: move from picking_quantity to 0 (picked items are now consumed)
        $itemResults = $task->pickingItemResults;
        foreach ($itemResults as $itemResult) {
            if ($itemResult->real_stock_id && $itemResult->picked_qty > 0) {
                DB::connection('sakemaru')
                    ->table('wms_real_stocks')
                    ->where('real_stock_id', $itemResult->real_stock_id)
                    ->update([
                        'picking_quantity' => DB::raw("GREATEST(picking_quantity - {$itemResult->picked_qty}, 0)"),
                        'updated_at' => now(),
                    ]);
            }
        }

        $response = [
            'code' => 'SUCCESS',
            'result' => [
                'data' => [
                    'id' => $task->id,
                    'status' => $task->status,
                    'completed_at' => $task->completed_at,
                ],
                'message' => 'Picking task completed',
                'debug_message' => null,
            ],
        ];

        // Log the action
        PickingLogService::logTaskComplete($request, $task, $statusBefore, $response, 200);

        return response()->json($response);
    }
}
