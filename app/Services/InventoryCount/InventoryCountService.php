<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use Illuminate\Support\Facades\DB;

class InventoryCountService
{
    public function create(array $data): WmsInventoryCount
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        $count = WmsInventoryCount::create([
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

        return $count;
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
        $oldQuantity = $round === 1
            ? $countItem->first_count_quantity
            : $countItem->second_count_quantity;

        // Update the appropriate count quantity based on round
        $updateData = [
            'input_count' => ($countItem->input_count ?? 0) + 1,
            'last_counted_at' => now(),
        ];

        if ($round === 1) {
            $updateData['first_count_quantity'] = $quantity;
        } else {
            $updateData['second_count_quantity'] = $quantity;
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
            ->whereNotNull('first_count_quantity')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    $finalQty = $item->final_count_quantity
                        ?? $item->second_count_quantity
                        ?? $item->first_count_quantity;

                    $item->final_count_quantity = $finalQty;
                    $item->difference_quantity = (float) $finalQty - (float) $item->system_quantity;
                    $item->difference_amount = (float) $item->difference_quantity * (float) $item->cost_price;
                    $item->save();
                }
            });

        $inventoryCount->items()
            ->whereNull('first_count_quantity')
            ->update([
                'final_count_quantity' => null,
                'difference_quantity' => null,
                'difference_amount' => null,
            ]);

        $inventoryCount->update(['status' => WmsInventoryCount::STATUS_CHECKED]);
    }

    public function confirm(WmsInventoryCount $inventoryCount, int $userId): void
    {
        DB::connection('sakemaru')->transaction(function () use ($inventoryCount, $userId) {
            $inventoryCount->update([
                'status' => WmsInventoryCount::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
            ]);

            $inventoryCount->items()
                ->whereNotNull('difference_quantity')
                ->where('difference_quantity', '!=', 0)
                ->whereNotNull('real_stock_id')
                ->chunkById(100, function ($items) {
                    foreach ($items as $item) {
                        $realStock = DB::connection('sakemaru')
                            ->table('real_stocks')
                            ->where('id', $item->real_stock_id)
                            ->first();

                        if (! $realStock) {
                            continue;
                        }

                        $diff = (float) $item->difference_quantity;
                        $affected = DB::connection('sakemaru')
                            ->table('real_stocks')
                            ->where('id', $item->real_stock_id)
                            ->where('wms_lock_version', $realStock->wms_lock_version)
                            ->update([
                                'current_quantity' => DB::raw("current_quantity + ({$diff})"),
                                'available_quantity' => DB::raw("available_quantity + ({$diff})"),
                                'wms_lock_version' => DB::raw('wms_lock_version + 1'),
                                'updated_at' => now(),
                            ]);

                        if ($affected === 0) {
                            throw new \RuntimeException(
                                "楽観ロック競合: real_stock_id={$item->real_stock_id}"
                            );
                        }
                    }
                });
        });
    }

    public function cancel(WmsInventoryCount $inventoryCount): void
    {
        $inventoryCount->update([
            'status' => WmsInventoryCount::STATUS_CANCELLED,
        ]);
    }
}
