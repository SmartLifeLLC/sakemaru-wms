<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WmsPicker;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentPickingService;
use App\Services\Shortage\ProxyShipmentQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProxyShipmentController extends Controller
{
    public function __construct(
        protected ProxyShipmentQueryService $queryService,
        protected ProxyShipmentPickingService $pickingService,
    ) {}

    /**
     * GET /api/proxy-shipments
     *
     * 横持ち出荷一覧取得
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer',
            'shipment_date' => 'nullable|date',
            'delivery_course_id' => 'nullable|integer',
        ]);

        $result = $this->queryService->listForWarehouse(
            $validated['warehouse_id'],
            $validated['shipment_date'] ?? null,
            $validated['delivery_course_id'] ?? null,
        );

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => $result,
        ]);
    }

    /**
     * GET /api/proxy-shipments/{id}
     *
     * 横持ち出荷詳細取得
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer',
        ]);

        $allocation = $this->queryService->findForWarehouse($id, $validated['warehouse_id']);

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => $this->queryService->formatDetailResponse($allocation),
            ],
        ]);
    }

    /**
     * POST /api/proxy-shipments/{id}/start
     *
     * 横持ち出荷開始
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer',
        ]);

        $allocation = $this->findAndValidateAllocation($id, $validated['warehouse_id']);
        $picker = $this->resolvePicker($request);

        $updated = $this->pickingService->start($allocation, $picker);

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => $this->queryService->formatAllocation($updated),
                'message' => '横���ち出荷を開始しました',
            ],
        ]);
    }

    /**
     * POST /api/proxy-shipments/{id}/update
     *
     * ピック数更新
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $allocation = WmsShortageAllocation::findOrFail($id);

        $validated = $request->validate([
            'warehouse_id' => 'required|integer',
            'picked_qty' => "required|integer|min:0|max:{$allocation->assign_qty}",
        ]);

        $allocation = $this->findAndValidateAllocation($id, $validated['warehouse_id']);
        $picker = $this->resolvePicker($request);

        $updated = $this->pickingService->update($allocation, $picker, $validated['picked_qty']);

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => $this->queryService->formatAllocation($updated),
                'message' => 'ピック���を更新しました',
            ],
        ]);
    }

    /**
     * POST /api/proxy-shipments/{id}/complete
     *
     * 横持ち出荷完了
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer',
            'picked_qty' => 'nullable|integer|min:0',
        ]);

        $allocation = $this->findAndValidateAllocation($id, $validated['warehouse_id']);
        $picker = $this->resolvePicker($request);

        // picked_qty > assign_qty のバリデーション（picked_qtyが指定された場合）
        if (isset($validated['picked_qty']) && $validated['picked_qty'] > $allocation->assign_qty) {
            return response()->json([
                'is_success' => false,
                'code' => 'VALIDATION_ERROR',
                'result' => [
                    'message' => "ピック数({$validated['picked_qty']})が指��数({$allocation->assign_qty})を超えています",
                ],
            ], 422);
        }

        $result = $this->pickingService->complete(
            $allocation,
            $picker,
            $validated['picked_qty'] ?? null,
        );

        return response()->json([
            'is_success' => true,
            'code' => 'SUCCESS',
            'result' => [
                'data' => $this->queryService->formatAllocation($result['allocation']),
                'stock_transfer_queue_id' => $result['stock_transfer_queue_id'],
                'message' => '横持ち出荷を完了しました',
            ],
        ]);
    }

    /**
     * allocation を取得し、倉庫一致・ステータス検証
     */
    protected function findAndValidateAllocation(int $id, int $warehouseId): WmsShortageAllocation
    {
        $allocation = WmsShortageAllocation::with([
            'shortage.item.item_search_information',
            'shortage.trade.partner',
            'targetWarehouse',
            'sourceWarehouse',
            'deliveryCourse',
        ])->find($id);

        if (! $allocation) {
            abort(404, '横持ち出荷が見つかりません');
        }

        if ((int) $allocation->target_warehouse_id !== $warehouseId) {
            abort(422, '指定された倉庫と一致しません');
        }

        if (! $allocation->is_confirmed) {
            abort(422, 'この横持ち出荷はまだ確定されていません');
        }

        // 完了済みの場合はcompleteのべき等性用にそのまま返す
        if ($allocation->is_finished) {
            return $allocation;
        }

        if (! in_array($allocation->status, [
            WmsShortageAllocation::STATUS_RESERVED,
            WmsShortageAllocation::STATUS_PICKING,
        ])) {
            abort(422, 'この横持ち出荷は操作できません（ステータス: ' . $allocation->status . '）');
        }

        return $allocation;
    }

    /**
     * リクエストからピッカーを解決
     */
    protected function resolvePicker(Request $request): WmsPicker
    {
        $user = $request->user();

        if ($user instanceof WmsPicker) {
            return $user;
        }

        // Sanctum token の tokenable が WmsPicker ���場合
        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token && $token->tokenable instanceof WmsPicker) {
                return $token->tokenable;
            }
        }

        Log::warning('Could not resolve picker from request', [
            'user_class' => $user ? get_class($user) : 'null',
        ]);

        abort(422, 'ピッカー情報を解決できません');
    }
}
