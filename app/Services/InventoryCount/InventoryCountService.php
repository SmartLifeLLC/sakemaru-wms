<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use App\Services\Warehouse91StockLotSyncService;
use Illuminate\Support\Facades\DB;

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
            ->where('rs.current_quantity', '!=', 0)
            ->select([
                'rs.id as real_stock_id',
                'rs.item_id',
                'i.code as item_code',
                'i.name as item_name',
                DB::raw('(SELECT isi.search_string FROM item_search_information isi WHERE isi.item_id = i.id AND isi.code_type = 1 AND isi.is_active = 1 LIMIT 1) as barcode'),
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
                            $row->location_code3,
                            '-'
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

    public function registerCount(
        WmsInventoryCountItem $countItem,
        float $quantity,
        int $round,
        ?string $deviceId,
        ?int $userId,
        string $requestUuid,
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

        // Update the appropriate count quantity based on round
        $updateData = [
            'input_count' => ($countItem->input_count ?? 0) + 1,
            'last_counted_at' => now(),
        ];

        match ($round) {
            1 => $updateData['first_count_quantity'] = $quantity,
            2 => $updateData['second_count_quantity'] = $quantity,
            3 => $updateData['final_count_quantity'] = $quantity,
            default => throw new \InvalidArgumentException('count round must be 1, 2, or 3'),
        };

        $countedQty = $round === 3
            ? $quantity
            : ($updateData['second_count_quantity'] ?? $countItem->second_count_quantity ?? $updateData['first_count_quantity'] ?? $countItem->first_count_quantity);

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
            'new_quantity' => $quantity,
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

            $missingFinalCount = $inventoryCount->items()
                ->whereNull('final_count_quantity')
                ->count();

            if ($missingFinalCount > 0) {
                throw new \RuntimeException("最終数量が未入力の明細が {$missingFinalCount} 件あります。");
            }

            $this->refreshDifferences($inventoryCount);

            $queueResult = $this->createStockAdjustmentQueue($inventoryCount);

            $inventoryCount->update([
                'status' => WmsInventoryCount::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
                'stock_adjustment_request_id' => $queueResult['request_id'],
                'stock_adjustment_queue_id' => $queueResult['queue_id'],
                'stock_adjustment_error_message' => null,
            ]);
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

    private function createStockAdjustmentQueue(WmsInventoryCount $inventoryCount): array
    {
        $connection = DB::connection('sakemaru');
        $requestId = "wms-inventory-count-{$inventoryCount->id}";
        $countDate = $inventoryCount->count_date?->toDateString() ?? (string) $inventoryCount->count_date;

        $existing = $connection->table('stock_adjustment_queue')
            ->where('request_id', $requestId)
            ->first(['id', 'request_id', 'status', 'stock_adjustment_id']);

        if ($existing) {
            return [
                'request_id' => $existing->request_id,
                'queue_id' => (int) $existing->id,
                'duplicated' => true,
            ];
        }

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
                'sa.code as stock_allocation_code',
            ]);

        if ($items->isEmpty()) {
            return [
                'request_id' => null,
                'queue_id' => null,
                'duplicated' => false,
            ];
        }

        $details = $items->map(fn ($item) => [
            'wms_inventory_count_item_id' => (int) $item->id,
            'real_stock_id' => $item->real_stock_id ? (int) $item->real_stock_id : null,
            'item_code' => (string) $item->item_code,
            'stock_allocation_code' => $item->stock_allocation_code ?: '1',
            'stock_quantity_before' => (int) $item->system_quantity,
            'stock_quantity_after' => (int) $item->final_count_quantity,
            'stock_adjustment_quantity' => (int) $item->difference_quantity,
            'unit_price' => (float) $item->cost_price,
            'amount' => (float) $item->difference_quantity * (float) $item->cost_price,
            'note' => "WMS棚卸 {$inventoryCount->count_no}",
        ])->values()->all();

        $queueId = $connection->table('stock_adjustment_queue')->insertGetId([
            'client_id' => $inventoryCount->client_id,
            'slip_number' => $inventoryCount->count_no,
            'process_date' => $countDate,
            'adjustment_date' => $countDate,
            'note' => "WMS棚卸確定 {$inventoryCount->count_no}",
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

        return [
            'request_id' => $requestId,
            'queue_id' => (int) $queueId,
            'duplicated' => false,
        ];
    }

    public function cancel(WmsInventoryCount $inventoryCount): void
    {
        $inventoryCount->update([
            'status' => WmsInventoryCount::STATUS_CANCELLED,
        ]);
    }
}
