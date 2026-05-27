<?php

namespace App\Http\Controllers\Api;

use App\Enums\EItemSearchCodeType;
use App\Models\Sakemaru\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MasterDataController extends ApiController
{
    /**
     * GET /api/master/warehouses
     *
     * 倉庫マスタ一覧取得
     *
     * @OA\Get(
     *     path="/api/master/warehouses",
     *     tags={"Master Data"},
     *     summary="Get warehouse master list",
     *     description="Retrieve all warehouses with id, code, name, kana_name, and out_of_stock_option",
     *     security={{"apiKey":{}, "sanctum":{}}},
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
     *                     property="data",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="object",
     *
     *                         @OA\Property(property="id", type="integer", example=991),
     *                         @OA\Property(property="code", type="string", example="991"),
     *                         @OA\Property(property="name", type="string", example="酒丸本社"),
     *                         @OA\Property(property="kana_name", type="string", example="サケマルホンシャ"),
     *                         @OA\Property(property="out_of_stock_option", type="string", example="enum ('IGNORE_STOCK', 'UP_TO_STOCK')")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="is_success", type="boolean", example=false),
     *             @OA\Property(property="code", type="string", example="UNAUTHORIZED"),
     *             @OA\Property(
     *                 property="result",
     *                 type="object",
     *                 @OA\Property(property="data", type="object", nullable=true, example=null),
     *                 @OA\Property(property="error_message", type="string", example="Unauthenticated")
     *             )
     *         )
     *     )
     * )
     */
    public function warehouses(): JsonResponse
    {
        $warehouses = DB::connection('sakemaru')
            ->table('warehouses')
            ->select([
                'id',
                'code',
                'name',
                'kana_name',
                'out_of_stock_option',
            ])
            ->orderBy('code', 'asc')
            ->get()
            ->toArray();

        return $this->success($warehouses);
    }

    /**
     * GET /api/master/item-locations
     *
     * Handy ロケ検索用の商品別ロケーション取得
     *
     * @OA\Get(
     *     path="/api/master/item-locations",
     *     tags={"Master Data"},
     *     summary="商品別ロケーション検索",
     *     description="商品CD、商品名、JAN/検索コード、社内JANから商品を検索し、指定倉庫内の商品基本情報、在庫状況、ロケーション情報を返します。頭0付きの検索でヒットしない場合、先頭の0を除去して再検索します。",
     *     security={{"apiKey":{}, "sanctum":{}}},
     *
     *     @OA\Parameter(name="warehouse_id", in="query", required=true, description="倉庫ID", @OA\Schema(type="integer", example=91)),
     *     @OA\Parameter(name="search", in="query", required=true, description="商品CD、商品名、JAN/検索コード", @OA\Schema(type="string", example="4901234567890")),
     *     @OA\Parameter(name="limit", in="query", required=false, description="商品の最大取得件数（デフォルト: 10、最大: 50）", @OA\Schema(type="integer", default=10)),
     *
     *     @OA\Response(
     *         response=200,
     *         description="成功",
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
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="object",
     *
     *                         @OA\Property(
     *                             property="item",
     *                             type="object",
     *                             description="商品基本情報。単価・税・原価・標準売価は返さない。",
     *                             @OA\Property(property="id", type="integer", example=123),
     *                             @OA\Property(property="code", type="string", example="10001"),
     *                             @OA\Property(property="name", type="string", example="商品A 720ml"),
     *                             @OA\Property(property="kana", type="string", nullable=true),
     *                             @OA\Property(property="volume", type="string", nullable=true, example="720"),
     *                             @OA\Property(property="volume_unit", type="string", nullable=true, example="ML"),
     *                             @OA\Property(property="capacity_case", type="integer", nullable=true, example=12),
     *                             @OA\Property(property="capacity_carton", type="integer", nullable=true),
     *                             @OA\Property(property="packaging", type="string", nullable=true, example="瓶"),
     *                             @OA\Property(property="temperature_type", type="string", nullable=true, example="NORMAL"),
     *                             @OA\Property(property="uses_expiration_date", type="boolean", example=true),
     *                             @OA\Property(property="images", type="array", @OA\Items(type="string")),
     *                             @OA\Property(
     *                                 property="search_codes",
     *                                 type="array",
     *                                 description="item_search_information.search_string",
     *
     *                                 @OA\Items(
     *                                     type="object",
     *
     *                                     @OA\Property(property="code", type="string", example="4901234567890"),
     *                                     @OA\Property(property="code_type", type="string", example="JAN"),
     *                                     @OA\Property(property="quantity_type", type="string", nullable=true, example="PIECE"),
     *                                     @OA\Property(property="priority", type="integer", nullable=true, example=1)
     *                                 )
     *                             ),
     *                             @OA\Property(property="jan_codes", type="array", @OA\Items(type="string")),
     *                             @OA\Property(
     *                                 property="item_quantity_codes",
     *                                 type="array",
     *                                 description="item_quantity_information.product_code / own_code",
     *
     *                                 @OA\Items(
     *                                     type="object",
     *
     *                                     @OA\Property(property="product_code", type="string", nullable=true, example="100010000"),
     *                                     @OA\Property(property="own_code", type="string", nullable=true, example="10001"),
     *                                     @OA\Property(property="quantity_code", type="string", nullable=true, example="00"),
     *                                     @OA\Property(property="quantity", type="integer", nullable=true, example=1),
     *                                     @OA\Property(property="can_order", type="boolean", example=true)
     *                                 )
     *                             )
     *                         ),
     *                         @OA\Property(
     *                             property="warehouse",
     *                             type="object",
     *                             nullable=true,
     *                             @OA\Property(property="id", type="integer", example=91),
     *                             @OA\Property(property="code", type="string", example="91"),
     *                             @OA\Property(property="name", type="string", example="華むすびの蔵センター"),
     *                             @OA\Property(property="kana_name", type="string", nullable=true)
     *                         ),
     *                         @OA\Property(
     *                             property="stock",
     *                             type="object",
     *                             description="指定倉庫のみの在庫状況",
     *                             @OA\Property(property="status", type="string", enum={"IN_STOCK", "RESERVED_ONLY", "NO_STOCK"}, example="IN_STOCK"),
     *                             @OA\Property(property="has_stock", type="boolean", example=true),
     *                             @OA\Property(property="lot_count", type="integer", example=2),
     *                             @OA\Property(property="location_count", type="integer", example=1),
     *                             @OA\Property(property="current_quantity", type="integer", example=24),
     *                             @OA\Property(property="reserved_quantity", type="integer", example=4),
     *                             @OA\Property(property="available_quantity", type="integer", example=20),
     *                             @OA\Property(property="earliest_expiration_date", type="string", format="date", nullable=true),
     *                             @OA\Property(property="latest_expiration_date", type="string", format="date", nullable=true)
     *                         ),
     *                         @OA\Property(
     *                             property="locations",
     *                             type="object",
     *                             @OA\Property(property="suggested", type="object", nullable=true),
     *                             @OA\Property(property="default", type="object", nullable=true),
     *                             @OA\Property(
     *                                 property="stock",
     *                                 type="array",
     *
     *                                 @OA\Items(
     *                                     type="object",
     *
     *                                     @OA\Property(property="id", type="integer", example=789),
     *                                     @OA\Property(property="warehouse_id", type="integer", example=91),
     *                                     @OA\Property(property="floor_id", type="integer", nullable=true),
     *                                     @OA\Property(property="code1", type="string", nullable=true, example="A"),
     *                                     @OA\Property(property="code2", type="string", nullable=true, example="1"),
     *                                     @OA\Property(property="code3", type="string", nullable=true, example="01"),
     *                                     @OA\Property(property="code", type="string", example="A-1-01"),
     *                                     @OA\Property(property="display_name", type="string", example="A-1-01 常温棚A"),
     *                                     @OA\Property(property="name", type="string", nullable=true),
     *                                     @OA\Property(property="source", type="string", example="stock_lot"),
     *                                     @OA\Property(property="is_no_location", type="boolean", example=false),
     *                                     @OA\Property(property="temperature_type", type="string", nullable=true),
     *                                     @OA\Property(property="is_restricted_area", type="boolean", example=false),
     *                                     @OA\Property(property="available_quantity_flags", type="integer", nullable=true, example=3),
     *                                     @OA\Property(property="lot_count", type="integer", example=2),
     *                                     @OA\Property(property="current_quantity", type="integer", example=24),
     *                                     @OA\Property(property="reserved_quantity", type="integer", example=4),
     *                                     @OA\Property(property="available_quantity", type="integer", example=20),
     *                                     @OA\Property(property="earliest_expiration_date", type="string", format="date", nullable=true),
     *                                     @OA\Property(property="latest_expiration_date", type="string", format="date", nullable=true)
     *                                 )
     *                             )
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="バリデーションエラー")
     * )
     */
    public function itemLocations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'warehouse_id' => 'required|integer',
            'search' => 'required|string|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $warehouseId = (int) $request->input('warehouse_id');
        $search = trim((string) $request->input('search'));
        $limit = (int) $request->input('limit', 10);

        $items = $this->searchItemsForLocation($search, $limit);

        if ($items->isEmpty() && preg_match('/^0+/', $search)) {
            $trimmedSearch = ltrim($search, '0') ?: '0';
            if ($trimmedSearch !== $search) {
                $items = $this->searchItemsForLocation($trimmedSearch, $limit);
            }
        }

        if ($items->isEmpty()) {
            return $this->success([]);
        }

        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $warehouse = $this->getWarehouse($warehouseId);
        $searchCodes = $this->getSearchCodesByItem($itemIds);
        $itemQuantityCodes = $this->getItemQuantityCodesByItem($itemIds);
        $stockSummaries = $this->getStockSummariesByItem($warehouseId, $itemIds);
        $defaultLocations = $this->getDefaultLocationsByItem($warehouseId, $itemIds);
        $stockLocations = $this->getStockLocationsByItem($warehouseId, $itemIds);
        $warehouseDefaultLocation = $this->getWarehouseDefaultLocation($warehouseId);

        $data = $items->map(function ($item) use ($warehouse, $searchCodes, $itemQuantityCodes, $stockSummaries, $defaultLocations, $stockLocations, $warehouseDefaultLocation) {
            $itemId = (int) $item->id;
            $defaultLocation = $defaultLocations->get($itemId);
            $itemStockLocations = $stockLocations->get($itemId, collect())->values();
            $stockLocation = $itemStockLocations->first();
            $stockSummary = $stockSummaries->get($itemId) ?? $this->emptyStockSummary();
            $suggestedLocation = $stockLocation
                ?? $defaultLocation
                ?? $warehouseDefaultLocation;

            return [
                'item' => [
                    'id' => $itemId,
                    'code' => $item->code,
                    'name' => $item->name,
                    'kana' => $item->kana,
                    'volume' => $item->volume,
                    'volume_unit' => $item->volume_unit,
                    'capacity_case' => $item->capacity_case !== null ? (int) $item->capacity_case : null,
                    'capacity_carton' => $item->capacity_carton !== null ? (int) $item->capacity_carton : null,
                    'packaging' => $item->packaging,
                    'temperature_type' => $item->temperature_type,
                    'uses_expiration_date' => (bool) $item->uses_expiration_date,
                    'images' => $this->getImages($item),
                    'search_codes' => $searchCodes->get($itemId, []),
                    'jan_codes' => collect($searchCodes->get($itemId, []))
                        ->where('code_type', EItemSearchCodeType::JAN->value)
                        ->pluck('code')
                        ->values()
                        ->all(),
                    'item_quantity_codes' => $itemQuantityCodes->get($itemId, []),
                ],
                'warehouse' => $warehouse,
                'stock' => $stockSummary,
                'locations' => [
                    'suggested' => $suggestedLocation ? $this->formatLocation($suggestedLocation, $suggestedLocation->source ?? 'suggested') : null,
                    'default' => $stockLocation
                        ? $this->formatLocation($stockLocation, 'stock_lot')
                        : ($defaultLocation ? $this->formatLocation($defaultLocation, 'item_default') : null),
                    'stock' => $itemStockLocations
                        ->map(fn ($location) => $this->formatLocation($location, 'stock_lot', [
                            'lot_count' => (int) $location->lot_count,
                            'current_quantity' => (int) $location->current_quantity,
                            'reserved_quantity' => (int) $location->reserved_quantity,
                            'available_quantity' => (int) $location->available_quantity,
                            'earliest_expiration_date' => $location->earliest_expiration_date,
                            'latest_expiration_date' => $location->latest_expiration_date,
                        ]))
                        ->values()
                        ->all(),
                ],
            ];
        })->values()->all();

        return $this->success($data);
    }

    private function searchItemsForLocation(string $search, int $limit): Collection
    {
        $normalizedSearch = function_exists('mb_convert_kana')
            ? mb_convert_kana($search, 'as')
            : $search;

        $like = "%{$normalizedSearch}%";

        return DB::connection('sakemaru')
            ->table('items as i')
            ->leftJoin('item_search_information as isi', 'isi.item_id', '=', 'i.id')
            ->leftJoin('item_quantity_information as iqi', 'iqi.item_id', '=', 'i.id')
            ->where('i.is_active', true)
            ->where(function ($query) use ($normalizedSearch, $like) {
                $query->where('i.code', 'like', $like)
                    ->orWhere('i.name', 'like', $like)
                    ->orWhere('isi.search_string', 'like', $like)
                    ->orWhere('iqi.product_code', 'like', $like)
                    ->orWhere('iqi.own_code', 'like', $like)
                    ->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$normalizedSearch])
                    ->orWhereRaw('LPAD(iqi.product_code, 13, "0") = ?', [$normalizedSearch])
                    ->orWhereRaw('LPAD(iqi.own_code, 13, "0") = ?', [$normalizedSearch]);
            })
            ->select([
                'i.id',
                'i.code',
                'i.name',
                'i.kana',
                'i.volume',
                'i.volume_unit',
                'i.capacity_case',
                'i.capacity_carton',
                'i.packaging',
                'i.temperature_type',
                'i.uses_expiration_date',
                'i.image_url_1',
                'i.image_url_2',
                'i.image_url_3',
            ])
            ->selectRaw(
                'MIN(CASE
                    WHEN i.code = ? THEN 0
                    WHEN isi.search_string = ? THEN 0
                    WHEN LPAD(isi.search_string, 13, "0") = ? THEN 0
                    WHEN iqi.product_code = ? THEN 0
                    WHEN LPAD(iqi.product_code, 13, "0") = ? THEN 0
                    WHEN iqi.own_code = ? THEN 0
                    WHEN LPAD(iqi.own_code, 13, "0") = ? THEN 0
                    WHEN i.code LIKE ? THEN 1
                    WHEN isi.search_string LIKE ? THEN 1
                    WHEN iqi.product_code LIKE ? THEN 1
                    WHEN iqi.own_code LIKE ? THEN 1
                    ELSE 2
                END) as match_rank',
                [
                    $normalizedSearch,
                    $normalizedSearch,
                    $normalizedSearch,
                    $normalizedSearch,
                    $normalizedSearch,
                    $normalizedSearch,
                    $normalizedSearch,
                    $like,
                    $like,
                    $like,
                    $like,
                ]
            )
            ->groupBy(
                'i.id',
                'i.code',
                'i.name',
                'i.kana',
                'i.volume',
                'i.volume_unit',
                'i.capacity_case',
                'i.capacity_carton',
                'i.packaging',
                'i.temperature_type',
                'i.uses_expiration_date',
                'i.image_url_1',
                'i.image_url_2',
                'i.image_url_3'
            )
            ->orderBy('match_rank')
            ->orderBy('i.code')
            ->limit($limit)
            ->get();
    }

    private function getWarehouse(int $warehouseId): ?array
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->first(['id', 'code', 'name', 'kana_name']);

        return $warehouse ? [
            'id' => (int) $warehouse->id,
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'kana_name' => $warehouse->kana_name,
        ] : null;
    }

    private function getSearchCodesByItem(array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->whereIn('item_id', $itemIds)
            ->whereNotNull('search_string')
            ->orderByDesc('updated_at')
            ->get(['item_id', 'search_string', 'code_type', 'quantity_type', 'priority'])
            ->groupBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'code' => $row->search_string,
                'code_type' => $row->code_type,
                'quantity_type' => $row->quantity_type,
                'priority' => $row->priority !== null ? (int) $row->priority : null,
            ])->values()->all());
    }

    private function getItemQuantityCodesByItem(array $itemIds): Collection
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
            ->get(['item_id', 'product_code', 'own_code', 'quantity_code', 'quantity', 'can_order'])
            ->groupBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($rows) => $rows->map(fn ($row) => [
                'product_code' => $row->product_code,
                'own_code' => $row->own_code,
                'quantity_code' => $row->quantity_code,
                'quantity' => $row->quantity !== null ? (int) $row->quantity : null,
                'can_order' => (bool) $row->can_order,
            ])->values()->all());
    }

    private function getStockSummariesByItem(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->whereIn('rs.item_id', $itemIds)
            ->where('rsl.status', 'ACTIVE')
            ->groupBy('rs.item_id')
            ->get([
                'rs.item_id',
                DB::raw('COUNT(*) as lot_count'),
                DB::raw('COUNT(DISTINCT rsl.location_id) as location_count'),
                DB::raw('SUM(rsl.current_quantity) as current_quantity'),
                DB::raw('SUM(rsl.reserved_quantity) as reserved_quantity'),
                DB::raw('SUM(GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0)) as available_quantity'),
                DB::raw('MIN(rsl.expiration_date) as earliest_expiration_date'),
                DB::raw('MAX(rsl.expiration_date) as latest_expiration_date'),
            ])
            ->keyBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($row) => $this->formatStockSummary($row));
    }

    private function emptyStockSummary(): array
    {
        return [
            'status' => 'NO_STOCK',
            'has_stock' => false,
            'lot_count' => 0,
            'location_count' => 0,
            'current_quantity' => 0,
            'reserved_quantity' => 0,
            'available_quantity' => 0,
            'earliest_expiration_date' => null,
            'latest_expiration_date' => null,
        ];
    }

    private function formatStockSummary(object $row): array
    {
        $availableQuantity = (int) $row->available_quantity;
        $currentQuantity = (int) $row->current_quantity;

        return [
            'status' => $availableQuantity > 0
                ? 'IN_STOCK'
                : ($currentQuantity > 0 ? 'RESERVED_ONLY' : 'NO_STOCK'),
            'has_stock' => $currentQuantity > 0,
            'lot_count' => (int) $row->lot_count,
            'location_count' => (int) $row->location_count,
            'current_quantity' => $currentQuantity,
            'reserved_quantity' => (int) $row->reserved_quantity,
            'available_quantity' => $availableQuantity,
            'earliest_expiration_date' => $row->earliest_expiration_date,
            'latest_expiration_date' => $row->latest_expiration_date,
        ];
    }

    private function getImages(object $item): array
    {
        return collect([
            $item->image_url_1,
            $item->image_url_2,
            $item->image_url_3,
        ])->filter()->values()->all();
    }

    private function getDefaultLocationsByItem(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_incoming_default_locations as idl')
            ->join('locations as l', 'l.id', '=', 'idl.location_id')
            ->where('idl.warehouse_id', $warehouseId)
            ->whereIn('idl.item_id', $itemIds)
            ->get([
                'idl.item_id',
                'l.id',
                'l.warehouse_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'l.name',
                'l.temperature_type',
                'l.is_restricted_area',
                'l.available_quantity_flags',
            ])
            ->keyBy(fn ($row) => (int) $row->item_id)
            ->map(function ($row) {
                $row->source = 'item_default';

                return $row;
            });
    }

    private function getStockLocationsByItem(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->whereIn('rs.item_id', $itemIds)
            ->where('rsl.status', 'ACTIVE')
            ->where('rsl.current_quantity', '>', 0)
            ->groupBy([
                'rs.item_id',
                'l.id',
                'l.warehouse_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'l.name',
                'l.temperature_type',
                'l.is_restricted_area',
                'l.available_quantity_flags',
            ])
            ->orderBy('l.code1')
            ->orderBy('l.code2')
            ->orderBy('l.code3')
            ->get([
                'rs.item_id',
                'l.id',
                'l.warehouse_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'l.name',
                'l.temperature_type',
                'l.is_restricted_area',
                'l.available_quantity_flags',
                DB::raw('COUNT(*) as lot_count'),
                DB::raw('SUM(rsl.current_quantity) as current_quantity'),
                DB::raw('SUM(rsl.reserved_quantity) as reserved_quantity'),
                DB::raw('SUM(GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0)) as available_quantity'),
                DB::raw('MIN(rsl.expiration_date) as earliest_expiration_date'),
                DB::raw('MAX(rsl.expiration_date) as latest_expiration_date'),
            ])
            ->groupBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($rows) => $rows->map(function ($row) {
                $row->source = 'stock_lot';

                return $row;
            }));
    }

    private function getWarehouseDefaultLocation(int $warehouseId): ?object
    {
        $patterns = [
            ['code1' => 'Z', 'code2' => '00', 'code3' => null],
            ['code1' => 'Z', 'code2' => '0', 'code3' => '0'],
            ['code1' => 'ZZ', 'code2' => '1', 'code3' => '100'],
        ];

        foreach ($patterns as $pattern) {
            $location = DB::connection('sakemaru')
                ->table('locations')
                ->where('warehouse_id', $warehouseId)
                ->where('code1', $pattern['code1'])
                ->where('code2', $pattern['code2'])
                ->when(
                    $pattern['code3'] === null,
                    fn ($query) => $query->whereNull('code3'),
                    fn ($query) => $query->where('code3', $pattern['code3'])
                )
                ->first([
                    'id',
                    'warehouse_id',
                    'floor_id',
                    'code1',
                    'code2',
                    'code3',
                    'name',
                    'temperature_type',
                    'is_restricted_area',
                    'available_quantity_flags',
                ]);

            if ($location) {
                $location->source = 'warehouse_default';
                $location->is_no_location = true;

                return $location;
            }
        }

        return null;
    }

    private function formatLocation(object $location, string $source, array $extra = []): array
    {
        $code = Location::formatCode($location->code1, $location->code2, $location->code3, '-');
        $isNoLocation = (bool) ($location->is_no_location ?? false);

        return array_merge([
            'id' => (int) $location->id,
            'warehouse_id' => (int) $location->warehouse_id,
            'floor_id' => $location->floor_id ? (int) $location->floor_id : null,
            'code1' => $location->code1,
            'code2' => $location->code2,
            'code3' => $location->code3,
            'code' => $code,
            'display_name' => $isNoLocation
                ? 'フリーロケ'
                : ($location->name ? "{$code} {$location->name}" : $code),
            'name' => $location->name,
            'source' => $source,
            'is_no_location' => $isNoLocation,
            'temperature_type' => $location->temperature_type,
            'is_restricted_area' => (bool) $location->is_restricted_area,
            'available_quantity_flags' => $location->available_quantity_flags !== null
                ? (int) $location->available_quantity_flags
                : null,
        ], $extra);
    }
}
