<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Models\WmsPicker;
use App\Services\Warehouse91StockLotSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryCountService
{
    public function create(array $data): WmsInventoryCount
    {
        return DB::connection('sakemaru')->transaction(function () use ($data) {
            $warehouse = Warehouse::findOrFail($data['warehouse_id']);

            $activeCounts = WmsInventoryCount::query()
                ->where('warehouse_id', $warehouse->id)
                ->active()
                ->lockForUpdate()
                ->get();

            if ($activeCounts->isNotEmpty() && empty($data['force_close_existing'])) {
                throw new \RuntimeException('この倉庫には処理中の棚卸しがあります。既存棚卸しを強制終了する確認が必要です。');
            }

            if ($activeCounts->isNotEmpty()) {
                WmsInventoryCount::query()
                    ->whereKey($activeCounts->pluck('id'))
                    ->update([
                        'status' => WmsInventoryCount::STATUS_CANCELLED,
                        'updated_at' => now(),
                    ]);
            }

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
        app(Warehouse91StockLotSyncService::class)->sync([], false);

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
        app(Warehouse91StockLotSyncService::class)->sync([], false);

        return DB::connection('sakemaru')->transaction(function () use ($inventoryCount) {
            $inventoryCount = WmsInventoryCount::query()
                ->whereKey($inventoryCount->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($inventoryCount->status, [
                WmsInventoryCount::STATUS_CONFIRMED,
                WmsInventoryCount::STATUS_CANCELLED,
            ], true)) {
                throw new \RuntimeException('確定済または取消済の棚卸しは現在庫に更新できません。');
            }

            $updatedItems = 0;
            $updatedDifferences = 0;
            $missingRealStocks = 0;

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
                ->chunkById(500, function ($items) use (&$updatedItems, &$updatedDifferences, &$missingRealStocks) {
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

                        if ($updateData !== []) {
                            $updateData['updated_at'] = now();
                            WmsInventoryCountItem::whereKey($item->id)->update($updateData);
                        }
                    }
                });

            return [
                'updated_items' => $updatedItems,
                'updated_differences' => $updatedDifferences,
                'missing_real_stocks' => $missingRealStocks,
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
        app(Warehouse91StockLotSyncService::class)->sync([], false);

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
        $countDate = $inventoryCount->count_date?->toDateString() ?? (string) $inventoryCount->count_date;

        $items = $connection
            ->table('wms_inventory_count_items as ici')
            ->leftJoin('real_stocks as rs', 'rs.id', '=', 'ici.real_stock_id')
            ->leftJoin('stock_allocations as sa', 'sa.id', '=', 'rs.stock_allocation_id')
            ->where('ici.inventory_count_id', $inventoryCount->id)
            ->whereNotNull('ici.final_count_quantity')
            ->whereNotNull('ici.difference_quantity')
            ->where('ici.difference_quantity', '!=', 0)
            ->orderBy('ici.id')
            ->get([
                'ici.id',
                'ici.real_stock_id',
                'ici.item_code',
                'ici.system_quantity',
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

            $details = $groupedItems->map(fn ($item) => [
                'wms_inventory_count_item_id' => (int) $item->id,
                'real_stock_id' => $item->real_stock_id ? (int) $item->real_stock_id : null,
                'item_code' => (string) $item->item_code,
                'stock_allocation_code' => $item->stock_allocation_code ?: '1',
                'stock_quantity_before' => (int) $item->system_quantity,
                'stock_quantity_after' => (int) $item->final_count_quantity,
                'inventory_adjustment_quantity' => (int) $item->difference_quantity,
                'unit_price' => (float) $item->cost_price,
                'amount' => (float) $item->difference_quantity * (float) $item->cost_price,
                'note' => "WMS棚卸 {$inventoryCount->count_no} 棚番{$bucket}",
            ])->values()->all();

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
        ]);
    }
}
