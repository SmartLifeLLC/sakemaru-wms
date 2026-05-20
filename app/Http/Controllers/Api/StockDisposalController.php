<?php

namespace App\Http\Controllers\Api;

use App\Models\WmsPicker;
use App\Models\WmsStockDisposalApiLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StockDisposalController extends ApiController
{
    private const DEFAULT_STOCK_ALLOCATION_CODE = '1';

    private const REASONS = [
        'EXPIRED',
        'DAMAGED',
        'STORE_PROMOTION_GIFT',
        'STORE_PROMOTION_TASTING',
        'CUSTOMER_PROMOTION_COOP',
        'ENTERTAINMENT_CONDOLENCE',
        'LOST',
        'OTHER',
    ];

    private const QUANTITY_TYPES = [
        'CASE',
        'PIECE',
        'CARTON',
    ];

    /**
     * GET /api/stock-disposals/items/search
     *
     * @OA\Get(
     *     path="/api/stock-disposals/items/search",
     *     tags={"Stock Adjustment"},
     *     summary="在庫調節用の商品検索",
     *     description="商品CD、商品名、検索コード、商品数量コード、自社コードで商品を検索し、選択倉庫の実在庫・理論在庫を返します。該当倉庫に在庫行がない商品は在庫0として返します。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(name="keyword", in="query", required=true, @OA\Schema(type="string", example="118207")),
     *     @OA\Parameter(name="warehouse_code", in="query", required=true, @OA\Schema(type="string", example="91")),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer", default=30, maximum=100)),
     *
     *     @OA\Response(response=200, description="成功"),
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function searchItems(Request $request): JsonResponse
    {
        $clientId = (int) config('app.client_id');
        $warehouseCodes = $this->warehouseCodes($clientId);

        $validator = Validator::make($request->all(), [
            'keyword' => ['required', 'string', 'max:255'],
            'warehouse_code' => ['required', 'string', Rule::in($warehouseCodes)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $keyword = trim((string) $request->input('keyword'));
        $normalizedKeyword = function_exists('mb_convert_kana')
            ? mb_convert_kana($keyword, 'as')
            : $keyword;
        $like = "%{$normalizedKeyword}%";
        $limit = (int) $request->input('limit', 30);

        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', $clientId)
            ->where('code', $request->input('warehouse_code'))
            ->first(['id', 'code', 'name', 'kana_name']);

        $items = $this->searchItemsForStockDisposal($clientId, $normalizedKeyword, $like, $limit);
        if ($items->isEmpty()) {
            return $this->success(['items' => []]);
        }

        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $searchCodes = $this->searchCodesByItem($clientId, $itemIds);
        $quantityCodes = $this->quantityCodesByItem($itemIds);
        $stocks = $this->stockSummariesByItem((int) $warehouse->id, $itemIds);

        return $this->success([
            'items' => $items->map(fn ($item) => [
                'item_id' => (int) $item->id,
                'item_code' => (string) $item->code,
                'item_name' => $item->name,
                'display_name' => $this->cleanText($item->name),
                'packaging' => $item->packaging,
                'display_packaging' => $this->cleanText($item->packaging),
                'name_with_packaging' => $this->nameWithPackaging($item->name, $item->packaging),
                'order_jan_code' => $this->orderJanCode($item, $quantityCodes),
                'capacity_case' => $item->capacity_case !== null ? (int) $item->capacity_case : null,
                'capacity_carton' => $item->capacity_carton !== null ? (int) $item->capacity_carton : null,
                'matched_field' => $this->matchedField($item, $normalizedKeyword, $searchCodes, $quantityCodes),
                'matched_value' => $this->matchedValue($item, $normalizedKeyword, $searchCodes, $quantityCodes),
                'stock' => $stocks->get((int) $item->id, $this->emptyStockSummary()),
            ])->values()->all(),
        ]);
    }

    /**
     * POST /api/stock-disposals
     *
     * @OA\Post(
     *     path="/api/stock-disposals",
     *     tags={"Stock Adjustment"},
     *     summary="在庫調節キュー登録",
     *     description="Android/WMSからの在庫調節依頼を酒丸DBの stock_disposal_queue に登録します。実伝票登録は酒丸本体のqueue workerが処理します。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Response(response=200, description="成功"),
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $clientId = (int) config('app.client_id');
        $warehouseCodes = $this->warehouseCodes($clientId);
        $itemCodes = $this->itemCodes($clientId);
        $stockAllocationCodes = $this->stockAllocationCodes($clientId);

        $validator = Validator::make($request->all(), [
            'stock_disposals' => ['required', 'array', 'min:1'],
            'stock_disposals.*.request_id' => ['required', 'string', 'max:255'],
            'stock_disposals.*.process_date' => ['required', 'date_format:Y-m-d'],
            'stock_disposals.*.disposal_date' => ['required', 'date_format:Y-m-d'],
            'stock_disposals.*.warehouse_code' => ['required', 'string', Rule::in($warehouseCodes)],
            'stock_disposals.*.reason' => ['required', Rule::in(self::REASONS)],
            'stock_disposals.*.slip_number' => ['nullable', 'string', 'max:255'],
            'stock_disposals.*.note' => ['nullable', 'string'],
            'stock_disposals.*.details' => ['required', 'array', 'min:1'],
            'stock_disposals.*.details.*.item_code' => ['required', 'string', Rule::in($itemCodes)],
            'stock_disposals.*.details.*.stock_allocation_code' => ['nullable', 'string', Rule::in($stockAllocationCodes)],
            'stock_disposals.*.details.*.quantity' => ['required', 'integer', 'not_in:0'],
            'stock_disposals.*.details.*.quantity_type' => ['required', Rule::in(self::QUANTITY_TYPES)],
            'stock_disposals.*.details.*.note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $picker = $request->user();
        if (!$picker instanceof WmsPicker) {
            return $this->unauthorized('Picker authentication is required');
        }

        $results = [];
        foreach ($request->input('stock_disposals', []) as $data) {
            $apiLog = $this->createApiLog($request, $picker, $data);

            try {
                $result = $this->createQueueRecord($clientId, $data);
                $apiLog->update([
                    'queue_id' => $result['queue_id'],
                    'result_status' => $result['duplicated'] ? 'DUPLICATED' : 'QUEUED',
                ]);
                $results[] = $result;
            } catch (\Throwable $exception) {
                $apiLog->update([
                    'result_status' => 'FAILED',
                    'error_message' => $exception->getMessage(),
                ]);
                throw $exception;
            }
        }

        return $this->success([
            'stock_disposal_queues' => $results,
        ]);
    }

    private function createQueueRecord(int $clientId, array $data): array
    {
        $connection = DB::connection('sakemaru');
        $existing = $connection->table('stock_disposal_queue')
            ->where('request_id', $data['request_id'])
            ->first(['id', 'request_id', 'status']);

        if ($existing) {
            return [
                'request_id' => $existing->request_id,
                'queue_id' => (int) $existing->id,
                'status' => $existing->status,
                'duplicated' => true,
            ];
        }

        $details = collect($data['details'])
            ->map(fn (array $detail) => array_merge($detail, [
                'stock_allocation_code' => $detail['stock_allocation_code'] ?? self::DEFAULT_STOCK_ALLOCATION_CODE,
            ]))
            ->values()
            ->all();

        try {
            $queueId = $connection->table('stock_disposal_queue')->insertGetId([
                'client_id' => $clientId,
                'slip_number' => $data['slip_number'] ?? null,
                'process_date' => $data['process_date'],
                'disposal_date' => $data['disposal_date'],
                'note' => $data['note'] ?? null,
                'items' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'warehouse_code' => $data['warehouse_code'],
                'reason' => $data['reason'],
                'request_id' => $data['request_id'],
                'status' => 'BEFORE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $queueId = (int) $connection->table('stock_disposal_queue')
                ->where('request_id', $data['request_id'])
                ->value('id');
        }

        return [
            'request_id' => $data['request_id'],
            'queue_id' => (int) $queueId,
            'status' => 'BEFORE',
            'duplicated' => false,
        ];
    }

    private function createApiLog(Request $request, WmsPicker $picker, array $data): WmsStockDisposalApiLog
    {
        return WmsStockDisposalApiLog::create([
            'picker_id' => $picker->id,
            'picker_code' => $picker->code,
            'picker_name' => $picker->name,
            'request_id' => $data['request_id'] ?? null,
            'warehouse_code' => $data['warehouse_code'],
            'reason' => $data['reason'],
            'process_date' => $data['process_date'] ?? null,
            'disposal_date' => $data['disposal_date'] ?? null,
            'slip_number' => $data['slip_number'] ?? null,
            'detail_count' => count($data['details'] ?? []),
            'request_payload' => $data,
            'result_status' => 'REQUESTED',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function searchItemsForStockDisposal(int $clientId, string $keyword, string $like, int $limit): Collection
    {
        return DB::connection('sakemaru')
            ->table('items as i')
            ->leftJoin('item_search_information as isi', function ($join) use ($clientId) {
                $join->on('isi.item_id', '=', 'i.id')
                    ->where('isi.client_id', '=', $clientId);
            })
            ->leftJoin('item_quantity_information as iqi', 'iqi.item_id', '=', 'i.id')
            ->where('i.client_id', $clientId)
            ->where('i.is_active', true)
            ->where('i.is_managed_stock', true)
            ->where(function ($query) use ($keyword, $like) {
                $query->where('i.code', 'like', $like)
                    ->orWhere('i.name', 'like', $like)
                    ->orWhere('isi.search_string', 'like', $like)
                    ->orWhere('iqi.product_code', 'like', $like)
                    ->orWhere('iqi.own_code', 'like', $like)
                    ->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$keyword])
                    ->orWhereRaw('LPAD(iqi.product_code, 13, "0") = ?', [$keyword])
                    ->orWhereRaw('LPAD(iqi.own_code, 13, "0") = ?', [$keyword]);
            })
            ->select([
                'i.id',
                'i.code',
                'i.name',
                'i.packaging',
                'i.capacity_case',
                'i.capacity_carton',
            ])
            ->selectRaw(
                'MIN(CASE
                    WHEN i.code = ? THEN 0
                    WHEN iqi.own_code = ? THEN 1
                    WHEN iqi.product_code = ? THEN 2
                    WHEN isi.search_string = ? THEN 3
                    WHEN iqi.own_code LIKE ? THEN 4
                    WHEN iqi.product_code LIKE ? THEN 5
                    WHEN isi.search_string LIKE ? THEN 6
                    WHEN i.name LIKE ? THEN 7
                    ELSE 8
                END) as match_rank',
                [$keyword, $keyword, $keyword, $keyword, $like, $like, $like, $like]
            )
            ->groupBy('i.id', 'i.code', 'i.name', 'i.packaging', 'i.capacity_case', 'i.capacity_carton')
            ->orderBy('match_rank')
            ->orderBy('i.code')
            ->limit($limit)
            ->get();
    }

    private function searchCodesByItem(int $clientId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('client_id', $clientId)
            ->whereIn('item_id', $itemIds)
            ->whereNotNull('search_string')
            ->orderBy('priority')
            ->get(['item_id', 'search_string'])
            ->groupBy(fn ($row) => (int) $row->item_id);
    }

    private function quantityCodesByItem(array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_quantity_information')
            ->whereIn('item_id', $itemIds)
            ->where(function ($query) {
                $query->whereNotNull('product_code')
                    ->orWhereNotNull('own_code');
            })
            ->orderBy('quantity')
            ->orderBy('id')
            ->get(['item_id', 'product_code', 'own_code'])
            ->groupBy(fn ($row) => (int) $row->item_id);
    }

    private function stockSummariesByItem(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->where('rs.warehouse_id', $warehouseId)
            ->whereIn('rs.item_id', $itemIds)
            ->groupBy('rs.item_id')
            ->get([
                'rs.item_id',
                DB::raw('COALESCE(SUM(rs.current_quantity), 0) as actual_quantity'),
                DB::raw('COALESCE(SUM(rs.available_quantity), 0) as theoretical_quantity'),
            ])
            ->keyBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($row) => [
                'actual_quantity' => (int) $row->actual_quantity,
                'theoretical_quantity' => (int) $row->theoretical_quantity,
            ]);
    }

    private function emptyStockSummary(): array
    {
        return [
            'actual_quantity' => 0,
            'theoretical_quantity' => 0,
        ];
    }

    private function nameWithPackaging(?string $name, ?string $packaging): ?string
    {
        return collect([$this->cleanText($name), $this->cleanText($packaging)])
            ->filter()
            ->implode(' / ') ?: null;
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim((string) preg_replace('/[\s　]+/u', ' ', $value));

        return $cleaned !== '' ? $cleaned : null;
    }

    private function matchedField(object $item, string $keyword, Collection $searchCodes, Collection $quantityCodes): string
    {
        if ((string) $item->code === $keyword || Str::contains((string) $item->code, $keyword)) {
            return 'items.code';
        }

        $itemQuantityCodes = $quantityCodes->get((int) $item->id, collect());
        if ($itemQuantityCodes->first(fn ($row) => Str::contains((string) $row->product_code, $keyword))) {
            return 'item_quantity_information.product_code';
        }

        if ($itemQuantityCodes->first(fn ($row) => Str::contains((string) $row->own_code, $keyword))) {
            return 'item_quantity_information.own_code';
        }

        $itemSearchCodes = $searchCodes->get((int) $item->id, collect());
        if ($itemSearchCodes->first(fn ($row) => Str::contains((string) $row->search_string, $keyword))) {
            return 'item_search_information.search_string';
        }

        return 'items.name';
    }

    private function orderJanCode(object $item, Collection $quantityCodes): ?string
    {
        return $quantityCodes
            ->get((int) $item->id, collect())
            ->first(fn ($row) => filled($row->product_code))
            ?->product_code;
    }

    private function matchedValue(object $item, string $keyword, Collection $searchCodes, Collection $quantityCodes): ?string
    {
        return match ($this->matchedField($item, $keyword, $searchCodes, $quantityCodes)) {
            'items.code' => (string) $item->code,
            'item_quantity_information.product_code' => (string) $quantityCodes
                ->get((int) $item->id, collect())
                ->first(fn ($row) => Str::contains((string) $row->product_code, $keyword))
                ?->product_code,
            'item_quantity_information.own_code' => (string) $quantityCodes
                ->get((int) $item->id, collect())
                ->first(fn ($row) => Str::contains((string) $row->own_code, $keyword))
                ?->own_code,
            'item_search_information.search_string' => (string) $searchCodes
                ->get((int) $item->id, collect())
                ->first(fn ($row) => Str::contains((string) $row->search_string, $keyword))
                ?->search_string,
            default => $item->name,
        };
    }

    private function warehouseCodes(int $clientId): array
    {
        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->all();
    }

    private function itemCodes(int $clientId): array
    {
        return DB::connection('sakemaru')
            ->table('items')
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->where('is_managed_stock', true)
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->all();
    }

    private function stockAllocationCodes(int $clientId): array
    {
        return $this->stockAllocations($clientId)
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->all();
    }

    private function stockAllocations(int $clientId): Collection
    {
        return DB::connection('sakemaru')
            ->table('stock_allocations')
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
