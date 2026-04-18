<?php

namespace App\Http\Controllers\Api;

use App\Models\WmsPicker;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentPickingService;
use App\Services\Shortage\ProxyShipmentQueryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProxyShipmentController extends ApiController
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

        return $this->success($result, '横持ち出荷一覧を取得しました');
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

        try {
            $allocation = $this->queryService->findForWarehouse($id, $validated['warehouse_id']);
        } catch (ModelNotFoundException $e) {
            return $this->notFound($e->getMessage() ?: '横持ち出荷が見つかりません');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError([], $e->getMessage());
        }

        return $this->success(
            $this->queryService->formatDetailResponse($allocation),
            '横持ち出荷詳細を取得しました'
        );
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

        try {
            $allocation = $this->findAndValidateAllocation($id, $validated['warehouse_id']);
            $picker = $this->resolvePicker($request);
        } catch (ModelNotFoundException $e) {
            return $this->notFound($e->getMessage() ?: '横持ち出荷が見つかりません');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError([], $e->getMessage());
        }

        $updated = $this->pickingService->start($allocation, $picker);

        return $this->success(
            $this->queryService->formatAllocation($updated),
            '横持ち出荷を開始しました'
        );
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

        try {
            $allocation = $this->findAndValidateAllocation($id, $validated['warehouse_id']);
            $picker = $this->resolvePicker($request);
        } catch (ModelNotFoundException $e) {
            return $this->notFound($e->getMessage() ?: '横持ち出荷が見つかりません');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError([], $e->getMessage());
        }

        $updated = $this->pickingService->update($allocation, $picker, $validated['picked_qty']);

        return $this->success(
            $this->queryService->formatAllocation($updated),
            'ピック数を更新しました'
        );
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

        try {
            $allocation = $this->findAndValidateAllocation($id, $validated['warehouse_id']);
            $picker = $this->resolvePicker($request);
        } catch (ModelNotFoundException $e) {
            return $this->notFound($e->getMessage() ?: '横持ち出荷が見つかりません');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError([], $e->getMessage());
        }

        // picked_qty > assign_qty のバリデーション（picked_qtyが指定された場合）
        if (isset($validated['picked_qty']) && $validated['picked_qty'] > $allocation->assign_qty) {
            return $this->validationError(
                ['picked_qty' => ["ピック数({$validated['picked_qty']})が指示数({$allocation->assign_qty})を超えています"]],
                "ピック数({$validated['picked_qty']})が指示数({$allocation->assign_qty})を超えています"
            );
        }

        $result = $this->pickingService->complete(
            $allocation,
            $picker,
            $validated['picked_qty'] ?? null,
        );

        $data = $this->queryService->formatAllocation($result['allocation']);
        $data['stock_transfer_queue_id'] = $result['stock_transfer_queue_id'];

        return $this->success($data, '横持ち出荷を完了しました');
    }

    /**
     * allocation を取得し、倉庫一致・ステータス検証
     *
     * @throws ModelNotFoundException
     * @throws \InvalidArgumentException
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
            throw new ModelNotFoundException('横持ち出荷が見つかりません');
        }

        if ((int) $allocation->target_warehouse_id !== $warehouseId) {
            throw new \InvalidArgumentException('指定された倉庫と一致しません');
        }

        if (! $allocation->is_confirmed) {
            throw new \InvalidArgumentException('この横持ち出荷はまだ確定されていません');
        }

        // 完了済みの場合はcompleteのべき等性用にそのまま返す
        if ($allocation->is_finished) {
            return $allocation;
        }

        if (! in_array($allocation->status, [
            WmsShortageAllocation::STATUS_RESERVED,
            WmsShortageAllocation::STATUS_PICKING,
        ])) {
            throw new \InvalidArgumentException('この横持ち出荷は操作できません（ステータス: ' . $allocation->status . '）');
        }

        return $allocation;
    }

    /**
     * リクエストからピッカーを解決
     *
     * @throws \InvalidArgumentException
     */
    protected function resolvePicker(Request $request): WmsPicker
    {
        $user = $request->user();

        if ($user instanceof WmsPicker) {
            return $user;
        }

        // Sanctum token の tokenable が WmsPicker の場合
        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token && $token->tokenable instanceof WmsPicker) {
                return $token->tokenable;
            }
        }

        Log::warning('Could not resolve picker from request', [
            'user_class' => $user ? get_class($user) : 'null',
        ]);

        throw new \InvalidArgumentException('ピッカー情報を解決できません');
    }
}
