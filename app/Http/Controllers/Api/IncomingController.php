<?php

namespace App\Http\Controllers\Api;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EItemSearchCodeType;
use App\Enums\EVolumeUnit;
use App\Enums\TemperatureType;
use App\Models\Sakemaru\ItemWarehouseLocation;
use App\Models\Sakemaru\Location;
use App\Models\WmsIncomingWorkItem;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingConfirmationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 入荷API コントローラ
 *
 * Handy端末での入荷作業用API
 *
 * @OA\Tag(
 *     name="Incoming",
 *     description="入荷作業関連API"
 * )
 */
class IncomingController extends ApiController
{
    public function __construct(
        private readonly IncomingConfirmationService $confirmationService
    ) {}

    /**
     * GET /api/incoming/schedules
     *
     * 入庫予定一覧取得（仮想倉庫対応）
     *
     * @OA\Get(
     *     path="/api/incoming/schedules",
     *     tags={"Incoming"},
     *     summary="入庫予定一覧取得",
     *     description="倉庫別の入庫予定を検索。商品コード、JANコード、商品名で検索可能。仮想倉庫に紐づく入庫予定も含む。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="warehouse_id",
     *         in="query",
     *         required=true,
     *         description="作業倉庫ID（実倉庫を指定すると仮想倉庫分も取得）",
     *         @OA\Schema(type="integer", example=991)
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         description="検索キーワード（商品コード、JANコード、商品名）",
     *         @OA\Schema(type="string", example="4901234567890")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="item_id", type="integer", example=123),
     *                         @OA\Property(property="item_code", type="string", example="10001"),
     *                         @OA\Property(property="item_name", type="string", example="商品A"),
     *                         @OA\Property(property="search_code", type="string", example="4901234567890,4901234567891"),
     *                         @OA\Property(property="jan_codes", type="array", @OA\Items(type="string")),
     *                         @OA\Property(property="volume", type="string", example="720ml"),
     *                         @OA\Property(property="temperature_type", type="string", example="常温"),
     *                         @OA\Property(property="images", type="array", @OA\Items(type="string")),
     *                         @OA\Property(property="total_expected_quantity", type="integer", example=100),
     *                         @OA\Property(property="total_received_quantity", type="integer", example=20),
     *                         @OA\Property(property="total_remaining_quantity", type="integer", example=80),
     *                         @OA\Property(
     *                             property="warehouses",
     *                             type="array",
     *                             description="倉庫別の入庫予定数",
     *                             @OA\Items(
     *                                 type="object",
     *                                 @OA\Property(property="warehouse_id", type="integer"),
     *                                 @OA\Property(property="warehouse_code", type="string"),
     *                                 @OA\Property(property="warehouse_name", type="string"),
     *                                 @OA\Property(property="expected_quantity", type="integer"),
     *                                 @OA\Property(property="received_quantity", type="integer"),
     *                                 @OA\Property(property="remaining_quantity", type="integer")
     *                             )
     *                         ),
     *                         @OA\Property(
     *                             property="schedules",
     *                             type="array",
     *                             description="個別の入庫予定",
     *                             @OA\Items(
     *                                 type="object",
     *                                 @OA\Property(property="id", type="integer"),
     *                                 @OA\Property(property="warehouse_id", type="integer"),
     *                                 @OA\Property(property="warehouse_name", type="string"),
     *                                 @OA\Property(property="expected_quantity", type="integer"),
     *                                 @OA\Property(property="received_quantity", type="integer"),
     *                                 @OA\Property(property="remaining_quantity", type="integer"),
     *                                 @OA\Property(property="quantity_type", type="string", enum={"PIECE", "CASE"}),
     *                                 @OA\Property(property="expected_arrival_date", type="string", format="date"),
     *                                 @OA\Property(property="status", type="string", enum={"PENDING", "PARTIAL"})
     *                             )
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'search' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $warehouseId = $request->input('warehouse_id');
        $search = $request->input('search');

        // Get warehouse IDs including virtual warehouses
        $warehouseIds = $this->getWarehouseIdsWithVirtual($warehouseId);

        // Build query
        $query = WmsOrderIncomingSchedule::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('status', [
                IncomingScheduleStatus::PENDING,
                IncomingScheduleStatus::PARTIAL,
            ])
            ->with(['warehouse', 'item', 'item.item_search_information', 'contractor']);

        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('search_code', 'like', "%{$search}%")
                    ->orWhereHas('item', function ($itemQuery) use ($search) {
                        $itemQuery->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $schedules = $query->orderBy('expected_arrival_date', 'asc')
            ->orderBy('item_id')
            ->get();

        // Group by item and format response
        $groupedData = $this->groupSchedulesByItem($schedules, $warehouseId);

        return $this->success($groupedData);
    }

    /**
     * GET /api/incoming/schedules/{id}
     *
     * 入庫予定詳細取得
     *
     * @OA\Get(
     *     path="/api/incoming/schedules/{id}",
     *     tags={"Incoming"},
     *     summary="入庫予定詳細取得",
     *     description="入庫予定の詳細情報を取得",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="入庫予定ID",
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="warehouse_id", type="integer"),
     *                     @OA\Property(property="warehouse_code", type="string"),
     *                     @OA\Property(property="warehouse_name", type="string"),
     *                     @OA\Property(property="item_id", type="integer"),
     *                     @OA\Property(property="item_code", type="string"),
     *                     @OA\Property(property="item_name", type="string"),
     *                     @OA\Property(property="search_code", type="string"),
     *                     @OA\Property(property="jan_codes", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="expected_quantity", type="integer"),
     *                     @OA\Property(property="received_quantity", type="integer"),
     *                     @OA\Property(property="remaining_quantity", type="integer"),
     *                     @OA\Property(property="quantity_type", type="string"),
     *                     @OA\Property(property="expected_arrival_date", type="string", format="date"),
     *                     @OA\Property(property="status", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="入庫予定が見つかりません")
     * )
     */
    public function show(int $id): JsonResponse
    {
        $schedule = WmsOrderIncomingSchedule::with(['warehouse', 'item', 'item.item_search_information', 'contractor'])
            ->find($id);

        if (! $schedule) {
            return $this->notFound('入庫予定が見つかりません');
        }

        return $this->success($this->formatScheduleDetail($schedule));
    }

    /**
     * GET /api/incoming/work-items
     *
     * 作業データ一覧取得（作業中・履歴）
     *
     * @OA\Get(
     *     path="/api/incoming/work-items",
     *     tags={"Incoming"},
     *     summary="作業データ一覧取得",
     *     description="指定倉庫の入荷作業データを取得。statusパラメータで作業中・完了・キャンセル済みを絞り込み可能。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="warehouse_id",
     *         in="query",
     *         required=true,
     *         description="倉庫ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="picker_id",
     *         in="query",
     *         required=false,
     *         description="作業者ID（指定時はその作業者のデータのみ）",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         description="ステータス（WORKING, COMPLETED, CANCELLED, all）。デフォルト: WORKING",
     *         @OA\Schema(type="string", enum={"WORKING", "COMPLETED", "CANCELLED", "all"}, default="WORKING")
     *     ),
     *     @OA\Parameter(
     *         name="from_date",
     *         in="query",
     *         required=false,
     *         description="開始日（履歴絞り込み用、YYYY-MM-DD形式）",
     *         @OA\Schema(type="string", format="date", example="2026-01-01")
     *     ),
     *     @OA\Parameter(
     *         name="to_date",
     *         in="query",
     *         required=false,
     *         description="終了日（履歴絞り込み用、YYYY-MM-DD形式）",
     *         @OA\Schema(type="string", format="date", example="2026-01-31")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="取得件数（デフォルト: 100）",
     *         @OA\Schema(type="integer", default=100)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/IncomingWorkItem")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function workItems(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'picker_id' => 'nullable|integer',
            'status' => 'nullable|string|in:WORKING,COMPLETED,CANCELLED,all',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $query = WmsIncomingWorkItem::with(['incomingSchedule', 'incomingSchedule.item', 'incomingSchedule.warehouse', 'location'])
            ->where('warehouse_id', $request->input('warehouse_id'));

        // ステータス絞り込み
        $status = $request->input('status', WmsIncomingWorkItem::STATUS_WORKING);
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // 作業者絞り込み
        if ($request->has('picker_id')) {
            $query->where('picker_id', $request->input('picker_id'));
        }

        // 日付絞り込み（履歴用）
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $limit = $request->input('limit', 100);
        $workItems = $query->orderBy('created_at', 'desc')->limit($limit)->get();

        return $this->success($workItems->map(fn ($item) => $this->formatWorkItem($item)));
    }

    /**
     * POST /api/incoming/work-items
     *
     * 入荷作業開始（作業データ作成）
     *
     * @OA\Post(
     *     path="/api/incoming/work-items",
     *     tags={"Incoming"},
     *     summary="入荷作業開始",
     *     description="入庫予定に対する入荷作業を開始し、作業データを作成",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"incoming_schedule_id", "picker_id", "warehouse_id"},
     *             @OA\Property(property="incoming_schedule_id", type="integer", description="入庫予定ID"),
     *             @OA\Property(property="picker_id", type="integer", description="作業者ID"),
     *             @OA\Property(property="warehouse_id", type="integer", description="作業倉庫ID")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", ref="#/components/schemas/IncomingWorkItem"),
     *                 @OA\Property(property="message", type="string", example="作業を開始しました")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="既に作業中 / 作業不可"),
     *     @OA\Response(response=404, description="入庫予定が見つかりません"),
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function startWork(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'incoming_schedule_id' => 'required|integer',
            'picker_id' => 'required|integer',
            'warehouse_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $schedule = WmsOrderIncomingSchedule::find($request->input('incoming_schedule_id'));

        if (! $schedule) {
            return $this->notFound('入庫予定が見つかりません');
        }

        if (! in_array($schedule->status, [IncomingScheduleStatus::PENDING, IncomingScheduleStatus::PARTIAL])) {
            return $this->error('この入庫予定は作業できません', 400);
        }

        // Check if already working
        $existingWork = WmsIncomingWorkItem::where('incoming_schedule_id', $schedule->id)
            ->where('status', WmsIncomingWorkItem::STATUS_WORKING)
            ->first();

        if ($existingWork) {
            return $this->error('この入庫予定は既に作業中です', 400);
        }

        try {
            $warehouseId = $request->input('warehouse_id');
            $item = $schedule->item;

            // デフォルトロケーション取得（優先順位に従う）
            $locationId = $this->getDefaultLocationId($warehouseId, $schedule->item_id);

            // デフォルト賞味期限計算（商品のdefault_expiration_daysから）
            $defaultExpirationDate = null;
            if ($item && $item->default_expiration_days) {
                $defaultExpirationDate = Carbon::today()->addDays($item->default_expiration_days)->format('Y-m-d');
            }

            $workItem = WmsIncomingWorkItem::create([
                'incoming_schedule_id' => $schedule->id,
                'picker_id' => $request->input('picker_id'),
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'work_quantity' => $schedule->remaining_quantity,
                'work_arrival_date' => now()->format('Y-m-d'),
                'work_expiration_date' => $defaultExpirationDate,
                'status' => WmsIncomingWorkItem::STATUS_WORKING,
                'started_at' => now(),
            ]);

            Log::info('Incoming work started', [
                'work_item_id' => $workItem->id,
                'schedule_id' => $schedule->id,
                'picker_id' => $request->input('picker_id'),
                'location_id' => $locationId,
                'default_expiration_date' => $defaultExpirationDate,
            ]);

            $workItem->load(['incomingSchedule', 'incomingSchedule.item', 'incomingSchedule.warehouse', 'location']);

            return $this->success($this->formatWorkItem($workItem), '作業を開始しました');
        } catch (\Exception $e) {
            Log::error('Failed to start incoming work', [
                'schedule_id' => $schedule->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('作業開始に失敗しました', 500, 'ERROR', $e->getMessage());
        }
    }

    /**
     * PUT /api/incoming/work-items/{id}
     *
     * 作業データ更新（数量・日付）
     *
     * @OA\Put(
     *     path="/api/incoming/work-items/{id}",
     *     tags={"Incoming"},
     *     summary="作業データ更新",
     *     description="入荷作業中のデータ（数量、入荷日、賞味期限）を更新",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="作業データID",
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="work_quantity", type="integer", description="入荷数量"),
     *             @OA\Property(property="work_arrival_date", type="string", format="date", description="入荷日"),
     *             @OA\Property(property="work_expiration_date", type="string", format="date", description="賞味期限"),
     *             @OA\Property(property="location_id", type="integer", description="入庫ロケーションID")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", ref="#/components/schemas/IncomingWorkItem"),
     *                 @OA\Property(property="message", type="string", example="更新しました")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="編集不可"),
     *     @OA\Response(response=404, description="作業データが見つかりません")
     * )
     */
    public function updateWork(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'work_quantity' => 'nullable|integer|min:0',
            'work_arrival_date' => 'nullable|date',
            'work_expiration_date' => 'nullable|date',
            'location_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $workItem = WmsIncomingWorkItem::find($id);

        if (! $workItem) {
            return $this->notFound('作業データが見つかりません');
        }

        if ($workItem->status !== WmsIncomingWorkItem::STATUS_WORKING) {
            return $this->error('この作業データは編集できません', 400);
        }

        try {
            $updateData = [];

            if ($request->has('work_quantity')) {
                $updateData['work_quantity'] = $request->input('work_quantity');
            }
            if ($request->has('work_arrival_date')) {
                $updateData['work_arrival_date'] = $request->input('work_arrival_date');
            }
            if ($request->has('work_expiration_date')) {
                $updateData['work_expiration_date'] = $request->input('work_expiration_date');
            }
            if ($request->has('location_id')) {
                $updateData['location_id'] = $request->input('location_id');
            }

            $workItem->update($updateData);

            Log::info('Incoming work updated', [
                'work_item_id' => $workItem->id,
                'update_data' => $updateData,
            ]);

            $workItem->load(['incomingSchedule', 'incomingSchedule.item', 'incomingSchedule.warehouse', 'location']);

            return $this->success($this->formatWorkItem($workItem), '更新しました');
        } catch (\Exception $e) {
            Log::error('Failed to update incoming work', [
                'work_item_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('更新に失敗しました', 500, 'ERROR', $e->getMessage());
        }
    }

    /**
     * POST /api/incoming/work-items/{id}/complete
     *
     * 入荷作業完了（入庫確定処理実行）
     *
     * @OA\Post(
     *     path="/api/incoming/work-items/{id}/complete",
     *     tags={"Incoming"},
     *     summary="入荷作業完了",
     *     description="入荷作業を完了し、入庫確定処理を実行。全量入庫または一部入庫を判定して処理。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="作業データID",
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="null"),
     *                 @OA\Property(property="message", type="string", example="入庫を確定しました")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="完了不可 / 入庫予定が見つかりません"),
     *     @OA\Response(response=404, description="作業データが見つかりません"),
     *     @OA\Response(response=500, description="入庫確定に失敗")
     * )
     */
    public function completeWork(int $id): JsonResponse
    {
        $workItem = WmsIncomingWorkItem::with('incomingSchedule')->find($id);

        if (! $workItem) {
            return $this->notFound('作業データが見つかりません');
        }

        if ($workItem->status !== WmsIncomingWorkItem::STATUS_WORKING) {
            return $this->error('この作業データは完了できません', 400);
        }

        $schedule = $workItem->incomingSchedule;

        if (! $schedule) {
            return $this->error('入庫予定が見つかりません', 400);
        }

        try {
            DB::connection('sakemaru')->transaction(function () use ($workItem, $schedule) {
                $workQuantity = $workItem->work_quantity;
                $remainingQty = $schedule->remaining_quantity;

                if ($workQuantity >= $remainingQty) {
                    // 全量入庫
                    $this->confirmationService->confirmIncoming(
                        $schedule,
                        $workItem->picker_id,
                        $schedule->expected_quantity,
                        $workItem->work_arrival_date?->format('Y-m-d'),
                        $workItem->work_expiration_date?->format('Y-m-d')
                    );
                } else {
                    // 一部入庫
                    $this->confirmationService->recordPartialIncoming(
                        $schedule,
                        $workQuantity,
                        $workItem->picker_id,
                        $workItem->work_arrival_date?->format('Y-m-d'),
                        $workItem->work_expiration_date?->format('Y-m-d')
                    );
                }

                // Mark work item as completed
                $workItem->update([
                    'status' => WmsIncomingWorkItem::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            });

            Log::info('Incoming work completed', [
                'work_item_id' => $workItem->id,
                'schedule_id' => $schedule->id,
                'quantity' => $workItem->work_quantity,
            ]);

            return $this->success(null, '入庫を確定しました');
        } catch (\Exception $e) {
            Log::error('Failed to complete incoming work', [
                'work_item_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('入庫確定に失敗しました: '.$e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/incoming/work-items/{id}
     *
     * 作業キャンセル
     *
     * @OA\Delete(
     *     path="/api/incoming/work-items/{id}",
     *     tags={"Incoming"},
     *     summary="作業キャンセル",
     *     description="入荷作業をキャンセル。作業中のデータのみキャンセル可能。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="作業データID",
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="null"),
     *                 @OA\Property(property="message", type="string", example="キャンセルしました")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="キャンセル不可"),
     *     @OA\Response(response=404, description="作業データが見つかりません")
     * )
     *
     * @OA\Schema(
     *     schema="IncomingWorkItem",
     *     type="object",
     *     @OA\Property(property="id", type="integer", description="作業データID"),
     *     @OA\Property(property="incoming_schedule_id", type="integer", description="入庫予定ID"),
     *     @OA\Property(property="picker_id", type="integer", description="作業者ID"),
     *     @OA\Property(property="warehouse_id", type="integer", description="倉庫ID"),
     *     @OA\Property(property="location_id", type="integer", nullable=true, description="入庫ロケーションID"),
     *     @OA\Property(
     *         property="location",
     *         type="object",
     *         nullable=true,
     *         description="ロケーション情報",
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="code1", type="string"),
     *         @OA\Property(property="code2", type="string"),
     *         @OA\Property(property="code3", type="string"),
     *         @OA\Property(property="name", type="string"),
     *         @OA\Property(property="display_name", type="string", example="A 1 01")
     *     ),
     *     @OA\Property(property="work_quantity", type="integer", description="作業数量"),
     *     @OA\Property(property="work_arrival_date", type="string", format="date", description="入荷日"),
     *     @OA\Property(property="work_expiration_date", type="string", format="date", nullable=true, description="賞味期限（デフォルト: 商品のdefault_expiration_daysから計算）"),
     *     @OA\Property(property="status", type="string", enum={"WORKING", "COMPLETED", "CANCELLED"}, description="ステータス"),
     *     @OA\Property(property="started_at", type="string", format="date-time", description="作業開始日時"),
     *     @OA\Property(
     *         property="schedule",
     *         type="object",
     *         nullable=true,
     *         description="入庫予定情報",
     *         @OA\Property(property="id", type="integer"),
     *         @OA\Property(property="item_id", type="integer"),
     *         @OA\Property(property="item_code", type="string"),
     *         @OA\Property(property="item_name", type="string"),
     *         @OA\Property(property="warehouse_id", type="integer"),
     *         @OA\Property(property="warehouse_name", type="string"),
     *         @OA\Property(property="expected_quantity", type="integer"),
     *         @OA\Property(property="received_quantity", type="integer"),
     *         @OA\Property(property="remaining_quantity", type="integer"),
     *         @OA\Property(property="quantity_type", type="string")
     *     )
     * )
     */
    public function cancelWork(int $id): JsonResponse
    {
        $workItem = WmsIncomingWorkItem::find($id);

        if (! $workItem) {
            return $this->notFound('作業データが見つかりません');
        }

        if ($workItem->status !== WmsIncomingWorkItem::STATUS_WORKING) {
            return $this->error('この作業データはキャンセルできません', 400);
        }

        try {
            $workItem->update([
                'status' => WmsIncomingWorkItem::STATUS_CANCELLED,
            ]);

            Log::info('Incoming work cancelled', [
                'work_item_id' => $workItem->id,
            ]);

            return $this->success(null, 'キャンセルしました');
        } catch (\Exception $e) {
            Log::error('Failed to cancel incoming work', [
                'work_item_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('キャンセルに失敗しました', 500, 'ERROR', $e->getMessage());
        }
    }

    // ========== Private Methods ==========

    /**
     * 実倉庫に紐づく仮想倉庫IDを含めて取得
     */
    private function getWarehouseIdsWithVirtual(int $warehouseId): array
    {
        // Check if the given warehouse is a real warehouse
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first();

        if (! $warehouse) {
            return [$warehouseId];
        }

        // If virtual, get the stock_warehouse_id (real warehouse)
        $realWarehouseId = $warehouse->is_virtual
            ? ($warehouse->stock_warehouse_id ?? $warehouseId)
            : $warehouseId;

        // Get all warehouse IDs that share the same real warehouse
        $warehouseIds = DB::connection('sakemaru')
            ->table('warehouses')
            ->where(function ($query) use ($realWarehouseId) {
                $query->where('id', $realWarehouseId)
                    ->orWhere('stock_warehouse_id', $realWarehouseId);
            })
            ->pluck('id')
            ->toArray();

        return $warehouseIds;
    }

    /**
     * スケジュールを商品でグループ化（仮想倉庫分も集約）
     */
    private function groupSchedulesByItem($schedules, int $requestWarehouseId): array
    {
        $grouped = [];

        foreach ($schedules as $schedule) {
            $itemId = $schedule->item_id;

            if (! isset($grouped[$itemId])) {
                $grouped[$itemId] = [
                    'item_id' => $itemId,
                    'item_code' => $schedule->item?->code,
                    'item_name' => $schedule->item?->name,
                    'search_code' => $schedule->search_code,
                    'jan_codes' => $this->getJanCodes($schedule->item),
                    'volume' => $this->getVolumeDisplay($schedule->item),
                    'temperature_type' => $this->getTemperatureTypeLabel($schedule->item),
                    'images' => $this->getImages($schedule->item),
                    'total_expected_quantity' => 0,
                    'total_received_quantity' => 0,
                    'total_remaining_quantity' => 0,
                    'warehouses' => [],
                    'schedules' => [],
                ];
            }

            // Add to totals
            $grouped[$itemId]['total_expected_quantity'] += $schedule->expected_quantity;
            $grouped[$itemId]['total_received_quantity'] += $schedule->received_quantity;
            $grouped[$itemId]['total_remaining_quantity'] += $schedule->remaining_quantity;

            // Track by warehouse
            $whId = $schedule->warehouse_id;
            if (! isset($grouped[$itemId]['warehouses'][$whId])) {
                $grouped[$itemId]['warehouses'][$whId] = [
                    'warehouse_id' => $whId,
                    'warehouse_code' => $schedule->warehouse?->code,
                    'warehouse_name' => $schedule->warehouse?->name,
                    'expected_quantity' => 0,
                    'received_quantity' => 0,
                    'remaining_quantity' => 0,
                ];
            }
            $grouped[$itemId]['warehouses'][$whId]['expected_quantity'] += $schedule->expected_quantity;
            $grouped[$itemId]['warehouses'][$whId]['received_quantity'] += $schedule->received_quantity;
            $grouped[$itemId]['warehouses'][$whId]['remaining_quantity'] += $schedule->remaining_quantity;

            // Add individual schedule
            $grouped[$itemId]['schedules'][] = [
                'id' => $schedule->id,
                'warehouse_id' => $schedule->warehouse_id,
                'warehouse_name' => $schedule->warehouse?->name,
                'expected_quantity' => $schedule->expected_quantity,
                'received_quantity' => $schedule->received_quantity,
                'remaining_quantity' => $schedule->remaining_quantity,
                'quantity_type' => $schedule->quantity_type?->value,
                'expected_arrival_date' => $schedule->expected_arrival_date?->format('Y-m-d'),
                'status' => $schedule->status->value,
            ];
        }

        // Convert warehouses from associative to indexed array
        foreach ($grouped as &$item) {
            $item['warehouses'] = array_values($item['warehouses']);
        }

        return array_values($grouped);
    }

    /**
     * スケジュール詳細フォーマット
     */
    private function formatScheduleDetail($schedule): array
    {
        return [
            'id' => $schedule->id,
            'warehouse_id' => $schedule->warehouse_id,
            'warehouse_code' => $schedule->warehouse?->code,
            'warehouse_name' => $schedule->warehouse?->name,
            'item_id' => $schedule->item_id,
            'item_code' => $schedule->item?->code,
            'item_name' => $schedule->item?->name,
            'search_code' => $schedule->search_code,
            'jan_codes' => $this->getJanCodes($schedule->item),
            'volume' => $this->getVolumeDisplay($schedule->item),
            'temperature_type' => $this->getTemperatureTypeLabel($schedule->item),
            'images' => $this->getImages($schedule->item),
            'contractor_id' => $schedule->contractor_id,
            'contractor_name' => $schedule->contractor?->name,
            'expected_quantity' => $schedule->expected_quantity,
            'received_quantity' => $schedule->received_quantity,
            'remaining_quantity' => $schedule->remaining_quantity,
            'quantity_type' => $schedule->quantity_type?->value,
            'order_date' => $schedule->order_date?->format('Y-m-d'),
            'expected_arrival_date' => $schedule->expected_arrival_date?->format('Y-m-d'),
            'actual_arrival_date' => $schedule->actual_arrival_date?->format('Y-m-d'),
            'expiration_date' => $schedule->expiration_date?->format('Y-m-d'),
            'status' => $schedule->status->value,
        ];
    }

    /**
     * 作業データフォーマット
     */
    private function formatWorkItem($workItem): array
    {
        $schedule = $workItem->incomingSchedule;
        $location = $workItem->location;

        return [
            'id' => $workItem->id,
            'incoming_schedule_id' => $workItem->incoming_schedule_id,
            'picker_id' => $workItem->picker_id,
            'warehouse_id' => $workItem->warehouse_id,
            'location_id' => $workItem->location_id,
            'location' => $location ? [
                'id' => $location->id,
                'code1' => $location->code1,
                'code2' => $location->code2,
                'code3' => $location->code3,
                'name' => $location->name,
                'display_name' => $location->code1.' '.$location->code2.' '.$location->code3,
            ] : null,
            'work_quantity' => $workItem->work_quantity,
            'work_arrival_date' => $workItem->work_arrival_date?->format('Y-m-d'),
            'work_expiration_date' => $workItem->work_expiration_date?->format('Y-m-d'),
            'status' => $workItem->status,
            'started_at' => $workItem->started_at?->toIso8601String(),
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'item_id' => $schedule->item_id,
                'item_code' => $schedule->item?->code,
                'item_name' => $schedule->item?->name,
                'warehouse_id' => $schedule->warehouse_id,
                'warehouse_name' => $schedule->warehouse?->name,
                'expected_quantity' => $schedule->expected_quantity,
                'received_quantity' => $schedule->received_quantity,
                'remaining_quantity' => $schedule->remaining_quantity,
                'quantity_type' => $schedule->quantity_type?->value,
            ] : null,
        ];
    }

    // Helper methods for item data

    private function getJanCodes($item): array
    {
        if (! $item || ! $item->item_search_information) {
            return [];
        }

        return $item->item_search_information
            ->filter(fn ($info) => $info->code_type === EItemSearchCodeType::JAN->value)
            ->sortByDesc('updated_at')
            ->pluck('search_string')
            ->values()
            ->toArray();
    }

    private function getVolumeDisplay($item): ?string
    {
        if (! $item || ! $item->volume) {
            return null;
        }

        $volumeUnit = EVolumeUnit::tryFrom($item->volume_unit);

        return $item->volume.($volumeUnit ? $volumeUnit->name() : '');
    }

    private function getTemperatureTypeLabel($item): ?string
    {
        if (! $item || ! $item->temperature_type) {
            return null;
        }

        $tempType = TemperatureType::tryFrom($item->temperature_type);

        return $tempType?->label();
    }

    private function getImages($item): array
    {
        $images = [];
        if (! $item) {
            return $images;
        }

        if ($item->image_url_1) {
            $images[] = $item->image_url_1;
        }
        if ($item->image_url_2) {
            $images[] = $item->image_url_2;
        }
        if ($item->image_url_3) {
            $images[] = $item->image_url_3;
        }

        return $images;
    }

    /**
     * デフォルトロケーションIDを取得（優先順位に従う）
     *
     * 1. 商品×倉庫のデフォルトロケーション (item_warehouse_locations)
     * 2. 同じ倉庫・商品の既存ロットのロケーション
     * 3. 倉庫のデフォルトロケーション (Z-0-0)
     */
    private function getDefaultLocationId(int $warehouseId, int $itemId): ?int
    {
        // 1. 商品×倉庫のデフォルトロケーション
        $itemLocation = ItemWarehouseLocation::getDefaultLocation($warehouseId, $itemId);
        if ($itemLocation) {
            return $itemLocation->id;
        }

        // 2. 既存ロットのロケーション（賞味期限が遠い順）
        $existingLocationId = DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->whereNotNull('rsl.location_id')
            ->orderByRaw('rsl.expiration_date IS NULL')
            ->orderByDesc('rsl.expiration_date')
            ->orderByDesc('rsl.location_id')
            ->value('rsl.location_id');

        if ($existingLocationId) {
            return $existingLocationId;
        }

        // 3. 倉庫のデフォルトロケーション (Z-0-0)
        $defaultLocation = Location::where('warehouse_id', $warehouseId)
            ->where('code1', 'Z')
            ->where('code2', '0')
            ->where('code3', '0')
            ->first();

        return $defaultLocation?->id;
    }

    /**
     * GET /api/incoming/locations
     *
     * ロケーション検索
     *
     * @OA\Get(
     *     path="/api/incoming/locations",
     *     tags={"Incoming"},
     *     summary="ロケーション検索",
     *     description="倉庫内のロケーションを検索。code1, code2, code3, nameで検索可能。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="warehouse_id",
     *         in="query",
     *         required=true,
     *         description="倉庫ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         description="検索キーワード（code1, code2, code3, nameで検索）",
     *         @OA\Schema(type="string", example="A-1")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="取得件数（デフォルト: 50）",
     *         @OA\Schema(type="integer", default=50)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="is_success", type="boolean", example=true),
     *             @OA\Property(property="code", type="string", example="SUCCESS"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="code1", type="string"),
     *                         @OA\Property(property="code2", type="string"),
     *                         @OA\Property(property="code3", type="string"),
     *                         @OA\Property(property="name", type="string"),
     *                         @OA\Property(property="display_name", type="string", example="A 1 01")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function searchLocations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'search' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $warehouseId = $request->input('warehouse_id');
        $search = $request->input('search');
        $limit = $request->input('limit', 50);

        $query = Location::where('warehouse_id', $warehouseId);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code1', 'like', "%{$search}%")
                    ->orWhere('code2', 'like', "%{$search}%")
                    ->orWhere('code3', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereRaw("CONCAT(code1, ' ', code2, ' ', code3) LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("CONCAT(code1, '-', code2, '-', code3) LIKE ?", ["%{$search}%"]);
            });
        }

        $locations = $query->orderBy('code1')
            ->orderBy('code2')
            ->orderBy('code3')
            ->limit($limit)
            ->get();

        return $this->success($locations->map(fn ($loc) => [
            'id' => $loc->id,
            'code1' => $loc->code1,
            'code2' => $loc->code2,
            'code3' => $loc->code3,
            'name' => $loc->name,
            'display_name' => $loc->code1.' '.$loc->code2.' '.$loc->code3,
        ]));
    }
}
