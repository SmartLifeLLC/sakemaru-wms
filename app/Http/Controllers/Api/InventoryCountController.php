<?php

namespace App\Http\Controllers\Api;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Services\InventoryCount\InventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                'current_round' => $this->currentRound($count),
                'total_items' => $count->items()->count(),
                'counted_items' => $count->items()->whereNotNull('first_count_quantity')->count(),
                'final_counted_items' => $count->items()->whereNotNull('final_count_quantity')->count(),
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

        $perPage = min((int) $request->input('per_page', 500), 500);
        $paginator = $query->paginate($perPage, ['*'], 'page', $request->input('page', 1));

        return $this->success([
            'items' => collect($paginator->items())
                ->map(fn (WmsInventoryCountItem $item) => $this->itemPayload($item, $request->boolean('compact')))
                ->values()
                ->all(),
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
                $like = "%{$normalizedKeyword}%";
                $query->where('id', $normalizedKeyword)
                    ->orWhere('item_code', $normalizedKeyword)
                    ->orWhere('item_code', 'like', $like)
                    ->orWhere('item_name', 'like', $like)
                    ->orWhere('barcode', $normalizedKeyword)
                    ->orWhereRaw('LPAD(barcode, 13, "0") = ?', [$normalizedKeyword])
                    ->orWhereExists(function ($sub) use ($normalizedKeyword, $like) {
                        $sub->selectRaw('1')
                            ->from('item_quantity_information as iqi')
                            ->whereColumn('iqi.item_id', 'wms_inventory_count_items.item_id')
                            ->where(function ($q) use ($normalizedKeyword, $like) {
                                $q->where('iqi.product_code', $normalizedKeyword)
                                    ->orWhere('iqi.own_code', $normalizedKeyword)
                                    ->orWhere('iqi.product_code', 'like', $like)
                                    ->orWhere('iqi.own_code', 'like', $like)
                                    ->orWhereRaw('LPAD(iqi.product_code, 13, "0") = ?', [$normalizedKeyword])
                                    ->orWhereRaw('LPAD(iqi.own_code, 13, "0") = ?', [$normalizedKeyword]);
                            });
                    });
            })
            ->orderBy('floor_name')
            ->orderBy('location_code1')
            ->orderBy('location_code2')
            ->orderBy('location_code3')
            ->limit(50)
            ->get();

        return $this->success([
            'items' => $items->map(fn (WmsInventoryCountItem $item) => $this->itemPayload($item))->values()->all(),
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
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'case_quantity' => ['nullable', 'integer', 'min:0'],
            'piece_quantity' => ['nullable', 'integer', 'min:0'],
            'count_round' => ['required', 'integer', 'in:1,2,3'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'request_uuid' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $picker = $request->user();
        $userId = $picker ? $picker->id : null;

        $quantity = $request->filled('quantity')
            ? (float) $request->input('quantity')
            : $this->calculateTotalPieces($countItem, (int) $request->input('case_quantity', 0), (int) $request->input('piece_quantity', 0));

        $countItem = $this->inventoryCountService->registerCount(
            countItem: $countItem,
            quantity: $quantity,
            round: (int) $request->input('count_round'),
            deviceId: $request->input('device_id'),
            userId: $userId,
            requestUuid: $request->input('request_uuid'),
        );

        return $this->success([
            'item' => $this->itemPayload($countItem->refresh()),
        ]);
    }

    public function bulkCount(Request $request, int $id): JsonResponse
    {
        $count = WmsInventoryCount::find($id);

        if (! $count) {
            return $this->notFound('棚卸データが見つかりません');
        }

        if ($count->status !== WmsInventoryCount::STATUS_COUNTING) {
            return $this->error('この棚卸はカウント中ではありません', 422, 'INVALID_STATUS');
        }

        $validator = Validator::make($request->all(), [
            'count_round' => ['required', 'integer', 'in:1,2,3'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.case_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.piece_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.request_uuid' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $round = (int) $request->input('count_round');
        $picker = $request->user();
        $userId = $picker ? $picker->id : null;
        $updated = [];
        $missingItemIds = [];
        $rows = $request->input('items', []);

        foreach ($rows as $row) {
            $countItem = WmsInventoryCountItem::where('inventory_count_id', $count->id)
                ->where('id', (int) $row['item_id'])
                ->first();

            if (! $countItem) {
                $missingItemIds[] = (int) $row['item_id'];

                continue;
            }

            $quantity = array_key_exists('quantity', $row) && $row['quantity'] !== null
                ? (float) $row['quantity']
                : $this->calculateTotalPieces($countItem, (int) ($row['case_quantity'] ?? 0), (int) ($row['piece_quantity'] ?? 0));

            $updatedItem = $this->inventoryCountService->registerCount(
                countItem: $countItem,
                quantity: $quantity,
                round: $round,
                deviceId: $request->input('device_id'),
                userId: $userId,
                requestUuid: (string) $row['request_uuid'],
            );

            $updated[] = $this->itemPayload($updatedItem->refresh(), true);
        }

        Log::info('Inventory bulk count received', [
            'inventory_count_id' => $count->id,
            'round' => $round,
            'requested_count' => count($rows),
            'updated_count' => count($updated),
            'missing_item_ids' => $missingItemIds,
            'picker_id' => $userId,
            'device_id' => $request->input('device_id'),
        ]);

        return $this->success([
            'updated_count' => count($updated),
            'missing_item_ids' => $missingItemIds,
            'items' => $updated,
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

    private function itemPayload(WmsInventoryCountItem $item, bool $compact = false): array
    {
        $master = $this->itemMaster($item->item_id);
        $capacityCase = max((int) ($master?->capacity_case ?? 1), 1);
        $systemQuantity = (int) $item->system_quantity;
        $currentCount = $item->final_count_quantity ?? $item->second_count_quantity ?? $item->first_count_quantity;
        $janCodes = $this->janCodes($item->item_id);
        $ownCodes = $this->ownCodes($item->item_id);

        $payload = [
            'id' => $item->id,
            'paper_barcode' => "ICITEM-{$item->id}",
            'search_text' => trim(implode(' ', array_filter([
                "ICITEM-{$item->id}",
                $item->item_code,
                $item->item_name,
                $item->barcode,
                $item->location_no,
                ...$janCodes,
                ...$ownCodes,
            ]))),
            'item_id' => $item->item_id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'barcode' => $item->barcode,
            'volume' => $master?->volume,
            'volume_unit' => $master?->volume_unit,
            'capacity_case' => $capacityCase,
            'capacity_carton' => $master?->capacity_carton !== null ? (int) $master->capacity_carton : null,
            'location' => [
                'id' => $item->location_id,
                'floor_name' => $item->floor_name,
                'location_no' => $item->location_no,
                'code1' => $item->location_code1,
                'code2' => $item->location_code2,
                'code3' => $item->location_code3,
            ],
            'system_quantity' => $systemQuantity,
            'system_case_quantity' => intdiv($systemQuantity, $capacityCase),
            'system_piece_quantity' => $systemQuantity % $capacityCase,
            'system_total_piece_quantity' => $systemQuantity,
            'first_count_quantity' => $item->first_count_quantity !== null ? (float) $item->first_count_quantity : null,
            'second_count_quantity' => $item->second_count_quantity !== null ? (float) $item->second_count_quantity : null,
            'final_count_quantity' => $item->final_count_quantity !== null ? (float) $item->final_count_quantity : null,
            'current_count_quantity' => $currentCount !== null ? (float) $currentCount : null,
            'difference_quantity' => $currentCount !== null ? (float) $currentCount - (float) $item->system_quantity : null,
            'input_count' => (int) ($item->input_count ?? 0),
            'last_counted_at' => $item->last_counted_at?->toIso8601String(),
        ];

        if (! $compact) {
            $payload['jan_codes'] = $janCodes;
            $payload['own_codes'] = $ownCodes;
        }

        return $payload;
    }

    private function calculateTotalPieces(WmsInventoryCountItem $item, int $caseQuantity, int $pieceQuantity): float
    {
        $capacityCase = max((int) ($this->itemMaster($item->item_id)?->capacity_case ?? 1), 1);

        return ($caseQuantity * $capacityCase) + $pieceQuantity;
    }

    private function itemMaster(int $itemId): ?object
    {
        static $cache = [];

        if (! array_key_exists($itemId, $cache)) {
            $cache[$itemId] = DB::connection('sakemaru')
                ->table('items')
                ->where('id', $itemId)
                ->first(['id', 'volume', 'volume_unit', 'capacity_case', 'capacity_carton']);
        }

        return $cache[$itemId];
    }

    private function janCodes(int $itemId): array
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_active', 1)
            ->pluck('search_string')
            ->filter()
            ->values()
            ->all();
    }

    private function ownCodes(int $itemId): array
    {
        return DB::connection('sakemaru')
            ->table('item_quantity_information')
            ->where('item_id', $itemId)
            ->whereNotNull('own_code')
            ->pluck('own_code')
            ->filter()
            ->values()
            ->all();
    }

    private function currentRound(WmsInventoryCount $count): int
    {
        if ($count->items()->whereNotNull('final_count_quantity')->exists()) {
            return 3;
        }

        if ($count->items()->whereNotNull('second_count_quantity')->exists()) {
            return 2;
        }

        return 1;
    }
}
