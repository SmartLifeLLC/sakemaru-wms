<?php

namespace App\Http\Controllers\Api;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EItemSearchCodeType;
use App\Enums\EVolumeUnit;
use App\Enums\QuantityType;
use App\Services\Distribution\DistributionDeliveryDateService;
use App\Services\Distribution\DistributionOrderCandidateService;
use App\Services\Distribution\DistributionStockTransferSlipService;
use App\Services\WarehouseResolver;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Sakemaru\Auth\Services\PermissionService;

class DistributionProductController extends ApiController
{
    private const PERMISSION_DISTRIBUTION_ADJUSTMENT = 'wms.distribution-adjustment.view';

    private const PERMISSION_DIRECT_DISTRIBUTION = 'wms.direct-distribution.view';

    private const PERMISSION_STORE_DISTRIBUTION_MANAGEMENT = 'wms.store-distribution-management.view';

    private const PERMISSIONS = [
        self::PERMISSION_DISTRIBUTION_ADJUSTMENT,
        self::PERMISSION_DIRECT_DISTRIBUTION,
        self::PERMISSION_STORE_DISTRIBUTION_MANAGEMENT,
    ];

    private const TEMPORARILY_HIDDEN_DESTINATION_CODES = [
        '5',
        '05',
        '6',
        '06',
        '23',
        '63',
        '71',
        '72',
        '73',
        '74',
        '75',
        '80',
        '89',
        '90',
        '91',
        '92',
        '93',
        '94',
        '95',
        '96',
        '97',
        '98',
        '100',
        '101',
    ];

    private const HQ_TRANSFER_CONTRACTOR_CODE = '9012';

    private const HQ_TRANSFER_WAREHOUSE_CODE = '91';

    public function __invoke(
        Request $request,
        PermissionService $permissionService,
        DistributionDeliveryDateService $deliveryDateService
    ): JsonResponse {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:100',
            'order_to_search' => 'nullable|string|max:100',
            'supplier_search' => 'nullable|string|max:100',
            'category1_id' => 'nullable|integer|min:1',
            'category2_id' => 'nullable|integer|min:1',
            'category3_id' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:50',
            'order_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $search = trim((string) $request->input('search', ''));
        $orderToSearch = trim((string) $request->input('order_to_search', ''));
        $supplierSearch = trim((string) $request->input('supplier_search', ''));
        $category1Id = $request->filled('category1_id') ? (int) $request->input('category1_id') : null;
        $category2Id = $request->filled('category2_id') ? (int) $request->input('category2_id') : null;
        $category3Id = $request->filled('category3_id') ? (int) $request->input('category3_id') : null;
        $limit = max(1, min(50, (int) $request->input('limit', 30)));
        $orderDate = $request->filled('order_date')
            ? Carbon::parse((string) $request->input('order_date'))->startOfDay()
            : Carbon::today()->startOfDay();

        if (
            $search === ''
            && $orderToSearch === ''
            && $supplierSearch === ''
            && ! $this->hasItemCategoryFilter($category1Id, $category2Id, $category3Id)
        ) {
            return $this->success([]);
        }

        $clientId = (int) config('app.client_id');
        $orderToContractorIds = $orderToSearch !== ''
            ? $this->searchOrderToContractorIds($clientId, $orderToSearch)
            : null;
        $supplierIds = $supplierSearch !== ''
            ? $this->searchSupplierIds($clientId, $supplierSearch)
            : null;

        if ($orderToContractorIds === [] || $supplierIds === []) {
            return $this->success([]);
        }

        $items = $this->searchItems(
            $clientId,
            $search,
            $limit,
            $orderToContractorIds,
            $supplierIds,
            $category1Id,
            $category2Id,
            $category3Id
        );

        if ($search !== '' && $items->isEmpty() && preg_match('/^0+/', $search)) {
            $trimmedSearch = ltrim($search, '0') ?: '0';
            if ($trimmedSearch !== $search) {
                $items = $this->searchItems(
                    $clientId,
                    $trimmedSearch,
                    $limit,
                    $orderToContractorIds,
                    $supplierIds,
                    $category1Id,
                    $category2Id,
                    $category3Id
                );
            }
        }

        if ($items->isEmpty()) {
            return $this->success([]);
        }

        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selectedWarehouseId = $user->getSelectedWarehouseId();
        $realWarehouseId = $selectedWarehouseId
            ? WarehouseResolver::resolveRealWarehouseId((int) $selectedWarehouseId)
            : null;
        $incomingWarehouseIds = $selectedWarehouseId
            ? WarehouseResolver::resolveAllWarehouseIds((int) $selectedWarehouseId)
            : [];

        $janCodes = $this->getJanCodesByItem($clientId, $itemIds);
        $contractors = $this->getContractorsByItem($clientId, $itemIds, $selectedWarehouseId, $realWarehouseId, $orderToContractorIds, $supplierIds);
        $contractorOptions = $contractors
            ->flatMap(fn (Collection $rows): Collection => $rows)
            ->keyBy(fn ($contractor): string => $this->contractorOptionKey((int) $contractor->item_id, $contractor));
        $suggestedDeliveryDates = $selectedWarehouseId
            ? $deliveryDateService->resolveForItems((int) $selectedWarehouseId, $contractorOptions, $orderDate)
            : collect();
        $stockSummaries = $realWarehouseId
            ? $this->getStockSummariesByItem((int) $realWarehouseId, $itemIds)
            : collect();
        $shelfLocations = $realWarehouseId
            ? $this->getShelfLocationsByItem((int) $realWarehouseId, $itemIds)
            : collect();
        $incomingSummaries = $incomingWarehouseIds !== []
            ? $this->getIncomingSummariesByItem($incomingWarehouseIds, $itemIds)
            : collect();
        $incomingSummariesByContractor = $incomingWarehouseIds !== []
            ? $this->getIncomingSummariesByContractor($incomingWarehouseIds, $itemIds)
            : collect();
        $lastOrderDates = $incomingWarehouseIds !== []
            ? $this->getLastOrderDatesByContractor($incomingWarehouseIds, $itemIds)
            : collect();
        $salesSummaries = $selectedWarehouseId
            ? $this->getSalesSummariesByItem((int) $selectedWarehouseId, $itemIds)
            : collect();

        $data = $items
            ->flatMap(function ($item) use (
                $janCodes,
                $contractors,
                $suggestedDeliveryDates,
                $stockSummaries,
                $shelfLocations,
                $incomingSummaries,
                $incomingSummariesByContractor,
                $lastOrderDates,
                $salesSummaries,
                $selectedWarehouseId,
                $realWarehouseId
            ): Collection {
                $itemId = (int) $item->id;
                $stock = $stockSummaries->get($itemId, $this->emptyStockSummary());
                $shelfLocation = (string) ($shelfLocations->get($itemId) ?? '');
                $sales = $salesSummaries->get($itemId, ['sales_week1' => 0, 'sales_week2' => 0, 'sales_week3' => 0]);
                $contractorRows = $contractors->get($itemId, collect());

                if ($contractorRows->isEmpty()) {
                    $contractorRows = collect([null]);
                }

                return $contractorRows->map(function ($contractor) use (
                    $item,
                    $itemId,
                    $janCodes,
                    $suggestedDeliveryDates,
                    $stock,
                    $shelfLocation,
                    $incomingSummaries,
                    $incomingSummariesByContractor,
                    $lastOrderDates,
                    $sales,
                    $selectedWarehouseId,
                    $realWarehouseId
                ): array {
                    $optionKey = $this->contractorOptionKey($itemId, $contractor);
                    $scheduleKey = $this->contractorScheduleKey($itemId, $contractor);
                    $suggestedDeliveryDate = $suggestedDeliveryDates->get($optionKey);
                    $incoming = $contractor
                        ? $incomingSummariesByContractor->get($scheduleKey, ['incoming_quantity' => 0, 'incoming_count' => 0])
                        : $incomingSummaries->get($itemId, ['incoming_quantity' => 0, 'incoming_count' => 0]);
                    $lastOrderDate = $contractor
                        ? (string) ($lastOrderDates->get($scheduleKey) ?? '')
                        : '';
                    $unitsPerCase = (int) ($item->capacity_case ?? 0);
                    $purchaseUnit = (int) ($contractor->purchase_unit ?? 0);

                    if ($unitsPerCase <= 0) {
                        $unitsPerCase = $purchaseUnit;
                    }

                    return [
                        'candidateKey' => $optionKey,
                        'id' => $itemId,
                        'itemContractorId' => $contractor ? (int) ($contractor->item_contractor_id ?? 0) : null,
                        'contractorId' => $contractor ? (int) ($contractor->contractor_id ?? 0) : null,
                        'supplierId' => $contractor ? (int) ($contractor->supplier_id ?? 0) : null,
                        'contractorWarehouseId' => $contractor ? (int) ($contractor->warehouse_id ?? 0) : null,
                        'code' => (string) $item->code,
                        'jan' => (string) ($janCodes->get($itemId) ?? ''),
                        'name' => (string) $item->name,
                        'volume' => $this->formatVolume($item->volume, $item->volume_unit),
                        'unitsPerCase' => max(1, $unitsPerCase),
                        'purchaseUnit' => max(1, $purchaseUnit ?: 1),
                        'orderPoint' => $contractor ? (int) ($contractor->safety_stock ?? 0) : 0,
                        'shelfLocation' => $shelfLocation,
                        'lastOrderDate' => $lastOrderDate,
                        'salesWeek1' => (int) ($sales['sales_week1'] ?? 0),
                        'salesWeek2' => (int) ($sales['sales_week2'] ?? 0),
                        'salesWeek3' => (int) ($sales['sales_week3'] ?? 0),
                        'supplier' => (string) ($contractor->supplier_name ?? ''),
                        'supplierCode' => (string) ($contractor->supplier_code ?? ''),
                        'orderTo' => (string) ($contractor->contractor_name ?? ''),
                        'orderToCode' => (string) ($contractor->contractor_code ?? ''),
                        'itemContractorNote' => (string) ($contractor->item_contractor_note ?? ''),
                        'suggestedDeliveryDate' => (string) ($suggestedDeliveryDate['suggested_delivery_date'] ?? ''),
                        'deliveryDateCalculation' => [
                            'acceptedOrderDate' => (string) ($suggestedDeliveryDate['accepted_order_date'] ?? ''),
                            'originalDeliveryDate' => (string) ($suggestedDeliveryDate['original_delivery_date'] ?? ''),
                            'leadTimeDays' => (int) ($suggestedDeliveryDate['lead_time_days'] ?? 0),
                            'shiftedDays' => (int) ($suggestedDeliveryDate['shifted_days'] ?? 0),
                            'shiftReasons' => $suggestedDeliveryDate['shift_reasons'] ?? [],
                        ],
                        'stock' => [
                            'selectedWarehouseId' => $selectedWarehouseId ? (int) $selectedWarehouseId : null,
                            'realWarehouseId' => $realWarehouseId ? (int) $realWarehouseId : null,
                            'actualQuantity' => (int) $stock['actual_quantity'],
                            'theoreticalQuantity' => (int) $stock['theoretical_quantity'],
                            'reservedQuantity' => (int) $stock['reserved_quantity'],
                            'incomingQuantity' => (int) $incoming['incoming_quantity'],
                            'incomingCount' => (int) $incoming['incoming_count'],
                        ],
                    ];
                });
            })
            ->values()
            ->take($limit)
            ->all();

        return $this->success($data);
    }

    public function resolveProductsBatch(
        Request $request,
        PermissionService $permissionService,
        DistributionDeliveryDateService $deliveryDateService
    ): JsonResponse {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'queries' => 'required|array|min:1|max:500',
            'queries.*.key' => 'nullable|string|max:120',
            'queries.*.search' => 'required|string|max:100',
            'queries.*.order_date' => 'nullable|date',
            'queries.*.order_to_search' => 'nullable|string|max:100',
            'queries.*.supplier_search' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $entries = collect($request->input('queries', []))
            ->map(function (array $query, int $index): array {
                $orderDate = isset($query['order_date']) && trim((string) $query['order_date']) !== ''
                    ? Carbon::parse((string) $query['order_date'])->toDateString()
                    : Carbon::today()->toDateString();

                return [
                    'key' => (string) ($query['key'] ?? $index),
                    'search' => trim((string) ($query['search'] ?? '')),
                    'order_date' => $orderDate,
                    'order_to_search' => trim((string) ($query['order_to_search'] ?? '')),
                    'supplier_search' => trim((string) ($query['supplier_search'] ?? '')),
                ];
            })
            ->filter(fn (array $query): bool => $query['search'] !== '')
            ->values();

        if ($entries->isEmpty()) {
            return $this->success(['items' => []]);
        }

        $clientId = (int) config('app.client_id');
        $results = [];
        $fallbackEntries = collect();

        $entries
            ->filter(fn (array $entry): bool => $entry['order_to_search'] === '' && $entry['supplier_search'] === '')
            ->groupBy('order_date')
            ->each(function (Collection $group, string $orderDate) use (&$results, &$fallbackEntries, $clientId, $user, $deliveryDateService): void {
                $searchesByKey = $group
                    ->mapWithKeys(fn (array $entry): array => [$entry['key'] => $entry['search']])
                    ->all();
                $itemIdsByKey = $this->resolveExactItemIdsBySearch($clientId, $searchesByKey);
                $itemIds = collect($itemIdsByKey)
                    ->flatten()
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                $productsByItemId = collect();

                if ($itemIds !== []) {
                    $items = $this->loadItemsByIds($clientId, $itemIds);
                    $productsByItemId = collect($this->buildProductCandidateRows(
                        user: $user,
                        items: $items,
                        orderDate: Carbon::parse($orderDate)->startOfDay(),
                        deliveryDateService: $deliveryDateService,
                        limit: null
                    ))->groupBy(fn (array $product): int => (int) ($product['id'] ?? 0));
                }

                foreach ($group as $entry) {
                    $products = collect($itemIdsByKey[$entry['key']] ?? [])
                        ->flatMap(fn (int $itemId): Collection => $productsByItemId->get($itemId, collect()))
                        ->values()
                        ->take(50)
                        ->all();

                    if ($products === []) {
                        $fallbackEntries->push($entry);
                        continue;
                    }

                    $results[$entry['key']] = [
                        'key' => $entry['key'],
                        'search' => $entry['search'],
                        'products' => $products,
                    ];
                }
            });

        $entries
            ->filter(fn (array $entry): bool => $entry['order_to_search'] !== '' || $entry['supplier_search'] !== '')
            ->each(fn (array $entry) => $fallbackEntries->push($entry));

        $fallbackEntries
            ->groupBy(fn (array $entry): string => implode("\x1F", [
                $this->normalizeSearchText($entry['search']),
                $entry['order_date'],
                $this->normalizeSearchText($entry['order_to_search']),
                $this->normalizeSearchText($entry['supplier_search']),
            ]))
            ->each(function (Collection $group) use (&$results, $clientId, $user, $deliveryDateService): void {
                $entry = $group->first();
                if (! $entry) {
                    return;
                }

                $orderToContractorIds = $entry['order_to_search'] !== ''
                    ? $this->searchOrderToContractorIds($clientId, $entry['order_to_search'])
                    : null;
                $supplierIds = $entry['supplier_search'] !== ''
                    ? $this->searchSupplierIds($clientId, $entry['supplier_search'])
                    : null;

                if ($orderToContractorIds === [] || $supplierIds === []) {
                    foreach ($group as $groupEntry) {
                        $results[$groupEntry['key']] = [
                            'key' => $groupEntry['key'],
                            'search' => $groupEntry['search'],
                            'products' => [],
                        ];
                    }

                    return;
                }

                $items = $this->searchItems($clientId, $entry['search'], 50, $orderToContractorIds, $supplierIds);

                if ($items->isEmpty() && preg_match('/^0+/', $entry['search'])) {
                    $trimmedSearch = ltrim($entry['search'], '0') ?: '0';
                    if ($trimmedSearch !== $entry['search']) {
                        $items = $this->searchItems($clientId, $trimmedSearch, 50, $orderToContractorIds, $supplierIds);
                    }
                }

                $products = $this->buildProductCandidateRows(
                    user: $user,
                    items: $items,
                    orderDate: Carbon::parse($entry['order_date'])->startOfDay(),
                    deliveryDateService: $deliveryDateService,
                    orderToContractorIds: $orderToContractorIds,
                    supplierIds: $supplierIds,
                    limit: 50
                );

                foreach ($group as $groupEntry) {
                    $results[$groupEntry['key']] = [
                        'key' => $groupEntry['key'],
                        'search' => $groupEntry['search'],
                        'products' => $products,
                    ];
                }
            });

        $ordered = $entries
            ->map(fn (array $entry): array => $results[$entry['key']] ?? [
                'key' => $entry['key'],
                'search' => $entry['search'],
                'products' => [],
            ])
            ->values()
            ->all();

        return $this->success(['items' => $ordered]);
    }

    public function arrivalSchedules(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'item_id' => 'nullable|integer|min:1',
            'product_code' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $itemId = (int) $request->input('item_id', 0);
        $productCode = trim((string) $request->input('product_code', ''));
        $limit = max(1, min(50, (int) $request->input('limit', 20)));

        if ($itemId <= 0 && $productCode === '') {
            return $this->success([]);
        }

        return $this->success(
            $this->searchArrivalSchedules($itemId, $productCode, $limit)
        );
    }

    public function destinations(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $selectedWarehouseId = $user->getSelectedWarehouseId();
        $excludedWarehouseIds = $selectedWarehouseId
            ? WarehouseResolver::resolveAllWarehouseIds((int) $selectedWarehouseId)
            : [];

        $includeHidden = $request->boolean('include_hidden', false);
        $includeSelectedWarehouse = $request->boolean('include_selected', false);

        $query = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('is_active', true)
            ->orderBy('code')
            ->limit(200);

        if (! $includeHidden && ! $includeSelectedWarehouse) {
            $query->whereNotIn('code', self::TEMPORARILY_HIDDEN_DESTINATION_CODES);
        }

        if (! $includeSelectedWarehouse && $excludedWarehouseIds !== []) {
            $query->whereNotIn('id', $excludedWarehouseIds);
        }

        $destinations = $query
            ->get(['id', 'code', 'name'])
            ->filter(function ($warehouse) use ($includeHidden, $includeSelectedWarehouse, $selectedWarehouseId): bool {
                if ($includeHidden) {
                    return true;
                }

                $code = (string) $warehouse->code;
                $isSelectedWarehouse = $selectedWarehouseId && (int) $warehouse->id === (int) $selectedWarehouseId;

                if (
                    $includeSelectedWarehouse
                    && $isSelectedWarehouse
                    && $code !== self::HQ_TRANSFER_WAREHOUSE_CODE
                ) {
                    return true;
                }

                return ! in_array($code, self::TEMPORARILY_HIDDEN_DESTINATION_CODES, true);
            })
            ->map(fn ($warehouse): array => [
                'id' => (int) $warehouse->id,
                'key' => $this->formatDestinationKey($warehouse->code, (int) $warehouse->id),
                'code' => (string) $warehouse->code,
                'name' => (string) $warehouse->name,
                'isHidden' => in_array((string) $warehouse->code, self::TEMPORARILY_HIDDEN_DESTINATION_CODES, true),
                'isSelectedWarehouse' => $selectedWarehouseId && (int) $warehouse->id === (int) $selectedWarehouseId,
            ])
            ->values()
            ->all();

        return $this->success($destinations);
    }

    public function deliveryCourses(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $search = $this->normalizeSearchText((string) $request->input('search', ''));
        $limit = max(1, min(50, (int) $request->input('limit', 20)));
        $digits = preg_replace('/\D/', '', $search);
        $like = "%{$search}%";

        $query = DB::connection('sakemaru')
            ->table('delivery_courses as dc')
            ->leftJoin('warehouses as w', 'w.id', '=', 'dc.warehouse_id')
            ->where('dc.client_id', (int) config('app.client_id'));

        if ($search !== '') {
            $query->where(function ($query) use ($search, $like, $digits): void {
                $query
                    ->where('dc.name', 'like', $like)
                    ->orWhere('dc.code', 'like', $like);

                if ($digits !== '' && $digits !== $search) {
                    $query->orWhere('dc.code', 'like', "%{$digits}%");
                }
            });
        }

        $courses = $query
            ->orderBy('dc.code')
            ->limit($limit)
            ->get([
                'dc.id',
                'dc.code',
                'dc.name',
                'dc.warehouse_id',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
            ])
            ->map(fn ($course): array => [
                'id' => (int) $course->id,
                'code' => (string) $course->code,
                'name' => (string) $course->name,
                'warehouse_id' => $course->warehouse_id !== null ? (int) $course->warehouse_id : null,
                'warehouse_code' => (string) ($course->warehouse_code ?? ''),
                'warehouse_name' => (string) ($course->warehouse_name ?? ''),
            ])
            ->values()
            ->all();

        return $this->success($courses);
    }

    public function hqTransferRequests(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $clientId = (int) config('app.client_id');
        $hqContractor = DB::connection('sakemaru')
            ->table('contractors')
            ->where('code', self::HQ_TRANSFER_CONTRACTOR_CODE)
            ->first(['id', 'code', 'name', 'supplier_id']);
        $hqWarehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', $clientId)
            ->where('code', self::HQ_TRANSFER_WAREHOUSE_CODE)
            ->first(['id', 'code', 'name']);

        if (! $hqWarehouse) {
            return $this->success([]);
        }

        $today = Carbon::today()->toDateString();
        $data = collect();

        if ($hqContractor) {
            $rows = DB::connection('sakemaru')
                ->table('wms_stock_transfer_candidates as tc')
                ->join('items as i', 'i.id', '=', 'tc.item_id')
                ->leftJoin('warehouses as sw', 'sw.id', '=', 'tc.satellite_warehouse_id')
                ->leftJoin('contractors as c', 'c.id', '=', 'tc.contractor_id')
                ->leftJoin('suppliers as s', 's.id', '=', 'c.supplier_id')
                ->leftJoin('partners as supplier_partners', 'supplier_partners.id', '=', 's.partner_id')
                ->where('i.client_id', $clientId)
                ->where('tc.hub_warehouse_id', (int) $hqWarehouse->id)
                ->where('tc.contractor_id', (int) $hqContractor->id)
                ->where('tc.status', CandidateStatus::PENDING->value)
                ->whereDate('tc.created_at', $today)
                ->where('tc.transfer_quantity', '>', 0)
                ->whereNotIn('sw.code', self::TEMPORARILY_HIDDEN_DESTINATION_CODES)
                ->select([
                    'tc.id',
                    'tc.batch_code',
                    'tc.satellite_warehouse_id',
                    'tc.hub_warehouse_id',
                    'tc.item_id',
                    'tc.item_code',
                    'tc.search_code',
                    'tc.ordering_code',
                    'tc.contractor_id',
                    'tc.transfer_quantity',
                    'tc.suggested_quantity',
                    'tc.current_effective_stock',
                    'tc.incoming_quantity',
                    'tc.calculated_available',
                    'tc.shortage_qty',
                    'tc.safety_stock',
                    'tc.hub_effective_stock',
                    'tc.purchase_unit',
                    'tc.quantity_type',
                    'tc.expected_arrival_date',
                    'tc.created_at',
                    'i.code as item_code_master',
                    'i.name as item_name',
                    'i.volume',
                    'i.volume_unit',
                    'i.capacity_case',
                    'sw.code as satellite_warehouse_code',
                    'sw.name as satellite_warehouse_name',
                    'c.code as contractor_code',
                    'c.name as contractor_name',
                    'supplier_partners.code as supplier_code',
                    'supplier_partners.name as supplier_name',
                ])
                ->orderByDesc('tc.created_at')
                ->orderBy('i.code')
                ->limit(2000)
                ->get();

            if ($rows->isNotEmpty()) {
                $itemIds = $rows->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $janCodes = $this->getJanCodesByItem($clientId, $itemIds);
                $contractorsByItem = $this->getContractorsByItem(
                    $clientId,
                    $itemIds,
                    (int) $hqWarehouse->id,
                    (int) $hqWarehouse->id,
                    null,
                    null,
                    [(int) $hqContractor->id]
                );

                $data = $rows
                    ->groupBy(fn ($row): string => Carbon::parse($row->created_at)->toDateString().':'.(int) $row->item_id)
                    ->map(function (Collection $itemRows) use ($janCodes, $contractorsByItem, $hqContractor, $hqWarehouse): array {
                        $first = $itemRows->first();
                        $contractor = $contractorsByItem->get((int) $first->item_id, collect())->first();
                        $wishes = [];
                        $candidateIdsByDestination = [];
                        $candidateIds = [];
                        $orderDate = Carbon::parse($first->created_at)->toDateString();

                        foreach ($itemRows as $row) {
                            $destinationKey = $this->formatDestinationKey($row->satellite_warehouse_code, (int) $row->satellite_warehouse_id);
                            $quantity = max(0, (int) $row->transfer_quantity);

                            if ($quantity <= 0) {
                                continue;
                            }

                            $wishes[$destinationKey] = ($wishes[$destinationKey] ?? 0) + $quantity;
                            $candidateIdsByDestination[$destinationKey] ??= [];
                            $candidateIdsByDestination[$destinationKey][] = (int) $row->id;
                            $candidateIds[] = (int) $row->id;
                        }

                        $deliveryDates = $itemRows
                            ->pluck('expected_arrival_date')
                            ->filter()
                            ->map(fn ($date): string => Carbon::parse($date)->toDateString());
                        $purchaseUnit = max(1, (int) ($contractor?->purchase_unit ?? 0) ?: (int) ($itemRows->max('purchase_unit') ?: 1));
                        $unitsPerCase = max(1, (int) ($first->capacity_case ?: $purchaseUnit));
                        $hubEffectiveStock = (int) ($itemRows->max('hub_effective_stock') ?? 0);

                        return [
                            'id' => 'hq-transfer-'.$orderDate.'-'.$first->item_id,
                            'source' => 'hq_transfer_request',
                            'sourceKey' => 'hq-transfer:'.$orderDate.':'.$first->item_id.':'.$hqContractor->id,
                            'sourceCandidateIds' => array_values(array_unique($candidateIds)),
                            'sourceCandidateIdsByDestination' => $candidateIdsByDestination,
                            'itemId' => (int) $first->item_id,
                            'candidateKey' => 'hq-transfer:'.$orderDate.':'.$first->item_id.':'.$hqContractor->id,
                            'itemContractorId' => $contractor ? (int) ($contractor->item_contractor_id ?? 0) : null,
                            'contractorId' => $contractor ? (int) ($contractor->contractor_id ?? 0) : null,
                            'supplierId' => $contractor ? (int) ($contractor->supplier_id ?? 0) : null,
                            'contractorWarehouseId' => (int) $hqWarehouse->id,
                            'productCode' => (string) ($first->item_code ?: $first->item_code_master),
                            'jan' => (string) ($janCodes->get((int) $first->item_id) ?? ''),
                            'name' => (string) $first->item_name,
                            'volume' => $this->formatVolume($first->volume, $first->volume_unit),
                            'unitsPerCase' => $unitsPerCase,
                            'purchaseUnit' => $purchaseUnit,
                            'lot' => $purchaseUnit,
                            'orderPoint' => (int) ($contractor?->safety_stock ?? $itemRows->max('safety_stock') ?? 0),
                            'currentStock' => $hubEffectiveStock,
                            'reserved' => $hubEffectiveStock,
                            'reservedStock' => 0,
                            'incomingQuantity' => 0,
                            'incomingCount' => 0,
                            'selectedWarehouseId' => (int) $hqWarehouse->id,
                            'realWarehouseId' => (int) $hqWarehouse->id,
                            'orderDate' => $orderDate,
                            'deliveryDate' => $deliveryDates->min() ?: '',
                            'orderTo' => (string) ($contractor->contractor_name ?? ''),
                            'orderToCode' => (string) ($contractor->contractor_code ?? ''),
                            'supplier' => (string) ($contractor->supplier_name ?? ''),
                            'supplierCode' => (string) ($contractor->supplier_code ?? ''),
                            'wishes' => $wishes,
                        ];
                    })
                    ->values();
            }
        }

        $internalDemandRows = $this->getInternalDemandRequestRows($request, $clientId, $hqWarehouse, $hqContractor);

        return $this->success($data
            ->merge($internalDemandRows)
            ->sortBy([
                ['orderDate', 'desc'],
                ['productCode', 'asc'],
            ])
            ->values()
            ->all());
    }

    private function getInternalDemandRequestRows(Request $request, int $clientId, object $hqWarehouse, ?object $hqContractor = null): Collection
    {
        $requiredTables = [
            'demand_requests',
            'demand_request_items',
            'demand_entries',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                return collect();
            }
        }

        $demandRequestId = (int) ($request->integer('demand_request_id') ?: $request->integer('internal_demand_request_id'));

        $query = DB::connection('sakemaru')
            ->table('demand_entries as de')
            ->join('demand_request_items as dri', 'dri.id', '=', 'de.demand_request_item_id')
            ->join('demand_requests as dr', 'dr.id', '=', 'de.demand_request_id')
            ->join('items as i', 'i.id', '=', 'dri.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'de.warehouse_id')
            ->where('i.client_id', $clientId)
            ->where('de.requested_quantity', '>', 0)
            ->whereNotIn('w.code', self::TEMPORARILY_HIDDEN_DESTINATION_CODES)
            ->select([
                'de.id as demand_entry_id',
                'de.demand_request_id',
                'de.demand_request_item_id',
                'de.warehouse_id',
                'de.requested_quantity',
                'de.note as entry_note',
                'dri.item_id',
                'dri.memo as item_memo',
                'dr.code as demand_request_code',
                'dr.title as demand_request_title',
                'dr.status as demand_request_status',
                'dr.deadline_at',
                'dr.created_at as demand_request_created_at',
                'dr.updated_at as demand_request_updated_at',
                'i.code as item_code',
                'i.name as item_name',
                'i.volume',
                'i.volume_unit',
                'i.capacity_case',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
            ]);

        if ($demandRequestId > 0) {
            $query->where('dr.id', $demandRequestId);
        } else {
            $query->whereNotIn('dr.status', ['draft', 'distributed', 'completed']);
        }

        $rows = $query
            ->orderByDesc('dr.updated_at')
            ->orderBy('i.code')
            ->limit(5000)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $itemIds = $rows->pluck('item_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $janCodes = $this->getJanCodesByItem($clientId, $itemIds);
        $stockSummaries = $this->getStockSummariesByItem((int) $hqWarehouse->id, $itemIds);
        $incomingSummaries = $this->getIncomingSummariesByItem([(int) $hqWarehouse->id], $itemIds);
        $contractorsByItem = $this->getContractorsByItem(
            $clientId,
            $itemIds,
            (int) $hqWarehouse->id,
            (int) $hqWarehouse->id,
            null,
            null,
            $hqContractor ? [(int) $hqContractor->id] : null
        );

        return $rows
            ->groupBy(fn ($row): string => (int) $row->demand_request_id.':'.(int) $row->item_id)
            ->map(function (Collection $itemRows) use ($janCodes, $stockSummaries, $incomingSummaries, $contractorsByItem, $hqWarehouse): array {
                $first = $itemRows->first();
                $itemId = (int) $first->item_id;
                $contractor = $contractorsByItem->get($itemId, collect())->first();
                $stock = $stockSummaries->get($itemId, $this->emptyStockSummary());
                $incoming = $incomingSummaries->get($itemId, [
                    'incoming_quantity' => 0,
                    'incoming_count' => 0,
                ]);
                $wishes = [];
                $entryIdsByDestination = [];
                $entryIds = [];

                foreach ($itemRows as $row) {
                    $destinationKey = $this->formatDestinationKey($row->warehouse_code, (int) $row->warehouse_id);
                    $quantity = max(0, (int) $row->requested_quantity);

                    if ($quantity <= 0) {
                        continue;
                    }

                    $wishes[$destinationKey] = ($wishes[$destinationKey] ?? 0) + $quantity;
                    $entryIdsByDestination[$destinationKey] ??= [];
                    $entryIdsByDestination[$destinationKey][] = (int) $row->demand_entry_id;
                    $entryIds[] = (int) $row->demand_entry_id;
                }

                $purchaseUnit = max(1, (int) ($contractor->purchase_unit ?? 0) ?: (int) ($first->capacity_case ?? 1));
                $unitsPerCase = max(1, (int) ($first->capacity_case ?: $purchaseUnit));
                $orderDate = Carbon::today()->toDateString();
                $deliveryDate = $first->deadline_at ? Carbon::parse($first->deadline_at)->toDateString() : '';
                $memoParts = array_values(array_filter([
                    '['.(string) $first->demand_request_code.'] '.(string) $first->demand_request_title,
                    (string) ($first->item_memo ?? ''),
                ], fn (string $memo): bool => trim($memo) !== ''));

                return [
                    'id' => 'internal-demand-'.$first->demand_request_id.'-'.$itemId,
                    'source' => 'internal_demand_request',
                    'sourceKey' => 'internal-demand:'.$first->demand_request_id.':'.$itemId,
                    'sourceDemandRequestId' => (int) $first->demand_request_id,
                    'sourceDemandEntryIds' => array_values(array_unique($entryIds)),
                    'sourceDemandEntryIdsByDestination' => $entryIdsByDestination,
                    'itemId' => $itemId,
                    'candidateKey' => 'internal-demand:'.$first->demand_request_id.':'.$itemId.':'.(int) ($contractor->contractor_id ?? 0),
                    'itemContractorId' => $contractor ? (int) ($contractor->item_contractor_id ?? 0) : null,
                    'contractorId' => $contractor ? (int) ($contractor->contractor_id ?? 0) : null,
                    'supplierId' => $contractor ? (int) ($contractor->supplier_id ?? 0) : null,
                    'contractorWarehouseId' => (int) $hqWarehouse->id,
                    'productCode' => (string) $first->item_code,
                    'jan' => (string) ($janCodes->get($itemId) ?? ''),
                    'name' => (string) $first->item_name,
                    'volume' => $this->formatVolume($first->volume, $first->volume_unit),
                    'unitsPerCase' => $unitsPerCase,
                    'purchaseUnit' => $purchaseUnit,
                    'lot' => $purchaseUnit,
                    'orderPoint' => (int) ($contractor->safety_stock ?? 0),
                    'currentStock' => (int) ($stock['actual_quantity'] ?? 0),
                    'reserved' => (int) ($stock['theoretical_quantity'] ?? 0),
                    'reservedStock' => (int) ($stock['reserved_quantity'] ?? 0),
                    'incomingQuantity' => (int) ($incoming['incoming_quantity'] ?? 0),
                    'incomingCount' => (int) ($incoming['incoming_count'] ?? 0),
                    'selectedWarehouseId' => (int) $hqWarehouse->id,
                    'realWarehouseId' => (int) $hqWarehouse->id,
                    'orderDate' => $orderDate,
                    'deliveryDate' => $deliveryDate,
                    'orderTo' => (string) ($contractor->contractor_name ?? ''),
                    'orderToCode' => (string) ($contractor->contractor_code ?? ''),
                    'supplier' => (string) ($contractor->supplier_name ?? ''),
                    'supplierCode' => (string) ($contractor->supplier_code ?? ''),
                    'memo' => implode("\n", $memoParts),
                    'wishes' => $wishes,
                ];
            })
            ->values();
    }

    public function createStockTransferSlips(
        Request $request,
        PermissionService $permissionService,
        DistributionStockTransferSlipService $service
    ): JsonResponse {
        $user = $request->user();

        if (! $user || ! $this->canView($permissionService, $user, self::PERMISSION_DISTRIBUTION_ADJUSTMENT)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'remark' => 'nullable|string|max:500',
            'rows' => 'required|array|min:1|max:100',
            'rows.*.row_id' => 'required|string|max:120',
            'rows.*.item_id' => 'nullable|integer|min:1',
            'rows.*.product_code' => 'nullable|string|max:100',
            'rows.*.item_contractor_id' => 'nullable|integer|min:1',
            'rows.*.contractor_id' => 'nullable|integer|min:1',
            'rows.*.supplier_id' => 'nullable|integer|min:1',
            'rows.*.order_to_code' => 'nullable|string|max:100',
            'rows.*.order_to' => 'nullable|string|max:255',
            'rows.*.supplier_code' => 'nullable|string|max:100',
            'rows.*.supplier' => 'nullable|string|max:255',
            'rows.*.order_date' => 'nullable|date',
            'rows.*.delivery_date' => 'nullable|date',
            'rows.*.delivery_course_id' => 'nullable|integer|min:1',
            'rows.*.memo' => 'nullable|string|max:500',
            'rows.*.allocations' => 'required|array|min:1|max:200',
            'rows.*.allocations.*.destination_id' => 'required|integer|min:1',
            'rows.*.allocations.*.destination_key' => 'nullable|string|max:50',
            'rows.*.allocations.*.quantity' => 'required|integer|min:0|max:99999999',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $selectedWarehouseId = $this->selectedKuraWarehouseId($user);

        if (! $selectedWarehouseId) {
            return $this->error('分配の伝票生成は華むすびの蔵センター選択時のみ実行できます。', 422, 'KURA_WAREHOUSE_REQUIRED');
        }

        try {
            return $this->success(
                $service->create(
                    selectedWarehouseId: (int) $selectedWarehouseId,
                    createdBy: (int) $user->id,
                    rows: $request->input('rows', []),
                    remark: $request->input('remark'),
                    preventDuplicateByDistributionRow: true
                )
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, 'DISTRIBUTION_TRANSFER_SLIP_ERROR');
        }
    }

    public function createWarehouseTransfers(
        Request $request,
        PermissionService $permissionService,
        DistributionStockTransferSlipService $service
    ): JsonResponse {
        $user = $request->user();

        if (! $user || ! $this->canView($permissionService, $user, self::PERMISSION_DISTRIBUTION_ADJUSTMENT)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'remark' => 'nullable|string|max:500',
            'process_date' => 'required|date_format:Y-m-d',
            'rows' => 'required|array|min:1|max:100',
            'rows.*.row_id' => 'required|string|max:120',
            'rows.*.item_id' => 'nullable|integer|min:1',
            'rows.*.product_code' => 'nullable|string|max:100',
            'rows.*.item_contractor_id' => 'nullable|integer|min:1',
            'rows.*.contractor_id' => 'nullable|integer|min:1',
            'rows.*.supplier_id' => 'nullable|integer|min:1',
            'rows.*.order_to_code' => 'nullable|string|max:100',
            'rows.*.order_to' => 'nullable|string|max:255',
            'rows.*.supplier_code' => 'nullable|string|max:100',
            'rows.*.supplier' => 'nullable|string|max:255',
            'rows.*.order_date' => 'nullable|date',
            'rows.*.delivery_date' => 'nullable|date',
            'rows.*.delivery_course_id' => 'nullable|integer|min:1',
            'rows.*.memo' => 'nullable|string|max:500',
            'rows.*.allocations' => 'required|array|min:1|max:200',
            'rows.*.allocations.*.destination_id' => 'required|integer|min:1',
            'rows.*.allocations.*.destination_key' => 'nullable|string|max:50',
            'rows.*.allocations.*.quantity' => 'required|integer|min:0|max:99999999',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $selectedWarehouseId = $this->selectedKuraWarehouseId($user);

        if (! $selectedWarehouseId) {
            return $this->error('倉庫移動生成は華むすびの蔵センター選択時のみ実行できます。', 422, 'KURA_WAREHOUSE_REQUIRED');
        }

        $lockKey = $this->distributionGenerationLockKey('warehouse-transfer', $selectedWarehouseId);

        try {
            return $this->runWithDistributionGenerationLock(
                $lockKey,
                fn (): JsonResponse => $this->success(
                    $service->create(
                        selectedWarehouseId: $selectedWarehouseId,
                        createdBy: (int) $user->id,
                        rows: $request->input('rows', []),
                        remark: $request->input('remark') ?: '倉庫移動生成',
                        sourceWarehouseCode: self::HQ_TRANSFER_WAREHOUSE_CODE,
                        fixedProcessDate: Carbon::parse((string) $request->input('process_date'))->toDateString(),
                        preventDuplicateByDistributionRow: true
                    )
                )
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, 'DISTRIBUTION_WAREHOUSE_TRANSFER_ERROR');
        }
    }

    public function createOrderCandidates(
        Request $request,
        PermissionService $permissionService,
        DistributionOrderCandidateService $service
    ): JsonResponse {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'mode' => 'nullable|string|in:allocation,direct',
            'check_only' => 'nullable|boolean',
            'rows' => 'required|array|min:1|max:100',
            'rows.*.row_id' => 'required|string|max:120',
            'rows.*.item_id' => 'nullable|integer|min:1',
            'rows.*.product_code' => 'nullable|string|max:100',
            'rows.*.item_contractor_id' => 'nullable|integer|min:1',
            'rows.*.contractor_id' => 'nullable|integer|min:1',
            'rows.*.supplier_id' => 'nullable|integer|min:1',
            'rows.*.order_to_code' => 'nullable|string|max:100',
            'rows.*.order_to' => 'nullable|string|max:255',
            'rows.*.supplier_code' => 'nullable|string|max:100',
            'rows.*.supplier' => 'nullable|string|max:255',
            'rows.*.order_date' => 'nullable|date',
            'rows.*.delivery_date' => 'nullable|date',
            'rows.*.alloc_total' => 'nullable|integer|min:0|max:99999999',
            'rows.*.po_case' => 'nullable|integer|min:0|max:99999999',
            'rows.*.po_each' => 'nullable|integer|min:0|max:99999999',
            'rows.*.units_per_case' => 'nullable|integer|min:1|max:99999999',
            'rows.*.purchase_unit' => 'nullable|integer|min:1|max:99999999',
            'rows.*.order_point' => 'nullable|integer|min:0|max:99999999',
            'rows.*.allocations' => 'nullable|array|max:200',
            'rows.*.allocations.*.destination_id' => 'nullable|integer|min:1',
            'rows.*.allocations.*.destination_key' => 'nullable|string|max:50',
            'rows.*.allocations.*.destination_name' => 'nullable|string|max:100',
            'rows.*.allocations.*.quantity' => 'required_with:rows.*.allocations|integer|min:1|max:99999999',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $mode = (string) $request->input('mode', 'allocation');
        $requiredPermission = $mode === 'direct'
            ? self::PERMISSION_DIRECT_DISTRIBUTION
            : self::PERMISSION_DISTRIBUTION_ADJUSTMENT;

        if (! $this->canView($permissionService, $user, $requiredPermission)) {
            abort(403);
        }

        $selectedWarehouseId = $this->selectedKuraWarehouseId($user);

        if (! $selectedWarehouseId) {
            return $this->error('分配の発注候補生成は華むすびの蔵センター選択時のみ実行できます。', 422, 'KURA_WAREHOUSE_REQUIRED');
        }

        if ($request->boolean('check_only')) {
            return $this->success(
                $service->findExistingGenerated(
                    selectedWarehouseId: (int) $selectedWarehouseId,
                    rows: $request->input('rows', []),
                    mode: $mode
                )
            );
        }

        try {
            $lockKey = $this->distributionGenerationLockKey("order-candidates-{$mode}", (int) $selectedWarehouseId);

            return $this->runWithDistributionGenerationLock(
                $lockKey,
                fn (): JsonResponse => $this->success(
                    $mode === 'direct'
                        ? $service->createDirect(
                            selectedWarehouseId: (int) $selectedWarehouseId,
                            createdBy: (int) $user->id,
                            rows: $request->input('rows', [])
                        )
                        : $service->create(
                            selectedWarehouseId: (int) $selectedWarehouseId,
                            createdBy: (int) $user->id,
                            rows: $request->input('rows', [])
                        )
                    )
                );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, 'DISTRIBUTION_ORDER_CANDIDATE_ERROR');
        }
    }

    private function runWithDistributionGenerationLock(string $lockKey, callable $callback): JsonResponse
    {
        try {
            return Cache::store('file')->lock($lockKey, 30)->block(1, $callback);
        } catch (LockTimeoutException) {
            return $this->error(
                '同じ生成処理が実行中です。完了してから再度実行してください。',
                429,
                'DISTRIBUTION_GENERATION_LOCKED'
            );
        }
    }

    private function distributionGenerationLockKey(string $operation, int $selectedWarehouseId): string
    {
        return implode(':', [
            'distribution',
            'generation',
            $operation,
            $selectedWarehouseId,
        ]);
    }

    private function canView(PermissionService $permissionService, $user, string $permission): bool
    {
        return $permissionService->check($user, $permission);
    }

    private function canSearch(PermissionService $permissionService, $user): bool
    {
        foreach (self::PERMISSIONS as $permission) {
            if ($this->canView($permissionService, $user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function selectedKuraWarehouseId($user): ?int
    {
        $warehouseId = (int) ($user->getSelectedWarehouseId() ?: 0);
        if ($warehouseId <= 0) {
            return null;
        }

        $exists = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('id', $warehouseId)
            ->where('code', self::HQ_TRANSFER_WAREHOUSE_CODE)
            ->where('is_active', true)
            ->exists();

        return $exists ? $warehouseId : null;
    }

    private function buildProductCandidateRows(
        $user,
        Collection $items,
        Carbon $orderDate,
        DistributionDeliveryDateService $deliveryDateService,
        ?array $orderToContractorIds = null,
        ?array $supplierIds = null,
        ?int $limit = 50
    ): array {
        if ($items->isEmpty()) {
            return [];
        }

        $clientId = (int) config('app.client_id');
        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $selectedWarehouseId = $user->getSelectedWarehouseId();
        $realWarehouseId = $selectedWarehouseId
            ? WarehouseResolver::resolveRealWarehouseId((int) $selectedWarehouseId)
            : null;
        $incomingWarehouseIds = $selectedWarehouseId
            ? WarehouseResolver::resolveAllWarehouseIds((int) $selectedWarehouseId)
            : [];

        $janCodes = $this->getJanCodesByItem($clientId, $itemIds);
        $contractors = $this->getContractorsByItem($clientId, $itemIds, $selectedWarehouseId, $realWarehouseId, $orderToContractorIds, $supplierIds);
        $contractorOptions = $contractors
            ->flatMap(fn (Collection $rows): Collection => $rows)
            ->keyBy(fn ($contractor): string => $this->contractorOptionKey((int) $contractor->item_id, $contractor));
        $suggestedDeliveryDates = $selectedWarehouseId
            ? $deliveryDateService->resolveForItems((int) $selectedWarehouseId, $contractorOptions, $orderDate)
            : collect();
        $stockSummaries = $realWarehouseId
            ? $this->getStockSummariesByItem((int) $realWarehouseId, $itemIds)
            : collect();
        $shelfLocations = $realWarehouseId
            ? $this->getShelfLocationsByItem((int) $realWarehouseId, $itemIds)
            : collect();
        $incomingSummaries = $incomingWarehouseIds !== []
            ? $this->getIncomingSummariesByItem($incomingWarehouseIds, $itemIds)
            : collect();
        $incomingSummariesByContractor = $incomingWarehouseIds !== []
            ? $this->getIncomingSummariesByContractor($incomingWarehouseIds, $itemIds)
            : collect();
        $lastOrderDates = $incomingWarehouseIds !== []
            ? $this->getLastOrderDatesByContractor($incomingWarehouseIds, $itemIds)
            : collect();
        $salesSummaries = $selectedWarehouseId
            ? $this->getSalesSummariesByItem((int) $selectedWarehouseId, $itemIds)
            : collect();

        $rows = $items
            ->flatMap(function ($item) use (
                $janCodes,
                $contractors,
                $suggestedDeliveryDates,
                $stockSummaries,
                $shelfLocations,
                $incomingSummaries,
                $incomingSummariesByContractor,
                $lastOrderDates,
                $salesSummaries,
                $selectedWarehouseId,
                $realWarehouseId
            ): Collection {
                $itemId = (int) $item->id;
                $stock = $stockSummaries->get($itemId, $this->emptyStockSummary());
                $shelfLocation = (string) ($shelfLocations->get($itemId) ?? '');
                $sales = $salesSummaries->get($itemId, ['sales_week1' => 0, 'sales_week2' => 0, 'sales_week3' => 0]);
                $contractorRows = $contractors->get($itemId, collect());

                if ($contractorRows->isEmpty()) {
                    $contractorRows = collect([null]);
                }

                return $contractorRows->map(function ($contractor) use (
                    $item,
                    $itemId,
                    $janCodes,
                    $suggestedDeliveryDates,
                    $stock,
                    $shelfLocation,
                    $incomingSummaries,
                    $incomingSummariesByContractor,
                    $lastOrderDates,
                    $sales,
                    $selectedWarehouseId,
                    $realWarehouseId
                ): array {
                    $optionKey = $this->contractorOptionKey($itemId, $contractor);
                    $scheduleKey = $this->contractorScheduleKey($itemId, $contractor);
                    $suggestedDeliveryDate = $suggestedDeliveryDates->get($optionKey);
                    $incoming = $contractor
                        ? $incomingSummariesByContractor->get($scheduleKey, ['incoming_quantity' => 0, 'incoming_count' => 0])
                        : $incomingSummaries->get($itemId, ['incoming_quantity' => 0, 'incoming_count' => 0]);
                    $lastOrderDate = $contractor
                        ? (string) ($lastOrderDates->get($scheduleKey) ?? '')
                        : '';
                    $unitsPerCase = (int) ($item->capacity_case ?? 0);
                    $purchaseUnit = (int) ($contractor->purchase_unit ?? 0);

                    if ($unitsPerCase <= 0) {
                        $unitsPerCase = $purchaseUnit;
                    }

                    return [
                        'candidateKey' => $optionKey,
                        'id' => $itemId,
                        'itemContractorId' => $contractor ? (int) ($contractor->item_contractor_id ?? 0) : null,
                        'contractorId' => $contractor ? (int) ($contractor->contractor_id ?? 0) : null,
                        'supplierId' => $contractor ? (int) ($contractor->supplier_id ?? 0) : null,
                        'contractorWarehouseId' => $contractor ? (int) ($contractor->warehouse_id ?? 0) : null,
                        'code' => (string) $item->code,
                        'jan' => (string) ($janCodes->get($itemId) ?? ''),
                        'name' => (string) $item->name,
                        'volume' => $this->formatVolume($item->volume, $item->volume_unit),
                        'unitsPerCase' => max(1, $unitsPerCase),
                        'purchaseUnit' => max(1, $purchaseUnit ?: 1),
                        'orderPoint' => $contractor ? (int) ($contractor->safety_stock ?? 0) : 0,
                        'shelfLocation' => $shelfLocation,
                        'lastOrderDate' => $lastOrderDate,
                        'salesWeek1' => (int) ($sales['sales_week1'] ?? 0),
                        'salesWeek2' => (int) ($sales['sales_week2'] ?? 0),
                        'salesWeek3' => (int) ($sales['sales_week3'] ?? 0),
                        'supplier' => (string) ($contractor->supplier_name ?? ''),
                        'supplierCode' => (string) ($contractor->supplier_code ?? ''),
                        'orderTo' => (string) ($contractor->contractor_name ?? ''),
                        'orderToCode' => (string) ($contractor->contractor_code ?? ''),
                        'itemContractorNote' => (string) ($contractor->item_contractor_note ?? ''),
                        'suggestedDeliveryDate' => (string) ($suggestedDeliveryDate['suggested_delivery_date'] ?? ''),
                        'deliveryDateCalculation' => [
                            'acceptedOrderDate' => (string) ($suggestedDeliveryDate['accepted_order_date'] ?? ''),
                            'originalDeliveryDate' => (string) ($suggestedDeliveryDate['original_delivery_date'] ?? ''),
                            'leadTimeDays' => (int) ($suggestedDeliveryDate['lead_time_days'] ?? 0),
                            'shiftedDays' => (int) ($suggestedDeliveryDate['shifted_days'] ?? 0),
                            'shiftReasons' => $suggestedDeliveryDate['shift_reasons'] ?? [],
                        ],
                        'stock' => [
                            'selectedWarehouseId' => $selectedWarehouseId ? (int) $selectedWarehouseId : null,
                            'realWarehouseId' => $realWarehouseId ? (int) $realWarehouseId : null,
                            'actualQuantity' => (int) $stock['actual_quantity'],
                            'theoreticalQuantity' => (int) $stock['theoretical_quantity'],
                            'reservedQuantity' => (int) $stock['reserved_quantity'],
                            'incomingQuantity' => (int) $incoming['incoming_quantity'],
                            'incomingCount' => (int) $incoming['incoming_count'],
                        ],
                    ];
                });
            })
            ->values();

        if ($limit !== null) {
            $rows = $rows->take($limit);
        }

        return $rows->all();
    }

    /**
     * @param  array<string, string>  $searchesByKey
     * @return array<string, array<int, int>>
     */
    private function resolveExactItemIdsBySearch(int $clientId, array $searchesByKey): array
    {
        $variantToKeys = [];
        $itemIdsByKey = [];

        foreach ($searchesByKey as $key => $search) {
            $itemIdsByKey[(string) $key] = [];

            foreach ($this->productSearchVariants((string) $search) as $variant) {
                $variantToKeys[$variant] ??= [];
                $variantToKeys[$variant][] = (string) $key;
            }
        }

        if ($variantToKeys === []) {
            return $itemIdsByKey;
        }

        $variants = array_keys($variantToKeys);
        $addMatch = function ($value, int $itemId) use (&$itemIdsByKey, $variantToKeys): void {
            $value = $this->normalizeSearchText((string) $value);
            foreach ($this->productSearchVariants($value) as $variant) {
                foreach ($variantToKeys[$variant] ?? [] as $key) {
                    $itemIdsByKey[$key][] = $itemId;
                }
            }
        };

        DB::connection('sakemaru')
            ->table('items')
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->whereIn('code', $variants)
            ->get(['id', 'code'])
            ->each(fn ($row) => $addMatch($row->code, (int) $row->id));

        DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->whereIn('code_type', array_map(fn (EItemSearchCodeType $type): string => $type->value, EItemSearchCodeType::cases()))
            ->whereIn('search_string', $variants)
            ->get(['item_id', 'search_string'])
            ->each(fn ($row) => $addMatch($row->search_string, (int) $row->item_id));

        DB::connection('sakemaru')
            ->table('item_quantity_information as iqi')
            ->join('items as i', 'i.id', '=', 'iqi.item_id')
            ->where('i.client_id', $clientId)
            ->where('i.is_active', true)
            ->where(function ($query) use ($variants): void {
                $query->whereIn('iqi.product_code', $variants)
                    ->orWhereIn('iqi.own_code', $variants);
            })
            ->get(['iqi.item_id', 'iqi.product_code', 'iqi.own_code'])
            ->each(function ($row) use ($addMatch): void {
                $addMatch($row->product_code, (int) $row->item_id);
                $addMatch($row->own_code, (int) $row->item_id);
            });

        foreach ($itemIdsByKey as $key => $itemIds) {
            $itemIdsByKey[$key] = array_values(array_unique(array_map('intval', $itemIds)));
        }

        return $itemIdsByKey;
    }

    /**
     * @return array<int, string>
     */
    private function productSearchVariants(string $search): array
    {
        $normalized = $this->normalizeSearchText($search);
        if ($normalized === '') {
            return [];
        }

        $variants = [$normalized];

        if (ctype_digit($normalized)) {
            $withoutLeadingZeros = ltrim($normalized, '0') ?: '0';
            $variants[] = $withoutLeadingZeros;

            if (strlen($withoutLeadingZeros) <= 13) {
                $variants[] = str_pad($withoutLeadingZeros, 13, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique(array_filter($variants, fn (string $variant): bool => $variant !== '')));
    }

    /**
     * @param  array<int, int>  $itemIds
     */
    private function loadItemsByIds(int $clientId, array $itemIds): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('items as i')
            ->where('i.client_id', $clientId)
            ->where('i.is_active', true)
            ->whereIn('i.id', array_values(array_unique($itemIds)))
            ->orderBy('i.code')
            ->get([
                'i.id',
                'i.code',
                'i.name',
                'i.volume',
                'i.volume_unit',
                'i.capacity_case',
            ]);
    }

    private function formatDestinationKey($code, int $id): string
    {
        $key = trim((string) ($code ?? ''));

        if ($key === '') {
            return (string) $id;
        }

        if (ctype_digit($key) && strlen($key) < 2) {
            return str_pad($key, 2, '0', STR_PAD_LEFT);
        }

        return $key;
    }

    private function searchItems(
        int $clientId,
        string $search,
        int $limit,
        ?array $contractorIds = null,
        ?array $supplierIds = null,
        ?int $category1Id = null,
        ?int $category2Id = null,
        ?int $category3Id = null
    ): Collection {
        $normalizedSearch = $this->normalizeSearchText($search);
        $like = "%{$normalizedSearch}%";
        $prefixLike = "{$normalizedSearch}%";
        $searchLength = function_exists('mb_strlen')
            ? mb_strlen($normalizedSearch)
            : strlen($normalizedSearch);
        $isNumericSearch = ctype_digit($normalizedSearch);
        $shouldSearchCodeInformation = $normalizedSearch !== '';
        $shouldSearchName = ! $isNumericSearch && $searchLength >= 2;
        $numericSearchValues = [$normalizedSearch];
        $numericSearchWithoutLeadingZeros = ltrim($normalizedSearch, '0');
        if ($isNumericSearch && $numericSearchWithoutLeadingZeros !== '' && $numericSearchWithoutLeadingZeros !== $normalizedSearch) {
            $numericSearchValues[] = $numericSearchWithoutLeadingZeros;
        }
        $paddedNumericSearch = null;
        if ($isNumericSearch) {
            $numericSearchBase = $numericSearchWithoutLeadingZeros !== '' ? $numericSearchWithoutLeadingZeros : '0';
            if (strlen($numericSearchBase) <= 13) {
                $paddedNumericSearch = str_pad($numericSearchBase, 13, '0', STR_PAD_LEFT);
                $numericSearchValues[] = $paddedNumericSearch;
            }
        }
        $numericSearchValues = array_values(array_unique($numericSearchValues));
        $searchCodeTypes = array_map(
            fn (EItemSearchCodeType $type): string => $type->value,
            EItemSearchCodeType::cases()
        );

        $candidateLimit = max($limit * 2, 100);
        $rankedItemIds = [];

        if (
            $normalizedSearch === ''
            && (
                $contractorIds !== null
                || $supplierIds !== null
                || $this->hasItemCategoryFilter($category1Id, $category2Id, $category3Id)
            )
        ) {
            $filteredItemQuery = DB::connection('sakemaru')
                ->table('items as i')
                ->where('i.client_id', $clientId)
                ->where('i.is_active', true)
                ->select('i.id')
                ->selectRaw('0 as match_rank')
                ->orderBy('i.code')
                ->limit($candidateLimit);

            if ($contractorIds !== null || $supplierIds !== null) {
                $this->applyItemContractorFilter($filteredItemQuery, $clientId, $contractorIds, $supplierIds);
            }

            $this->applyItemCategoryFilters($filteredItemQuery, $category1Id, $category2Id, $category3Id);

            $this->mergeRankedItemCandidates($rankedItemIds, $filteredItemQuery->get());

            return $this->loadRankedItems($clientId, $rankedItemIds, $limit);
        }

        $itemQuery = DB::connection('sakemaru')
            ->table('items as i')
            ->where('i.client_id', $clientId)
            ->where('i.is_active', true)
            ->where(function ($query) use ($like, $prefixLike, $shouldSearchName) {
                $query->where('i.code', 'like', $prefixLike);

                if ($shouldSearchName) {
                    $query->orWhere('i.name', 'like', $like);
                }
            })
            ->select('i.id')
            ->selectRaw('CASE WHEN i.code = ? THEN 0 ELSE 1 END as match_rank', [$normalizedSearch])
            ->orderBy('match_rank')
            ->orderBy('i.code')
            ->limit($candidateLimit);

        if ($contractorIds !== null || $supplierIds !== null) {
            $this->applyItemContractorFilter($itemQuery, $clientId, $contractorIds, $supplierIds);
        }
        $this->applyItemCategoryFilters($itemQuery, $category1Id, $category2Id, $category3Id);

        $this->mergeRankedItemCandidates($rankedItemIds, $itemQuery->get());

        if ($shouldSearchCodeInformation) {
            $searchInfoQuery = DB::connection('sakemaru')
                ->table('item_search_information as isi')
                ->where('isi.client_id', $clientId)
                ->whereIn('isi.code_type', $searchCodeTypes)
                ->where('isi.is_active', true)
                ->where(function ($query) use ($numericSearchValues, $paddedNumericSearch, $prefixLike) {
                    $query->where('isi.search_string', 'like', $prefixLike);

                    if (count($numericSearchValues) > 1) {
                        $query->orWhereIn('isi.search_string', $numericSearchValues);
                    }

                    if ($paddedNumericSearch !== null) {
                        $query->orWhereRaw('LPAD(isi.search_string, 13, "0") = ?', [$paddedNumericSearch]);
                    }
                })
                ->select('isi.item_id as id')
                ->selectRaw('0 as match_rank')
                ->groupBy('isi.item_id')
                ->limit($candidateLimit);

            if ($contractorIds !== null || $supplierIds !== null) {
                $this->applyItemContractorFilter($searchInfoQuery, $clientId, $contractorIds, $supplierIds, 'isi.item_id');
            }
            $this->applyItemCategoryExistsFilter($searchInfoQuery, $clientId, $category1Id, $category2Id, $category3Id, 'isi.item_id');

            $this->mergeRankedItemCandidates($rankedItemIds, $searchInfoQuery->get());
        }

        $quantityQuery = DB::connection('sakemaru')
            ->table('item_quantity_information as iqi')
            ->join('items as i', 'i.id', '=', 'iqi.item_id')
            ->where('i.client_id', $clientId)
            ->where('i.is_active', true)
            ->where(function ($query) use ($numericSearchValues, $paddedNumericSearch, $prefixLike) {
                $query->where('iqi.product_code', 'like', $prefixLike)
                    ->orWhere('iqi.own_code', 'like', $prefixLike);

                if (count($numericSearchValues) > 1) {
                    $query->orWhereIn('iqi.product_code', $numericSearchValues)
                        ->orWhereIn('iqi.own_code', $numericSearchValues);
                }

                if ($paddedNumericSearch !== null) {
                    $query->orWhereRaw('LPAD(iqi.product_code, 13, "0") = ?', [$paddedNumericSearch])
                        ->orWhereRaw('LPAD(iqi.own_code, 13, "0") = ?', [$paddedNumericSearch]);
                }
            })
            ->select('iqi.item_id as id')
            ->selectRaw('0 as match_rank')
            ->groupBy('iqi.item_id')
            ->orderBy('match_rank')
            ->limit($candidateLimit);

        if ($contractorIds !== null || $supplierIds !== null) {
            $this->applyItemContractorFilter($quantityQuery, $clientId, $contractorIds, $supplierIds);
        }
        $this->applyItemCategoryFilters($quantityQuery, $category1Id, $category2Id, $category3Id);

        $this->mergeRankedItemCandidates($rankedItemIds, $quantityQuery->get());

        return $this->loadRankedItems($clientId, $rankedItemIds, $limit);
    }

    /**
     * @param  array<int, int>  $rankedItemIds
     */
    private function mergeRankedItemCandidates(array &$rankedItemIds, Collection $candidates): void
    {
        foreach ($candidates as $candidate) {
            $itemId = (int) ($candidate->id ?? 0);
            if ($itemId < 1) {
                continue;
            }

            $rank = (int) ($candidate->match_rank ?? 1);
            if (! isset($rankedItemIds[$itemId]) || $rank < $rankedItemIds[$itemId]) {
                $rankedItemIds[$itemId] = $rank;
            }
        }
    }

    /**
     * @param  array<int, int>  $rankedItemIds
     */
    private function loadRankedItems(int $clientId, array $rankedItemIds, int $limit): Collection
    {
        if ($rankedItemIds === []) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('items as i')
            ->where('i.client_id', $clientId)
            ->where('i.is_active', true)
            ->whereIn('i.id', array_keys($rankedItemIds))
            ->get([
                'i.id',
                'i.code',
                'i.name',
                'i.volume',
                'i.volume_unit',
                'i.capacity_case',
            ])
            ->sort(function ($left, $right) use ($rankedItemIds): int {
                $rankCompare = ($rankedItemIds[(int) $left->id] ?? 9) <=> ($rankedItemIds[(int) $right->id] ?? 9);
                if ($rankCompare !== 0) {
                    return $rankCompare;
                }

                return strcmp((string) $left->code, (string) $right->code);
            })
            ->take($limit)
            ->values();
    }

    private function normalizeSearchText(string $search): string
    {
        $normalizedSearch = function_exists('mb_convert_kana')
            ? mb_convert_kana($search, 'as')
            : $search;

        return trim($normalizedSearch);
    }

    private function hasItemCategoryFilter(?int $category1Id, ?int $category2Id, ?int $category3Id): bool
    {
        return $category1Id !== null || $category2Id !== null || $category3Id !== null;
    }

    private function applyItemCategoryFilters(
        $query,
        ?int $category1Id = null,
        ?int $category2Id = null,
        ?int $category3Id = null,
        string $itemAlias = 'i'
    ): void {
        if ($category1Id !== null) {
            $query->where("{$itemAlias}.item_category1_id", $category1Id);
        }

        if ($category2Id !== null) {
            $query->where("{$itemAlias}.item_category2_id", $category2Id);
        }

        if ($category3Id !== null) {
            $query->where("{$itemAlias}.item_category3_id", $category3Id);
        }
    }

    private function applyItemCategoryExistsFilter(
        $query,
        int $clientId,
        ?int $category1Id = null,
        ?int $category2Id = null,
        ?int $category3Id = null,
        string $itemColumn = 'i.id'
    ): void {
        if (! $this->hasItemCategoryFilter($category1Id, $category2Id, $category3Id)) {
            return;
        }

        $query->whereExists(function ($subQuery) use ($clientId, $category1Id, $category2Id, $category3Id, $itemColumn) {
            $subQuery->selectRaw('1')
                ->from('items as category_filter_items')
                ->whereColumn('category_filter_items.id', $itemColumn)
                ->where('category_filter_items.client_id', $clientId)
                ->where('category_filter_items.is_active', true);

            if ($category1Id !== null) {
                $subQuery->where('category_filter_items.item_category1_id', $category1Id);
            }

            if ($category2Id !== null) {
                $subQuery->where('category_filter_items.item_category2_id', $category2Id);
            }

            if ($category3Id !== null) {
                $subQuery->where('category_filter_items.item_category3_id', $category3Id);
            }
        });
    }

    /**
     * @return array<int, int>
     */
    private function searchOrderToContractorIds(int $clientId, string $search): array
    {
        $normalizedSearch = $this->normalizeSearchText($search);
        $searchLength = function_exists('mb_strlen')
            ? mb_strlen($normalizedSearch)
            : strlen($normalizedSearch);

        if ($searchLength < 2) {
            return [];
        }

        $like = "%{$normalizedSearch}%";
        $digits = preg_replace('/\D/', '', $normalizedSearch);
        $exactCode = $digits !== '' ? (int) $digits : -1;
        $codeLike = $digits !== '' ? "{$digits}%" : $like;

        if ($digits !== '') {
            $exactIds = DB::connection('sakemaru')
                ->table('contractors')
                ->where('client_id', $clientId)
                ->where('is_active', true)
                ->where('code', $exactCode)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($exactIds !== []) {
                return $exactIds;
            }
        }

        return DB::connection('sakemaru')
            ->table('contractors')
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->where(function ($query) use ($exactCode, $codeLike, $like) {
                $query->where('code', $exactCode)
                    ->orWhereRaw('CAST(code AS CHAR) LIKE ?', [$codeLike])
                    ->orWhere('name', 'like', $like)
                    ->orWhere('nickname', 'like', $like);
            })
            ->orderByRaw(
                'CASE
                    WHEN code = ? THEN 0
                    WHEN name LIKE ? THEN 1
                    WHEN nickname LIKE ? THEN 1
                    ELSE 2
                END',
                [$exactCode, $like, $like]
            )
            ->orderBy('code')
            ->limit(100)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function searchSupplierIds(int $clientId, string $search): array
    {
        $normalizedSearch = $this->normalizeSearchText($search);
        $searchLength = function_exists('mb_strlen')
            ? mb_strlen($normalizedSearch)
            : strlen($normalizedSearch);

        if ($searchLength < 2) {
            return [];
        }

        $like = "%{$normalizedSearch}%";
        $digits = preg_replace('/\D/', '', $normalizedSearch);
        $codeLike = $digits !== '' ? "{$digits}%" : $like;

        return DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 'p.id', '=', 's.partner_id')
            ->where('s.client_id', $clientId)
            ->where('p.client_id', $clientId)
            ->where('p.is_active', true)
            ->where(function ($query) use ($digits, $codeLike, $like) {
                if ($digits !== '') {
                    $query->whereRaw('CAST(p.code AS CHAR) = ?', [$digits])
                        ->orWhereRaw('CAST(p.code AS CHAR) LIKE ?', [$codeLike]);
                }

                $query->orWhere('p.name', 'like', $like)
                    ->orWhere('p.nickname', 'like', $like)
                    ->orWhere('p.abbreviation', 'like', $like);
            })
            ->orderByRaw(
                'CASE
                    WHEN CAST(p.code AS CHAR) = ? THEN 0
                    WHEN p.name LIKE ? THEN 1
                    WHEN p.nickname LIKE ? THEN 1
                    WHEN p.abbreviation LIKE ? THEN 1
                    ELSE 2
                END',
                [$digits, $like, $like, $like]
            )
            ->orderBy('p.code')
            ->limit(100)
            ->pluck('s.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>|null  $contractorIds
     * @param  array<int, int>|null  $supplierIds
     */
    private function applyItemContractorFilter(
        $query,
        int $clientId,
        ?array $contractorIds = null,
        ?array $supplierIds = null,
        string $itemColumn = 'i.id'
    ): void {
        $query->whereExists(function ($subQuery) use ($clientId, $contractorIds, $supplierIds, $itemColumn) {
            $subQuery->selectRaw('1')
                ->from('item_contractors as ic_filter')
                ->whereColumn('ic_filter.item_id', $itemColumn)
                ->where('ic_filter.client_id', $clientId);

            if ($contractorIds !== null) {
                $subQuery->whereIn('ic_filter.contractor_id', $contractorIds);
            }

            if ($supplierIds !== null) {
                $subQuery->whereIn('ic_filter.supplier_id', $supplierIds);
            }
        });
    }

    private function getJanCodesByItem(int $clientId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('client_id', $clientId)
            ->whereIn('item_id', $itemIds)
            ->where('code_type', EItemSearchCodeType::JAN->value)
            ->where('is_active', true)
            ->whereNotNull('search_string')
            ->orderBy('priority')
            ->orderByDesc('updated_at')
            ->get(['item_id', 'search_string'])
            ->groupBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($rows) => $rows->first()->search_string);
    }

    private function getContractorsByItem(
        int $clientId,
        array $itemIds,
        ?int $warehouseId = null,
        ?int $realWarehouseId = null,
        ?array $contractorIds = null,
        ?array $supplierIds = null,
        ?array $excludedContractorIds = null
    ): Collection {
        $selectColumns = [
            'ic.id as item_contractor_id',
            'ic.item_id',
            'ic.warehouse_id',
            'ic.contractor_id',
            'ic.supplier_id',
            'ic.safety_stock',
            'ic.purchase_unit',
            'c.code as contractor_code',
            'c.name as contractor_name',
            'supplier_partners.code as supplier_code',
            'supplier_partners.name as supplier_name',
        ];

        $selectColumns[] = Schema::connection('sakemaru')->hasColumn('item_contractors', 'note')
            ? 'ic.note as item_contractor_note'
            : DB::raw("'' as item_contractor_note");

        $query = DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->leftJoin('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'ic.supplier_id')
            ->leftJoin('partners as supplier_partners', 'supplier_partners.id', '=', 's.partner_id')
            ->where('ic.client_id', $clientId)
            ->whereIn('ic.item_id', $itemIds)
            ->select($selectColumns)
            ->orderBy('ic.item_id');

        if ($contractorIds !== null) {
            $query->whereIn('ic.contractor_id', $contractorIds);
        }

        if ($supplierIds !== null) {
            $query->whereIn('ic.supplier_id', $supplierIds);
        }

        if ($excludedContractorIds !== null && $excludedContractorIds !== []) {
            $query->whereNotIn('ic.contractor_id', $excludedContractorIds);
        }

        $warehousePriorityBindings = [];
        $warehousePriorityCases = [];

        if ($warehouseId) {
            $warehousePriorityCases[] = 'WHEN ic.warehouse_id = ? THEN 0';
            $warehousePriorityBindings[] = $warehouseId;
        }

        if ($realWarehouseId && $realWarehouseId !== $warehouseId) {
            $warehousePriorityCases[] = 'WHEN ic.warehouse_id = ? THEN 1';
            $warehousePriorityBindings[] = $realWarehouseId;
        }

        if ($warehousePriorityCases !== []) {
            $query->orderByRaw(
                'CASE '.implode(' ', $warehousePriorityCases).' ELSE 2 END',
                $warehousePriorityBindings
            );
        }

        $rows = $query
            ->orderByDesc('ic.is_auto_order')
            ->orderBy('ic.id')
            ->get()
            ->groupBy(fn ($row) => (int) $row->item_id);

        return $rows->map(function (Collection $itemRows) use ($warehouseId, $realWarehouseId): Collection {
            $bestPriority = $itemRows
                ->map(fn ($row): int => $this->contractorWarehousePriority($row, $warehouseId, $realWarehouseId))
                ->min();

            return $itemRows
                ->filter(fn ($row): bool => $this->contractorWarehousePriority($row, $warehouseId, $realWarehouseId) === $bestPriority)
                ->values();
        });
    }

    private function contractorWarehousePriority(object $contractor, ?int $warehouseId, ?int $realWarehouseId): int
    {
        $contractorWarehouseId = (int) ($contractor->warehouse_id ?? 0);

        if ($warehouseId && $contractorWarehouseId === (int) $warehouseId) {
            return 0;
        }

        if ($realWarehouseId && $contractorWarehouseId === (int) $realWarehouseId) {
            return 1;
        }

        return 2;
    }

    private function contractorOptionKey(int $itemId, ?object $contractor): string
    {
        if (! $contractor) {
            return "{$itemId}:0:0:0:0";
        }

        return implode(':', [
            $itemId,
            (int) ($contractor->item_contractor_id ?? 0),
            (int) ($contractor->contractor_id ?? 0),
            (int) ($contractor->supplier_id ?? 0),
            (int) ($contractor->warehouse_id ?? 0),
        ]);
    }

    private function contractorScheduleKey(int $itemId, ?object $contractor): string
    {
        if (! $contractor) {
            return "{$itemId}:0:0:0";
        }

        return implode(':', [
            $itemId,
            (int) ($contractor->contractor_id ?? 0),
            (int) ($contractor->supplier_id ?? 0),
            (int) ($contractor->warehouse_id ?? 0),
        ]);
    }

    private function getStockSummariesByItem(int $warehouseId, array $itemIds): Collection
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
                DB::raw('COALESCE(SUM(rs.reserved_quantity), 0) as reserved_quantity'),
            ])
            ->keyBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($row) => [
                'actual_quantity' => (int) $row->actual_quantity,
                'theoretical_quantity' => (int) $row->theoretical_quantity,
                'reserved_quantity' => (int) $row->reserved_quantity,
            ]);
    }

    private function getShelfLocationsByItem(int $warehouseId, array $itemIds): Collection
    {
        $defaultLocations = DB::connection('sakemaru')
            ->table('item_incoming_default_locations as idl')
            ->join('locations as l', 'l.id', '=', 'idl.location_id')
            ->where('idl.warehouse_id', $warehouseId)
            ->whereIn('idl.item_id', $itemIds)
            ->orderBy('l.code1')
            ->orderBy('l.code2')
            ->orderBy('l.code3')
            ->get([
                'idl.item_id',
                'l.code1',
                'l.code2',
                'l.code3',
            ])
            ->groupBy(fn ($row): int => (int) $row->item_id)
            ->map(fn (Collection $rows): string => $this->formatLocationCode($rows->first()));

        $stockLocations = DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->whereIn('rs.item_id', $itemIds)
            ->where('rsl.status', 'ACTIVE')
            ->where('rsl.current_quantity', '>', 0)
            ->groupBy('rs.item_id', 'l.id', 'l.code1', 'l.code2', 'l.code3')
            ->orderBy('l.code1')
            ->orderBy('l.code2')
            ->orderBy('l.code3')
            ->get([
                'rs.item_id',
                'l.code1',
                'l.code2',
                'l.code3',
                DB::raw('COALESCE(SUM(rsl.current_quantity), 0) as location_quantity'),
            ])
            ->groupBy(fn ($row): int => (int) $row->item_id)
            ->map(fn (Collection $rows): string => $this->formatLocationCode($rows->first()));

        return $defaultLocations->union($stockLocations);
    }

    private function formatLocationCode(?object $location): string
    {
        if (! $location) {
            return '';
        }

        $parts = [
            trim((string) ($location->code1 ?? '')),
            trim((string) ($location->code2 ?? '')),
            trim((string) ($location->code3 ?? '')),
        ];

        return implode('-', array_values(array_filter($parts, fn (string $part): bool => $part !== '')));
    }

    private function emptyStockSummary(): array
    {
        return [
            'actual_quantity' => 0,
            'theoretical_quantity' => 0,
            'reserved_quantity' => 0,
        ];
    }

    private function getIncomingSummariesByItem(array $warehouseIds, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('item_id', $itemIds)
            ->whereIn('status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
                IncomingScheduleStatus::TRANSMITTED->value,
            ])
            ->whereColumn('expected_quantity', '>', 'received_quantity')
            ->groupBy('item_id')
            ->get([
                'item_id',
                DB::raw('COALESCE(SUM(expected_quantity - received_quantity), 0) as incoming_quantity'),
                DB::raw('COUNT(*) as incoming_count'),
            ])
            ->keyBy(fn ($row) => (int) $row->item_id)
            ->map(fn ($row) => [
                'incoming_quantity' => (int) $row->incoming_quantity,
                'incoming_count' => (int) $row->incoming_count,
            ]);
    }

    private function getIncomingSummariesByContractor(array $warehouseIds, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('item_id', $itemIds)
            ->whereIn('status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
                IncomingScheduleStatus::TRANSMITTED->value,
            ])
            ->whereColumn('expected_quantity', '>', 'received_quantity')
            ->groupBy('item_id', 'contractor_id', 'supplier_id', 'warehouse_id')
            ->get([
                'item_id',
                'contractor_id',
                'supplier_id',
                'warehouse_id',
                DB::raw('COALESCE(SUM(expected_quantity - received_quantity), 0) as incoming_quantity'),
                DB::raw('COUNT(*) as incoming_count'),
            ])
            ->keyBy(fn ($row): string => $this->contractorScheduleKey((int) $row->item_id, $row))
            ->map(fn ($row) => [
                'incoming_quantity' => (int) $row->incoming_quantity,
                'incoming_count' => (int) $row->incoming_count,
            ]);
    }

    private function getLastOrderDatesByContractor(array $warehouseIds, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('item_id', $itemIds)
            ->whereNotNull('order_date')
            ->whereNull('cancelled_at')
            ->groupBy('item_id', 'contractor_id', 'supplier_id', 'warehouse_id')
            ->get([
                'item_id',
                'contractor_id',
                'supplier_id',
                'warehouse_id',
                DB::raw('MAX(order_date) as last_order_date'),
            ])
            ->keyBy(fn ($row): string => $this->contractorScheduleKey((int) $row->item_id, $row))
            ->map(fn ($row): string => (string) $row->last_order_date);
    }

    private function getSalesSummariesByItem(int $warehouseId, array $itemIds): Collection
    {
        $today = Carbon::today();
        $week1Start = $today->copy()->subDays(6)->toDateString();
        $week1End = $today->toDateString();
        $week2Start = $today->copy()->subDays(13)->toDateString();
        $week2End = $today->copy()->subDays(7)->toDateString();
        $week3Start = $today->copy()->subDays(20)->toDateString();
        $week3End = $today->copy()->subDays(14)->toDateString();

        return DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->whereBetween('business_date', [$week3Start, $week1End])
            ->groupBy('item_id')
            ->get([
                'item_id',
                DB::raw("COALESCE(SUM(CASE WHEN business_date BETWEEN '{$week1Start}' AND '{$week1End}' THEN shipped_piece_qty ELSE 0 END), 0) as sales_week1"),
                DB::raw("COALESCE(SUM(CASE WHEN business_date BETWEEN '{$week2Start}' AND '{$week2End}' THEN shipped_piece_qty ELSE 0 END), 0) as sales_week2"),
                DB::raw("COALESCE(SUM(CASE WHEN business_date BETWEEN '{$week3Start}' AND '{$week3End}' THEN shipped_piece_qty ELSE 0 END), 0) as sales_week3"),
            ])
            ->keyBy(fn ($row): int => (int) $row->item_id)
            ->map(fn ($row): array => [
                'sales_week1' => (int) $row->sales_week1,
                'sales_week2' => (int) $row->sales_week2,
                'sales_week3' => (int) $row->sales_week3,
            ]);
    }

    private function searchArrivalSchedules(int $itemId, string $productCode, int $limit): array
    {
        $query = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules as ios')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ios.warehouse_id')
            ->leftJoin('contractors as c', 'c.id', '=', 'ios.contractor_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'ios.supplier_id')
            ->leftJoin('partners as supplier_partners', 'supplier_partners.id', '=', 's.partner_id')
            ->whereIn('ios.status', [
                IncomingScheduleStatus::PENDING->value,
                IncomingScheduleStatus::PARTIAL->value,
                IncomingScheduleStatus::TRANSMITTED->value,
            ])
            ->whereColumn('ios.expected_quantity', '>', 'ios.received_quantity');

        if ($itemId > 0) {
            $query->where('ios.item_id', $itemId);
        } else {
            $query->where('ios.item_code', $productCode);
        }

        $today = Carbon::today()->toDateString();

        return $query
            ->select([
                'ios.id',
                'ios.item_id',
                'ios.item_code',
                'ios.order_date',
                'ios.expected_arrival_date',
                'ios.expected_quantity',
                'ios.received_quantity',
                'ios.quantity_type',
                'ios.status',
                'ios.note',
                'ios.slip_number',
                'ios.purchase_slip_number',
                'w.code as warehouse_code',
                'w.name as warehouse_name',
                'c.code as contractor_code',
                'c.name as contractor_name',
                'supplier_partners.code as supplier_code',
                'supplier_partners.name as supplier_name',
            ])
            ->orderByRaw('CASE WHEN ios.expected_arrival_date >= ? THEN 0 ELSE 1 END', [$today])
            ->orderByRaw('CASE WHEN ios.expected_arrival_date >= ? THEN ios.expected_arrival_date END ASC', [$today])
            ->orderByRaw('CASE WHEN ios.expected_arrival_date < ? THEN ios.expected_arrival_date END DESC', [$today])
            ->orderBy('ios.id')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                $remainingQuantity = max(0, (int) $row->expected_quantity - (int) $row->received_quantity);
                $quantityType = QuantityType::tryFrom((string) $row->quantity_type);
                $status = IncomingScheduleStatus::tryFrom((string) $row->status);

                return [
                    'id' => (int) $row->id,
                    'itemId' => (int) $row->item_id,
                    'productCode' => (string) ($row->item_code ?? ''),
                    'orderDate' => (string) ($row->order_date ?? ''),
                    'arrivalDate' => (string) ($row->expected_arrival_date ?? ''),
                    'quantity' => $remainingQuantity,
                    'quantityType' => (string) ($row->quantity_type ?? ''),
                    'quantityTypeLabel' => $quantityType?->name() ?? (string) ($row->quantity_type ?? ''),
                    'expectedQuantity' => (int) $row->expected_quantity,
                    'receivedQuantity' => (int) $row->received_quantity,
                    'supplier' => (string) ($row->supplier_name ?? ''),
                    'supplierCode' => (string) ($row->supplier_code ?? ''),
                    'orderTo' => (string) ($row->contractor_name ?? ''),
                    'orderToCode' => (string) ($row->contractor_code ?? ''),
                    'warehouse' => (string) ($row->warehouse_name ?? ''),
                    'warehouseCode' => (string) ($row->warehouse_code ?? ''),
                    'status' => (string) ($row->status ?? ''),
                    'statusLabel' => $status?->label() ?? (string) ($row->status ?? ''),
                    'slipNumber' => (string) ($row->slip_number ?? ''),
                    'purchaseSlipNumber' => (string) ($row->purchase_slip_number ?? ''),
                    'note' => (string) ($row->note ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function formatVolume($volume, $unit): string
    {
        if ($volume === null || $volume === '') {
            return '';
        }

        $unitName = '';

        if ($unit !== null && $unit !== '') {
            $unitName = is_numeric($unit)
                ? EVolumeUnit::fromPrevID((int) $unit)->name()
                : (EVolumeUnit::tryFrom((string) $unit)?->name() ?? (string) $unit);
        }

        return "{$volume}{$unitName}";
    }
}
