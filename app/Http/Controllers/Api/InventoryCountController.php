<?php

namespace App\Http\Controllers\Api;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Services\InventoryCount\InventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryCountController extends ApiController
{
    public function __construct(
        private readonly InventoryCountService $inventoryCountService
    ) {}

    /**
     * GET /api/wms/inventory-counts
     *
     * List inventory counts with status=counting
     */
    public function index(Request $request): JsonResponse
    {
        $counts = WmsInventoryCount::where('status', WmsInventoryCount::STATUS_COUNTING)
            ->orderBy('count_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $this->success([
            'inventory_counts' => $counts->map(fn (WmsInventoryCount $count) => [
                'id' => $count->id,
                'count_no' => $count->count_no,
                'warehouse_id' => $count->warehouse_id,
                'warehouse_code' => $count->warehouse_code,
                'warehouse_name' => $count->warehouse_name,
                'count_date' => $count->count_date?->format('Y-m-d'),
                'status' => $count->status,
                'status_label' => $count->status_label,
                'started_at' => $count->started_at?->toIso8601String(),
                'memo' => $count->memo,
            ])->values()->all(),
        ]);
    }

    /**
     * GET /api/wms/inventory-counts/{id}
     *
     * Show inventory count header info
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        $itemStats = WmsInventoryCountItem::where('inventory_count_id', $count->id)
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('COUNT(first_count_quantity) as counted_items')
            ->selectRaw('COUNT(*) - COUNT(first_count_quantity) as uncounted_items')
            ->first();

        return $this->success([
            'inventory_count' => [
                'id' => $count->id,
                'count_no' => $count->count_no,
                'warehouse_id' => $count->warehouse_id,
                'warehouse_code' => $count->warehouse_code,
                'warehouse_name' => $count->warehouse_name,
                'count_date' => $count->count_date?->format('Y-m-d'),
                'status' => $count->status,
                'status_label' => $count->status_label,
                'started_at' => $count->started_at?->toIso8601String(),
                'snapshot_taken_at' => $count->snapshot_taken_at?->toIso8601String(),
                'memo' => $count->memo,
                'total_items' => (int) $itemStats->total_items,
                'counted_items' => (int) $itemStats->counted_items,
                'uncounted_items' => (int) $itemStats->uncounted_items,
            ],
        ]);
    }

    /**
     * GET /api/wms/inventory-counts/{id}/items
     *
     * List inventory count items with filters
     */
    public function items(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        $query = WmsInventoryCountItem::where('inventory_count_id', $count->id);

        // Filter by floor_name
        if ($request->filled('floor_name')) {
            $query->where('floor_name', $request->input('floor_name'));
        }

        // Filter by location_code1
        if ($request->filled('location_code1')) {
            $query->where('location_code1', $request->input('location_code1'));
        }

        // Filter uncounted items (first_count_quantity is null)
        if ($request->boolean('uncounted')) {
            $query->whereNull('first_count_quantity');
        }

        // Filter items with difference (first_count_quantity != system_quantity)
        if ($request->boolean('has_difference')) {
            $query->whereNotNull('first_count_quantity')
                ->whereColumn('first_count_quantity', '!=', 'system_quantity');
        }

        $query->orderBy('floor_name')
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->orderBy('item_code');

        $perPage = min((int) $request->input('per_page', 50), 200);
        $paginator = $query->paginate($perPage, ['*'], 'page', $request->input('page', 1));

        return $this->success([
            'items' => collect($paginator->items())->map(fn (WmsInventoryCountItem $item) => [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'barcode' => $item->barcode,
                'floor_name' => $item->floor_name,
                'location_no' => $item->location_no,
                'location_code1' => $item->location_code1,
                'location_code2' => $item->location_code2,
                'location_code3' => $item->location_code3,
                'system_quantity' => (float) $item->system_quantity,
                'first_count_quantity' => $item->first_count_quantity !== null ? (float) $item->first_count_quantity : null,
                'second_count_quantity' => $item->second_count_quantity !== null ? (float) $item->second_count_quantity : null,
                'difference_quantity' => $item->first_count_quantity !== null
                    ? (float) $item->first_count_quantity - (float) $item->system_quantity
                    : null,
                'input_count' => (int) ($item->input_count ?? 0),
                'last_counted_at' => $item->last_counted_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/wms/inventory-counts/{id}/scan
     *
     * Search items within an inventory count by barcode or item_code
     */
    public function scan(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        $validator = Validator::make($request->all(), [
            'keyword' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $keyword = trim((string) $request->input('keyword'));
        $normalizedKeyword = function_exists('mb_convert_kana')
            ? mb_convert_kana($keyword, 'as')
            : $keyword;

        $items = WmsInventoryCountItem::where('inventory_count_id', $count->id)
            ->where(function ($query) use ($normalizedKeyword) {
                $query->where('item_code', $normalizedKeyword)
                    ->orWhere('barcode', $normalizedKeyword)
                    ->orWhereRaw('LPAD(barcode, 13, "0") = ?', [$normalizedKeyword]);
            })
            ->orderBy('floor_name')
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->limit(50)
            ->get();

        return $this->success([
            'items' => $items->map(fn (WmsInventoryCountItem $item) => [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'barcode' => $item->barcode,
                'floor_name' => $item->floor_name,
                'location_no' => $item->location_no,
                'location_code1' => $item->location_code1,
                'location_code2' => $item->location_code2,
                'location_code3' => $item->location_code3,
                'system_quantity' => (float) $item->system_quantity,
                'first_count_quantity' => $item->first_count_quantity !== null ? (float) $item->first_count_quantity : null,
                'second_count_quantity' => $item->second_count_quantity !== null ? (float) $item->second_count_quantity : null,
                'input_count' => (int) ($item->input_count ?? 0),
                'last_counted_at' => $item->last_counted_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * POST /api/wms/inventory-count-items/{itemId}/count
     *
     * Register actual count for an inventory count item
     */
    public function count(Request $request, int $itemId): JsonResponse
    {
        $countItem = WmsInventoryCountItem::find($itemId);

        if (! $countItem) {
            return $this->notFound('棚卸明細が見つかりません');
        }

        // Verify parent inventory count is in counting status
        $inventoryCount = WmsInventoryCount::find($countItem->inventory_count_id);
        if (! $inventoryCount || $inventoryCount->status !== WmsInventoryCount::STATUS_COUNTING) {
            return $this->error('この棚卸はカウント中ではありません', 422, 'INVALID_STATUS');
        }

        $validator = Validator::make($request->all(), [
            'quantity' => ['required', 'numeric', 'min:0'],
            'count_round' => ['required', 'integer', 'in:1,2'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'request_uuid' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $picker = $request->user();
        $userId = $picker ? $picker->id : null;

        $countItem = $this->inventoryCountService->registerCount(
            countItem: $countItem,
            quantity: (float) $request->input('quantity'),
            round: (int) $request->input('count_round'),
            deviceId: $request->input('device_id'),
            userId: $userId,
            requestUuid: $request->input('request_uuid'),
        );

        return $this->success([
            'item' => [
                'id' => $countItem->id,
                'item_code' => $countItem->item_code,
                'item_name' => $countItem->item_name,
                'system_quantity' => (float) $countItem->system_quantity,
                'first_count_quantity' => $countItem->first_count_quantity !== null ? (float) $countItem->first_count_quantity : null,
                'second_count_quantity' => $countItem->second_count_quantity !== null ? (float) $countItem->second_count_quantity : null,
                'input_count' => (int) ($countItem->input_count ?? 0),
                'last_counted_at' => $countItem->last_counted_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/wms/inventory-count-items/{itemId}/logs
     *
     * Get input history for an inventory count item
     */
    public function logs(Request $request, int $itemId): JsonResponse
    {
        $countItem = WmsInventoryCountItem::find($itemId);

        if (! $countItem) {
            return $this->notFound('棚卸明細が見つかりません');
        }

        $logs = WmsInventoryCountItemLog::where('inventory_count_item_id', $countItem->id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $this->success([
            'logs' => $logs->map(fn (WmsInventoryCountItemLog $log) => [
                'id' => $log->id,
                'device_id' => $log->device_id,
                'user_id' => $log->user_id,
                'count_round' => $log->count_round,
                'old_quantity' => $log->old_quantity !== null ? (float) $log->old_quantity : null,
                'new_quantity' => (float) $log->new_quantity,
                'request_uuid' => $log->request_uuid,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
