<?php

namespace App\Http\Controllers\Api;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EItemSearchCodeType;
use App\Enums\EVolumeUnit;
use App\Enums\QuantityType;
use App\Models\WmsDistributionRow;
use App\Services\Distribution\DistributionDeliveryDateService;
use App\Services\Distribution\DistributionOrderCandidateService;
use App\Services\Distribution\DistributionStockTransferSlipService;
use App\Services\WarehouseResolver;
use App\Support\DbMutex;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

    private const DISTRIBUTION_DEPARTMENT_CODES = ['90', '92', '93', '94', '95', '96', '97', '98'];

    private const DISTRIBUTION_ROW_MODES = ['allocation', 'direct'];

    private const DISTRIBUTION_ROW_FETCH_LIMIT = 3000;

    private const DISTRIBUTION_STORE_ROW_FETCH_LIMIT = 1000;

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
                        'orderToTel' => (string) ($contractor->contractor_tel ?? ''),
                        'orderToFax' => (string) ($contractor->contractor_fax ?? ''),
                        'orderToAddress' => $this->formatContractorAddress($contractor),
                        'transmissionType' => (string) ($contractor->transmission_type ?? ''),
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
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $search = $this->normalizeSearchText((string) $request->input('search', ''));
        $limit = max(1, min(200, (int) $request->input('limit', 200)));
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
            ->orderByRaw('CASE WHEN dc.code LIKE ? THEN 0 ELSE 1 END', ['91%'])
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
                            'orderToTel' => (string) ($contractor->contractor_tel ?? ''),
                            'orderToFax' => (string) ($contractor->contractor_fax ?? ''),
                            'orderToAddress' => $this->formatContractorAddress($contractor),
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
                    'orderToTel' => (string) ($contractor->contractor_tel ?? ''),
                    'orderToFax' => (string) ($contractor->contractor_fax ?? ''),
                    'orderToAddress' => $this->formatContractorAddress($contractor),
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
            'rows.*.distribution_business_key' => 'nullable|string|size:40',
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
            'rows.*.delivery_course_id' => 'required|integer|min:1',
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

    public function itemContractorNote(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'item_contractor_id' => 'required|integer|min:1',
            'item_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $query = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('client_id', (int) config('app.client_id'))
            ->where('id', (int) $request->integer('item_contractor_id'));

        if ($request->filled('item_id')) {
            $query->where('item_id', (int) $request->integer('item_id'));
        }

        $note = Schema::connection('sakemaru')->hasColumn('item_contractors', 'note')
            ? $query->value('note')
            : null;

        return $this->success([
            'note' => trim((string) ($note ?? '')),
        ]);
    }

    public function distributionRows(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->canSearch($permissionService, $user)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'mode' => 'nullable|string|in:all,allocation,direct',
            'context' => 'nullable|string|in:edit,store',
            'confirm_status' => 'nullable|string|in:all,confirmed,unconfirmed',
            'product_search' => 'nullable|string|max:100',
            'keyword_search' => 'nullable|string|max:200',
            'order_date_from' => 'nullable|date_format:Y-m-d',
            'order_date_to' => 'nullable|date_format:Y-m-d',
            'delivery_date_from' => 'nullable|date_format:Y-m-d',
            'delivery_date_to' => 'nullable|date_format:Y-m-d',
            'order_to_search' => 'nullable|string|max:200',
            'supplier_search' => 'nullable|string|max:200',
            'status_filter' => 'nullable|string|in:all,checked,unchecked,order_created,order_pending,printed,unprinted,unprocessed,request_printed',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $requestedMode = (string) $request->input('mode', 'all');
        $modes = $requestedMode === 'all' ? self::DISTRIBUTION_ROW_MODES : [$requestedMode];
        $context = (string) $request->input('context', 'edit');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(200, (int) $request->input('per_page', 100)));
        $rowFetchLimit = $context === 'store'
            ? self::DISTRIBUTION_STORE_ROW_FETCH_LIMIT
            : self::DISTRIBUTION_ROW_FETCH_LIMIT;
        $selectedKuraWarehouseId = $this->selectedKuraWarehouseId($user);
        $storeDestinationKey = null;
        $readOnly = false;

        if ($context === 'store') {
            if (! $this->canView($permissionService, $user, self::PERMISSION_STORE_DISTRIBUTION_MANAGEMENT)) {
                abort(403);
            }

            $rowWarehouseId = $this->kuraWarehouseId();
            if (! $rowWarehouseId) {
                return $this->success([
                    'allocation' => [],
                    'direct' => [],
                    'persisted' => true,
                    'read_only' => true,
                    'context' => $context,
                ]);
            }

            if (! $selectedKuraWarehouseId) {
                $storeDestinationKey = $this->selectedWarehouseDestinationKey($user);
                if (! $storeDestinationKey) {
                    abort(403);
                }

                $readOnly = true;
            }
        } else {
            if (! $selectedKuraWarehouseId) {
                abort(403);
            }

            foreach ($modes as $modeName) {
                if (! $this->canView($permissionService, $user, $this->distributionRowModePermission($modeName))) {
                    abort(403);
                }
            }

            $rowWarehouseId = $selectedKuraWarehouseId;
        }

        if (! Schema::connection('sakemaru')->hasTable('wms_distribution_rows')) {
            return $this->success([
                'allocation' => [],
                'direct' => [],
                'persisted' => false,
                'read_only' => $readOnly,
                'context' => $context,
            ]);
        }

        $clientId = (int) config('app.client_id');

        $grouped = [
            'allocation' => [],
            'direct' => [],
        ];
        $modeCounts = [
            'allocation' => 0,
            'direct' => 0,
        ];
        $pagination = [];
        $revisions = [];

        foreach ($modes as $modeName) {
            $query = WmsDistributionRow::query()
                ->forClient($clientId)
                ->forMode($modeName)
                ->where('warehouse_id', (int) $rowWarehouseId);

            if ($context === 'store') {
                $this->applyStoreDistributionRowsFilters($query, $request);
                if ($storeDestinationKey !== null) {
                    $this->applyStoreDistributionDestinationFilter($query, $storeDestinationKey);
                }
            } elseif ($modeName === 'direct') {
                $this->applyDirectDistributionRowsFilters($query, $request);
            }

            $modeCounts[$modeName] = (clone $query)->count();
            $usesPaging = $context === 'edit' && $requestedMode === 'direct' && $modeName === 'direct';

            if (! $usesPaging && $modeCounts[$modeName] > $rowFetchLimit) {
                $modeLabel = $modeName === 'direct' ? '直送分配' : '本部分配';

                return $this->error(
                    $modeLabel.'の分配データが'.$rowFetchLimit.'件を超えているため、安全のため一覧を読み込めません。条件を絞るか、不要なデータを整理してから再読み込みしてください。',
                    409,
                    'DISTRIBUTION_ROWS_LIMIT_EXCEEDED',
                    null,
                    [
                        'limit' => $rowFetchLimit,
                        'mode' => $requestedMode,
                        'exceeded_mode' => $modeName,
                        'mode_counts' => $modeCounts,
                    ]
                );
            }

            $orderedQuery = $query
                ->orderBy('sort_order')
                ->orderBy('id');
            $rows = $usesPaging
                ? $orderedQuery->forPage($page, $perPage)->get()
                : $orderedQuery->limit($rowFetchLimit)->get();

            if ($usesPaging) {
                $pagination[$modeName] = [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $modeCounts[$modeName],
                    'last_page' => max(1, (int) ceil($modeCounts[$modeName] / $perPage)),
                ];
            }

            $revisions[$modeName] = $this->distributionRevision(
                $clientId,
                $modeName,
                (int) $rowWarehouseId
            );

            $orderToContacts = collect();
            $itemContractorNotes = collect();
            if (Schema::connection('sakemaru')->hasColumn('item_contractors', 'note')) {
                $itemContractorIds = $rows
                    ->map(fn (WmsDistributionRow $row): int => (int) data_get($row->row_data, 'itemContractorId', data_get($row->row_data, 'item_contractor_id', 0)))
                    ->filter()
                    ->unique()
                    ->values();

                if ($itemContractorIds->isNotEmpty()) {
                    $itemContractorNotes = DB::connection('sakemaru')
                        ->table('item_contractors')
                        ->where('client_id', $clientId)
                        ->whereIn('id', $itemContractorIds->all())
                        ->pluck('note', 'id');
                }
            }

            if ($modeName === 'direct') {
                $orderToCodes = $rows
                    ->map(fn (WmsDistributionRow $row): string => trim((string) data_get($row->row_data, 'orderToCode', data_get($row->row_data, 'order_to_code', ''))))
                    ->filter()
                    ->unique()
                    ->values();

                if ($orderToCodes->isNotEmpty()) {
                    $orderToContacts = DB::connection('sakemaru')
                        ->table('contractors as c')
                        ->leftJoin('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'c.id')
                        ->where('c.client_id', $clientId)
                        ->whereIn('c.code', $orderToCodes->all())
                        ->get(['c.code', 'c.postal_code', 'c.address1', 'c.address2', 'c.tel', 'c.fax', 'wcs.transmission_type'])
                        ->keyBy(fn ($contractor): string => trim((string) $contractor->code));
                }
            }

            $rows->each(function (WmsDistributionRow $row) use (&$grouped, $storeDestinationKey, $orderToContacts, $itemContractorNotes): void {
                $data = is_array($row->row_data) ? $row->row_data : [];
                if ($data === []) {
                    return;
                }

                $itemContractorId = (int) ($data['itemContractorId'] ?? $data['item_contractor_id'] ?? 0);
                if ($itemContractorId > 0 && $itemContractorNotes->has($itemContractorId)) {
                    $data['itemContractorNote'] = trim((string) ($itemContractorNotes->get($itemContractorId) ?? ''));
                    $data['itemContractorNoteLoaded'] = true;
                }

                if ($row->mode === 'direct' && $orderToContacts->isNotEmpty()) {
                    $orderToCode = trim((string) ($data['orderToCode'] ?? $data['order_to_code'] ?? ''));
                    $contact = $orderToContacts->get($orderToCode);
                    if ($contact) {
                        $data['orderToTel'] = trim((string) ($contact->tel ?? ''));
                        $data['orderToFax'] = trim((string) ($contact->fax ?? ''));
                        $data['orderToAddress'] = $this->formatContractorAddress($contact);
                        $data['transmissionType'] = trim((string) ($contact->transmission_type ?? ''));
                    }
                }

                if ($storeDestinationKey !== null) {
                    $data = $this->scopeDistributionRowDataForDestination($data, $storeDestinationKey);
                    if ($data === null) {
                        return;
                    }
                }

                $grouped[$row->mode][] = $data;
            });
        }

        return $this->success([
            'allocation' => $grouped['allocation'],
            'direct' => $grouped['direct'],
            'persisted' => true,
            'read_only' => $readOnly,
            'context' => $context,
            'total_count' => array_sum($modeCounts),
            'mode_counts' => $modeCounts,
            'limit' => $rowFetchLimit,
            'pagination' => $pagination,
            'revisions' => $revisions,
        ]);
    }

    public function saveDistributionRows(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'mode' => 'required|string|in:allocation,direct',
            'replace' => 'nullable|boolean',
            'expected_revision' => 'required|integer|min:0',
            'deleted_row_ids' => 'nullable|array|max:3000',
            'deleted_row_ids.*' => 'string|max:120',
            'rows' => 'present|array|max:3000',
            'rows.*' => 'array',
            'rows.*.id' => 'required|string|max:120',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $mode = (string) $request->input('mode');
        $requiredPermission = $this->distributionRowModePermission($mode);

        if (! $this->canView($permissionService, $user, $requiredPermission)) {
            abort(403);
        }

        $warehouseId = $this->selectedKuraWarehouseId($user);
        if (! $warehouseId) {
            abort(403);
        }

        if (
            ! Schema::connection('sakemaru')->hasTable('wms_distribution_rows')
            || ! Schema::connection('sakemaru')->hasTable('wms_distribution_revisions')
        ) {
            return $this->error('分配データ保存テーブルが未作成です。マイグレーションを実行してください。', 503, 'DISTRIBUTION_ROWS_TABLE_MISSING');
        }

        $clientId = (int) config('app.client_id');
        $rows = $request->input('rows', []);
        $quantityErrors = $this->distributionRowQuantityErrors($rows);
        if ($quantityErrors !== []) {
            $invalidRowIds = collect(array_keys($quantityErrors))
                ->map(function (string $path) use ($rows): string {
                    if (preg_match('/^rows\.(\d+)\./', $path, $matches) !== 1) {
                        return '';
                    }

                    $row = $rows[(int) $matches[1]] ?? null;

                    return is_array($row) ? trim((string) ($row['id'] ?? '')) : '';
                })
                ->filter()
                ->unique()
                ->values();
            $existingRowData = $invalidRowIds->isEmpty()
                ? []
                : WmsDistributionRow::query()
                    ->forClient($clientId)
                    ->forMode($mode)
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('row_id', $invalidRowIds->all())
                    ->get(['row_id', 'row_data'])
                    ->mapWithKeys(fn (WmsDistributionRow $row): array => [
                        (string) $row->row_id => is_array($row->row_data) ? $row->row_data : [],
                    ])
                    ->all();
            $quantityErrors = $this->distributionRowQuantityErrors($rows, $existingRowData);
        }
        if ($quantityErrors !== []) {
            return $this->error(
                '希望数・分配数には0以上の整数を入力してください。',
                422,
                'DISTRIBUTION_ROW_QUANTITY_INVALID',
                null,
                $quantityErrors
            );
        }
        $replaceAll = $request->boolean('replace');
        $deletedRowIds = collect($request->input('deleted_row_ids', []))
            ->map(fn ($rowId): string => trim((string) $rowId))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $incomingRowIds = [];
        $upsertRows = [];
        $newRevision = 0;
        $protectedRowIds = [];
        $deleteProtectedRowIds = [];
        $fieldProtectedRowIds = [];
        $confirmationProtectedRowIds = [];
        $resyncRequiredRowIds = [];
        $savedRowCount = 0;
        $deletedRowCount = 0;

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowId = trim((string) ($row['id'] ?? ''));
            if ($rowId === '') {
                continue;
            }

            $businessKey = trim((string) ($row['distributionBusinessKey'] ?? ''));
            if (preg_match('/^[0-9a-f]{40}$/i', $businessKey) !== 1) {
                $businessKey = sha1(json_encode([
                    'mode' => $mode,
                    'product_code' => $row['productCode'] ?? '',
                    'item_id' => $row['itemId'] ?? null,
                    'source' => $row['source'] ?? '',
                    'source_key' => $row['sourceKey'] ?? '',
                    'row_id' => $rowId,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $row['distributionBusinessKey'] = $businessKey;
            }

            $incomingRowIds[$rowId] = $rowId;
            $upsertRows[$rowId] = [
                'client_id' => $clientId,
                'mode' => $mode,
                'warehouse_id' => $warehouseId,
                'row_id' => $rowId,
                'sort_order' => $index,
                'business_key' => strtolower($businessKey),
                'source' => mb_substr((string) ($row['source'] ?? ''), 0, 50),
                'source_key' => mb_substr((string) ($row['sourceKey'] ?? ''), 0, 255),
                'product_code' => mb_substr((string) ($row['productCode'] ?? ''), 0, 100),
                'item_id' => filled($row['itemId'] ?? null) ? (int) $row['itemId'] : null,
                'row_data' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_by' => (int) $user->id,
                'updated_by' => (int) $user->id,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ];
        }

        $incomingRowIds = array_values($incomingRowIds);
        $upsertRows = array_values($upsertRows);

        try {
            DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $mode, $upsertRows, $deletedRowIds, $replaceAll, $request, $incomingRowIds, &$newRevision, &$protectedRowIds, &$deleteProtectedRowIds, &$fieldProtectedRowIds, &$confirmationProtectedRowIds, &$resyncRequiredRowIds, &$savedRowCount, &$deletedRowCount): void {
                DB::connection('sakemaru')->table('wms_distribution_revisions')->insertOrIgnore([
                    'client_id' => $clientId,
                    'mode' => $mode,
                    'warehouse_id' => $warehouseId,
                    'revision' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $revisionRow = DB::connection('sakemaru')
                    ->table('wms_distribution_revisions')
                    ->where('client_id', $clientId)
                    ->where('mode', $mode)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first(['revision']);
                $currentRevision = (int) ($revisionRow->revision ?? 0);
                $expectedRevision = (int) $request->input('expected_revision');

                if ($currentRevision !== $expectedRevision) {
                    throw new \RuntimeException('DISTRIBUTION_REVISION_CONFLICT:'.$currentRevision);
                }

                $candidateProtectedRowIds = $replaceAll
                    ? null
                    : array_values(array_unique(array_merge($incomingRowIds, $deletedRowIds)));
                $protectedRowIds = $this->distributionProtectedRowIds(
                    $clientId,
                    $mode,
                    $warehouseId,
                    $candidateProtectedRowIds,
                    false
                );
                $deleteProtectedRowIds = $this->distributionProtectedRowIds(
                    $clientId,
                    $mode,
                    $warehouseId,
                    $candidateProtectedRowIds,
                    true
                );
                $protectedLookup = array_fill_keys($protectedRowIds, true);
                $deleteProtectedLookup = array_fill_keys($deleteProtectedRowIds, true);
                $saveRows = array_values(array_filter(
                    $upsertRows,
                    fn (array $row): bool => ! isset($protectedLookup[(string) $row['row_id']])
                ));
                $confirmationProtectedRowIds = [];
                [$saveRows, $fieldProtectedRowIds] = $this->preserveOrderCandidateLockedFields(
                    $clientId,
                    $mode,
                    $warehouseId,
                    $saveRows
                );
                $deletableRowIds = array_values(array_filter(
                    $deletedRowIds,
                    fn (string $rowId): bool => ! isset($deleteProtectedLookup[$rowId])
                ));
                $savedRowCount = count($saveRows);
                $incomingLookup = array_fill_keys($incomingRowIds, true);
                $resyncRequiredRowIds = array_values(array_unique(array_merge(
                    $confirmationProtectedRowIds,
                    $fieldProtectedRowIds,
                    array_values(array_filter(
                        $deleteProtectedRowIds,
                        fn (string $rowId): bool => ! isset($incomingLookup[$rowId]) || in_array($rowId, $deletedRowIds, true)
                    ))
                )));

                if (! $replaceAll && $saveRows !== []) {
                    $existingSortOrders = DB::connection('sakemaru')
                        ->table('wms_distribution_rows')
                        ->where('client_id', $clientId)
                        ->where('mode', $mode)
                        ->where('warehouse_id', $warehouseId)
                        ->whereIn('row_id', $incomingRowIds)
                        ->pluck('sort_order', 'row_id');
                    $nextSortOrder = (int) (DB::connection('sakemaru')
                        ->table('wms_distribution_rows')
                        ->where('client_id', $clientId)
                        ->where('mode', $mode)
                        ->where('warehouse_id', $warehouseId)
                        ->max('sort_order') ?? -1);

                    foreach ($saveRows as &$upsertRow) {
                        $rowId = (string) $upsertRow['row_id'];
                        $upsertRow['sort_order'] = $existingSortOrders->has($rowId)
                            ? (int) $existingSortOrders->get($rowId)
                            : ++$nextSortOrder;
                    }
                    unset($upsertRow);
                }

                foreach (array_chunk($saveRows, 250) as $chunk) {
                    DB::connection('sakemaru')->table('wms_distribution_rows')->upsert(
                        $chunk,
                        ['client_id', 'mode', 'warehouse_id', 'row_id'],
                        [
                            'sort_order',
                            'business_key',
                            'source',
                            'source_key',
                            'product_code',
                            'item_id',
                            'row_data',
                            'updated_by',
                            'updated_at',
                            'deleted_at',
                        ]
                    );
                }

                if ($deletableRowIds !== []) {
                    $deletedRowCount += WmsDistributionRow::query()
                        ->forClient($clientId)
                        ->forMode($mode)
                        ->where('warehouse_id', $warehouseId)
                        ->whereIn('row_id', $deletableRowIds)
                        ->delete();
                }

                if ($replaceAll) {
                    $deleteQuery = WmsDistributionRow::query()
                        ->forClient($clientId)
                        ->forMode($mode)
                        ->where('warehouse_id', $warehouseId);

                    if ($incomingRowIds !== []) {
                        $deleteQuery->whereNotIn('row_id', $incomingRowIds);
                    }
                    if ($deleteProtectedRowIds !== []) {
                        $deleteQuery->whereNotIn('row_id', $deleteProtectedRowIds);
                    }

                    $deletedRowCount += $deleteQuery->delete();
                }

                $newRevision = $currentRevision + 1;
                DB::connection('sakemaru')
                    ->table('wms_distribution_revisions')
                    ->where('client_id', $clientId)
                    ->where('mode', $mode)
                    ->where('warehouse_id', $warehouseId)
                    ->update([
                        'revision' => $newRevision,
                        'updated_at' => now(),
                    ]);
            }, 5);
        } catch (\RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'DISTRIBUTION_REVISION_CONFLICT:')) {
                $currentRevision = (int) str($exception->getMessage())->after(':')->value();

                return $this->error(
                    '別の画面で分配データが更新されています。画面を再読み込みしてから操作してください。',
                    409,
                    'DISTRIBUTION_REVISION_CONFLICT',
                    null,
                    ['current_revision' => $currentRevision]
                );
            }

            throw $exception;
        }

        return $this->success([
            'saved_count' => $savedRowCount,
            'deleted_count' => $deletedRowCount,
            'protected_count' => count($protectedRowIds),
            'protected_row_ids' => $protectedRowIds,
            'delete_protected_count' => count($deleteProtectedRowIds),
            'delete_protected_row_ids' => $deleteProtectedRowIds,
            'field_protected_count' => count($fieldProtectedRowIds),
            'field_protected_row_ids' => $fieldProtectedRowIds,
            'confirmation_protected_count' => count($confirmationProtectedRowIds),
            'confirmation_protected_row_ids' => $confirmationProtectedRowIds,
            'resync_required_row_ids' => $resyncRequiredRowIds,
            'mode' => $mode,
            'revision' => $newRevision,
        ]);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<string, array<string, mixed>>  $existingRowData
     * @return array<string, array<int, string>>
     */
    private function distributionRowQuantityErrors(array $rows, array $existingRowData = []): array
    {
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $field => $value) {
                $field = (string) $field;
                if (! str_starts_with($field, 'wish_') && ! str_starts_with($field, 'alloc_')) {
                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }

                $validated = filter_var($value, FILTER_VALIDATE_INT);
                if ($validated === false || $validated < 0 || $validated > 99999999) {
                    $rowId = trim((string) ($row['id'] ?? ''));
                    $existingValue = $existingRowData[$rowId][$field] ?? null;
                    $existingValidated = filter_var($existingValue, FILTER_VALIDATE_INT);
                    if (
                        $validated !== false
                        && $validated < 0
                        && $existingValidated !== false
                        && $existingValidated === $validated
                    ) {
                        continue;
                    }

                    $errors["rows.{$rowIndex}.{$field}"] = ['0以上99,999,999以下の整数を入力してください。'];
                }
            }
        }

        return $errors;
    }

    public function markDirectRequestPrinted(Request $request, PermissionService $permissionService): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->canView($permissionService, $user, self::PERMISSION_DIRECT_DISTRIBUTION)) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'row_ids' => 'required|array|min:1|max:3000',
            'row_ids.*' => 'required|string|max:120|distinct',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $warehouseId = $this->selectedKuraWarehouseId($user);
        if (! $warehouseId) {
            abort(403);
        }

        if (
            ! Schema::connection('sakemaru')->hasTable('wms_distribution_rows')
            || ! Schema::connection('sakemaru')->hasTable('wms_distribution_revisions')
        ) {
            return $this->error('分配データ保存テーブルが未作成です。マイグレーションを実行してください。', 503, 'DISTRIBUTION_ROWS_TABLE_MISSING');
        }

        $clientId = (int) config('app.client_id');
        $rowIds = collect($request->input('row_ids', []))
            ->map(fn ($rowId): string => trim((string) $rowId))
            ->filter()
            ->unique()
            ->values();

        try {
            $result = DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $rowIds, $user): array {
                DB::connection('sakemaru')->table('wms_distribution_revisions')->insertOrIgnore([
                    'client_id' => $clientId,
                    'mode' => 'direct',
                    'warehouse_id' => $warehouseId,
                    'revision' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $revisionRow = DB::connection('sakemaru')
                    ->table('wms_distribution_revisions')
                    ->where('client_id', $clientId)
                    ->where('mode', 'direct')
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first(['revision']);

                $rowsQuery = WmsDistributionRow::query()
                    ->forClient($clientId)
                    ->forMode('direct')
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('row_id', $rowIds->all());
                $foundRowIds = (clone $rowsQuery)
                    ->lockForUpdate()
                    ->pluck('row_id')
                    ->map(fn ($rowId): string => (string) $rowId)
                    ->values();

                if ($foundRowIds->count() !== $rowIds->count()) {
                    throw new RuntimeException('DIRECT_REQUEST_PRINT_ROWS_NOT_FOUND');
                }

                $validDestinations = DB::connection('sakemaru')
                    ->table('warehouses')
                    ->where('client_id', $clientId)
                    ->where('is_active', true)
                    ->where('id', '<>', $warehouseId)
                    ->get(['id', 'code']);
                $validDestinationKeys = $validDestinations
                    ->mapWithKeys(fn ($warehouse): array => [
                        $this->formatDestinationKey($warehouse->code, (int) $warehouse->id) => true,
                    ])
                    ->all();
                $validDestinationIds = $validDestinations
                    ->mapWithKeys(fn ($warehouse): array => [(int) $warehouse->id => true])
                    ->all();

                $now = now();
                $updatedCount = 0;
                foreach ($foundRowIds->chunk(250) as $chunk) {
                    $chunkRows = WmsDistributionRow::query()
                        ->forClient($clientId)
                        ->forMode('direct')
                        ->where('warehouse_id', $warehouseId)
                        ->whereIn('row_id', $chunk->all())
                        ->get(['row_id', 'row_data']);
                    $invalidAllocationRow = $chunkRows->first(function (WmsDistributionRow $row) use ($validDestinationKeys, $validDestinationIds): bool {
                        $data = is_array($row->row_data) ? $row->row_data : [];

                        foreach ($data as $field => $value) {
                            $field = (string) $field;
                            $destinationKey = str_starts_with($field, 'alloc_') ? substr($field, 6) : '';
                            if ($destinationKey !== '' && isset($validDestinationKeys[$destinationKey]) && (int) $value > 0) {
                                return false;
                            }
                        }
                        foreach (($data['allocations'] ?? []) as $allocation) {
                            if (! is_array($allocation) || (int) ($allocation['quantity'] ?? 0) <= 0) {
                                continue;
                            }

                            $destinationKey = trim((string) ($allocation['destination_key'] ?? ''));
                            $destinationId = (int) ($allocation['destination_id'] ?? 0);
                            if (
                                ($destinationKey !== '' && isset($validDestinationKeys[$destinationKey]))
                                || ($destinationId > 0 && isset($validDestinationIds[$destinationId]))
                            ) {
                                return false;
                            }
                        }

                        return true;
                    });
                    if ($invalidAllocationRow) {
                        throw new RuntimeException('DIRECT_REQUEST_PRINT_ROW_HAS_NO_ALLOCATION');
                    }

                    $updatedCount += WmsDistributionRow::query()
                        ->forClient($clientId)
                        ->forMode('direct')
                        ->where('warehouse_id', $warehouseId)
                        ->whereIn('row_id', $chunk->all())
                        ->update([
                            'row_data' => DB::raw("JSON_SET(row_data, '$.printed', JSON_EXTRACT('true', '$'))"),
                            'updated_by' => (int) $user->id,
                            'updated_at' => $now,
                        ]);
                }

                $revision = (int) ($revisionRow->revision ?? 0) + 1;
                DB::connection('sakemaru')
                    ->table('wms_distribution_revisions')
                    ->where('client_id', $clientId)
                    ->where('mode', 'direct')
                    ->where('warehouse_id', $warehouseId)
                    ->update([
                        'revision' => $revision,
                        'updated_at' => $now,
                    ]);

                return [
                    'updated_count' => $updatedCount,
                    'row_ids' => $foundRowIds->all(),
                    'revision' => $revision,
                ];
            }, 5);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'DIRECT_REQUEST_PRINT_ROWS_NOT_FOUND') {
                return $this->error('出力対象の一部が削除または変更されています。画面を再読み込みしてください。', 409, 'DISTRIBUTION_ROWS_STALE');
            }
            if ($exception->getMessage() === 'DIRECT_REQUEST_PRINT_ROW_HAS_NO_ALLOCATION') {
                return $this->error('店舗別分配数が入力されていないデータは出力済みに更新できません。画面を再読み込みしてください。', 409, 'DISTRIBUTION_ROWS_STALE');
            }

            throw $exception;
        }

        return $this->success($result);
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
            'rows.*.distribution_business_key' => 'nullable|string|size:40',
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
            'rows.*.delivery_course_id' => 'required|integer|min:1',
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

        try {
            $this->assertDistributionGenerationRowsAreCurrent(
                clientId: (int) config('app.client_id'),
                warehouseId: (int) $selectedWarehouseId,
                mode: 'allocation',
                rows: $request->input('rows', []),
                operation: 'warehouse-transfer'
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 409, 'DISTRIBUTION_ROWS_STALE');
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
            'rows.*.distribution_business_key' => 'nullable|string|size:40',
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
            $this->assertDistributionGenerationRowsAreCurrent(
                clientId: (int) config('app.client_id'),
                warehouseId: (int) $selectedWarehouseId,
                mode: $mode,
                rows: $request->input('rows', []),
                operation: 'order-candidate'
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 409, 'DISTRIBUTION_ROWS_STALE');
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
        $databaseLockKey = 'wms-dist:'.sha1($lockKey);

        try {
            $acquired = DbMutex::acquireOrFail($databaseLockKey, 1, 'sakemaru');
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                '生成処理の排他制御に失敗しました。しばらくしてから再度実行してください。',
                503,
                'DISTRIBUTION_GENERATION_LOCK_ERROR'
            );
        }

        if (! $acquired) {
            return $this->error(
                '同じ生成処理が実行中です。完了してから再度実行してください。',
                429,
                'DISTRIBUTION_GENERATION_LOCKED'
            );
        }

        try {
            return $callback();
        } finally {
            DbMutex::release($databaseLockKey, 'sakemaru');
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

    private function kuraWarehouseId(): ?int
    {
        $warehouseId = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('code', self::HQ_TRANSFER_WAREHOUSE_CODE)
            ->where('is_active', true)
            ->value('id');

        return $warehouseId ? (int) $warehouseId : null;
    }

    private function selectedWarehouseDestinationKey($user): ?string
    {
        $warehouseId = (int) ($user->getSelectedWarehouseId() ?: 0);
        if ($warehouseId <= 0) {
            return null;
        }

        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->first(['id', 'code']);

        if (! $warehouse) {
            return null;
        }

        return $this->formatDestinationKey($warehouse->code, (int) $warehouse->id);
    }

    private function distributionRowModePermission(string $mode): string
    {
        return $mode === 'direct'
            ? self::PERMISSION_DIRECT_DISTRIBUTION
            : self::PERMISSION_DISTRIBUTION_ADJUSTMENT;
    }

    private function distributionRevision(int $clientId, string $mode, int $warehouseId): int
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_distribution_revisions')) {
            return 0;
        }

        return (int) (DB::connection('sakemaru')
            ->table('wms_distribution_revisions')
            ->where('client_id', $clientId)
            ->where('mode', $mode)
            ->where('warehouse_id', $warehouseId)
            ->value('revision') ?? 0);
    }

    /**
     * @param  array<int, string>|null  $candidateRowIds  Null means the complete scope.
     * @return array<int, string>
     */
    private function distributionProtectedRowIds(
        int $clientId,
        string $mode,
        int $warehouseId,
        ?array $candidateRowIds,
        bool $includeDeleteOnlyLocks
    ): array {
        if ($candidateRowIds === []) {
            return [];
        }

        $fullLockCondition = <<<'SQL'
(
    LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.warehouseTransferGenerated')), 'false')) IN ('true', '1')
    OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.warehouse_transfer_generated')), 'false')) IN ('true', '1')
    OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.warehouseTransferQueueIds')), 0) > 0
    OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.warehouse_transfer_queue_ids')), 0) > 0
    OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.transferSlipQueueIds')), 0) > 0
    OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.transfer_slip_queue_ids')), 0) > 0
)
SQL;
        $checkedDeleteCondition = 'FALSE';
        $deleteOnlyCondition = <<<SQL
(
    {$checkedDeleteCondition}
    OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderCandidateGenerated')), 'false')) IN ('true', '1')
    OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.order_candidate_generated')), 'false')) IN ('true', '1')
    OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.orderCandidateIds')), 0) > 0
    OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.order_candidate_ids')), 0) > 0
)
SQL;

        $query = WmsDistributionRow::query()
            ->forClient($clientId)
            ->forMode($mode)
            ->where('warehouse_id', $warehouseId)
            ->whereRaw($includeDeleteOnlyLocks
                ? "({$fullLockCondition} OR {$deleteOnlyCondition})"
                : $fullLockCondition);

        if ($candidateRowIds !== null) {
            $query->whereIn('row_id', $candidateRowIds);
        }

        return $query
            ->pluck('row_id')
            ->map(fn ($rowId): string => (string) $rowId)
            ->values()
            ->all();
    }

    /**
     * Preserve a confirmed row while still allowing the confirmation to be removed.
     *
     * @param  array<int, array<string, mixed>>  $saveRows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function preserveConfirmedLockedFields(
        int $clientId,
        string $mode,
        int $warehouseId,
        array $saveRows
    ): array {
        if ($mode === 'direct') {
            return [$saveRows, []];
        }

        if ($saveRows === []) {
            return [$saveRows, []];
        }

        $existingRows = WmsDistributionRow::query()
            ->forClient($clientId)
            ->forMode($mode)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('row_id', array_column($saveRows, 'row_id'))
            ->whereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.checked')), 'false')) IN ('true', '1')")
            ->whereRaw("NOT (
                LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderCandidateGenerated')), 'false')) IN ('true', '1')
                OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.order_candidate_generated')), 'false')) IN ('true', '1')
                OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.orderCandidateIds')), 0) > 0
                OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.order_candidate_ids')), 0) > 0
            )")
            ->whereRaw("NOT (
                LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.warehouseTransferGenerated')), 'false')) IN ('true', '1')
                OR LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.warehouse_transfer_generated')), 'false')) IN ('true', '1')
                OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.warehouseTransferQueueIds')), 0) > 0
                OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.warehouse_transfer_queue_ids')), 0) > 0
            )")
            ->get(['row_id', 'business_key', 'source', 'source_key', 'product_code', 'item_id', 'row_data'])
            ->keyBy(fn ($row): string => (string) $row->row_id);

        if ($existingRows->isEmpty()) {
            return [$saveRows, []];
        }

        $changedRowIds = [];
        foreach ($saveRows as &$saveRow) {
            $rowId = (string) $saveRow['row_id'];
            $existing = $existingRows->get($rowId);
            if (! $existing) {
                continue;
            }

            $incomingData = json_decode((string) $saveRow['row_data'], true, flags: JSON_THROW_ON_ERROR);
            $existingData = is_array($existing->row_data)
                ? $existing->row_data
                : json_decode((string) $existing->row_data, true, flags: JSON_THROW_ON_ERROR);
            $incomingChecked = array_key_exists('checked', $incomingData)
                ? filter_var($incomingData['checked'], FILTER_VALIDATE_BOOL)
                : true;
            $preservedData = $existingData;
            $preservedData['checked'] = $incomingChecked;
            $preservedData['confirmedAt'] = $incomingChecked
                ? (string) ($existingData['confirmedAt'] ?? $existingData['confirmed_at'] ?? '')
                : '';
            if (array_key_exists('confirmed_at', $preservedData)) {
                $preservedData['confirmed_at'] = $incomingChecked
                    ? $preservedData['confirmedAt']
                    : '';
            }

            if ($preservedData !== $incomingData) {
                $changedRowIds[] = $rowId;
            }

            $saveRow['business_key'] = $existing->business_key;
            $saveRow['source'] = $existing->source;
            $saveRow['source_key'] = $existing->source_key;
            $saveRow['product_code'] = $existing->product_code;
            $saveRow['item_id'] = $existing->item_id;
            $saveRow['row_data'] = json_encode($preservedData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        unset($saveRow);

        return [$saveRows, array_values(array_unique($changedRowIds))];
    }

    /**
     * Keep fields that become immutable after an order candidate is generated.
     * Other fields, including the confirmation checkbox, remain editable.
     *
     * @param  array<int, array<string, mixed>>  $saveRows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function preserveOrderCandidateLockedFields(
        int $clientId,
        string $mode,
        int $warehouseId,
        array $saveRows
    ): array {
        if ($saveRows === []) {
            return [$saveRows, []];
        }

        $rowIds = array_column($saveRows, 'row_id');
        $existingRows = WmsDistributionRow::query()
            ->forClient($clientId)
            ->forMode($mode)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('row_id', $rowIds)
            ->where(function ($query): void {
                $query
                    ->whereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderCandidateGenerated')), 'false')) IN ('true', '1')")
                    ->orWhereRaw("LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.order_candidate_generated')), 'false')) IN ('true', '1')")
                    ->orWhereRaw("COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.orderCandidateIds')), 0) > 0")
                    ->orWhereRaw("COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.order_candidate_ids')), 0) > 0");
            })
            ->select(['row_id', 'business_key', 'source', 'source_key', 'product_code', 'item_id', 'row_data'])
            ->get()
            ->keyBy(fn ($row): string => (string) $row->row_id);

        if ($existingRows->isEmpty()) {
            return [$saveRows, []];
        }

        $fixedFields = [
            'itemId', 'candidateKey', 'itemContractorId', 'contractorId', 'supplierId',
            'contractorWarehouseId', 'productCode', 'jan', 'name', 'lot', 'unitsPerCase',
            'purchaseUnit', 'orderPoint', 'orderToCode', 'orderTo', 'supplierCode', 'supplier',
            'poCase', 'poEach', 'distributionBusinessKey', 'source', 'sourceKey',
            'orderCandidateGenerated', 'orderCandidateIds',
        ];
        $changedRowIds = [];

        foreach ($saveRows as &$saveRow) {
            $rowId = (string) $saveRow['row_id'];
            $existing = $existingRows->get($rowId);
            if (! $existing) {
                continue;
            }

            $incomingData = json_decode((string) $saveRow['row_data'], true, flags: JSON_THROW_ON_ERROR);
            $existingData = is_array($existing->row_data)
                ? $existing->row_data
                : json_decode((string) $existing->row_data, true, flags: JSON_THROW_ON_ERROR);
            $before = $incomingData;

            if ($mode === 'direct') {
                $incomingData = $existingData;
            }

            if ($mode !== 'direct') {
                foreach ($fixedFields as $field) {
                    if (array_key_exists($field, $existingData)) {
                        $incomingData[$field] = $existingData[$field];
                    } else {
                        unset($incomingData[$field]);
                    }
                }

                foreach (array_keys($incomingData) as $field) {
                    if (str_starts_with((string) $field, 'alloc_')) {
                        unset($incomingData[$field]);
                    }
                }
                foreach ($existingData as $field => $value) {
                    if (str_starts_with((string) $field, 'alloc_')) {
                        $incomingData[$field] = $value;
                    }
                }
            }

            if ($incomingData !== $before) {
                $changedRowIds[] = $rowId;
            }

            $saveRow['business_key'] = $existing->business_key;
            $saveRow['source'] = $existing->source;
            $saveRow['source_key'] = $existing->source_key;
            $saveRow['product_code'] = $existing->product_code;
            $saveRow['item_id'] = $existing->item_id;
            $saveRow['row_data'] = json_encode($incomingData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        unset($saveRow);

        return [$saveRows, array_values(array_unique($changedRowIds))];
    }

    /**
     * Reject generation requests created from a stale or modified browser row.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertDistributionGenerationRowsAreCurrent(
        int $clientId,
        int $warehouseId,
        string $mode,
        array $rows,
        string $operation
    ): void {
        $rowIds = collect($rows)
            ->map(fn ($row): string => trim((string) ($row['row_id'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        $persistedRows = WmsDistributionRow::query()
            ->forClient($clientId)
            ->forMode($mode)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('row_id', $rowIds->all())
            ->get(['row_id', 'business_key', 'row_data'])
            ->keyBy(fn (WmsDistributionRow $row): string => (string) $row->row_id);

        $staleRowIds = [];
        foreach ($rows as $row) {
            $rowId = trim((string) ($row['row_id'] ?? ''));
            $persisted = $persistedRows->get($rowId);
            if (! $persisted) {
                $staleRowIds[] = $rowId;
                continue;
            }

            $data = is_array($persisted->row_data) ? $persisted->row_data : [];
            $requestSnapshot = $this->distributionGenerationRequestSnapshot($row, $operation);
            $persistedSnapshot = $this->distributionGenerationPersistedSnapshot(
                $data,
                (string) ($persisted->business_key ?? ''),
                $operation
            );

            if ($requestSnapshot !== $persistedSnapshot) {
                $staleRowIds[] = $rowId;
            }
        }

        if ($staleRowIds !== []) {
            throw new RuntimeException(
                '対象データが別の画面で更新されています。画面を再読み込みしてから再実行してください。対象: '
                .implode(', ', array_slice(array_values(array_unique($staleRowIds)), 0, 5))
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function distributionGenerationRequestSnapshot(array $row, string $operation): array
    {
        $snapshot = [
            'business_key' => strtolower(trim((string) ($row['distribution_business_key'] ?? ''))),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'product_code' => trim((string) ($row['product_code'] ?? '')),
            'allocations' => $this->normalizeDistributionGenerationAllocations($row['allocations'] ?? [], $operation),
        ];

        if ($operation === 'warehouse-transfer') {
            $snapshot['order_date'] = trim((string) ($row['order_date'] ?? ''));
            $snapshot['delivery_date'] = trim((string) ($row['delivery_date'] ?? ''));
            $snapshot['delivery_course_id'] = (int) ($row['delivery_course_id'] ?? 0);

            return $snapshot;
        }

        return $snapshot + [
            'item_contractor_id' => (int) ($row['item_contractor_id'] ?? 0),
            'contractor_id' => (int) ($row['contractor_id'] ?? 0),
            'supplier_id' => (int) ($row['supplier_id'] ?? 0),
            'order_date' => trim((string) ($row['order_date'] ?? '')),
            'delivery_date' => trim((string) ($row['delivery_date'] ?? '')),
            'po_case' => (int) ($row['po_case'] ?? 0),
            'po_each' => (int) ($row['po_each'] ?? 0),
            'units_per_case' => (int) ($row['units_per_case'] ?? 1),
        ];
    }

    /** @param array<string, mixed> $data */
    private function distributionGenerationPersistedSnapshot(
        array $data,
        string $businessKey,
        string $operation
    ): array {
        $snapshot = [
            'business_key' => strtolower(trim($businessKey)),
            'item_id' => (int) ($data['itemId'] ?? $data['item_id'] ?? 0),
            'product_code' => trim((string) ($data['productCode'] ?? $data['product_code'] ?? '')),
            'allocations' => $this->persistedDistributionGenerationAllocations($data, $operation),
        ];

        if ($operation === 'warehouse-transfer') {
            $snapshot['order_date'] = trim((string) ($data['orderDate'] ?? $data['order_date'] ?? ''));
            $snapshot['delivery_date'] = trim((string) ($data['deliveryDate'] ?? $data['delivery_date'] ?? ''));
            $snapshot['delivery_course_id'] = (int) ($data['deliveryCourseId'] ?? $data['delivery_course_id'] ?? 0);

            return $snapshot;
        }

        return $snapshot + [
            'item_contractor_id' => (int) ($data['itemContractorId'] ?? $data['item_contractor_id'] ?? 0),
            'contractor_id' => (int) ($data['contractorId'] ?? $data['contractor_id'] ?? 0),
            'supplier_id' => (int) ($data['supplierId'] ?? $data['supplier_id'] ?? 0),
            'order_date' => trim((string) ($data['orderDate'] ?? $data['order_date'] ?? '')),
            'delivery_date' => trim((string) ($data['deliveryDate'] ?? $data['delivery_date'] ?? '')),
            'po_case' => (int) ($data['poCase'] ?? $data['po_case'] ?? 0),
            'po_each' => (int) ($data['poEach'] ?? $data['po_each'] ?? 0),
            'units_per_case' => (int) ($data['unitsPerCase'] ?? $data['units_per_case'] ?? 1),
        ];
    }

    /** @param mixed $allocations */
    private function normalizeDistributionGenerationAllocations($allocations, string $operation): array
    {
        return collect(is_array($allocations) ? $allocations : [])
            ->mapWithKeys(function ($allocation) use ($operation): array {
                if (! is_array($allocation)) {
                    return [];
                }
                $key = trim((string) ($allocation['destination_key'] ?? $allocation['destination_id'] ?? ''));
                $quantity = (int) ($allocation['quantity'] ?? 0);

                if ($operation === 'warehouse-transfer' && in_array($key, self::DISTRIBUTION_DEPARTMENT_CODES, true)) {
                    return [];
                }

                return $key !== '' && $quantity > 0 ? [$key => $quantity] : [];
            })
            ->sortKeys()
            ->all();
    }

    /** @param array<string, mixed> $data */
    private function persistedDistributionGenerationAllocations(array $data, string $operation): array
    {
        return collect($data)
            ->filter(fn ($value, $key): bool => str_starts_with((string) $key, 'alloc_') && (int) $value > 0)
            ->mapWithKeys(function ($value, $key) use ($operation): array {
                $destinationKey = substr((string) $key, 6);
                if ($operation === 'warehouse-transfer' && in_array($destinationKey, self::DISTRIBUTION_DEPARTMENT_CODES, true)) {
                    return [];
                }

                return [$destinationKey => (int) $value];
            })
            ->sortKeys()
            ->all();
    }

    private function applyStoreDistributionRowsFilters($query, Request $request): void
    {
        $confirmedRetentionCutoff = Carbon::today()->subMonthNoOverflow()->toDateString();
        $confirmedExpression = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.checked')), 'false')";
        $confirmedAtExpression = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.confirmedAt')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.confirmed_at')), ''), updated_at)";

        $query->where(function ($query) use ($confirmedExpression, $confirmedAtExpression, $confirmedRetentionCutoff): void {
            $query
                ->whereRaw("{$confirmedExpression} NOT IN ('true', '1')")
                ->orWhereRaw("{$confirmedAtExpression} >= ?", [$confirmedRetentionCutoff.' 00:00:00']);
        });

        $confirmStatus = (string) $request->input('confirm_status', 'unconfirmed');
        if ($confirmStatus === 'confirmed') {
            $query->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.checked')), 'false') IN ('true', '1')");
        } elseif ($confirmStatus === 'unconfirmed') {
            $query->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.checked')), 'false') NOT IN ('true', '1')");
        }

        $search = trim((string) $request->input('product_search', ''));
        if ($search === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
        $query->where(function ($query) use ($like): void {
            $query
                ->where('product_code', 'like', $like)
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.name')) LIKE ? ESCAPE '\\\\'", [$like])
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.jan')) LIKE ? ESCAPE '\\\\'", [$like]);
        });
    }

    private function applyDirectDistributionRowsFilters($query, Request $request): void
    {
        $orderDateExpression = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderDate')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.order_date')), ''), '')";
        $deliveryDateExpression = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.deliveryDate')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.delivery_date')), ''), '')";

        if ($request->filled('order_date_from')) {
            $query->whereRaw("{$orderDateExpression} >= ?", [(string) $request->input('order_date_from')]);
        }
        if ($request->filled('order_date_to')) {
            $query->whereRaw("{$orderDateExpression} <= ?", [(string) $request->input('order_date_to')]);
        }
        if ($request->filled('delivery_date_from')) {
            $query->whereRaw("{$deliveryDateExpression} >= ?", [(string) $request->input('delivery_date_from')]);
        }
        if ($request->filled('delivery_date_to')) {
            $query->whereRaw("{$deliveryDateExpression} <= ?", [(string) $request->input('delivery_date_to')]);
        }

        $this->applyDistributionRowKeywordFilter($query, (string) $request->input('keyword_search', ''), [
            'product_code',
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.name'))",
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.jan'))",
        ]);
        $this->applyDistributionRowKeywordFilter($query, (string) $request->input('order_to_search', ''), [
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderTo'))",
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.order_to'))",
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderToCode'))",
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.order_to_code'))",
        ]);
        $this->applyDistributionRowKeywordFilter($query, (string) $request->input('supplier_search', ''), [
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.supplier'))",
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.supplier_name'))",
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.supplierCode'))",
            "JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.supplier_code'))",
        ]);

        $checkedExpression = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.checked')), 'false') IN ('true', '1')";
        $orderCreatedExpression = "(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.orderCandidateGenerated')), 'false') IN ('true', '1') OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.orderCandidateIds')), 0) > 0)";
        $printedExpression = "(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.printed')), 'false') IN ('true', '1') OR COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(row_data, '$.transferSlipCreatedAt')), 'null'), ''), '') <> '' OR COALESCE(JSON_LENGTH(JSON_EXTRACT(row_data, '$.transferSlipQueueIds')), 0) > 0)";

        match ((string) $request->input('status_filter', 'all')) {
            'checked' => $query->whereRaw($checkedExpression),
            'unchecked' => $query->whereRaw("NOT ({$checkedExpression})"),
            'order_created' => $query->whereRaw($orderCreatedExpression),
            'order_pending' => $query->whereRaw("NOT ({$orderCreatedExpression})"),
            'printed' => $query->whereRaw($printedExpression),
            'unprinted' => $query->whereRaw("NOT ({$printedExpression})"),
            'unprocessed' => $query
                ->whereRaw("NOT ({$orderCreatedExpression})")
                ->whereRaw("NOT ({$printedExpression})"),
            'request_printed' => $query
                ->whereRaw($printedExpression)
                ->whereRaw("NOT ({$orderCreatedExpression})"),
            default => null,
        };
    }

    /** @param array<int, string> $fieldExpressions */
    private function applyDistributionRowKeywordFilter($query, string $search, array $fieldExpressions): void
    {
        $normalized = mb_strtolower(mb_convert_kana(trim($search), 'as'));
        $keywords = preg_split('/[\s,、]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $compactPattern = '[[:space:]\\[\\]（）(){}｛｝_./／:：-]+';

        foreach ($keywords as $keyword) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword).'%';
            $compactKeyword = preg_replace('/[\s　\[\]（）(){}｛｝\-_\/／.:：]/u', '', $keyword) ?? '';
            $compactLike = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $compactKeyword).'%';
            $numericKeyword = ctype_digit($compactKeyword)
                ? (int) ltrim($compactKeyword, '0')
                : null;

            $query->where(function ($query) use ($fieldExpressions, $like, $compactPattern, $compactKeyword, $compactLike, $numericKeyword): void {
                foreach ($fieldExpressions as $index => $expression) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $searchExpression = $this->distributionRowSearchExpression($expression);
                    $query->{$method}("{$searchExpression} LIKE ? ESCAPE '\\\\'", [$like]);

                    if ($compactKeyword !== '') {
                        $query->orWhereRaw(
                            "REGEXP_REPLACE({$searchExpression}, ?, '') LIKE ? ESCAPE '\\\\'",
                            [$compactPattern, $compactLike]
                        );
                    }

                    if ($numericKeyword !== null) {
                        $query->orWhereRaw("CAST(COALESCE({$expression}, '') AS UNSIGNED) = ?", [$numericKeyword]);
                    }
                }
            });
        }
    }

    private function distributionRowSearchExpression(string $expression): string
    {
        return "LOWER(CONVERT(COALESCE({$expression}, '') USING utf8mb4)) COLLATE utf8mb4_0900_ai_ci";
    }

    private function applyStoreDistributionDestinationFilter($query, string $destinationKey): void
    {
        $destinationKey = trim($destinationKey);
        if ($destinationKey === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        $listPaths = [
            '$.visibleDestinationKeys',
            '$.visible_destination_keys',
            '$.enteredDestinationKeys',
            '$.entered_destination_keys',
            '$.csvEnteredDestinationKeys',
            '$.csv_entered_destination_keys',
        ];
        $escapedKey = str_replace(['\\', '"'], ['\\\\', '\\"'], $destinationKey);
        $wishPath = '$."wish_'.$escapedKey.'"';
        $allocationPath = '$."alloc_'.$escapedKey.'"';

        $query->where(function ($query) use ($listPaths, $destinationKey, $wishPath, $allocationPath): void {
            foreach ($listPaths as $index => $path) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $query->{$method}(
                    'JSON_CONTAINS(COALESCE(JSON_EXTRACT(row_data, ?), JSON_ARRAY()), JSON_QUOTE(?))',
                    [$path, $destinationKey]
                );
            }

            $query
                ->orWhereRaw(
                    "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, ?)), '0') AS SIGNED) <> 0",
                    [$wishPath]
                )
                ->orWhereRaw(
                    "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_data, ?)), '0') AS SIGNED) <> 0",
                    [$allocationPath]
                );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function scopeDistributionRowDataForDestination(array $data, string $destinationKey): ?array
    {
        if (! $this->distributionRowHasDestination($data, $destinationKey)) {
            return null;
        }

        foreach (array_keys($data) as $key) {
            $key = (string) $key;
            if (! preg_match('/^(wish|alloc)_/', $key)) {
                continue;
            }

            if ($key !== 'wish_'.$destinationKey && $key !== 'alloc_'.$destinationKey) {
                unset($data[$key]);
            }
        }

        $listKeys = [
            'visibleDestinationKeys',
            'visible_destination_keys',
            'enteredDestinationKeys',
            'entered_destination_keys',
            'csvEnteredDestinationKeys',
            'csv_entered_destination_keys',
        ];
        $hasDestinationList = false;

        foreach ($listKeys as $listKey) {
            if (! array_key_exists($listKey, $data) || ! is_array($data[$listKey])) {
                continue;
            }

            $filtered = $this->filterDestinationKeyList($data[$listKey], $destinationKey);
            $data[$listKey] = $filtered;
            $hasDestinationList = $hasDestinationList || $filtered !== [];
        }

        if (! $hasDestinationList) {
            $data['visibleDestinationKeys'] = [$destinationKey];
            $data['enteredDestinationKeys'] = [$destinationKey];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function distributionRowHasDestination(array $data, string $destinationKey): bool
    {
        foreach ([
            'visibleDestinationKeys',
            'visible_destination_keys',
            'enteredDestinationKeys',
            'entered_destination_keys',
            'csvEnteredDestinationKeys',
            'csv_entered_destination_keys',
        ] as $listKey) {
            if (
                array_key_exists($listKey, $data)
                && is_array($data[$listKey])
                && $this->filterDestinationKeyList($data[$listKey], $destinationKey) !== []
            ) {
                return true;
            }
        }

        return $this->distributionRowQuantity($data['wish_'.$destinationKey] ?? 0) > 0
            || $this->distributionRowQuantity($data['alloc_'.$destinationKey] ?? 0) > 0;
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private function filterDestinationKeyList(array $keys, string $destinationKey): array
    {
        $filtered = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            if ($key === $destinationKey && ! in_array($key, $filtered, true)) {
                $filtered[] = $key;
            }
        }

        return $filtered;
    }

    private function distributionRowQuantity($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        $normalized = preg_replace('/[^\d\-]/', '', (string) $value);
        if ($normalized === '' || $normalized === '-') {
            return 0;
        }

        return (int) $normalized;
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
                        'orderToTel' => (string) ($contractor->contractor_tel ?? ''),
                        'orderToFax' => (string) ($contractor->contractor_fax ?? ''),
                        'orderToAddress' => $this->formatContractorAddress($contractor),
                        'transmissionType' => (string) ($contractor->transmission_type ?? ''),
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
        if ($isNumericSearch) {
            $numericSearchBase = $numericSearchWithoutLeadingZeros !== '' ? $numericSearchWithoutLeadingZeros : '0';
            $baseLength = strlen($numericSearchBase);
            if ($baseLength <= 13) {
                for ($length = $baseLength; $length <= 13; $length++) {
                    $numericSearchValues[] = str_pad($numericSearchBase, $length, '0', STR_PAD_LEFT);
                }
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
                ->where(function ($query) use ($numericSearchValues, $prefixLike) {
                    $query->where('isi.search_string', 'like', $prefixLike);

                    if (count($numericSearchValues) > 1) {
                        $query->orWhereIn('isi.search_string', $numericSearchValues);
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
            ->where(function ($query) use ($numericSearchValues, $prefixLike) {
                $query->where('iqi.product_code', 'like', $prefixLike)
                    ->orWhere('iqi.own_code', 'like', $prefixLike);

                if (count($numericSearchValues) > 1) {
                    $query->orWhereIn('iqi.product_code', $numericSearchValues)
                        ->orWhereIn('iqi.own_code', $numericSearchValues);
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
            'c.tel as contractor_tel',
            'c.fax as contractor_fax',
            'c.postal_code as contractor_postal_code',
            'c.address1 as contractor_address1',
            'c.address2 as contractor_address2',
            'wcs.transmission_type',
            'supplier_partners.code as supplier_code',
            'supplier_partners.name as supplier_name',
        ];

        $selectColumns[] = Schema::connection('sakemaru')->hasColumn('item_contractors', 'note')
            ? 'ic.note as item_contractor_note'
            : DB::raw("'' as item_contractor_note");

        $query = DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->leftJoin('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->leftJoin('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'ic.contractor_id')
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
            ->leftJoin('items as i', 'i.id', '=', 'ios.item_id')
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
                'i.capacity_case',
                'i.capacity_carton',
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
                $totalPieces = match ($quantityType) {
                    QuantityType::CASE => $remainingQuantity * max(1, (int) ($row->capacity_case ?? 1)),
                    QuantityType::CARTON => $remainingQuantity * max(1, (int) ($row->capacity_carton ?? 1)),
                    default => $remainingQuantity,
                };

                return [
                    'id' => (int) $row->id,
                    'itemId' => (int) $row->item_id,
                    'productCode' => (string) ($row->item_code ?? ''),
                    'orderDate' => (string) ($row->order_date ?? ''),
                    'arrivalDate' => (string) ($row->expected_arrival_date ?? ''),
                    'quantity' => $remainingQuantity,
                    'totalPieces' => $totalPieces,
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

    private function formatContractorAddress(?object $contractor): string
    {
        if (! $contractor) {
            return '';
        }

        $postalCode = trim((string) ($contractor->contractor_postal_code ?? $contractor->postal_code ?? ''));
        $address = trim(
            (string) ($contractor->contractor_address1 ?? $contractor->address1 ?? '')
            .(string) ($contractor->contractor_address2 ?? $contractor->address2 ?? '')
        );

        return trim(($postalCode !== '' ? '〒'.$postalCode.' ' : '').$address);
    }
}
