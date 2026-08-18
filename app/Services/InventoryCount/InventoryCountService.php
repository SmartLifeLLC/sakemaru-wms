<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Models\WmsPicker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InventoryCountService
{
    public const INVENTORY_ADJUSTMENT_EXCLUDED_PREFIXES = ['4', '5', '7', '8', '9'];

    private const THEORY_UPDATE_RUNS_TABLE = 'wms_inventory_count_theory_update_runs';

    private const THEORY_UPDATE_ROWS_TABLE = 'wms_inventory_count_theory_update_rows';

    public function create(array $data): WmsInventoryCount
    {
        return DB::connection('sakemaru')->transaction(function () use ($data) {
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);

            return WmsInventoryCount::create([
                'count_no' => WmsInventoryCount::generateCountNo($data['count_date']),
                'client_id' => $warehouse->client_id,
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code ?? '',
                'warehouse_name' => $warehouse->name ?? '',
                'count_date' => $data['count_date'],
                'status' => WmsInventoryCount::STATUS_DRAFT,
                'memo' => $data['memo'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    public function takeSnapshot(WmsInventoryCount $inventoryCount): int
    {
        $warehouseId = $inventoryCount->warehouse_id;
        $inserted = 0;

        $lotRanked = DB::raw(
            '(SELECT rsl.real_stock_id, rsl.location_id, rsl.floor_id, ROW_NUMBER() OVER (PARTITION BY rsl.real_stock_id ORDER BY rsl.updated_at DESC, rsl.id DESC) AS rn FROM real_stock_lots rsl WHERE rsl.status = \'ACTIVE\') as lot'
        );

        DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('items as i', 'i.id', '=', 'rs.item_id')
            ->leftJoin($lotRanked, function ($join) {
                $join->on('lot.real_stock_id', '=', 'rs.id')
                    ->where('lot.rn', '=', 1);
            })
            ->leftJoin('locations as l', 'l.id', '=', 'lot.location_id')
            ->leftJoin('floors as f', 'f.id', '=', DB::raw('COALESCE(lot.floor_id, l.floor_id)'))
            ->where('rs.warehouse_id', $warehouseId)
            ->where(function ($query) {
                $query->where('rs.current_quantity', '!=', 0)
                    ->orWhereNotNull('lot.real_stock_id');
            })
            ->select([
                'rs.id as real_stock_id',
                'rs.item_id',
                'i.code as item_code',
                'i.name as item_name',
                DB::raw("(SELECT isi.search_string FROM item_search_information isi WHERE isi.item_id = i.id AND isi.code_type = 'JAN' AND isi.quantity_type = 'PIECE' AND isi.is_active = 1 ORDER BY isi.priority IS NULL, isi.priority, isi.id LIMIT 1) as barcode"),
                'l.id as location_id',
                'f.id as floor_id',
                'f.name as floor_name',
                'l.code1 as location_code1',
                'l.code2 as location_code2',
                'l.code3 as location_code3',
                'rs.current_quantity as system_quantity',
                DB::raw('COALESCE((SELECT ip.cost_unit_price FROM item_prices ip WHERE ip.item_id = i.id AND ip.is_active = 1 LIMIT 1), 0) as cost_price'),
            ])
            ->orderBy('f.name')
            ->orderBy('l.code1')
            ->orderBy('l.code2')
            ->orderBy('l.code3')
            ->chunk(1000, function ($rows) use ($inventoryCount, &$inserted) {
                $records = [];
                foreach ($rows as $row) {
                    $records[] = [
                        'inventory_count_id' => $inventoryCount->id,
                        'real_stock_id' => $row->real_stock_id,
                        'item_id' => $row->item_id,
                        'item_code' => $row->item_code ?? '',
                        'item_name' => $row->item_name ?? '',
                        'barcode' => $row->barcode,
                        'location_id' => $row->location_id,
                        'floor_id' => $row->floor_id,
                        'floor_name' => $row->floor_name,
                        'location_code1' => $row->location_code1,
                        'location_code2' => $row->location_code2,
                        'location_code3' => $row->location_code3,
                        'location_no' => Location::formatCode(
                            $row->location_code1,
                            $row->location_code2,
                            $row->location_code3
                        ),
                        'system_quantity' => $row->system_quantity,
                        'cost_price' => $row->cost_price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                WmsInventoryCountItem::insert($records);
                $inserted += count($records);
            });

        $inventoryCount->update(['snapshot_taken_at' => now()]);

        return $inserted;
    }

    public function startCounting(WmsInventoryCount $inventoryCount): void
    {
        $inventoryCount->update([
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'started_at' => now(),
        ]);
    }

    public function refreshSystemQuantities(WmsInventoryCount $inventoryCount): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($inventoryCount) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->refreshSystemQuantitiesLocked($inventoryCount);
        });
    }

    public function refreshSystemQuantitiesFromDailySnapshot(WmsInventoryCount $inventoryCount, string $snapshotDate): array
    {
        $snapshotDate = CarbonImmutable::parse($snapshotDate)->toDateString();

        if ($snapshotDate > now()->toDateString()) {
            throw new \RuntimeException('未来日の在庫スナップショットには更新できません。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($inventoryCount, $snapshotDate) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! DB::connection('sakemaru')->table('real_stock_daily_snapshots')->where('snapshot_date', $snapshotDate)->exists()) {
                throw new \RuntimeException("{$snapshotDate} の在庫スナップショットがありません。");
            }

            return $this->refreshSystemQuantitiesFromDailySnapshotLocked($inventoryCount, $snapshotDate);
        });
    }

    public function refreshEndingSystemQuantitiesFromLedger(WmsInventoryCount $inventoryCount, string $endDate): array
    {
        $endDate = CarbonImmutable::parse($endDate)->toDateString();

        if ($endDate > now()->toDateString()) {
            throw new \RuntimeException('未来日の受払では理論在庫を更新できません。');
        }

        $this->assertCanRefreshSystemQuantities($inventoryCount);
        $this->assertEndingStockColumnsExist();
        $this->assertTheoryUpdateBackupTablesExist();

        $ledgerService = new InventoryCountLedgerBalanceService;
        $calculationStartedAt = microtime(true);
        $balances = $this->calculateEndingLedgerBalancesWithoutWriteLocks(
            $ledgerService,
            (int) $inventoryCount->client_id,
            (int) $inventoryCount->warehouse_id,
            $endDate,
        );
        $calculationSeconds = round(microtime(true) - $calculationStartedAt, 3);

        $updateStartedAt = microtime(true);

        return DB::connection('sakemaru')->transaction(function () use ($inventoryCount, $endDate, $ledgerService, $balances, $calculationSeconds, $updateStartedAt) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanRefreshSystemQuantities($inventoryCount);
            $this->assertEndingStockColumnsExist();
            $this->assertTheoryUpdateBackupTablesExist();

            $runId = $this->createTheoryUpdateRun(
                $inventoryCount,
                $endDate,
                count($balances),
                $calculationSeconds,
            );

            $existingItemIds = WmsInventoryCountItem::query()
                ->where('inventory_count_id', $inventoryCount->id)
                ->distinct()
                ->pluck('item_id')
                ->map(fn ($itemId) => (int) $itemId)
                ->filter()
                ->values()
                ->all();

            $eligibleExistingItemIds = array_flip($ledgerService->eligibleItemIds($existingItemIds, (int) $inventoryCount->client_id));
            [$targetQuantities, $skippedItems] = $this->targetEndingQuantitiesByCountItemId(
                (int) $inventoryCount->id,
                $balances,
                $eligibleExistingItemIds,
            );
            $backedUpExistingRows = $this->backupExistingTheoryUpdateRows(
                $runId,
                (int) $inventoryCount->id,
                $targetQuantities,
            );
            $updatedItems = $this->updateExistingEndingSystemQuantities($targetQuantities);

            $insertedItemIds = $this->insertMissingLedgerStockItems($inventoryCount, $balances, $existingItemIds, $ledgerService);
            $backedUpInsertedRows = $this->backupInsertedTheoryUpdateRows($runId, $insertedItemIds);
            $insertedItems = count($insertedItemIds);
            $finishedAt = now();
            $updateSeconds = round(microtime(true) - $updateStartedAt, 3);

            $inventoryCount->update([
                'ending_stock_taken_at' => $finishedAt,
            ]);

            DB::connection('sakemaru')->table(self::THEORY_UPDATE_RUNS_TABLE)
                ->where('id', $runId)
                ->update([
                    'status' => 'finished',
                    'finished_at' => $finishedAt,
                    'ending_stock_taken_at_after' => $finishedAt,
                    'updated_items' => $updatedItems,
                    'inserted_items' => $insertedItems,
                    'skipped_items' => $skippedItems,
                    'backed_up_existing_rows' => $backedUpExistingRows,
                    'backed_up_inserted_rows' => $backedUpInsertedRows,
                    'update_seconds' => $updateSeconds,
                    'updated_at' => $finishedAt,
                ]);

            Log::info('Inventory count ending theory updated from ledger', [
                'inventory_count_id' => $inventoryCount->id,
                'theory_update_run_id' => $runId,
                'end_date' => $endDate,
                'calculated_item_count' => count($balances),
                'updated_items' => $updatedItems,
                'inserted_items' => $insertedItems,
                'skipped_items' => $skippedItems,
                'calculation_seconds' => $calculationSeconds,
                'update_seconds' => $updateSeconds,
            ]);

            return [
                'end_date' => $endDate,
                'calculated_item_count' => count($balances),
                'updated_items' => $updatedItems,
                'inserted_items' => $insertedItems,
                'skipped_items' => $skippedItems,
                'backup_run_id' => $runId,
                'backed_up_existing_rows' => $backedUpExistingRows,
                'backed_up_inserted_rows' => $backedUpInsertedRows,
                'calculation_seconds' => $calculationSeconds,
                'update_seconds' => $updateSeconds,
            ];
        });
    }

    public function calculatePostCountMovements(WmsInventoryCount $inventoryCount, string $countedAt): array
    {
        return (new InventoryCountMovementService)->calculatePostCountMovements($inventoryCount, $countedAt);
    }

    /**
     * 受払計算は更新トランザクションから分離する。READ ONLYの一貫スナップショットなので行ロックは取らない。
     *
     * @return array<int, float>
     */
    private function calculateEndingLedgerBalancesWithoutWriteLocks(
        InventoryCountLedgerBalanceService $ledgerService,
        int $clientId,
        int $warehouseId,
        string $endDate,
    ): array {
        $connection = DB::connection('sakemaru');

        if ($connection->transactionLevel() > 0) {
            return $ledgerService->balancesByItem($clientId, $warehouseId, $endDate);
        }

        $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $connection->beginTransaction();

        try {
            $balances = $ledgerService->balancesByItem($clientId, $warehouseId, $endDate);
            $connection->commit();

            return $balances;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    private function createTheoryUpdateRun(
        WmsInventoryCount $inventoryCount,
        string $endDate,
        int $calculatedItemCount,
        float $calculationSeconds,
    ): int {
        $now = now();

        return (int) DB::connection('sakemaru')->table(self::THEORY_UPDATE_RUNS_TABLE)->insertGetId([
            'inventory_count_id' => $inventoryCount->id,
            'client_id' => $inventoryCount->client_id,
            'warehouse_id' => $inventoryCount->warehouse_id,
            'end_date' => $endDate,
            'update_type' => 'ending_ledger',
            'status' => 'running',
            'executed_by' => auth()->id(),
            'started_at' => $now,
            'ending_stock_taken_at_before' => $inventoryCount->ending_stock_taken_at,
            'calculated_item_count' => $calculatedItemCount,
            'calculation_seconds' => $calculationSeconds,
            'metadata' => json_encode([
                'opening_date' => InventoryCountLedgerBalanceService::OPENING_DATE,
                'calculation' => 'repeatable_read_read_only_transaction_without_row_locks',
                'backup' => 'existing_rows_before_update_and_inserted_rows_after_insert',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<int, float>  $balances
     * @param  array<int, int>  $eligibleExistingItemIds
     * @return array{0: array<int, int>, 1: int}
     */
    private function targetEndingQuantitiesByCountItemId(
        int $inventoryCountId,
        array $balances,
        array $eligibleExistingItemIds,
    ): array {
        $targetQuantities = [];
        $skippedItems = 0;
        $seenItemIds = [];

        WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCountId)
            ->select(['id', 'item_id'])
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($balances, $eligibleExistingItemIds, &$targetQuantities, &$skippedItems, &$seenItemIds) {
                foreach ($items as $item) {
                    $itemId = (int) $item->item_id;
                    if (! isset($eligibleExistingItemIds[$itemId])) {
                        $skippedItems++;

                        continue;
                    }

                    $targetQuantities[(int) $item->id] = isset($seenItemIds[$itemId])
                        ? 0
                        : (int) round($balances[$itemId] ?? 0);

                    $seenItemIds[$itemId] = true;
                }
            });

        return [$targetQuantities, $skippedItems];
    }

    /**
     * @param  array<int, int>  $targetQuantities
     */
    private function backupExistingTheoryUpdateRows(int $runId, int $inventoryCountId, array $targetQuantities): int
    {
        $backedUp = 0;

        DB::connection('sakemaru')
            ->table('wms_inventory_count_items')
            ->where('inventory_count_id', $inventoryCountId)
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($runId, $inventoryCountId, $targetQuantities, &$backedUp) {
                $now = now();
                $records = [];

                foreach ($items as $item) {
                    $oldValues = (array) $item;

                    $records[] = [
                        'run_id' => $runId,
                        'inventory_count_id' => $inventoryCountId,
                        'inventory_count_item_id' => $item->id,
                        'was_existing' => true,
                        'item_id' => $item->item_id,
                        'real_stock_id' => $item->real_stock_id,
                        'old_ending_system_quantity' => $item->ending_system_quantity,
                        'new_ending_system_quantity' => $targetQuantities[(int) $item->id] ?? $item->ending_system_quantity,
                        'old_values' => json_encode($oldValues, JSON_UNESCAPED_UNICODE),
                        'new_values' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($records === []) {
                    return;
                }

                DB::connection('sakemaru')->table(self::THEORY_UPDATE_ROWS_TABLE)->insert($records);
                $backedUp += count($records);
            });

        return $backedUp;
    }

    /**
     * @param  array<int, int>  $targetQuantities
     */
    private function updateExistingEndingSystemQuantities(array $targetQuantities): int
    {
        if ($targetQuantities === []) {
            return 0;
        }

        $updatedItems = 0;

        foreach (array_chunk($targetQuantities, 500, true) as $chunk) {
            WmsInventoryCountItem::query()
                ->whereKey(array_keys($chunk))
                ->select(['id', 'ending_system_quantity'])
                ->orderBy('id')
                ->chunkById(500, function ($items) use ($chunk, &$updatedItems) {
                    foreach ($items as $item) {
                        $systemQuantity = (int) $chunk[(int) $item->id];
                        $this->updateCountItemEndingSystemQuantity($item, $systemQuantity, $updatedItems);
                    }
                });
        }

        return $updatedItems;
    }

    public function saveCurrentStock(WmsInventoryCount $inventoryCount): void
    {
        DB::connection('sakemaru')->transaction(function () use ($inventoryCount) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($inventoryCount->status, [
                WmsInventoryCount::STATUS_DRAFT,
                WmsInventoryCount::STATUS_COUNTING,
            ], true)) {
                throw new \RuntimeException('現状保存できるのは下書きまたはカウント中の棚卸しのみです。');
            }

            $inventoryCount->update([
                'status' => WmsInventoryCount::STATUS_COUNTING,
                'started_at' => $inventoryCount->started_at ?? now(),
                'current_stock_saved_at' => now(),
            ]);
        });
    }

    public function resumeCurrentStockSavedForCounting(WmsInventoryCount $inventoryCount): void
    {
        DB::connection('sakemaru')->transaction(function () use ($inventoryCount) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $inventoryCount->isCurrentStockSaved()) {
                throw new \RuntimeException('現状保存済みの棚卸しのみ再開できます。');
            }

            $inventoryCount->update([
                'status' => WmsInventoryCount::STATUS_COUNTING,
                'current_stock_saved_at' => null,
            ]);
        });
    }

    private function refreshSystemQuantitiesLocked(WmsInventoryCount $inventoryCount): array
    {
        $this->assertCanRefreshSystemQuantities($inventoryCount);
        $this->assertEndingStockColumnsExist();

        $updatedItems = 0;
        $missingRealStocks = 0;

        WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->whereNotNull('real_stock_id')
            ->select([
                'id',
                'real_stock_id',
                'ending_system_quantity',
            ])
            ->chunkById(500, function ($items) use (&$updatedItems, &$missingRealStocks) {
                $stockQuantities = DB::connection('sakemaru')
                    ->table('real_stocks')
                    ->whereIn('id', $items->pluck('real_stock_id')->filter()->unique()->values())
                    ->pluck('current_quantity', 'id');

                foreach ($items as $item) {
                    if (! $stockQuantities->has($item->real_stock_id)) {
                        $missingRealStocks++;

                        continue;
                    }

                    $systemQuantity = (int) $stockQuantities->get($item->real_stock_id);
                    $this->updateCountItemEndingSystemQuantity($item, $systemQuantity, $updatedItems);
                }
            });

        $insertedItems = $this->insertMissingEndingStockItems($inventoryCount);

        $inventoryCount->update([
            'ending_stock_taken_at' => now(),
        ]);

        return [
            'updated_items' => $updatedItems,
            'inserted_items' => $insertedItems,
            'missing_real_stocks' => $missingRealStocks,
        ];
    }

    private function refreshSystemQuantitiesFromDailySnapshotLocked(WmsInventoryCount $inventoryCount, string $snapshotDate): array
    {
        $this->assertCanRefreshSystemQuantities($inventoryCount);

        $updatedItems = 0;
        $updatedDifferences = 0;
        $missingSnapshotRows = 0;

        WmsInventoryCountItem::query()
            ->where('inventory_count_id', $inventoryCount->id)
            ->whereNotNull('real_stock_id')
            ->select([
                'id',
                'real_stock_id',
                'system_quantity',
                'first_count_quantity',
                'second_count_quantity',
                'final_count_quantity',
                'difference_quantity',
                'cost_price',
            ])
            ->chunkById(500, function ($items) use ($inventoryCount, $snapshotDate, &$updatedItems, &$updatedDifferences, &$missingSnapshotRows) {
                $realStockIds = $items->pluck('real_stock_id')->filter()->unique()->values();
                $latestSnapshots = DB::connection('sakemaru')
                    ->table('real_stock_daily_snapshots')
                    ->whereIn('real_stock_id', $realStockIds)
                    ->where('warehouse_id', $inventoryCount->warehouse_id)
                    ->where('snapshot_date', '<=', $snapshotDate)
                    ->groupBy('real_stock_id')
                    ->selectRaw('real_stock_id, MAX(snapshot_date) as snapshot_date');

                $snapshotQuantities = DB::connection('sakemaru')
                    ->table('real_stock_daily_snapshots as snapshots')
                    ->joinSub($latestSnapshots, 'latest_snapshots', function ($join) {
                        $join->on('snapshots.real_stock_id', '=', 'latest_snapshots.real_stock_id')
                            ->on('snapshots.snapshot_date', '=', 'latest_snapshots.snapshot_date');
                    })
                    ->pluck('snapshots.current_quantity', 'snapshots.real_stock_id');

                foreach ($items as $item) {
                    if (! $snapshotQuantities->has($item->real_stock_id)) {
                        $missingSnapshotRows++;

                        continue;
                    }

                    $systemQuantity = (int) $snapshotQuantities->get($item->real_stock_id);
                    $this->updateCountItemSystemQuantity($item, $systemQuantity, $updatedItems, $updatedDifferences);
                }
            });

        return [
            'snapshot_date' => $snapshotDate,
            'updated_items' => $updatedItems,
            'updated_differences' => $updatedDifferences,
            'missing_snapshot_rows' => $missingSnapshotRows,
        ];
    }

    private function assertCanRefreshSystemQuantities(WmsInventoryCount $inventoryCount): void
    {
        if ($inventoryCount->isCurrentStockSaved()) {
            throw new \RuntimeException('現状保存後の棚卸しは現在庫に更新できません。');
        }

        if (in_array($inventoryCount->status, [
            WmsInventoryCount::STATUS_CONFIRMED,
            WmsInventoryCount::STATUS_CANCELLED,
        ], true)) {
            throw new \RuntimeException('確定済または取消済の棚卸しは現在庫に更新できません。');
        }
    }

    private function updateCountItemSystemQuantity(WmsInventoryCountItem $item, int $systemQuantity, int &$updatedItems, int &$updatedDifferences): void
    {
        $updateData = [];

        if ((int) $item->system_quantity !== $systemQuantity) {
            $updateData['system_quantity'] = $systemQuantity;
            $updatedItems++;
        }

        if ($item->difference_quantity !== null) {
            $countedQuantity = $item->final_count_quantity
                ?? $item->second_count_quantity
                ?? $item->first_count_quantity;

            if ($countedQuantity !== null) {
                $differenceQuantity = (int) $countedQuantity - $systemQuantity;
                $updateData['difference_quantity'] = $differenceQuantity;
                $updateData['difference_amount'] = $differenceQuantity * (float) $item->cost_price;
            } else {
                $updateData['difference_quantity'] = null;
                $updateData['difference_amount'] = null;
            }

            $updatedDifferences++;
        }

        if ($updateData === []) {
            return;
        }

        $updateData['updated_at'] = now();
        WmsInventoryCountItem::whereKey($item->id)->update($updateData);
    }

    private function updateCountItemEndingSystemQuantity(WmsInventoryCountItem $item, int $systemQuantity, int &$updatedItems): void
    {
        if ($item->ending_system_quantity !== null && (int) $item->ending_system_quantity === $systemQuantity) {
            return;
        }

        WmsInventoryCountItem::whereKey($item->id)->update([
            'ending_system_quantity' => $systemQuantity,
            'updated_at' => now(),
        ]);

        $updatedItems++;
    }

    private function insertMissingEndingStockItems(WmsInventoryCount $inventoryCount): int
    {
        $inserted = 0;
        $lotRanked = DB::raw(
            '(SELECT rsl.real_stock_id, rsl.location_id, rsl.floor_id, ROW_NUMBER() OVER (PARTITION BY rsl.real_stock_id ORDER BY rsl.updated_at DESC, rsl.id DESC) AS rn FROM real_stock_lots rsl WHERE rsl.status = \'ACTIVE\') as lot'
        );

        DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('items as i', 'i.id', '=', 'rs.item_id')
            ->leftJoin($lotRanked, function ($join) {
                $join->on('lot.real_stock_id', '=', 'rs.id')
                    ->where('lot.rn', '=', 1);
            })
            ->leftJoin('locations as l', 'l.id', '=', 'lot.location_id')
            ->leftJoin('floors as f', 'f.id', '=', DB::raw('COALESCE(lot.floor_id, l.floor_id)'))
            ->leftJoin('wms_inventory_count_items as ici', function ($join) use ($inventoryCount) {
                $join->on('ici.real_stock_id', '=', 'rs.id')
                    ->where('ici.inventory_count_id', '=', $inventoryCount->id);
            })
            ->where('rs.warehouse_id', $inventoryCount->warehouse_id)
            ->whereNull('ici.id')
            ->where(function ($query) {
                $query->where('rs.current_quantity', '!=', 0)
                    ->orWhereNotNull('lot.real_stock_id');
            })
            ->select([
                'rs.id as real_stock_id',
                'rs.item_id',
                'i.code as item_code',
                'i.name as item_name',
                DB::raw("(SELECT isi.search_string FROM item_search_information isi WHERE isi.item_id = i.id AND isi.code_type = 'JAN' AND isi.quantity_type = 'PIECE' AND isi.is_active = 1 ORDER BY isi.priority IS NULL, isi.priority, isi.id LIMIT 1) as barcode"),
                'l.id as location_id',
                'f.id as floor_id',
                'f.name as floor_name',
                'l.code1 as location_code1',
                'l.code2 as location_code2',
                'l.code3 as location_code3',
                'rs.current_quantity as ending_system_quantity',
                DB::raw('COALESCE((SELECT ip.cost_unit_price FROM item_prices ip WHERE ip.item_id = i.id AND ip.is_active = 1 LIMIT 1), 0) as cost_price'),
            ])
            ->chunkById(1000, function ($rows) use ($inventoryCount, &$inserted) {
                $now = now();
                $records = [];

                foreach ($rows as $row) {
                    $records[] = [
                        'inventory_count_id' => $inventoryCount->id,
                        'real_stock_id' => $row->real_stock_id,
                        'item_id' => $row->item_id,
                        'item_code' => $row->item_code ?? '',
                        'item_name' => $row->item_name ?? '',
                        'barcode' => $row->barcode,
                        'location_id' => $row->location_id,
                        'floor_id' => $row->floor_id,
                        'floor_name' => $row->floor_name,
                        'location_code1' => $row->location_code1,
                        'location_code2' => $row->location_code2,
                        'location_code3' => $row->location_code3,
                        'location_no' => Location::formatCode(
                            $row->location_code1,
                            $row->location_code2,
                            $row->location_code3
                        ),
                        'system_quantity' => 0,
                        'ending_system_quantity' => $row->ending_system_quantity,
                        'cost_price' => $row->cost_price,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($records === []) {
                    return;
                }

                WmsInventoryCountItem::insert($records);
                $inserted += count($records);
            }, 'rs.id', 'real_stock_id');

        return $inserted;
    }

    /**
     * @return array<int, int>
     */
    private function insertMissingLedgerStockItems(
        WmsInventoryCount $inventoryCount,
        array $balances,
        array $existingItemIds,
        InventoryCountLedgerBalanceService $ledgerService,
    ): array {
        $existingItemIdMap = array_flip(array_map('intval', $existingItemIds));
        $eligibleItemIds = $ledgerService->eligibleItemIds(array_keys($balances), (int) $inventoryCount->client_id);
        $missingItemIds = collect($eligibleItemIds)
            ->filter(fn (int $itemId): bool => ! isset($existingItemIdMap[$itemId]) && abs((float) ($balances[$itemId] ?? 0)) > 0.0001)
            ->values()
            ->all();

        if ($missingItemIds === []) {
            return [];
        }

        $insertedIds = [];
        $lotRanked = DB::raw(
            '(SELECT rsl.real_stock_id, rsl.location_id, rsl.floor_id, ROW_NUMBER() OVER (PARTITION BY rsl.real_stock_id ORDER BY rsl.updated_at DESC, rsl.id DESC) AS rn FROM real_stock_lots rsl WHERE rsl.status = \'ACTIVE\') as lot'
        );

        foreach (array_chunk($missingItemIds, 500) as $chunkItemIds) {
            $stockRows = DB::connection('sakemaru')
                ->table('real_stocks as rs')
                ->leftJoin($lotRanked, function ($join) {
                    $join->on('lot.real_stock_id', '=', 'rs.id')
                        ->where('lot.rn', '=', 1);
                })
                ->leftJoin('locations as l', 'l.id', '=', 'lot.location_id')
                ->leftJoin('floors as f', 'f.id', '=', DB::raw('COALESCE(lot.floor_id, l.floor_id)'))
                ->where('rs.client_id', $inventoryCount->client_id)
                ->where('rs.warehouse_id', $inventoryCount->warehouse_id)
                ->whereIn('rs.item_id', $chunkItemIds)
                ->orderBy('rs.id')
                ->get([
                    'rs.id as real_stock_id',
                    'rs.item_id',
                    'l.id as location_id',
                    'f.id as floor_id',
                    'f.name as floor_name',
                    'l.code1 as location_code1',
                    'l.code2 as location_code2',
                    'l.code3 as location_code3',
                ])
                ->groupBy('item_id')
                ->map(fn ($rows) => $rows->first());

            $items = DB::connection('sakemaru')
                ->table('items as i')
                ->whereIn('i.id', $chunkItemIds)
                ->select([
                    'i.id as item_id',
                    'i.code as item_code',
                    'i.name as item_name',
                    DB::raw("(SELECT isi.search_string FROM item_search_information isi WHERE isi.item_id = i.id AND isi.code_type = 'JAN' AND isi.quantity_type = 'PIECE' AND isi.is_active = 1 ORDER BY isi.priority IS NULL, isi.priority, isi.id LIMIT 1) as barcode"),
                    DB::raw('COALESCE((SELECT ip.cost_unit_price FROM item_prices ip WHERE ip.item_id = i.id AND ip.is_active = 1 LIMIT 1), 0) as cost_price'),
                ])
                ->get()
                ->keyBy('item_id');

            $now = now();

            foreach ($chunkItemIds as $itemId) {
                $item = $items->get($itemId);
                if (! $item) {
                    continue;
                }

                $stock = $stockRows->get($itemId);
                $insertedIds[] = (int) WmsInventoryCountItem::query()->insertGetId([
                    'inventory_count_id' => $inventoryCount->id,
                    'real_stock_id' => $stock?->real_stock_id,
                    'item_id' => $item->item_id,
                    'item_code' => $item->item_code ?? '',
                    'item_name' => $item->item_name ?? '',
                    'barcode' => $item->barcode,
                    'location_id' => $stock?->location_id,
                    'floor_id' => $stock?->floor_id,
                    'floor_name' => $stock?->floor_name,
                    'location_code1' => $stock?->location_code1,
                    'location_code2' => $stock?->location_code2,
                    'location_code3' => $stock?->location_code3,
                    'location_no' => Location::formatCode(
                        $stock?->location_code1,
                        $stock?->location_code2,
                        $stock?->location_code3
                    ),
                    'system_quantity' => 0,
                    'ending_system_quantity' => (int) round($balances[(int) $item->item_id] ?? 0),
                    'cost_price' => $item->cost_price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return $insertedIds;
    }

    /**
     * @param  array<int, int>  $insertedItemIds
     */
    private function backupInsertedTheoryUpdateRows(int $runId, array $insertedItemIds): int
    {
        if ($insertedItemIds === []) {
            return 0;
        }

        $backedUp = 0;

        foreach (array_chunk($insertedItemIds, 500) as $chunkIds) {
            $items = DB::connection('sakemaru')
                ->table('wms_inventory_count_items')
                ->whereIn('id', $chunkIds)
                ->orderBy('id')
                ->get();

            $now = now();
            $records = [];

            foreach ($items as $item) {
                $newValues = (array) $item;

                $records[] = [
                    'run_id' => $runId,
                    'inventory_count_id' => $item->inventory_count_id,
                    'inventory_count_item_id' => $item->id,
                    'was_existing' => false,
                    'item_id' => $item->item_id,
                    'real_stock_id' => $item->real_stock_id,
                    'old_ending_system_quantity' => null,
                    'new_ending_system_quantity' => $item->ending_system_quantity,
                    'old_values' => null,
                    'new_values' => json_encode($newValues, JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($records === []) {
                continue;
            }

            DB::connection('sakemaru')->table(self::THEORY_UPDATE_ROWS_TABLE)->insert($records);
            $backedUp += count($records);
        }

        return $backedUp;
    }

    private function assertEndingStockColumnsExist(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', 'ending_stock_taken_at')
            || ! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')
        ) {
            throw new \RuntimeException('終了時理論在庫用のDB列が未作成です。マイグレーションを実行してください。');
        }
    }

    private function assertTheoryUpdateBackupTablesExist(): void
    {
        if (! Schema::connection('sakemaru')->hasTable(self::THEORY_UPDATE_RUNS_TABLE)
            || ! Schema::connection('sakemaru')->hasTable(self::THEORY_UPDATE_ROWS_TABLE)
        ) {
            throw new \RuntimeException('理論在庫更新バックアップ用のDBテーブルが未作成です。マイグレーションを実行してください。');
        }
    }

    public function addSingleItemByCode(WmsInventoryCount $inventoryCount, string $itemCode): array
    {
        $itemCode = trim(mb_convert_kana($itemCode, 'as'));

        if ($itemCode === '') {
            throw new \InvalidArgumentException('商品CDを入力してください。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($inventoryCount, $itemCode) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($inventoryCount->status, [
                WmsInventoryCount::STATUS_CONFIRMED,
                WmsInventoryCount::STATUS_CANCELLED,
            ], true)) {
                throw new \RuntimeException('確定済または取消済の棚卸しには追加できません。');
            }

            $item = DB::connection('sakemaru')
                ->table('items')
                ->where('code', $itemCode)
                ->first(['id', 'code', 'name']);

            if (! $item) {
                throw new \RuntimeException("商品CD {$itemCode} が見つかりません。");
            }

            $latestLot = DB::raw(
                '(SELECT rsl.real_stock_id, rsl.location_id, rsl.floor_id, ROW_NUMBER() OVER (PARTITION BY rsl.real_stock_id ORDER BY rsl.updated_at DESC, rsl.id DESC) AS rn FROM real_stock_lots rsl) as lot'
            );

            $stocks = DB::connection('sakemaru')
                ->table('real_stocks as rs')
                ->leftJoin($latestLot, function ($join) {
                    $join->on('lot.real_stock_id', '=', 'rs.id')
                        ->where('lot.rn', '=', 1);
                })
                ->leftJoin('locations as l', 'l.id', '=', 'lot.location_id')
                ->leftJoin('floors as f', 'f.id', '=', DB::raw('COALESCE(lot.floor_id, l.floor_id)'))
                ->where('rs.warehouse_id', $inventoryCount->warehouse_id)
                ->where('rs.item_id', $item->id)
                ->select([
                    'rs.id as real_stock_id',
                    'rs.item_id',
                    'rs.current_quantity as system_quantity',
                    'l.id as location_id',
                    'f.id as floor_id',
                    'f.name as floor_name',
                    'l.code1 as location_code1',
                    'l.code2 as location_code2',
                    'l.code3 as location_code3',
                    DB::raw("(SELECT isi.search_string FROM item_search_information isi WHERE isi.item_id = rs.item_id AND isi.code_type = 'JAN' AND isi.quantity_type = 'PIECE' AND isi.is_active = 1 ORDER BY isi.priority IS NULL, isi.priority, isi.id LIMIT 1) as barcode"),
                    DB::raw('COALESCE((SELECT ip.cost_unit_price FROM item_prices ip WHERE ip.item_id = rs.item_id AND ip.is_active = 1 LIMIT 1), 0) as cost_price'),
                ])
                ->orderBy('rs.id')
                ->get();

            if ($stocks->isEmpty()) {
                throw new \RuntimeException("商品CD {$itemCode} はこの倉庫の在庫行がありません。");
            }

            $existingRealStockIds = WmsInventoryCountItem::query()
                ->where('inventory_count_id', $inventoryCount->id)
                ->whereIn('real_stock_id', $stocks->pluck('real_stock_id'))
                ->pluck('real_stock_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $existingMap = array_flip($existingRealStockIds);
            $records = [];
            $now = now();

            foreach ($stocks as $stock) {
                if (isset($existingMap[(int) $stock->real_stock_id])) {
                    continue;
                }

                $records[] = [
                    'inventory_count_id' => $inventoryCount->id,
                    'real_stock_id' => $stock->real_stock_id,
                    'item_id' => $stock->item_id,
                    'item_code' => $item->code ?? '',
                    'item_name' => $item->name ?? '',
                    'barcode' => $stock->barcode,
                    'location_id' => $stock->location_id,
                    'floor_id' => $stock->floor_id,
                    'floor_name' => $stock->floor_name,
                    'location_code1' => $stock->location_code1,
                    'location_code2' => $stock->location_code2,
                    'location_code3' => $stock->location_code3,
                    'location_no' => Location::formatCode(
                        $stock->location_code1,
                        $stock->location_code2,
                        $stock->location_code3
                    ),
                    'system_quantity' => $stock->system_quantity,
                    'cost_price' => $stock->cost_price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($records !== []) {
                WmsInventoryCountItem::insert($records);
            }

            return [
                'item_code' => (string) $item->code,
                'item_name' => (string) $item->name,
                'stock_count' => $stocks->count(),
                'inserted_count' => count($records),
                'existing_count' => $stocks->count() - count($records),
            ];
        });
    }

    public function registerCount(
        WmsInventoryCountItem $countItem,
        float $quantity,
        int $round,
        ?string $deviceId,
        ?int $userId,
        string $requestUuid,
        bool $accumulate = false,
    ): WmsInventoryCountItem {
        // Idempotency check: if this request_uuid already exists, return as-is
        $existingLog = WmsInventoryCountItemLog::where('request_uuid', $requestUuid)->first();
        if ($existingLog) {
            return $countItem;
        }

        // Save old quantity for the log
        $oldQuantity = match ($round) {
            1 => $countItem->first_count_quantity,
            2 => $countItem->second_count_quantity,
            3 => $countItem->final_count_quantity,
            default => null,
        };

        $newQuantity = $accumulate
            ? (float) ($oldQuantity ?? 0) + (float) $quantity
            : (float) $quantity;

        // Update the appropriate count quantity based on round
        $updateData = [
            'input_count' => ($countItem->input_count ?? 0) + 1,
            'last_counted_at' => now(),
        ];

        match ($round) {
            1 => $updateData += [
                'first_count_quantity' => $newQuantity,
                'first_count_actor_name' => $this->actorName($deviceId, $userId),
            ],
            2 => $updateData += [
                'second_count_quantity' => $newQuantity,
                'second_count_actor_name' => $this->actorName($deviceId, $userId),
            ],
            3 => $updateData += [
                'final_count_quantity' => $newQuantity,
                'final_count_actor_name' => $this->actorName($deviceId, $userId),
            ],
            default => throw new \InvalidArgumentException('count round must be 1, 2, or 3'),
        };

        $countedQty = match ($round) {
            1 => $updateData['first_count_quantity'] ?? $countItem->first_count_quantity,
            2 => $updateData['second_count_quantity'] ?? $countItem->second_count_quantity,
            3 => $updateData['final_count_quantity'] ?? $countItem->final_count_quantity,
        };

        if ($countedQty !== null) {
            $updateData['difference_quantity'] = (float) $countedQty - (float) $countItem->system_quantity;
            $updateData['difference_amount'] = (float) $updateData['difference_quantity'] * (float) $countItem->cost_price;
        }

        $countItem->update($updateData);

        // Create log record
        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $countItem->id,
            'device_id' => $deviceId,
            'user_id' => $userId,
            'count_round' => $round,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'request_uuid' => $requestUuid,
            'created_at' => now(),
        ]);

        return $countItem;
    }

    public function calculateDifferences(WmsInventoryCount $inventoryCount): void
    {
        $inventoryCount->items()
            ->whereNotNull('final_count_quantity')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $finalQty = $item->final_count_quantity;

                    $item->difference_quantity = (float) $finalQty - (float) $item->system_quantity;
                    $item->difference_amount = (float) $item->difference_quantity * (float) $item->cost_price;
                    $item->save();
                }
            });

        $inventoryCount->items()
            ->whereNull('final_count_quantity')
            ->update([
                'difference_quantity' => null,
                'difference_amount' => null,
            ]);

        $inventoryCount->update(['status' => WmsInventoryCount::STATUS_CHECKED]);
    }

    public function confirm(WmsInventoryCount $inventoryCount, int $userId): void
    {
        DB::connection('sakemaru')->transaction(function () use ($inventoryCount, $userId) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventoryCount->status === WmsInventoryCount::STATUS_CONFIRMED) {
                return;
            }

            $this->confirmUncountedItemsAsCurrentQuantity($inventoryCount);

            $this->refreshDifferences($inventoryCount);

            $queueResult = $this->createInventoryAdjustmentQueues($inventoryCount);

            $updates = [
                'status' => WmsInventoryCount::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
                'handy_reception' => false,
            ];

            foreach ([
                'inventory_adjustment_request_id' => $queueResult['request_id'],
                'inventory_adjustment_queue_id' => $queueResult['queue_id'],
                'inventory_adjustment_request_ids' => $queueResult['request_ids'] !== [] ? json_encode($queueResult['request_ids'], JSON_UNESCAPED_UNICODE) : null,
                'inventory_adjustment_queue_ids' => $queueResult['queue_ids'] !== [] ? json_encode($queueResult['queue_ids'], JSON_UNESCAPED_UNICODE) : null,
                'inventory_adjustment_queue_count' => count($queueResult['queue_ids']),
                'inventory_adjustment_error_message' => null,
            ] as $column => $value) {
                if (Schema::connection('sakemaru')->hasColumn('wms_inventory_counts', $column)) {
                    $updates[$column] = $value;
                }
            }

            $inventoryCount->update($updates);
        });
    }

    private function refreshDifferences(WmsInventoryCount $inventoryCount): void
    {
        $inventoryCount->items()
            ->whereNotNull('final_count_quantity')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $differenceQuantity = (int) $item->final_count_quantity - (int) $item->system_quantity;
                    $item->update([
                        'difference_quantity' => $differenceQuantity,
                        'difference_amount' => $differenceQuantity * (float) $item->cost_price,
                    ]);
                }
            });
    }

    private function confirmUncountedItemsAsCurrentQuantity(WmsInventoryCount $inventoryCount): void
    {
        $inventoryCount->items()
            ->whereNull('final_count_quantity')
            ->update([
                'final_count_quantity' => DB::raw('system_quantity'),
                'difference_quantity' => 0,
                'difference_amount' => 0,
                'updated_at' => now(),
            ]);
    }

    private function actorName(?string $deviceId, ?int $userId): string
    {
        if ($deviceId === 'WEB') {
            $userName = $userId ? User::find($userId)?->name : null;

            return $userName ? "WEB: {$userName}" : 'WEB';
        }

        $pickerName = $userId ? WmsPicker::find($userId)?->display_name : null;
        if ($pickerName) {
            return $pickerName;
        }

        $userName = $userId ? User::find($userId)?->name : null;

        return $userName
            ?? ($deviceId ? "HANDY: {$deviceId}" : '不明');
    }

    private function createInventoryAdjustmentQueues(WmsInventoryCount $inventoryCount): array
    {
        $connection = DB::connection('sakemaru');
        $countDate = $inventoryCount->stock_movement_from_at?->toDateString()
            ?? $inventoryCount->count_date?->toDateString()
            ?? (string) $inventoryCount->count_date;

        $items = $this->inventoryAdjustmentBaseQuery($inventoryCount)
            ->whereNotIn(DB::raw('LEFT(TRIM(CAST(ici.item_code AS CHAR)), 1)'), self::INVENTORY_ADJUSTMENT_EXCLUDED_PREFIXES)
            ->orderBy('ici.id')
            ->get([
                'ici.id',
                'ici.real_stock_id',
                'ici.item_code',
                'ici.system_quantity',
                'ici.post_count_movement_quantity',
                'ici.final_count_quantity',
                'ici.difference_quantity',
                'ici.cost_price',
                'ici.location_no',
                'ici.location_code1',
                'sa.code as stock_allocation_code',
            ]);

        if ($items->isEmpty()) {
            return [
                'request_id' => null,
                'queue_id' => null,
                'request_ids' => [],
                'queue_ids' => [],
                'duplicated' => false,
            ];
        }

        if (! Schema::connection('sakemaru')->hasTable('inventory_adjustment_queue')) {
            throw new \RuntimeException('実棚変更キューテーブルが見つかりません。ai-core側のマイグレーションを先に実行してください。');
        }

        $requestIds = [];
        $queueIds = [];
        $duplicated = false;

        foreach ($items->groupBy(fn ($item) => $this->inventoryAdjustmentLocationBucket($item)) as $bucket => $groupedItems) {
            $requestId = "wms-inventory-adjustment-{$inventoryCount->id}-{$bucket}";

            $existing = $connection->table('inventory_adjustment_queue')
                ->where('request_id', $requestId)
                ->first(['id', 'request_id', 'status', 'inventory_adjustment_id']);

            if ($existing) {
                $requestIds[] = $existing->request_id;
                $queueIds[] = (int) $existing->id;
                $duplicated = true;

                continue;
            }

            $details = $groupedItems->map(function ($item) use ($inventoryCount, $bucket) {
                $postCountMovementQuantity = (int) ($item->post_count_movement_quantity ?? 0);

                return [
                    'wms_inventory_count_item_id' => (int) $item->id,
                    'real_stock_id' => $item->real_stock_id ? (int) $item->real_stock_id : null,
                    'item_code' => (string) $item->item_code,
                    'stock_allocation_code' => $item->stock_allocation_code ?: '1',
                    'stock_quantity_before' => (int) $item->system_quantity + $postCountMovementQuantity,
                    'stock_quantity_after' => (int) $item->final_count_quantity + $postCountMovementQuantity,
                    'inventory_adjustment_quantity' => (int) $item->difference_quantity,
                    'unit_price' => (float) $item->cost_price,
                    'amount' => (float) $item->difference_quantity * (float) $item->cost_price,
                    'note' => "WMS棚卸 {$inventoryCount->count_no} 棚番{$bucket}",
                ];
            })->values()->all();

            $queueId = $connection->table('inventory_adjustment_queue')->insertGetId([
                'client_id' => $inventoryCount->client_id,
                'slip_number' => "{$inventoryCount->count_no}-{$bucket}",
                'process_date' => $countDate,
                'adjustment_date' => $countDate,
                'note' => "WMS棚卸確定 {$inventoryCount->count_no} 棚番{$bucket}",
                'items' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'warehouse_code' => $inventoryCount->warehouse_code,
                'source_type' => 'WMS_INVENTORY_COUNT',
                'source_id' => $inventoryCount->id,
                'wms_inventory_count_id' => $inventoryCount->id,
                'request_id' => $requestId,
                'status' => 'BEFORE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $requestIds[] = $requestId;
            $queueIds[] = (int) $queueId;
        }

        return [
            'request_id' => $requestIds[0] ?? null,
            'queue_id' => $queueIds[0] ?? null,
            'request_ids' => $requestIds,
            'queue_ids' => $queueIds,
            'duplicated' => $duplicated,
        ];
    }

    public function inventoryAdjustmentExcludedSummary(WmsInventoryCount $inventoryCount, int $limit = 100): array
    {
        $query = $this->inventoryAdjustmentBaseQuery($inventoryCount)
            ->whereIn(DB::raw('LEFT(TRIM(CAST(ici.item_code AS CHAR)), 1)'), self::INVENTORY_ADJUSTMENT_EXCLUDED_PREFIXES);

        $detailCount = (clone $query)->count();
        $itemCount = (clone $query)->distinct()->count('ici.item_code');

        $items = (clone $query)
            ->selectRaw('ici.item_code, MAX(ici.item_name) as item_name, COUNT(*) as detail_count, SUM(ici.difference_quantity) as difference_quantity, SUM(ici.difference_amount) as difference_amount')
            ->groupBy('ici.item_code')
            ->orderBy('ici.item_code')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'item_code' => (string) $item->item_code,
                'item_name' => (string) $item->item_name,
                'detail_count' => (int) $item->detail_count,
                'difference_quantity' => (float) $item->difference_quantity,
                'difference_amount' => (float) $item->difference_amount,
            ])
            ->all();

        return [
            'prefixes' => self::INVENTORY_ADJUSTMENT_EXCLUDED_PREFIXES,
            'detail_count' => $detailCount,
            'item_count' => $itemCount,
            'items' => $items,
            'has_more' => $itemCount > count($items),
        ];
    }

    private function inventoryAdjustmentBaseQuery(WmsInventoryCount $inventoryCount)
    {
        return DB::connection('sakemaru')
            ->table('wms_inventory_count_items as ici')
            ->leftJoin('real_stocks as rs', 'rs.id', '=', 'ici.real_stock_id')
            ->leftJoin('stock_allocations as sa', 'sa.id', '=', 'rs.stock_allocation_id')
            ->where('ici.inventory_count_id', $inventoryCount->id)
            ->whereNotNull('ici.final_count_quantity')
            ->whereNotNull('ici.difference_quantity')
            ->where('ici.difference_quantity', '!=', 0);
    }

    private function inventoryAdjustmentLocationBucket(object $item): string
    {
        $location = trim((string) ($item->location_no ?: $item->location_code1 ?: ''));

        if ($location === '') {
            return 'NO_LOCATION';
        }

        $bucket = substr(preg_replace('/[^A-Za-z0-9]/', '', $location) ?: $location, 0, 2);

        return $bucket !== '' ? strtoupper($bucket) : 'NO_LOCATION';
    }

    public function cancel(WmsInventoryCount $inventoryCount): void
    {
        $inventoryCount->update([
            'status' => WmsInventoryCount::STATUS_CANCELLED,
            'handy_reception' => false,
        ]);
    }

    public function restoreCancelledForCounting(WmsInventoryCount $inventoryCount): void
    {
        DB::connection('sakemaru')->transaction(function () use ($inventoryCount) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($inventoryCount->status !== WmsInventoryCount::STATUS_CANCELLED) {
                throw new \RuntimeException('取消済みの棚卸しのみカウント中に戻せます。');
            }

            $currentRound = min(max((int) ($inventoryCount->current_count_round ?: 1), 1), 3);
            $updates = [
                'status' => WmsInventoryCount::STATUS_COUNTING,
                'current_count_round' => $currentRound,
                'started_at' => $inventoryCount->started_at ?? now(),
                'handy_reception' => false,
            ];

            if ($inventoryCount->final_count_confirmed_at !== null) {
                $updates['current_count_round'] = 3;
                $updates['final_count_confirmed_at'] = null;
                $updates['final_count_confirmed_by'] = null;
            }

            $inventoryCount->update($updates);
        });
    }
}
