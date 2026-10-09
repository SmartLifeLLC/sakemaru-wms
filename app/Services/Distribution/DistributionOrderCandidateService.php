<?php

namespace App\Services\Distribution;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DistributionOrderCandidateService
{
    private const HQ_TRANSFER_CONTRACTOR_CODE = '9012';

    private ?int $hqTransferContractorId = null;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function create(int $selectedWarehouseId, int $createdBy, array $rows): array
    {
        $warehouse = $this->resolveWarehouse($selectedWarehouseId);

        return DB::connection('sakemaru')->transaction(
            fn (): array => $this->createForWarehouse($warehouse, (int) $warehouse->id, $createdBy, $rows)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function createDirect(int $selectedWarehouseId, int $createdBy, array $rows): array
    {
        $expandedRows = $this->expandRowsForDirectDestinations($selectedWarehouseId, $rows);

        if ($expandedRows === []) {
            throw new RuntimeException('直送分配の発注候補を作成できる店舗別分配数がありません。');
        }

        $warehouseIds = collect($expandedRows)
            ->pluck('target_warehouse_id')
            ->filter()
            ->map(fn ($warehouseId): int => (int) $warehouseId)
            ->unique()
            ->values()
            ->all();

        $warehouses = collect($warehouseIds)
            ->mapWithKeys(fn (int $warehouseId): array => [$warehouseId => $this->resolveWarehouse($warehouseId)]);

        return DB::connection('sakemaru')->transaction(function () use ($createdBy, $expandedRows, $warehouses): array {
            $results = [];
            $failedGroups = [];
            $groups = collect($expandedRows)->groupBy(fn (array $row): int => (int) ($row['target_warehouse_id'] ?? 0));

            foreach ($groups as $warehouseId => $warehouseRows) {
                $warehouse = $warehouses->get((int) $warehouseId);
                if (! $warehouse) {
                    continue;
                }

                try {
                    $results[] = $this->createForWarehouse(
                        $warehouse,
                        (int) $warehouse->id,
                        $createdBy,
                        $warehouseRows->values()->all(),
                        'direct_distribution'
                    );
                } catch (RuntimeException $e) {
                    foreach ($warehouseRows as $row) {
                        $failedGroups[] = $this->skip(
                            (string) ($row['source_row_id'] ?? $row['row_id'] ?? ''),
                            (string) ($row['product_code'] ?? ''),
                            $e->getMessage()
                        );
                    }
                }
            }

            if ($results === []) {
                $firstReason = $failedGroups[0]['reason'] ?? '直送分配の発注候補を作成できる店舗がありません。';

                throw new RuntimeException($firstReason);
            }

            $merged = $this->mergeCreateResults($results);
            if ($failedGroups !== []) {
                $merged['skipped_count'] = (int) ($merged['skipped_count'] ?? 0) + count($failedGroups);
                $merged['skipped'] = array_slice(array_merge($merged['skipped'] ?? [], $failedGroups), 0, 20);
            }

            return $merged;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function findExistingGenerated(int $selectedWarehouseId, array $rows, string $mode = 'allocation'): array
    {
        if ($mode === 'direct') {
            return $this->findExistingGeneratedDirect($selectedWarehouseId, $rows);
        }

        $warehouse = $this->resolveWarehouse($selectedWarehouseId);

        return $this->findExistingGeneratedForWarehouse($warehouse, $rows, 'distribution');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function findExistingGeneratedDirect(int $selectedWarehouseId, array $rows): array
    {
        $expandedRows = $this->expandRowsForDirectDestinations($selectedWarehouseId, $rows);

        if ($expandedRows === []) {
            return $this->emptyExistingGeneratedResult();
        }

        $warehouseIds = collect($expandedRows)
            ->pluck('target_warehouse_id')
            ->filter()
            ->map(fn ($warehouseId): int => (int) $warehouseId)
            ->unique()
            ->values()
            ->all();

        $warehouses = collect($warehouseIds)
            ->mapWithKeys(fn (int $warehouseId): array => [$warehouseId => $this->resolveWarehouse($warehouseId)]);

        $results = collect($expandedRows)
            ->groupBy(fn (array $row): int => (int) ($row['target_warehouse_id'] ?? 0))
            ->map(function (Collection $warehouseRows, int $warehouseId) use ($warehouses): array {
                $warehouse = $warehouses->get($warehouseId);

                return $warehouse
                    ? $this->findExistingGeneratedForWarehouse($warehouse, $warehouseRows->values()->all(), 'direct_distribution')
                    : $this->emptyExistingGeneratedResult();
            })
            ->values()
            ->all();

        return $this->mergeExistingGeneratedResults($results);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function findExistingGeneratedForWarehouse(object $warehouse, array $rows, string $source): array
    {
        $incomingWarehouseId = (int) ($warehouse->stock_warehouse_id ?? $warehouse->id);
        $items = $this->resolveItems($rows);
        $itemIds = $items->keys()->map(fn ($id): int => (int) $id)->values()->all();

        if ($itemIds === []) {
            return $this->emptyExistingGeneratedResult();
        }

        $excludedContractorIds = array_values(array_filter([$this->resolveHqTransferContractorId()]));
        $rows = $this->hydrateRowPartnerIds($rows, $excludedContractorIds);
        $itemContractors = $this->resolveItemContractors($incomingWarehouseId, $itemIds, $rows, $excludedContractorIds);
        $existingGeneratedCandidates = $this->loadExistingGeneratedCandidates((int) $warehouse->id, $itemIds);
        $rowStatuses = [];
        $candidateIds = [];

        foreach ($rows as $row) {
            $rowId = (string) ($row['row_id'] ?? '');
            $resultRowId = (string) ($row['source_row_id'] ?? $rowId);
            $dedupeRowIds = $this->distributionDedupeRowIds($row, $rowId);
            if ($resultRowId === '') {
                continue;
            }

            $item = $this->resolveRowItem($items, $row);
            if (! $item) {
                continue;
            }

            $itemId = (int) $item->id;
            $itemContractor = $this->resolveRowItemContractor($itemContractors, $itemId, $row, $excludedContractorIds);
            if (! $itemContractor) {
                continue;
            }

            $quantities = $this->resolveQuantities($row, $item);
            if ($quantities === []) {
                continue;
            }

            $rowStatuses[$resultRowId] ??= ['expected' => 0, 'existing' => 0];

            foreach ($quantities as $quantityTypeValue => $quantity) {
                if ((int) $quantity <= 0) {
                    continue;
                }

                $quantityType = QuantityType::from($quantityTypeValue);
                $orderDedupeKeys = collect($dedupeRowIds)
                    ->map(fn (string $dedupeRowId): string => $this->orderDedupeKey(
                        $source,
                        (int) $warehouse->id,
                        $itemId,
                        (int) $itemContractor->contractor_id,
                        (int) $itemContractor->supplier_id,
                        $quantityType,
                        $dedupeRowId
                    ));

                $rowStatuses[$resultRowId]['expected']++;

                if ($existing = $this->firstExistingGeneratedCandidate($existingGeneratedCandidates, $orderDedupeKeys)) {
                    $rowStatuses[$resultRowId]['existing']++;
                    $candidateIds[] = (int) ($existing['candidate_id'] ?? 0);
                }
            }
        }

        return $this->buildExistingGeneratedResult($rowStatuses, $candidateIds);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function mergeExistingGeneratedResults(array $results): array
    {
        $rowStatuses = [];
        $candidateIds = [];

        foreach ($results as $result) {
            foreach (($result['row_statuses'] ?? []) as $rowId => $status) {
                $rowStatuses[$rowId] ??= ['expected' => 0, 'existing' => 0];
                $rowStatuses[$rowId]['expected'] += (int) ($status['expected'] ?? 0);
                $rowStatuses[$rowId]['existing'] += (int) ($status['existing'] ?? 0);
            }

            $candidateIds = array_merge($candidateIds, $result['candidate_ids'] ?? []);
        }

        return $this->buildExistingGeneratedResult($rowStatuses, $candidateIds);
    }

    /**
     * @param  array<string, array{expected: int, existing: int}>  $rowStatuses
     * @param  array<int, int>  $candidateIds
     * @return array<string, mixed>
     */
    private function buildExistingGeneratedResult(array $rowStatuses, array $candidateIds): array
    {
        $alreadyGeneratedRowIds = collect($rowStatuses)
            ->filter(fn (array $status): bool => (int) ($status['expected'] ?? 0) > 0 && (int) ($status['existing'] ?? 0) >= (int) ($status['expected'] ?? 0))
            ->keys()
            ->values()
            ->all();

        return [
            'already_generated_count' => count($alreadyGeneratedRowIds),
            'already_generated_row_ids' => $alreadyGeneratedRowIds,
            'candidate_ids' => collect($candidateIds)->filter()->unique()->values()->all(),
            'row_statuses' => $rowStatuses,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyExistingGeneratedResult(): array
    {
        return [
            'already_generated_count' => 0,
            'already_generated_row_ids' => [],
            'candidate_ids' => [],
            'row_statuses' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function createForWarehouse(object $warehouse, int $jobWarehouseId, int $createdBy, array $rows, string $source = 'distribution'): array
    {
        $incomingWarehouseId = (int) ($warehouse->stock_warehouse_id ?? $warehouse->id);

        $items = $this->resolveItems($rows);
        $itemIds = $items->keys()->map(fn ($id): int => (int) $id)->values()->all();

        if ($itemIds === []) {
            throw new RuntimeException('発注候補にできる商品がありません。商品マスタを選択してください。');
        }

        $excludedContractorIds = array_values(array_filter([$this->resolveHqTransferContractorId()]));
        $rows = $this->hydrateRowPartnerIds($rows, $excludedContractorIds);
        $itemContractors = $this->resolveItemContractors($incomingWarehouseId, $itemIds, $rows, $excludedContractorIds);
        $orderingCodes = $this->resolveOrderingCodes($itemIds);
        $stockByItem = $this->resolveAvailableStocks((int) $warehouse->id, $itemIds);
        $incomingByItem = $this->resolveIncomingQuantities($incomingWarehouseId, $itemIds);

        $existingCandidates = $this->loadExistingCandidates((int) $warehouse->id, $items->keys()->all(), $createdBy);
        $existingGeneratedCandidates = $this->loadExistingGeneratedCandidates((int) $warehouse->id, $items->keys()->all());

        $job = null;
        $created = 0;
        $updated = 0;
        $alreadyGenerated = 0;
        $skipped = [];
        $candidateIds = [];
        $currentRequestCandidateIds = [];
        $rowIds = [];
        $generatedRowIds = [];
        $alreadyGeneratedRowIds = [];
        $now = now();

        foreach ($rows as $row) {
            $rowId = (string) ($row['row_id'] ?? '');
            $dedupeRowIds = $this->distributionDedupeRowIds($row, $rowId);
            $dedupeRowId = $dedupeRowIds[0] ?? $rowId;
            $item = $this->resolveRowItem($items, $row);

            if (! $item) {
                $skipped[] = $this->skip($rowId, (string) ($row['product_code'] ?? ''), '商品マスタが見つかりません');

                continue;
            }

            if (($item->end_of_sale_type ?? 'NORMAL') !== 'NORMAL' || (bool) ($item->is_ended ?? false)) {
                $skipped[] = $this->skip($rowId, (string) $item->code, '販売終了商品です');

                continue;
            }

            $itemId = (int) $item->id;
            $itemContractor = $this->resolveRowItemContractor($itemContractors, $itemId, $row, $excludedContractorIds);
            if (! $itemContractor) {
                $skipped[] = $this->skip($rowId, (string) $item->code, '発注先マスタが未設定です');

                continue;
            }

            $quantities = $this->resolveQuantities($row, $item);
            if ($quantities === []) {
                $skipped[] = $this->skip($rowId, (string) $item->code, '発注数量が0です');

                continue;
            }

            $allocTotal = max(0, (int) ($row['alloc_total'] ?? 0));
            $expectedArrivalDate = $this->resolveExpectedArrivalDate($row);
            $orderingCode = $orderingCodes->get($itemId);
            $searchCode = filled($orderingCode) ? (string) $orderingCode : null;
            $orderingCode = filled($orderingCode) ? str_pad((string) $orderingCode, 13, '0', STR_PAD_LEFT) : null;
            $capacityCase = max(1, (int) ($item->capacity_case ?? 1));
            $currentStock = (int) ($stockByItem->get($itemId) ?? 0);
            $incomingQuantity = (int) ($incomingByItem->get($itemId) ?? 0);
            $demandBreakdown = $this->buildDemandBreakdown($row);
            $originWarehouseIds = collect($demandBreakdown)
                ->pluck('warehouse_id')
                ->filter()
                ->unique()
                ->implode(',');

            foreach ($quantities as $quantityTypeValue => $quantity) {
                $quantityType = QuantityType::from($quantityTypeValue);
                $orderDedupeKey = $this->orderDedupeKey(
                    $source,
                    (int) $warehouse->id,
                    $itemId,
                    (int) $itemContractor->contractor_id,
                    (int) $itemContractor->supplier_id,
                    $quantityType,
                    $dedupeRowId
                );
                $legacyOrderDedupeKeys = collect(array_slice($dedupeRowIds, 1))
                    ->map(fn (string $legacyRowId): string => $this->orderDedupeKey(
                        $source,
                        (int) $warehouse->id,
                        $itemId,
                        (int) $itemContractor->contractor_id,
                        (int) $itemContractor->supplier_id,
                        $quantityType,
                        $legacyRowId
                    ));
                $existingGeneratedCandidate = $this->firstExistingGeneratedCandidate(
                    $existingGeneratedCandidates,
                    collect([$orderDedupeKey])->concat($legacyOrderDedupeKeys)
                );
                $resultRowId = (string) ($row['source_row_id'] ?? $rowId);

                if ($existingGeneratedCandidate) {
                    $alreadyGenerated++;
                    $candidateIds[] = (int) $existingGeneratedCandidate['candidate_id'];
                    $rowIds[$resultRowId] = $resultRowId;
                    $alreadyGeneratedRowIds[$resultRowId] = $resultRowId;
                    $skipped[] = $this->skip($resultRowId, (string) $item->code, '発注候補生成済みです');

                    continue;
                }

                $existingKey = $this->candidateKey($itemId, (int) $itemContractor->contractor_id, (int) $itemContractor->supplier_id, $quantityType);
                $existing = $existingCandidates->get($existingKey, collect());
                $existingDist = $existing->first(fn (WmsOrderCandidate $candidate): bool => $candidate->origin_type === OriginType::DIST);

                if (! $existingDist && $existing->isNotEmpty()) {
                    $skipped[] = $this->skip($rowId, (string) $item->code, '同じ商品の未確定候補が既に存在します');

                    continue;
                }

                $purchaseUnitPrice = $quantityType === QuantityType::CASE
                    ? $item->current_price?->purchase_case_price
                    : $item->current_price?->purchase_unit_price;
                $totalPieces = $quantityType === QuantityType::CASE
                    ? $quantity * $capacityCase
                    : $quantity;
                $job ??= $this->findOrCreateJob($jobWarehouseId, $createdBy);

                $candidateData = [
                    'batch_code' => $job->batch_code,
                    'warehouse_id' => (int) $warehouse->id,
                    'item_id' => $itemId,
                    'item_code' => (string) $item->code,
                    'search_code' => $searchCode,
                    'ordering_code' => $orderingCode,
                    'contractor_id' => (int) $itemContractor->contractor_id,
                    'supplier_id' => (int) $itemContractor->supplier_id,
                    'purchase_unit_price' => $purchaseUnitPrice,
                    'self_shortage_qty' => $allocTotal,
                    'satellite_demand_qty' => 0,
                    'demand_breakdown' => $demandBreakdown !== [] ? $demandBreakdown : null,
                    'origin_warehouse_ids' => $originWarehouseIds !== '' ? $originWarehouseIds : null,
                    'suggested_quantity' => $totalPieces,
                    'order_quantity' => $quantity,
                    'current_effective_stock' => $currentStock,
                    'incoming_quantity' => $incomingQuantity,
                    'safety_stock' => (int) ($itemContractor->safety_stock ?? 0),
                    'calculated_shortage_qty' => $allocTotal,
                    'purchase_unit' => max(1, (int) ($itemContractor->purchase_unit ?? 1)),
                    'quantity_type' => $quantityType,
                    'expected_arrival_date' => $expectedArrivalDate,
                    'original_arrival_date' => $expectedArrivalDate,
                    'status' => CandidateStatus::PENDING,
                    'lot_status' => LotStatus::RAW,
                    'origin_type' => OriginType::DIST,
                    'is_manually_modified' => true,
                    'modified_by' => $createdBy,
                    'modified_at' => $now,
                ];

                if ($existingDist) {
                    if (isset($currentRequestCandidateIds[(int) $existingDist->id])) {
                        $candidateData['order_quantity'] = (int) $existingDist->order_quantity + $quantity;
                        $candidateData['suggested_quantity'] = (int) $existingDist->suggested_quantity + $totalPieces;
                        $candidateData['self_shortage_qty'] = (int) $existingDist->self_shortage_qty + $allocTotal;
                        $candidateData['calculated_shortage_qty'] = (int) $existingDist->calculated_shortage_qty + $allocTotal;
                        $candidateData['demand_breakdown'] = array_values(array_merge(
                            (array) ($existingDist->demand_breakdown ?? []),
                            $demandBreakdown
                        ));
                    }

                    $existingDist->update($candidateData);
                    $candidate = $existingDist->refresh();
                    $updated++;
                } else {
                    $candidate = WmsOrderCandidate::create($candidateData);
                    $created++;
                    $existingCandidates->put($existingKey, collect([$candidate]));
                }

                $candidateIds[] = (int) $candidate->id;
                $currentRequestCandidateIds[(int) $candidate->id] = true;
                $rowIds[$resultRowId] = $resultRowId;
                $generatedRowIds[$resultRowId] = $resultRowId;
                $existingGeneratedCandidates->put($orderDedupeKey, [
                    'candidate_id' => (int) $candidate->id,
                    'row_id' => $resultRowId,
                ]);

                WmsOrderCalculationLog::create([
                    'batch_code' => $job->batch_code,
                    'warehouse_id' => (int) $warehouse->id,
                    'item_id' => $itemId,
                    'calculation_type' => CalculationType::EXTERNAL,
                    'contractor_id' => (int) $itemContractor->contractor_id,
                    'source_warehouse_id' => null,
                    'current_effective_stock' => $currentStock,
                    'incoming_quantity' => $incomingQuantity,
                    'safety_stock_setting' => (int) ($itemContractor->safety_stock ?? 0),
                    'lead_time_days' => max(0, Carbon::today()->diffInDays(Carbon::parse($expectedArrivalDate), false)),
                    'calculated_shortage_qty' => $allocTotal,
                    'calculated_order_quantity' => $quantity,
                    'calculation_details' => [
                        'source' => $source,
                        'row_id' => $rowId,
                        'source_row_id' => $row['source_row_id'] ?? null,
                        'distribution_business_key' => $dedupeRowId,
                        'distribution_order_dedupe_key' => $orderDedupeKey,
                        'candidate_id' => (int) $candidate->id,
                        'quantity_type' => $quantityType->value,
                        'contractor_id' => (int) $itemContractor->contractor_id,
                        'supplier_id' => (int) $itemContractor->supplier_id,
                        'allocation_total' => $allocTotal,
                        'order_case_qty' => max(0, (int) ($row['po_case'] ?? 0)),
                        'order_piece_qty' => max(0, (int) ($row['po_each'] ?? 0)),
                        'units_per_case' => max(1, (int) ($row['units_per_case'] ?? $capacityCase)),
                        'demand_breakdown' => $demandBreakdown,
                        'created_by' => $createdBy,
                        'created_at' => $now->toDateTimeString(),
                    ],
                ]);
            }
        }

        if ($created === 0 && $updated === 0 && $candidateIds === []) {
            $firstReason = $skipped[0]['reason'] ?? '発注候補にできる行がありません';

            throw new RuntimeException($firstReason);
        }

        if ($job) {
            $job->markAsSuccess($created + $updated, [
                'source' => $source,
                'created' => $created,
                'updated' => $updated,
                'already_generated' => $alreadyGenerated,
                'skipped' => count($skipped),
            ]);
        }

        return [
            'batch_code' => $job?->batch_code ?? '',
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => count($skipped),
            'already_generated_count' => $alreadyGenerated,
            'candidate_count' => $created + $updated,
            'candidate_ids' => array_values(array_unique($candidateIds)),
            'row_ids' => array_values($rowIds),
            'generated_row_ids' => array_values($generatedRowIds),
            'already_generated_row_ids' => array_values($alreadyGeneratedRowIds),
            'skipped' => array_slice($skipped, 0, 20),
            'redirect_url' => '/admin/wms-order-candidates',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function mergeCreateResults(array $results): array
    {
        $batchCodes = collect($results)
            ->pluck('batch_code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'batch_code' => $batchCodes[0] ?? '',
            'batch_codes' => $batchCodes,
            'created_count' => array_sum(array_map(fn (array $result): int => (int) ($result['created_count'] ?? 0), $results)),
            'updated_count' => array_sum(array_map(fn (array $result): int => (int) ($result['updated_count'] ?? 0), $results)),
            'skipped_count' => array_sum(array_map(fn (array $result): int => (int) ($result['skipped_count'] ?? 0), $results)),
            'already_generated_count' => array_sum(array_map(fn (array $result): int => (int) ($result['already_generated_count'] ?? 0), $results)),
            'candidate_count' => array_sum(array_map(fn (array $result): int => (int) ($result['candidate_count'] ?? 0), $results)),
            'candidate_ids' => collect($results)
                ->flatMap(fn (array $result): array => $result['candidate_ids'] ?? [])
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'row_ids' => collect($results)
                ->flatMap(fn (array $result): array => $result['row_ids'] ?? [])
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'generated_row_ids' => collect($results)
                ->flatMap(fn (array $result): array => $result['generated_row_ids'] ?? [])
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'already_generated_row_ids' => collect($results)
                ->flatMap(fn (array $result): array => $result['already_generated_row_ids'] ?? [])
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'skipped' => collect($results)
                ->flatMap(fn (array $result): array => $result['skipped'] ?? [])
                ->take(20)
                ->values()
                ->all(),
            'redirect_url' => '/admin/wms-order-candidates',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function expandRowsForDirectDestinations(int $selectedWarehouseId, array $rows): array
    {
        $expanded = [];

        foreach ($rows as $row) {
            $sourceRowId = (string) ($row['row_id'] ?? '');
            $allocations = is_array($row['allocations'] ?? null) ? $row['allocations'] : [];

            foreach ($allocations as $allocation) {
                $destinationId = (int) ($allocation['destination_id'] ?? 0);
                $quantity = (int) ($allocation['quantity'] ?? 0);

                if ($destinationId <= 0 || $quantity <= 0) {
                    continue;
                }

                $directRow = $row;
                $directRow['row_id'] = $sourceRowId.':dest:'.$destinationId;
                $directRow['source_row_id'] = $sourceRowId;
                $directRow['source_selected_warehouse_id'] = $selectedWarehouseId;
                $directRow['target_warehouse_id'] = $destinationId;
                $directRow['alloc_total'] = $quantity;
                $directRow['po_case'] = 0;
                $directRow['po_each'] = 0;
                $directRow['allocations'] = [[
                    'destination_id' => $destinationId,
                    'destination_key' => (string) ($allocation['destination_key'] ?? ''),
                    'destination_name' => (string) ($allocation['destination_name'] ?? ''),
                    'quantity' => $quantity,
                ]];

                $expanded[] = $directRow;
            }
        }

        return $expanded;
    }

    private function resolveWarehouse(int $selectedWarehouseId): object
    {
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('id', $selectedWarehouseId)
            ->where('is_active', true)
            ->first(['id', 'code', 'name', 'stock_warehouse_id']);

        if (! $warehouse) {
            throw new RuntimeException('倉庫が選択されていません。ヘッダーの倉庫選択を確認してください。');
        }

        return $warehouse;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, Item>
     */
    private function resolveItems(array $rows): Collection
    {
        $itemIds = collect($rows)
            ->pluck('item_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $productCodes = collect($rows)
            ->pluck('product_code')
            ->filter()
            ->map(fn ($code): string => (string) $code)
            ->unique()
            ->values()
            ->all();

        if ($itemIds === [] && $productCodes === []) {
            return collect();
        }

        return Item::query()
            ->with('current_price')
            ->where('client_id', (int) config('app.client_id'))
            ->where(function ($query) use ($itemIds, $productCodes): void {
                if ($itemIds !== []) {
                    $query->orWhereIn('id', $itemIds);
                }

                if ($productCodes !== []) {
                    $query->orWhereIn('code', $productCodes);
                }
            })
            ->get(['id', 'code', 'name', 'capacity_case', 'end_of_sale_type', 'is_ended'])
            ->keyBy(fn (Item $item): int => (int) $item->id);
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<int, Collection<int, object>>
     */
    private function resolveItemContractors(int $warehouseId, array $itemIds, array $rows = [], array $excludedContractorIds = []): Collection
    {
        $selectedItemContractorIds = collect($rows)
            ->pluck('item_contractor_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $selectedContractorIds = collect($rows)
            ->pluck('contractor_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && ! $this->isExcludedContractorId($id, $excludedContractorIds))
            ->unique()
            ->values()
            ->all();
        $selectedSupplierIds = collect($rows)
            ->pluck('supplier_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('client_id', (int) config('app.client_id'))
            ->whereIn('item_id', $itemIds)
            ->when(
                $excludedContractorIds !== [],
                fn ($query) => $query->whereNotIn('contractor_id', $excludedContractorIds)
            )
            ->where(function ($query) use ($warehouseId, $selectedItemContractorIds, $selectedContractorIds, $selectedSupplierIds): void {
                $query->where('warehouse_id', $warehouseId);

                if ($selectedItemContractorIds !== []) {
                    $query->orWhereIn('id', $selectedItemContractorIds);
                }

                if ($selectedContractorIds !== []) {
                    $query->orWhereIn('contractor_id', $selectedContractorIds);
                }

                if ($selectedSupplierIds !== []) {
                    $query->orWhereIn('supplier_id', $selectedSupplierIds);
                }
            })
            ->orderByDesc('is_auto_order')
            ->orderBy('id')
            ->get(['id', 'item_id', 'contractor_id', 'supplier_id', 'safety_stock', 'purchase_unit'])
            ->groupBy(fn ($row): int => (int) $row->item_id)
            ->map(fn (Collection $rows): Collection => $rows->values());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateRowPartnerIds(array $rows, array $excludedContractorIds = []): array
    {
        $contractorLookup = $this->resolveContractorIdsForRows($rows);
        $supplierLookup = $this->resolveSupplierIdsForRows($rows);

        return array_map(function (array $row) use ($contractorLookup, $supplierLookup, $excludedContractorIds): array {
            $contractorId = (int) ($row['contractor_id'] ?? 0);
            if ($contractorId <= 0) {
                $contractorId = $this->lookupPartnerIdForRow($contractorLookup, $row, 'order_to_code', 'order_to');
                if ($contractorId > 0 && ! $this->isExcludedContractorId($contractorId, $excludedContractorIds)) {
                    $row['contractor_id'] = $contractorId;
                }
            }

            $supplierId = (int) ($row['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                $supplierId = $this->lookupPartnerIdForRow($supplierLookup, $row, 'supplier_code', 'supplier');
                if ($supplierId > 0) {
                    $row['supplier_id'] = $supplierId;
                }
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{codes: array<string, int>, names: array<string, int>}
     */
    private function resolveContractorIdsForRows(array $rows): array
    {
        $codes = $this->collectPartnerCodes($rows, ['order_to_code', 'order_to']);
        $names = $this->collectPartnerNames($rows, ['order_to']);

        if ($codes === [] && $names === []) {
            return ['codes' => [], 'names' => []];
        }

        $query = DB::connection('sakemaru')
            ->table('contractors')
            ->where('client_id', (int) config('app.client_id'))
            ->where('is_active', true)
            ->where(function ($query) use ($codes, $names): void {
                if ($codes !== []) {
                    $query->where(fn ($codeQuery) => $this->wherePartnerCodeMatches($codeQuery, 'code', $codes));
                }

                if ($names !== []) {
                    $method = $codes === [] ? 'where' : 'orWhere';
                    $query->{$method}(fn ($nameQuery) => $this->wherePartnerNameMatches($nameQuery, ['name', 'nickname'], $names));
                }
            });

        return $this->buildPartnerLookup(
            $query->get(['id', 'code', 'name', 'nickname']),
            ['name', 'nickname']
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{codes: array<string, int>, names: array<string, int>}
     */
    private function resolveSupplierIdsForRows(array $rows): array
    {
        $codes = $this->collectPartnerCodes($rows, ['supplier_code', 'supplier']);
        $names = $this->collectPartnerNames($rows, ['supplier']);

        if ($codes === [] && $names === []) {
            return ['codes' => [], 'names' => []];
        }

        $query = DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 'p.id', '=', 's.partner_id')
            ->where('s.client_id', (int) config('app.client_id'))
            ->where('p.client_id', (int) config('app.client_id'))
            ->where('p.is_active', true)
            ->where(function ($query) use ($codes, $names): void {
                if ($codes !== []) {
                    $query->where(fn ($codeQuery) => $this->wherePartnerCodeMatches($codeQuery, 'p.code', $codes));
                }

                if ($names !== []) {
                    $method = $codes === [] ? 'where' : 'orWhere';
                    $query->{$method}(fn ($nameQuery) => $this->wherePartnerNameMatches($nameQuery, ['p.name', 'p.nickname', 'p.abbreviation'], $names));
                }
            });

        return $this->buildPartnerLookup(
            $query->get(['s.id', 'p.code', 'p.name', 'p.nickname', 'p.abbreviation']),
            ['name', 'nickname', 'abbreviation']
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function collectPartnerCodes(array $rows, array $keys): array
    {
        $codes = [];

        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $code = $this->normalizePartnerCode($row[$key] ?? '');
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function collectPartnerNames(array $rows, array $keys): array
    {
        $names = [];

        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $name = $this->normalizePartnerName($row[$key] ?? '');
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  Collection<int, object>  $partners
     * @param  array<int, string>  $nameColumns
     * @return array{codes: array<string, int>, names: array<string, int>}
     */
    private function buildPartnerLookup(Collection $partners, array $nameColumns): array
    {
        $lookup = ['codes' => [], 'names' => []];

        foreach ($partners as $partner) {
            $id = (int) ($partner->id ?? 0);
            if ($id <= 0) {
                continue;
            }

            $code = $this->normalizePartnerCode($partner->code ?? '');
            if ($code !== '' && ! isset($lookup['codes'][$code])) {
                $lookup['codes'][$code] = $id;
            }

            foreach ($nameColumns as $column) {
                $name = $this->normalizePartnerName($partner->{$column} ?? '');
                if ($name !== '' && ! isset($lookup['names'][$name])) {
                    $lookup['names'][$name] = $id;
                }
            }
        }

        return $lookup;
    }

    private function wherePartnerCodeMatches($query, string $column, array $codes): void
    {
        $codes = array_values(array_unique(array_filter($codes, fn (string $code): bool => $code !== '')));

        if ($codes === []) {
            return;
        }

        $castColumn = "CAST({$column} AS CHAR)";
        $query
            ->whereIn(DB::raw($castColumn), $codes)
            ->orWhereIn(DB::raw("TRIM(LEADING '0' FROM {$castColumn})"), $codes);
    }

    private function wherePartnerNameMatches($query, array $columns, array $names): void
    {
        $names = array_values(array_unique(array_filter($names, fn (string $name): bool => $name !== '')));

        foreach ($columns as $index => $column) {
            if ($index === 0) {
                $query->whereIn($column, $names);
            } else {
                $query->orWhereIn($column, $names);
            }
        }
    }

    /**
     * @param  array{codes: array<string, int>, names: array<string, int>}  $lookup
     */
    private function lookupPartnerIdForRow(array $lookup, array $row, string $codeKey, string $nameKey): int
    {
        $code = $this->normalizePartnerCode($row[$codeKey] ?? '');
        if ($code === '') {
            $code = $this->normalizePartnerCode($row[$nameKey] ?? '');
        }

        if ($code !== '' && isset($lookup['codes'][$code])) {
            return (int) $lookup['codes'][$code];
        }

        $name = $this->normalizePartnerName($row[$nameKey] ?? '');

        return $name !== '' ? (int) ($lookup['names'][$name] ?? 0) : 0;
    }

    private function normalizePartnerCode(mixed $value): string
    {
        $text = $this->normalizeSearchText((string) $value);
        if ($text === '') {
            return '';
        }

        if (preg_match('/\[([^\]]+)\]/u', $text, $matches)) {
            return $this->canonicalPartnerCode($matches[1]);
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $text) === 1) {
            return $this->canonicalPartnerCode($text);
        }

        return '';
    }

    private function canonicalPartnerCode(mixed $value): string
    {
        $code = $this->normalizeSearchText((string) $value);

        if ($code === '') {
            return '';
        }

        return ctype_digit($code) ? (ltrim($code, '0') ?: '0') : $code;
    }

    private function normalizePartnerName(mixed $value): string
    {
        $text = $this->normalizeSearchText((string) $value);
        if ($text === '') {
            return '';
        }

        return trim((string) preg_replace('/^\[[^\]]+\]\s*/', '', $text));
    }

    private function normalizeSearchText(string $search): string
    {
        $normalizedSearch = function_exists('mb_convert_kana')
            ? mb_convert_kana($search, 'as')
            : $search;

        return trim($normalizedSearch);
    }

    /**
     * @param  Collection<int, Collection<int, object>>  $itemContractors
     */
    private function resolveRowItemContractor(Collection $itemContractors, int $itemId, array $row, array $excludedContractorIds = []): ?object
    {
        $rows = $itemContractors->get($itemId, collect());
        if ($rows->isEmpty()) {
            return $this->makeRowItemContractorFallback($itemId, $row, $excludedContractorIds);
        }

        $itemContractorId = (int) ($row['item_contractor_id'] ?? 0);
        if ($itemContractorId > 0) {
            $matched = $rows->first(fn ($candidate): bool => (int) ($candidate->id ?? 0) === $itemContractorId);
            if ($matched) {
                return $matched;
            }

            $fallback = $this->makeRowItemContractorFallback($itemId, $row, $excludedContractorIds);
            if ($fallback) {
                return $fallback;
            }
        }

        $contractorId = (int) ($row['contractor_id'] ?? 0);
        $supplierId = (int) ($row['supplier_id'] ?? 0);

        if ($this->isExcludedContractorId($contractorId, $excludedContractorIds)) {
            return $rows->first();
        }

        if ($contractorId > 0 || $supplierId > 0) {
            $matched = $rows->first(function ($candidate) use ($contractorId, $supplierId): bool {
                if ($contractorId > 0 && (int) ($candidate->contractor_id ?? 0) !== $contractorId) {
                    return false;
                }

                if ($supplierId > 0 && (int) ($candidate->supplier_id ?? 0) !== $supplierId) {
                    return false;
                }

                return true;
            });

            if ($matched) {
                return $matched;
            }

            return $this->makeRowItemContractorFallback($itemId, $row, $excludedContractorIds);
        }

        return $rows->first();
    }

    private function makeRowItemContractorFallback(int $itemId, array $row, array $excludedContractorIds = []): ?object
    {
        $contractorId = (int) ($row['contractor_id'] ?? 0);
        if ($contractorId <= 0 || $this->isExcludedContractorId($contractorId, $excludedContractorIds)) {
            return null;
        }

        return (object) [
            'id' => (int) ($row['item_contractor_id'] ?? 0),
            'item_id' => $itemId,
            'contractor_id' => $contractorId,
            'supplier_id' => (int) ($row['supplier_id'] ?? 0),
            'safety_stock' => (int) ($row['order_point'] ?? 0),
            'purchase_unit' => max(1, (int) ($row['purchase_unit'] ?? $row['units_per_case'] ?? 1)),
        ];
    }

    private function resolveHqTransferContractorId(): int
    {
        if ($this->hqTransferContractorId !== null) {
            return $this->hqTransferContractorId;
        }

        $contractorId = DB::connection('sakemaru')
            ->table('contractors')
            ->where('code', self::HQ_TRANSFER_CONTRACTOR_CODE)
            ->value('id');

        $this->hqTransferContractorId = (int) ($contractorId ?? 0);

        return $this->hqTransferContractorId;
    }

    private function isExcludedContractorId(int $contractorId, array $excludedContractorIds): bool
    {
        return $contractorId > 0 && in_array($contractorId, $excludedContractorIds, true);
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<int, string>
     */
    private function resolveOrderingCodes(array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('client_id', (int) config('app.client_id'))
            ->whereIn('item_id', $itemIds)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->whereNotNull('search_string')
            ->orderBy('id')
            ->get(['item_id', 'search_string'])
            ->groupBy(fn ($row): int => (int) $row->item_id)
            ->map(fn (Collection $rows): string => (string) $rows->first()->search_string);
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<int, int>
     */
    private function resolveAvailableStocks(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('client_id', (int) config('app.client_id'))
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id')
            ->get([
                'item_id',
                DB::raw('COALESCE(SUM(available_quantity), 0) as quantity'),
            ])
            ->keyBy(fn ($row): int => (int) $row->item_id)
            ->map(fn ($row): int => (int) $row->quantity);
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<int, int>
     */
    private function resolveIncomingQuantities(int $warehouseId, array $itemIds): Collection
    {
        return DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->where('warehouse_id', $warehouseId)
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
                DB::raw('COALESCE(SUM(expected_quantity - received_quantity), 0) as quantity'),
            ])
            ->keyBy(fn ($row): int => (int) $row->item_id)
            ->map(fn ($row): int => (int) $row->quantity);
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<string, Collection<int, WmsOrderCandidate>>
     */
    private function loadExistingCandidates(int $warehouseId, array $itemIds, int $createdBy): Collection
    {
        return WmsOrderCandidate::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)
            ->where('status', CandidateStatus::PENDING)
            ->forCreatedBy($createdBy)
            ->get()
            ->groupBy(fn (WmsOrderCandidate $candidate): string => $this->candidateKey(
                (int) $candidate->item_id,
                (int) $candidate->contractor_id,
                (int) $candidate->supplier_id,
                $candidate->quantity_type instanceof QuantityType
                    ? $candidate->quantity_type
                    : QuantityType::from((string) $candidate->quantity_type)
            ));
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<string, array{candidate_id: int, row_id: string}>
     */
    private function loadExistingGeneratedCandidates(int $warehouseId, array $itemIds): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        $rows = DB::connection('sakemaru')
            ->table('wms_order_calculation_logs as logs')
            ->join('wms_order_candidates as candidates', function ($join): void {
                $join->on('candidates.batch_code', '=', 'logs.batch_code')
                    ->on('candidates.warehouse_id', '=', 'logs.warehouse_id')
                    ->on('candidates.item_id', '=', 'logs.item_id')
                    ->on('candidates.contractor_id', '=', 'logs.contractor_id');
            })
            ->where('logs.warehouse_id', $warehouseId)
            ->whereIn('logs.item_id', $itemIds)
            ->where('logs.calculation_type', CalculationType::EXTERNAL->value)
            ->where('candidates.origin_type', OriginType::DIST->value)
            ->where(function ($query): void {
                $source = "JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.source'))";
                $dedupeKey = "JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.distribution_order_dedupe_key'))";

                $query->whereRaw("{$source} IN (?, ?)", ['distribution', 'direct_distribution'])
                    ->orWhereRaw("{$dedupeKey} IS NOT NULL");
            })
            ->get([
                'candidates.id as candidate_id',
                'candidates.warehouse_id',
                'candidates.item_id',
                'candidates.contractor_id',
                'candidates.supplier_id',
                'candidates.quantity_type',
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.source')) as source"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.row_id')) as row_id"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.source_row_id')) as source_row_id"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.distribution_order_dedupe_key')) as distribution_order_dedupe_key"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.quantity_type')) as logged_quantity_type"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.supplier_id')) as logged_supplier_id"),
                DB::raw("CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.order_case_qty')), '0') AS UNSIGNED) as order_case_qty"),
                DB::raw("CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(logs.calculation_details, '$.order_piece_qty')), '0') AS UNSIGNED) as order_piece_qty"),
            ]);

        return $rows->reduce(function (Collection $carry, object $row): Collection {
            $quantityType = QuantityType::tryFrom((string) $row->quantity_type);
            if (! $quantityType) {
                return $carry;
            }

            if (filled($row->logged_quantity_type ?? null) && (string) $row->logged_quantity_type !== $quantityType->value) {
                return $carry;
            }

            if (filled($row->logged_supplier_id ?? null) && (int) $row->logged_supplier_id !== (int) ($row->supplier_id ?? 0)) {
                return $carry;
            }

            $source = (string) ($row->source ?? '');
            $rowId = (string) ($row->row_id ?? '');
            $dedupeKeys = collect([(string) ($row->distribution_order_dedupe_key ?? '')])
                ->filter();
            $hasLegacyQuantity = ($quantityType === QuantityType::CASE && (int) ($row->order_case_qty ?? 0) > 0)
                || ($quantityType === QuantityType::PIECE && (int) ($row->order_piece_qty ?? 0) > 0);

            if ($source !== '' && $rowId !== '' && $hasLegacyQuantity) {
                $dedupeKeys->push($this->orderDedupeKey(
                    $source,
                    (int) $row->warehouse_id,
                    (int) $row->item_id,
                    (int) $row->contractor_id,
                    (int) ($row->supplier_id ?? 0),
                    $quantityType,
                    $rowId
                ));
            }

            $dedupeKeys->unique()->each(function (string $dedupeKey) use ($carry, $row, $rowId): void {
                if (! $carry->has($dedupeKey)) {
                    $carry->put($dedupeKey, [
                        'candidate_id' => (int) $row->candidate_id,
                        'row_id' => (string) ($row->source_row_id ?: $rowId),
                    ]);
                }
            });

            return $carry;
        }, collect());
    }

    private function orderDedupeKey(
        string $source,
        int $warehouseId,
        int $itemId,
        int $contractorId,
        int $supplierId,
        QuantityType $quantityType,
        string $rowId
    ): string {
        return sha1(json_encode([
            'source' => $source,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'contractor_id' => $contractorId,
            'supplier_id' => $supplierId,
            'quantity_type' => $quantityType->value,
            'row_id' => $rowId,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function distributionDedupeRowIds(array $row, string $fallbackRowId): array
    {
        $businessKey = trim((string) ($row['distribution_business_key'] ?? ''));
        $keys = [];

        if (preg_match('/^[0-9a-f]{40}$/i', $businessKey) === 1) {
            $keys[] = strtolower($businessKey);
        }

        if ($fallbackRowId !== '') {
            $keys[] = $fallbackRowId;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  Collection<string, array{candidate_id: int, row_id: string}>  $existingCandidates
     * @param  iterable<int, string>  $dedupeKeys
     * @return array{candidate_id: int, row_id: string}|null
     */
    private function firstExistingGeneratedCandidate(Collection $existingCandidates, iterable $dedupeKeys): ?array
    {
        foreach ($dedupeKeys as $dedupeKey) {
            $candidate = $existingCandidates->get($dedupeKey);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function findOrCreateJob(int $warehouseId, int $createdBy): WmsAutoOrderJobControl
    {
        $job = WmsAutoOrderJobControl::findPendingSettlementForWarehouse(
            warehouseId: $warehouseId,
            createdBy: $createdBy,
            processNames: [JobProcessName::ORDER_CALC]
        );

        if ($job) {
            return $job;
        }

        return WmsAutoOrderJobControl::startJob(
            processName: JobProcessName::ORDER_CALC,
            createdBy: $createdBy,
            warehouseId: $warehouseId,
            batchCode: WmsAutoOrderJobControl::generateBatchCode($warehouseId),
        );
    }

    private function resolveRowItem(Collection $items, array $row): ?Item
    {
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemId > 0 && $items->has($itemId)) {
            return $items->get($itemId);
        }

        $productCode = (string) ($row['product_code'] ?? '');

        return $items->first(fn (Item $item): bool => (string) $item->code === $productCode);
    }

    /**
     * @return array<string, int>
     */
    private function resolveQuantities(array $row, Item $item): array
    {
        $caseQty = max(0, (int) ($row['po_case'] ?? 0));
        $pieceQty = max(0, (int) ($row['po_each'] ?? 0));

        if ($caseQty <= 0 && $pieceQty <= 0) {
            $allocTotal = max(0, (int) ($row['alloc_total'] ?? 0));
            if ($allocTotal > 0) {
                $pieceQty = $allocTotal;
            }
        }

        return collect([
            QuantityType::CASE->value => $caseQty,
            QuantityType::PIECE->value => $pieceQty,
        ])
            ->filter(fn (int $quantity): bool => $quantity > 0)
            ->all();
    }

    private function resolveExpectedArrivalDate(array $row): string
    {
        $date = (string) ($row['delivery_date'] ?? '');
        if ($date === '') {
            $date = (string) ($row['order_date'] ?? '');
        }

        if ($date === '') {
            return Carbon::today()->toDateString();
        }

        return Carbon::parse($date)->toDateString();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDemandBreakdown(array $row): array
    {
        return collect($row['allocations'] ?? [])
            ->map(function (array $allocation): ?array {
                $quantity = (int) ($allocation['quantity'] ?? 0);
                if ($quantity <= 0) {
                    return null;
                }

                return [
                    'warehouse_id' => isset($allocation['destination_id']) ? (int) $allocation['destination_id'] : null,
                    'warehouse_key' => (string) ($allocation['destination_key'] ?? ''),
                    'warehouse_name' => (string) ($allocation['destination_name'] ?? ''),
                    'quantity' => $quantity,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function candidateKey(int $itemId, int $contractorId, int $supplierId, QuantityType $quantityType): string
    {
        return "{$itemId}:{$contractorId}:{$supplierId}:{$quantityType->value}";
    }

    /**
     * @return array{row_id: string, product_code: string, reason: string}
     */
    private function skip(string $rowId, string $productCode, string $reason): array
    {
        return [
            'row_id' => $rowId,
            'product_code' => $productCode,
            'reason' => $reason,
        ];
    }
}
