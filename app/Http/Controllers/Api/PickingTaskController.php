<?php

namespace App\Http\Controllers\Api;

use App\Enums\EItemSearchCodeType;
use App\Enums\EVolumeUnit;
use App\Enums\TemperatureType;
use App\Http\Controllers\Controller;
use App\Models\WmsPickingTask;
use App\Services\EarningDeliveryQueueService;
use App\Services\PickingLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PickingTaskController extends Controller
{
    /**
     * Format item result data for API response
     */
    private function formatItemResult($itemResult): array
    {
        $item = $itemResult->item;

        // Get JAN codes from item_search_information
        $janCodes = [];
        if ($item && $item->item_search_information) {
            $janCodes = $item->item_search_information
                ->filter(fn ($info) => $info->code_type === EItemSearchCodeType::JAN->value)
                ->sortByDesc('updated_at')
                ->pluck('search_string')
                ->values()
                ->toArray();
        }

        // Get volume with unit
        $volumeDisplay = null;
        if ($item && $item->volume) {
            $volumeUnit = EVolumeUnit::tryFrom($item->volume_unit);
            $volumeDisplay = $item->volume.($volumeUnit ? $volumeUnit->name() : '');
        }

        // Get temperature type label
        $temperatureTypeLabel = null;
        if ($item && $item->temperature_type) {
            $tempType = TemperatureType::tryFrom($item->temperature_type);
            $temperatureTypeLabel = $tempType?->label();
        }

        // Get image URLs
        $images = [];
        if ($item) {
            if ($item->image_url_1) {
                $images[] = $item->image_url_1;
            }
            if ($item->image_url_2) {
                $images[] = $item->image_url_2;
            }
            if ($item->image_url_3) {
                $images[] = $item->image_url_3;
            }
        }

        return [
            'wms_picking_item_result_id' => $itemResult->id,
            'item_id' => $itemResult->item_id,
            'item_name' => $item->name ?? 'Unknown Item',
            'jan_code' => $janCodes[0] ?? null,
            'jan_code_list' => $janCodes,
            'volume' => $volumeDisplay,
            'capacity_case' => $item->capacity_case ?? null,
            'packaging' => $item->packaging ?? null,
            'temperature_type' => $temperatureTypeLabel,
            'images' => $images,
            'planned_qty_type' => $itemResult->planned_qty_type,
            'planned_qty' => $itemResult->planned_qty,
            'picked_qty' => $itemResult->picked_qty ?? 0,
            'status' => $itemResult->status,
            'slip_number' => $itemResult->earning_id,
        ];
    }

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
     *
     *     @OA\Parameter(
     *         name="warehouse_id",
     *         in="query",
     *         description="Warehouse ID (required)",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=991)
     *     ),
     *
     *     @OA\Parameter(
     *         name="picker_id",
     *         in="query",
     *         description="Picker ID (optional, filter tasks by specific picker)",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Parameter(
     *         name="picking_area_id",
     *         in="query",
     *         description="Picking Area ID (optional, filter tasks by specific area)",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object",
     *
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
     *
     *                         @OA\Items(
     *                             type="object",
     *
     *                             @OA\Property(property="wms_picking_item_result_id", type="integer", example=1, description="Picking item result ID"),
     *                             @OA\Property(property="item_id", type="integer", example=111110),
     *                             @OA\Property(property="item_name", type="string", example="×白鶴特撰　本醸造生貯蔵酒７２０ｍｌ（ギフト）"),
     *                             @OA\Property(property="jan_code", type="string", example="4901681115008", nullable=true, description="Representative JAN code (most recently updated)"),
     *                             @OA\Property(property="jan_code_list", type="array", @OA\Items(type="string"), example={"4901681115008", "4901681115015"}, description="List of all JAN codes ordered by updated_at desc"),
     *                             @OA\Property(property="volume", type="string", example="720ml", nullable=true, description="Item volume with unit"),
     *                             @OA\Property(property="capacity_case", type="integer", example=12, nullable=true, description="Items per case"),
     *                             @OA\Property(property="packaging", type="string", example="瓶", nullable=true, description="Item packaging type"),
     *                             @OA\Property(property="temperature_type", type="string", example="常温", nullable=true, description="Temperature type label"),
     *                             @OA\Property(property="images", type="array", @OA\Items(type="string"), example={"https://example.com/image1.jpg", "https://example.com/image2.jpg"}, description="List of item image URLs (image_url_1, image_url_2, image_url_3)"),
     *                             @OA\Property(property="planned_qty_type", type="string", example="CASE", description="Quantity type: CASE or PIECE"),
     *                             @OA\Property(property="planned_qty", type="string", example="2.00"),
     *                             @OA\Property(property="picked_qty", type="string", example="0.00"),
     *                             @OA\Property(property="status", type="string", example="PENDING", description="Item status: PENDING (not started), PICKING (in progress), COMPLETED, SHORTAGE"),
     *                             @OA\Property(property="slip_number", type="integer", example=1, description="Earning ID used as slip number")
     *                         )
     *                     )
     *                 )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="The warehouse id field is required."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="warehouse_id",
     *                     type="array",
     *
     *                     @OA\Items(type="string", example="The warehouse id field is required.")
     *                 )
     *             )
     *         )
     *     ),
     *
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
            'pickingArea',
            'deliveryCourse',
            'pickingItemResults.item.item_search_information',
            'pickingItemResults.earning',
        ])
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['PENDING', 'PICKING']);

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
            // Get delivery course from task (tasks are grouped by delivery_course_id)
            if (! $task->deliveryCourse) {
                continue; // Skip tasks without delivery course
            }

            $deliveryCourseCode = $task->deliveryCourse->code;
            $deliveryCourseName = $task->deliveryCourse->name;
            $pickingAreaCode = $task->pickingArea->code ?? 'UNKNOWN';
            $pickingAreaName = $task->pickingArea->name ?? 'Unknown Area';

            // Create unique key for course + area combination
            $groupKey = "{$deliveryCourseCode}_{$pickingAreaCode}";

            if (! isset($groupedData[$groupKey])) {
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

            // Add item results to picking list, sorted by item_id
            // Note: walking_order is no longer used. Sorting will be calculated based on location x_pos, y_pos
            $itemResults = $task->pickingItemResults()
                ->with(['item.item_search_information'])
                ->where('planned_qty', '>', 0) // Filter out items with 0 planned quantity (complete shortage)
                ->orderBy('item_id', 'asc')
                ->get();

            foreach ($itemResults as $itemResult) {
                $groupedData[$groupKey]['picking_list'][] = $this->formatItemResult($itemResult);
            }
        }

        // Convert to array indexed from 1 (as per spec example)
        $data = array_values($groupedData);

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => $data,
            ],
        ]);
    }

    /**
     * GET /api/picking/tasks/{id}
     *
     * 単一タスク取得
     *
     * @OA\Get(
     *     path="/api/picking/tasks/{id}",
     *     tags={"Picking Tasks"},
     *     summary="Get single picking task",
     *     description="Retrieve a single picking task with course, picking area, wave, and picking list",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Picking Task ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Task not found")
     * )
     */
    public function show($id)
    {
        $task = WmsPickingTask::with([
            'pickingArea',
            'deliveryCourse',
            'pickingItemResults.item.item_search_information',
            'pickingItemResults.earning',
        ])->find($id);

        if (! $task) {
            return response()->json([
                'is_success' => false,
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking task not found',
                ],
            ], 404);
        }

        $deliveryCourseCode = $task->deliveryCourse->code ?? 'UNKNOWN';
        $deliveryCourseName = $task->deliveryCourse->name ?? 'Unknown Course';
        $pickingAreaCode = $task->pickingArea->code ?? 'UNKNOWN';
        $pickingAreaName = $task->pickingArea->name ?? 'Unknown Area';

        // Build picking list
        $pickingList = [];
        $itemResults = $task->pickingItemResults()
            ->with(['item.item_search_information'])
            ->where('planned_qty', '>', 0)
            ->orderBy('item_id', 'asc')
            ->get();

        foreach ($itemResults as $itemResult) {
            $pickingList[] = $this->formatItemResult($itemResult);
        }

        $data = [
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
            'picking_list' => $pickingList,
        ];

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => $data,
            ],
        ]);
    }

    /**
     * GET /api/picking/items/{id}
     *
     * 単一ピッキングアイテム取得
     *
     * @OA\Get(
     *     path="/api/picking/items/{id}",
     *     tags={"Picking Tasks"},
     *     summary="Get single picking item result",
     *     description="Retrieve a single picking item result with item details",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Picking Item Result ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS")
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Item not found")
     * )
     */
    public function showItem($id)
    {
        $itemResult = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('id', $id)
            ->first();

        if (! $itemResult) {
            return response()->json([
                'is_success' => false,
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking item result not found',
                ],
            ], 404);
        }

        // Load the item with item_search_information
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('id', $itemResult->item_id)
            ->first();

        // Get JAN codes
        $janCodes = [];
        if ($item) {
            $searchInfos = DB::connection('sakemaru')
                ->table('item_search_information')
                ->where('item_id', $item->id)
                ->where('code_type', EItemSearchCodeType::JAN->value)
                ->orderByDesc('updated_at')
                ->pluck('search_string')
                ->toArray();
            $janCodes = $searchInfos;
        }

        // Get volume with unit
        $volumeDisplay = null;
        if ($item && $item->volume) {
            $volumeUnit = EVolumeUnit::tryFrom($item->volume_unit);
            $volumeDisplay = $item->volume.($volumeUnit ? $volumeUnit->name() : '');
        }

        // Get temperature type label
        $temperatureTypeLabel = null;
        if ($item && $item->temperature_type) {
            $tempType = TemperatureType::tryFrom($item->temperature_type);
            $temperatureTypeLabel = $tempType?->label();
        }

        // Get image URLs
        $images = [];
        if ($item) {
            if ($item->image_url_1) {
                $images[] = $item->image_url_1;
            }
            if ($item->image_url_2) {
                $images[] = $item->image_url_2;
            }
            if ($item->image_url_3) {
                $images[] = $item->image_url_3;
            }
        }

        $data = [
            'wms_picking_item_result_id' => $itemResult->id,
            'item_id' => $itemResult->item_id,
            'item_name' => $item->name ?? 'Unknown Item',
            'jan_code' => $janCodes[0] ?? null,
            'jan_code_list' => $janCodes,
            'volume' => $volumeDisplay,
            'capacity_case' => $item->capacity_case ?? null,
            'packaging' => $item->packaging ?? null,
            'temperature_type' => $temperatureTypeLabel,
            'images' => $images,
            'planned_qty_type' => $itemResult->planned_qty_type,
            'planned_qty' => $itemResult->planned_qty,
            'picked_qty' => $itemResult->picked_qty ?? 0,
            'status' => $itemResult->status,
            'slip_number' => $itemResult->earning_id,
        ];

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => $data,
            ],
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
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Picking Task ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Task started successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
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
     *
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Task already started or completed")
     * )
     */
    public function start(Request $request, $id)
    {
        $task = WmsPickingTask::find($id);

        if (! $task) {
            return response()->json([
                'is_success' => false,
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking task not found',
                ],
            ], 404);
        }

        // Validate task can be started
        if (! in_array($task->status, ['PENDING', 'PICKING'])) {
            return response()->json([
                'is_success' => false,
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
            'is_success' => true,
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
     * POST /api/picking/tasks/{wms_picking_item_result_id}/update
     *
     * ピッキング実績登録
     *
     * @OA\Post(
     *     path="/api/picking/tasks/{wms_picking_item_result_id}/update",
     *     tags={"Picking Tasks"},
     *     summary="Update picking result",
     *     description="Update picked quantity for a specific item in the picking task",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="wms_picking_item_result_id",
     *         in="path",
     *         description="Picking Item Result ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"picked_qty"},
     *
     *             @OA\Property(property="picked_qty", type="number", example=5, description="Picked quantity"),
     *             @OA\Property(property="picked_qty_type", type="string", example="PIECE", description="Quantity type (CASE/PIECE)")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Picking result updated",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
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
     *
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

        if (! $itemResult) {
            return response()->json([
                'is_success' => false,
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking item result not found',
                ],
            ], 404);
        }

        // Capture state before update
        $itemResultBefore = (array) $itemResult;

        $pickedQty = $validated['picked_qty'];
        $pickedQtyType = $validated['picked_qty_type'] ?? $itemResult->picked_qty_type;

        // Calculate shortage
        $shortageQty = max(0, $itemResult->planned_qty - $pickedQty);

        // Status is always PICKING during picking operation
        // Final status (COMPLETED/SHORTAGE) is determined at task complete
        $status = 'PICKING';

        // Update picking item result (real_stock update is done at task complete)
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

        $response = [
            'is_success' => true,
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

        // Log the action (stock update is done at task complete, so no stock changes here)
        PickingLogService::logItemPick(
            $request,
            $itemResultId,
            $itemResultBefore,
            $itemResultAfter,
            null,
            null,
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
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Picking Task ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Task completed",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
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
     *
     *     @OA\Response(response=404, description="Task not found"),
     *     @OA\Response(response=422, description="Task cannot be completed")
     * )
     */
    public function complete(Request $request, $id)
    {
        $task = WmsPickingTask::with('pickingItemResults')->find($id);

        if (! $task) {
            return response()->json([
                'is_success' => false,
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking task not found',
                ],
            ], 404);
        }

        // Check if task can be completed
        if (! in_array($task->status, ['PICKING', 'PENDING', 'SHORTAGE'])) {
            return response()->json([
                'is_success' => false,
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

        // Check if any items have PENDING or PICKING status (not allowed to complete)
        $incompleteItems = $task->pickingItemResults()
            ->whereIn('status', ['PENDING', 'PICKING'])
            ->get();

        if ($incompleteItems->isNotEmpty()) {
            $incompleteCount = $incompleteItems->count();

            return response()->json([
                'is_success' => false,
                'code' => 'VALIDATION_ERROR',
                'result' => [
                    'data' => null,
                    'error_message' => 'ピッキング完了していない商品があります',
                    'errors' => [
                        'items' => ["{$incompleteCount}件の商品がまだピッキング中です"],
                    ],
                ],
            ], 422);
        }

        // Capture status before update
        $statusBefore = $task->status;

        // Update all item results: SHORTAGE if shortage_qty > 0, otherwise COMPLETED
        $itemResults = $task->pickingItemResults;
        $hasShortage = false;

        foreach ($itemResults as $itemResult) {
            $itemStatus = $itemResult->shortage_qty > 0 ? 'SHORTAGE' : 'COMPLETED';
            if ($itemStatus === 'SHORTAGE') {
                $hasShortage = true;
            }

            DB::connection('sakemaru')
                ->table('wms_picking_item_results')
                ->where('id', $itemResult->id)
                ->update([
                    'status' => $itemStatus,
                    'updated_at' => now(),
                ]);

            // Note: real_stocks の数量更新は earning_delivery_queue 経由で
            // Sakemaru側の ProcessEarningDeliveryQueue Job が実行する
        }

        // Set task status based on shortage existence
        $finalStatus = $hasShortage ? 'SHORTAGE' : 'COMPLETED';

        // Update task
        $task->update([
            'status' => $finalStatus,
            'completed_at' => now(),
        ]);

        // Register to earning_delivery_queue for lot-level stock updates
        // This allows sakemaru Job to process lot allocation confirmation
        try {
            $queueService = new EarningDeliveryQueueService;
            $queueRecord = $queueService->registerFromPickingTask($task);
            if ($queueRecord) {
                Log::info('Registered picking task completion to earning_delivery_queue', [
                    'task_id' => $task->id,
                    'queue_id' => $queueRecord->id,
                ]);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the request - queue registration is for async processing
            Log::error('Failed to register to earning_delivery_queue', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }

        $response = [
            'is_success' => true,
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

    /**
     * POST /api/picking/tasks/{wms_picking_item_result_id}/cancel
     *
     * ピッキングアイテムキャンセル
     *
     * @OA\Post(
     *     path="/api/picking/tasks/{wms_picking_item_result_id}/cancel",
     *     tags={"Picking Tasks"},
     *     summary="Cancel picking item result",
     *     description="Reset picking item result to PENDING status with picked_qty = 0",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="wms_picking_item_result_id",
     *         in="path",
     *         description="Picking Item Result ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Picking item cancelled",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="picked_qty", type="number", example=0),
     *                     @OA\Property(property="shortage_qty", type="number", example=0),
     *                     @OA\Property(property="status", type="string", example="PENDING")
     *                 ),
     *                 @OA\Property(property="message", type="string", example="Picking item cancelled"),
     *                 @OA\Property(property="debug_message", type="string", example=null, nullable=true)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Item result not found"),
     *     @OA\Response(response=422, description="Item cannot be cancelled (already completed)")
     * )
     */
    public function cancelItemResult(Request $request, $itemResultId)
    {
        $itemResult = DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('id', $itemResultId)
            ->first();

        if (! $itemResult) {
            return response()->json([
                'is_success' => false,
                'code' => 'NOT_FOUND',
                'result' => [
                    'data' => null,
                    'error_message' => 'Picking item result not found',
                ],
            ], 404);
        }

        // Check if item can be cancelled (not already COMPLETED or SHORTAGE)
        if (in_array($itemResult->status, ['COMPLETED', 'SHORTAGE'])) {
            return response()->json([
                'is_success' => false,
                'code' => 'VALIDATION_ERROR',
                'result' => [
                    'data' => null,
                    'error_message' => 'キャンセルできません',
                    'errors' => [
                        'status' => ["ステータスが{$itemResult->status}のためキャンセルできません"],
                    ],
                ],
            ], 422);
        }

        // Reset item result to PENDING status
        DB::connection('sakemaru')
            ->table('wms_picking_item_results')
            ->where('id', $itemResultId)
            ->update([
                'picked_qty' => 0,
                'shortage_qty' => 0,
                'status' => 'PENDING',
                'picked_at' => null,
                'updated_at' => now(),
            ]);

        $response = [
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => [
                    'id' => $itemResultId,
                    'picked_qty' => 0,
                    'shortage_qty' => 0,
                    'status' => 'PENDING',
                ],
                'message' => 'Picking item cancelled',
                'debug_message' => null,
            ],
        ];

        return response()->json($response);
    }
}
