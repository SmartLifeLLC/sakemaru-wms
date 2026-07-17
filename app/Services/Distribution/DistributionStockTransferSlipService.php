<?php

namespace App\Services\Distribution;

use App\Services\WarehouseResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DistributionStockTransferSlipService
{
    private const DISTRIBUTION_DEDUPE_ITEM_KEY = '_distribution_dedupe_key';

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{queue_count: int, line_count: int, row_ids: array<int, string>, queues: array<int, array<string, mixed>>, created_queue_count?: int, skipped_queue_count?: int, skipped_row_ids?: array<int, string>}
     */
    public function create(
        int $selectedWarehouseId,
        int $createdBy,
        array $rows,
        ?string $remark = null,
        ?string $sourceWarehouseCode = null,
        ?string $fixedProcessDate = null,
        string $requestIdPrefix = 'distribution-transfer',
        bool $preventDuplicateByDistributionRow = false
    ): array {
        $sourceWarehouse = $this->resolveSourceWarehouse($selectedWarehouseId, $sourceWarehouseCode);
        $destinations = $this->resolveDestinations($rows);
        $items = $this->resolveItems($rows);
        $processDate = $fixedProcessDate !== null
            ? Carbon::parse($fixedProcessDate)->format('Y-m-d')
            : null;
        $linesByGroup = $this->buildGroupedLines($sourceWarehouse, $destinations, $items, $rows, $processDate, $requestIdPrefix);

        if ($linesByGroup->isEmpty()) {
            throw new RuntimeException('伝票出力できる確定行がありません。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($sourceWarehouse, $linesByGroup, $createdBy, $remark, $preventDuplicateByDistributionRow): array {
            $skippedRows = [];
            $skippedQueues = [];

            if ($preventDuplicateByDistributionRow) {
                [$linesByGroup, $skippedRows, $skippedQueues] = $this->removeAlreadyQueuedDistributionLines($linesByGroup);
            }

            $queues = [];
            $rowIds = [];
            $lineCount = 0;
            $createdQueueCount = 0;
            $skippedQueueCount = count($skippedQueues);

            foreach ($linesByGroup as $group) {
                $queue = $this->upsertStockTransferQueue($sourceWarehouse, $group, $createdBy, $remark);
                $queueId = (int) $queue['queue_id'];
                if ($queue['created']) {
                    $createdQueueCount++;
                } else {
                    $skippedQueueCount++;
                }

                $queues[] = [
                    'queue_id' => $queueId,
                    'request_id' => $group['request_id'],
                    'from_warehouse_code' => (string) $sourceWarehouse->code,
                    'to_warehouse_code' => (string) $group['destination']->code,
                    'process_date' => $group['process_date'],
                    'line_count' => count($group['items']),
                    'created' => (bool) $queue['created'],
                ];

                foreach ($group['row_ids'] as $rowId) {
                    $rowIds[$rowId] = $rowId;
                }

                $lineCount += count($group['items']);
            }

            foreach ($skippedRows as $rowId) {
                $rowIds[$rowId] = $rowId;
            }

            return [
                'queue_count' => $createdQueueCount,
                'created_queue_count' => $createdQueueCount,
                'skipped_queue_count' => $skippedQueueCount,
                'line_count' => $lineCount,
                'row_ids' => array_values($rowIds),
                'skipped_row_ids' => array_values($skippedRows),
                'queues' => array_values(array_merge($queues, $skippedQueues)),
            ];
        });
    }

    private function resolveSourceWarehouse(int $selectedWarehouseId, ?string $sourceWarehouseCode = null): object
    {
        $query = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('is_active', true);

        if ($sourceWarehouseCode !== null && trim($sourceWarehouseCode) !== '') {
            $query->where('code', trim($sourceWarehouseCode));
        } else {
            $sourceWarehouseId = WarehouseResolver::resolveRealWarehouseId($selectedWarehouseId);
            $query->where('id', $sourceWarehouseId);
        }

        $warehouse = $query->first(['id', 'code', 'name']);

        if (! $warehouse) {
            throw new RuntimeException('出庫元倉庫が取得できません。倉庫選択を確認してください。');
        }

        return $warehouse;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, object>
     */
    private function resolveDestinations(array $rows): Collection
    {
        $destinationIds = collect($rows)
            ->flatMap(fn (array $row): array => $row['allocations'] ?? [])
            ->pluck('destination_id')
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($destinationIds === []) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('is_active', true)
            ->whereIn('id', $destinationIds)
            ->get(['id', 'code', 'name', 'stock_warehouse_id'])
            ->keyBy(fn ($warehouse) => (int) $warehouse->id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, object>
     */
    private function resolveItems(array $rows): Collection
    {
        $itemIds = collect($rows)
            ->pluck('item_id')
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $productCodes = collect($rows)
            ->pluck('product_code')
            ->filter(fn ($code): bool => filled($code))
            ->map(fn ($code): string => (string) $code)
            ->unique()
            ->values()
            ->all();

        if ($itemIds === [] && $productCodes === []) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('items')
            ->where('client_id', (int) config('app.client_id'))
            ->where('is_active', true)
            ->where(function ($query) use ($itemIds, $productCodes) {
                if ($itemIds !== []) {
                    $query->orWhereIn('id', $itemIds);
                }

                if ($productCodes !== []) {
                    $query->orWhereIn('code', $productCodes);
                }
            })
            ->get(['id', 'code', 'name'])
            ->keyBy(fn ($item) => (int) $item->id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<string, array<string, mixed>>
     */
    private function buildGroupedLines(
        object $sourceWarehouse,
        Collection $destinations,
        Collection $items,
        array $rows,
        ?string $fixedProcessDate = null,
        string $requestIdPrefix = 'distribution-transfer'
    ): Collection {
        $groups = collect();
        $sourceRealWarehouseId = (int) $sourceWarehouse->id;
        $deliveryCourseIds = [];
        $deliveryCourseExists = [];

        foreach ($rows as $row) {
            $item = $this->resolveRowItem($items, $row);
            if (! $item) {
                throw new RuntimeException('商品マスタが取得できない行があります。商品を選択し直してください。');
            }

            $rowId = (string) ($row['row_id'] ?? '');
            if ($rowId === '') {
                throw new RuntimeException('分配行IDが取得できません。画面を再読み込みしてください。');
            }

            $processDate = $fixedProcessDate ?: $this->resolveProcessDate($row);

            foreach (($row['allocations'] ?? []) as $allocation) {
                $quantity = (int) ($allocation['quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }

                $destinationId = (int) ($allocation['destination_id'] ?? 0);
                $destination = $destinations->get($destinationId);
                if (! $destination) {
                    throw new RuntimeException('分配先倉庫が取得できない行があります。店舗マスタを再読み込みしてください。');
                }

                $destinationRealWarehouseId = (int) ($destination->stock_warehouse_id ?? $destination->id);
                if ($destinationRealWarehouseId === $sourceRealWarehouseId) {
                    throw new RuntimeException('出庫元と同じ倉庫には倉庫移動伝票を作成できません。');
                }

                $rowDeliveryCourseId = (int) ($row['delivery_course_id'] ?? 0);
                if ($rowDeliveryCourseId > 0) {
                    if (! array_key_exists($rowDeliveryCourseId, $deliveryCourseExists)) {
                        $deliveryCourseExists[$rowDeliveryCourseId] = $this->deliveryCourseExists($rowDeliveryCourseId);
                    }

                    if (! $deliveryCourseExists[$rowDeliveryCourseId]) {
                        throw new RuntimeException('配送コースマスタが取得できない行があります。配送コースを選択し直してください。');
                    }

                    $deliveryCourseId = $rowDeliveryCourseId;
                } else {
                    $deliveryCourseKey = "{$sourceRealWarehouseId}:{$destination->id}";
                    if (! array_key_exists($deliveryCourseKey, $deliveryCourseIds)) {
                        $deliveryCourseIds[$deliveryCourseKey] = $this->resolveTransferDeliveryCourseId($sourceRealWarehouseId, (int) $destination->id);
                    }
                    $deliveryCourseId = $deliveryCourseIds[$deliveryCourseKey];
                }
                $groupKey = implode('|', [
                    $sourceRealWarehouseId,
                    (int) $destination->id,
                    $processDate,
                    $deliveryCourseId ?? 'none',
                ]);

                $group = $groups->get($groupKey, [
                    'source_warehouse_id' => $sourceRealWarehouseId,
                    'request_id_prefix' => $requestIdPrefix,
                    'destination' => $destination,
                    'process_date' => $processDate,
                    'delivery_course_id' => $deliveryCourseId,
                    'items' => [],
                    'line_meta' => [],
                    'row_ids' => [],
                    'dedupe_keys' => [],
                ]);

                $detailNote = trim((string) ($row['memo'] ?? ''));
                $dedupeKey = $this->makeDistributionLineDedupeKey($sourceRealWarehouseId, (int) $destination->id, $rowId);

                $group['items'][] = [
                    'item_code' => (string) $item->code,
                    'quantity' => $quantity,
                    'quantity_type' => 'PIECE',
                    'stock_allocation_code' => '1',
                    'note' => $detailNote !== '' ? mb_substr($detailNote, 0, 500) : "分配行ID: {$rowId}",
                    self::DISTRIBUTION_DEDUPE_ITEM_KEY => $dedupeKey,
                ];
                $group['line_meta'][] = [
                    'row_id' => $rowId,
                    'dedupe_key' => $dedupeKey,
                ];
                $group['row_ids'][$rowId] = $rowId;
                $group['dedupe_keys'][$dedupeKey] = $dedupeKey;
                $groups->put($groupKey, $group);
            }
        }

        return $groups->map(fn (array $group): array => $this->assignGroupRequestId($group));
    }

    public function makeDistributionLineDedupeKey(int $sourceWarehouseId, int $destinationWarehouseId, string $rowId): string
    {
        return sha1(json_encode([
            'source_warehouse_id' => $sourceWarehouseId,
            'destination_warehouse_id' => $destinationWarehouseId,
            'row_id' => $rowId,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<int, string>  $dedupeKeys
     */
    public function hasExistingDistributionDedupeKeys(array $dedupeKeys): bool
    {
        return $this->findExistingQueuesByDistributionDedupeKeys($dedupeKeys) !== [];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $linesByGroup
     * @return array{0: Collection<string, array<string, mixed>>, 1: array<int, string>, 2: array<int, array<string, mixed>>}
     */
    private function removeAlreadyQueuedDistributionLines(Collection $linesByGroup): array
    {
        $existingByRequestId = $this->findExistingQueuesByRequestIds(
            $linesByGroup
                ->pluck('request_id')
                ->filter()
                ->unique()
                ->values()
                ->all()
        );
        $skippedRows = [];
        $skippedQueues = [];

        if ($existingByRequestId !== []) {
            $linesByGroup = $linesByGroup
                ->map(function (array $group) use ($existingByRequestId, &$skippedRows, &$skippedQueues): ?array {
                    $requestId = (string) ($group['request_id'] ?? '');
                    if ($requestId === '' || ! isset($existingByRequestId[$requestId])) {
                        return $group;
                    }

                    foreach (($group['row_ids'] ?? []) as $rowId) {
                        $skippedRows[$rowId] = $rowId;
                    }

                    $existing = $existingByRequestId[$requestId];
                    $skippedQueues[(int) $existing['queue_id']] = $existing + [
                        'line_count' => 0,
                        'created' => false,
                    ];

                    return null;
                })
                ->filter()
                ->values();

            if ($linesByGroup->isEmpty()) {
                return [$linesByGroup, array_values($skippedRows), array_values($skippedQueues)];
            }
        }

        $dedupeKeys = $linesByGroup
            ->flatMap(fn (array $group): array => array_values($group['dedupe_keys'] ?? []))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($dedupeKeys === []) {
            return [$linesByGroup, array_values($skippedRows), array_values($skippedQueues)];
        }

        $existingByDedupeKey = $this->findExistingQueuesByDistributionDedupeKeys($dedupeKeys);
        if ($existingByDedupeKey === []) {
            return [$linesByGroup, array_values($skippedRows), array_values($skippedQueues)];
        }

        $filteredGroups = $linesByGroup
            ->map(function (array $group) use ($existingByDedupeKey, &$skippedRows, &$skippedQueues): ?array {
                $items = [];
                $lineMeta = [];
                $rowIds = [];
                $dedupeKeys = [];

                foreach (($group['items'] ?? []) as $index => $item) {
                    $meta = $group['line_meta'][$index] ?? [];
                    $dedupeKey = (string) ($meta['dedupe_key'] ?? '');
                    $rowId = (string) ($meta['row_id'] ?? '');

                    if ($dedupeKey !== '' && isset($existingByDedupeKey[$dedupeKey])) {
                        if ($rowId !== '') {
                            $skippedRows[$rowId] = $rowId;
                        }

                        $existing = $existingByDedupeKey[$dedupeKey];
                        $skippedQueues[(int) $existing['queue_id']] = $existing + [
                            'line_count' => 0,
                            'created' => false,
                        ];

                        continue;
                    }

                    $items[] = $item;
                    $lineMeta[] = $meta;

                    if ($rowId !== '') {
                        $rowIds[$rowId] = $rowId;
                    }

                    if ($dedupeKey !== '') {
                        $dedupeKeys[$dedupeKey] = $dedupeKey;
                    }
                }

                if ($items === []) {
                    return null;
                }

                $group['items'] = $items;
                $group['line_meta'] = $lineMeta;
                $group['row_ids'] = $rowIds;
                $group['dedupe_keys'] = $dedupeKeys;

                return $this->assignGroupRequestId($group);
            })
            ->filter()
            ->values();

        return [$filteredGroups, array_values($skippedRows), array_values($skippedQueues)];
    }

    /**
     * @param  array<int, string>  $requestIds
     * @return array<string, array<string, mixed>>
     */
    private function findExistingQueuesByRequestIds(array $requestIds): array
    {
        $requestIds = array_values(array_filter(array_unique($requestIds)));
        if ($requestIds === []) {
            return [];
        }

        $existingByRequestId = [];
        foreach (array_chunk($requestIds, 100) as $chunk) {
            $queues = DB::connection('sakemaru')
                ->table('stock_transfer_queue')
                ->where('client_id', (int) config('app.client_id'))
                ->where('action_type', 'CREATE')
                ->whereIn('request_id', $chunk)
                ->get(['id', 'request_id', 'from_warehouse_code', 'to_warehouse_code', 'process_date']);

            foreach ($queues as $queue) {
                $existingByRequestId[(string) $queue->request_id] = [
                    'queue_id' => (int) $queue->id,
                    'request_id' => (string) $queue->request_id,
                    'from_warehouse_code' => (string) $queue->from_warehouse_code,
                    'to_warehouse_code' => (string) $queue->to_warehouse_code,
                    'process_date' => (string) $queue->process_date,
                    'created' => false,
                ];
            }
        }

        return $existingByRequestId;
    }

    /**
     * @param  array<int, string>  $dedupeKeys
     * @return array<string, array<string, mixed>>
     */
    private function findExistingQueuesByDistributionDedupeKeys(array $dedupeKeys): array
    {
        $existingByDedupeKey = [];

        foreach (array_chunk($dedupeKeys, 50) as $chunk) {
            $queues = DB::connection('sakemaru')
                ->table('stock_transfer_queue')
                ->where('client_id', (int) config('app.client_id'))
                ->where('action_type', 'CREATE')
                ->where(function ($query) use ($chunk) {
                    foreach ($chunk as $dedupeKey) {
                        $query
                            ->orWhere('items', 'like', '%'.$dedupeKey.'%')
                            ->orWhere('note', 'like', '%'.$dedupeKey.'%');
                    }
                })
                ->get(['id', 'request_id', 'from_warehouse_code', 'to_warehouse_code', 'process_date', 'items', 'note']);

            foreach ($queues as $queue) {
                $note = (string) ($queue->note ?? '');
                $items = (string) ($queue->items ?? '');
                foreach ($chunk as $dedupeKey) {
                    if (! str_contains($items, $dedupeKey) && ! str_contains($note, $dedupeKey)) {
                        continue;
                    }

                    $existingByDedupeKey[$dedupeKey] = [
                        'queue_id' => (int) $queue->id,
                        'request_id' => (string) $queue->request_id,
                        'from_warehouse_code' => (string) $queue->from_warehouse_code,
                        'to_warehouse_code' => (string) $queue->to_warehouse_code,
                        'process_date' => (string) $queue->process_date,
                        'created' => false,
                    ];
                }
            }
        }

        return $existingByDedupeKey;
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function assignGroupRequestId(array $group): array
    {
        $requestSeed = json_encode([
            'source' => (int) ($group['source_warehouse_id'] ?? 0),
            'destination' => (int) $group['destination']->id,
            'process_date' => $group['process_date'],
            'delivery_course_id' => $group['delivery_course_id'],
            'rows' => array_values($group['row_ids']),
            'items' => $group['items'],
        ], JSON_UNESCAPED_UNICODE);

        $group['request_id'] = (string) ($group['request_id_prefix'] ?? 'distribution-transfer').'-'.sha1((string) $requestSeed);

        return $group;
    }

    private function resolveRowItem(Collection $items, array $row): ?object
    {
        $itemId = (int) ($row['item_id'] ?? 0);
        if ($itemId > 0 && $items->has($itemId)) {
            return $items->get($itemId);
        }

        $productCode = (string) ($row['product_code'] ?? '');

        return $items->first(fn ($item): bool => (string) $item->code === $productCode);
    }

    private function resolveProcessDate(array $row): string
    {
        $date = (string) ($row['delivery_date'] ?? '');
        if ($date === '') {
            $date = (string) ($row['order_date'] ?? '');
        }

        if ($date === '') {
            return Carbon::today()->format('Y-m-d');
        }

        return Carbon::parse($date)->format('Y-m-d');
    }

    private function resolveTransferDeliveryCourseId(int $fromWarehouseId, int $toWarehouseId): ?int
    {
        $deliveryCourseId = DB::connection('sakemaru')
            ->table('warehouse_stock_transfer_delivery_courses')
            ->where('from_warehouse_id', $fromWarehouseId)
            ->where('to_warehouse_id', $toWarehouseId)
            ->whereNotNull('delivery_course_id')
            ->value('delivery_course_id');

        return $deliveryCourseId !== null ? (int) $deliveryCourseId : null;
    }

    private function deliveryCourseExists(int $deliveryCourseId): bool
    {
        return DB::connection('sakemaru')
            ->table('delivery_courses')
            ->where('client_id', (int) config('app.client_id'))
            ->where('id', $deliveryCourseId)
            ->exists();
    }

    /**
     * @param  array{destination: object, process_date: string, delivery_course_id: int|null, items: array<int, array<string, mixed>>, row_ids: array<string, string>, request_id: string, dedupe_keys?: array<string, string>}  $group
     * @return array{queue_id: int, created: bool}
     */
    private function upsertStockTransferQueue(object $sourceWarehouse, array $group, int $createdBy, ?string $remark): array
    {
        $note = '分配画面から作成';
        if ($remark !== null && trim($remark) !== '') {
            $note .= ' / '.mb_substr(trim($remark), 0, 200);
        }
        $note .= " / 作成者ID: {$createdBy}";

        $queueData = [
            'client_id' => (int) config('app.client_id'),
            'request_id' => $group['request_id'],
            'slip_number' => null,
            'process_date' => $group['process_date'],
            'delivered_date' => $group['process_date'],
            'note' => $note,
            'items' => json_encode($group['items'], JSON_UNESCAPED_UNICODE),
            'from_warehouse_code' => (string) $sourceWarehouse->code,
            'to_warehouse_code' => (string) $group['destination']->code,
            'delivery_course_id' => $group['delivery_course_id'],
            'status' => 'BEFORE',
            'action_type' => 'CREATE',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $existingQueue = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('request_id', $group['request_id'])
            ->lockForUpdate()
            ->first();

        if ($existingQueue) {
            return [
                'queue_id' => (int) $existingQueue->id,
                'created' => false,
            ];
        }

        $queueId = (int) DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->insertGetId($queueData);

        return [
            'queue_id' => $queueId,
            'created' => true,
        ];
    }
}
