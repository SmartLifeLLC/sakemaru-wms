<?php

namespace App\Console\Commands;

use App\Enums\AvailableQuantityFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPickableLotsToRealStocksCommand extends Command
{
    protected $signature = 'stock:sync-pickable-lots-to-real-stocks
                            {--warehouse-id= : 対象倉庫ID。未指定時は --all-warehouses が必要}
                            {--all-warehouses : 全倉庫を対象にする}
                            {--item-code= : 対象商品CD}
                            {--item-id= : 対象商品ID}
                            {--quantity-type=PIECE : CASE|PIECE|CARTON}
                            {--mode=both : both|increase|decrease}
                            {--limit=100 : 最大処理件数}
                            {--output= : CSV output path. Defaults to storage/reports/current timestamp}
                            {--include-empty-containers : 商品名に空容器を含む在庫も対象にする}
                            {--include-negative-reserved : real_stocks.reserved_quantity < 0 の在庫も対象に含める}
                            {--apply : 実際にreal_stock_lotsを補正する}';

    protected $description = 'Sync WMS pickable real_stock_lots availability to real_stocks.available_quantity.';

    public function handle(): int
    {
        $warehouseId = $this->resolveWarehouseId();
        if ($warehouseId === false) {
            return self::FAILURE;
        }

        $mode = $this->resolveMode();
        if ($mode === false) {
            return self::FAILURE;
        }

        $itemId = $this->resolveItemId();
        $quantityFlag = $this->resolveQuantityFlag();
        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN: no rows will be changed. Add --apply to sync data.');
        }

        $targets = $this->findTargets($warehouseId, $quantityFlag, $itemId, $mode, $limit);
        if ($targets->isEmpty()) {
            $this->info('No sync targets found.');

            return self::SUCCESS;
        }

        $rows = [];
        $updated = 0;
        $created = 0;
        $skipped = 0;
        $totalDelta = 0;

        foreach ($targets as $target) {
            $direction = (int) $target->delta > 0 ? 'increase' : 'decrease';
            $plan = $direction === 'increase'
                ? $this->buildIncreasePlan($target, $quantityFlag)
                : null;

            $status = 'dry';
            $message = '';
            $applied = [
                'mode' => $direction,
                'qty' => abs((int) $target->delta),
                'lot_ids' => [],
                'location_id' => $plan?->location_id,
                'location_code' => $this->formatLocationCode($plan),
                'location_source' => $plan?->location_source,
            ];

            if ($direction === 'increase' && ! $plan) {
                $status = 'skipped';
                $message = 'no safe pickable location';
                $skipped++;
            } elseif ($apply) {
                $applied = $direction === 'increase'
                    ? $this->applyIncrease((int) $target->real_stock_id, $quantityFlag, $plan)
                    : $this->applyDecrease((int) $target->real_stock_id, $quantityFlag);

                if ($applied['qty'] <= 0) {
                    $status = 'skipped';
                    $message = 'no delta after recheck';
                    $skipped++;
                } elseif ($applied['mode'] === 'create') {
                    $status = 'created';
                    $created++;
                    $totalDelta += $applied['qty'];
                } else {
                    $status = 'updated';
                    $updated++;
                    $totalDelta += $applied['qty'];
                }
            } else {
                $totalDelta += $applied['qty'];
            }

            $this->line(sprintf(
                '%s %s item=%s warehouse=%s real_stock_id=%d real_available=%d pickable_available=%d qty=%d lots=%s location=%s(%s) %s',
                strtoupper($status),
                strtoupper($direction),
                $target->item_code,
                $target->warehouse_code,
                $target->real_stock_id,
                $target->real_available,
                $target->pickable_available,
                $applied['qty'],
                implode('|', $applied['lot_ids'] ?? []),
                $applied['location_code'] ?? '-',
                $applied['location_id'] ?? '-',
                $message
            ));

            $rows[] = [
                'status' => $status,
                'message' => $message,
                'direction' => $direction,
                'mode' => $applied['mode'],
                'real_stock_id' => $target->real_stock_id,
                'warehouse_id' => $target->warehouse_id,
                'warehouse_code' => $target->warehouse_code,
                'item_id' => $target->item_id,
                'item_code' => $target->item_code,
                'item_name' => $target->item_name,
                'real_current' => $target->current_quantity,
                'real_reserved' => $target->reserved_quantity,
                'real_available' => $target->real_available,
                'pickable_available' => $target->pickable_available,
                'delta' => $target->delta,
                'applied_qty' => $applied['qty'],
                'lot_ids' => implode('|', $applied['lot_ids'] ?? []),
                'location_id' => $applied['location_id'] ?? null,
                'location_code' => $applied['location_code'] ?? null,
                'location_source' => $applied['location_source'] ?? null,
            ];
        }

        $outputPath = $this->writeCsv($rows);

        $this->info(($apply ? 'Sync complete' : 'Dry run complete')
            .": updated={$updated}, created={$created}, skipped={$skipped}, total_delta_qty={$totalDelta}");
        $this->info("CSV: {$outputPath}");

        return self::SUCCESS;
    }

    private function resolveWarehouseId(): int|false|null
    {
        $warehouseId = $this->option('warehouse-id');
        if ($warehouseId !== null && $warehouseId !== '') {
            return (int) $warehouseId;
        }

        if ($this->option('all-warehouses')) {
            return null;
        }

        $this->error('--warehouse-id is required unless --all-warehouses is set.');

        return false;
    }

    private function resolveMode(): string|false
    {
        $mode = strtolower((string) $this->option('mode'));
        if (in_array($mode, ['both', 'increase', 'decrease'], true)) {
            return $mode;
        }

        $this->error('--mode must be one of: both, increase, decrease.');

        return false;
    }

    private function resolveItemId(): ?int
    {
        if ($this->option('item-id')) {
            return (int) $this->option('item-id');
        }

        if (! $this->option('item-code')) {
            return null;
        }

        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('code', $this->option('item-code'))
            ->first(['id']);

        if (! $item) {
            $this->error("Item code not found: {$this->option('item-code')}");
            exit(self::FAILURE);
        }

        return (int) $item->id;
    }

    private function resolveQuantityFlag(): AvailableQuantityFlag
    {
        return match (strtoupper((string) $this->option('quantity-type'))) {
            'CASE' => AvailableQuantityFlag::CASE,
            'CARTON' => AvailableQuantityFlag::CARTON,
            default => AvailableQuantityFlag::PIECE,
        };
    }

    private function findTargets(?int $warehouseId, AvailableQuantityFlag $quantityFlag, ?int $itemId, string $mode, int $limit): \Illuminate\Support\Collection
    {
        $pickableAvailableSql = 'COALESCE(SUM(CASE WHEN l.id IS NOT NULL THEN GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0) ELSE 0 END), 0)';
        $realAvailableSql = 'GREATEST(rs.available_quantity, 0)';
        $deltaSql = "({$realAvailableSql} - {$pickableAvailableSql})";

        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('items as i', 'i.id', '=', 'rs.item_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'rs.warehouse_id')
            ->leftJoin('real_stock_lots as rsl', function ($join) {
                $join->on('rsl.real_stock_id', '=', 'rs.id')
                    ->where('rsl.status', '=', 'ACTIVE');
            })
            ->leftJoin('locations as l', function ($join) use ($quantityFlag) {
                $join->on('l.id', '=', 'rsl.location_id')
                    ->whereNotNull('l.floor_id')
                    ->where('l.available_quantity_flags', '!=', AvailableQuantityFlag::UNKNOWN->value)
                    ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0");
            });

        if ($warehouseId !== null) {
            $query->where('rs.warehouse_id', $warehouseId);
        }

        if (! $this->option('include-negative-reserved')) {
            $query->where('rs.reserved_quantity', '>=', 0);
        }

        if (! $this->option('include-empty-containers')) {
            $query->where('i.name', 'not like', '%空容器%');
        }

        if ($itemId !== null) {
            $query->where('rs.item_id', $itemId);
        }

        $having = match ($mode) {
            'increase' => "{$deltaSql} > 0",
            'decrease' => "{$deltaSql} < 0",
            default => "{$deltaSql} <> 0",
        };

        return $query
            ->groupBy('rs.id', 'rs.warehouse_id', 'w.code', 'rs.item_id', 'i.code', 'i.name', 'rs.current_quantity', 'rs.reserved_quantity', 'rs.available_quantity')
            ->havingRaw($having)
            ->orderByRaw("ABS({$deltaSql}) DESC")
            ->limit($limit)
            ->get([
                'rs.id as real_stock_id',
                'rs.warehouse_id',
                'w.code as warehouse_code',
                'rs.item_id',
                'i.code as item_code',
                'i.name as item_name',
                'rs.current_quantity',
                'rs.reserved_quantity',
                DB::raw("{$realAvailableSql} as real_available"),
                DB::raw("{$pickableAvailableSql} as pickable_available"),
                DB::raw("{$deltaSql} as delta"),
            ]);
    }

    private function buildIncreasePlan(object $target, AvailableQuantityFlag $quantityFlag): ?object
    {
        $initialLot = $this->initialPickableLot((int) $target->real_stock_id, $quantityFlag);
        if ($initialLot) {
            $initialLot->mode = 'update';
            $initialLot->location_source = 'existing_initial_lot';

            return $initialLot;
        }

        $existingLotLocation = $this->existingPickableLotLocation((int) $target->real_stock_id, $quantityFlag);
        if ($existingLotLocation) {
            $existingLotLocation->mode = 'create';
            $existingLotLocation->location_source = 'existing_lot_location';

            return $existingLotLocation;
        }

        $defaultLocation = $this->defaultLocation((int) $target->warehouse_id, (int) $target->item_id, $quantityFlag);
        if ($defaultLocation) {
            $defaultLocation->mode = 'create';
            $defaultLocation->location_source = 'item_default_location';

            return $defaultLocation;
        }

        $sameItemLocation = $this->sameItemPickableLotLocation((int) $target->warehouse_id, (int) $target->item_id, $quantityFlag);
        if ($sameItemLocation) {
            $sameItemLocation->mode = 'create';
            $sameItemLocation->location_source = 'same_item_existing_lot_location';

            return $sameItemLocation;
        }

        $fallbackLocation = $this->z00Location((int) $target->warehouse_id, $quantityFlag);
        if ($fallbackLocation) {
            $fallbackLocation->mode = 'create';
            $fallbackLocation->location_source = 'z00_fallback';

            return $fallbackLocation;
        }

        return null;
    }

    private function applyIncrease(int $realStockId, AvailableQuantityFlag $quantityFlag, object $plan): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($realStockId, $quantityFlag, $plan): array {
            $delta = $this->lockedDelta($realStockId, $quantityFlag);
            if ($delta <= 0) {
                return ['mode' => 'skip', 'qty' => 0, 'lot_ids' => []];
            }

            if ($plan->mode === 'update' && $plan->lot_id) {
                DB::connection('sakemaru')
                    ->table('real_stock_lots')
                    ->where('id', $plan->lot_id)
                    ->where('real_stock_id', $realStockId)
                    ->whereNull('purchase_id')
                    ->whereNull('trade_item_id')
                    ->where('status', 'ACTIVE')
                    ->update([
                        'initial_quantity' => DB::raw("initial_quantity + {$delta}"),
                        'current_quantity' => DB::raw("current_quantity + {$delta}"),
                        'updated_at' => now(),
                    ]);

                return [
                    'mode' => 'update',
                    'qty' => $delta,
                    'lot_ids' => [$plan->lot_id],
                    'location_id' => $plan->location_id,
                    'location_code' => $this->formatLocationCode($plan),
                    'location_source' => $plan->location_source,
                ];
            }

            $lotId = DB::connection('sakemaru')
                ->table('real_stock_lots')
                ->insertGetId([
                    'real_stock_id' => $realStockId,
                    'purchase_id' => null,
                    'trade_item_id' => null,
                    'purchase_price' => null,
                    'floor_id' => $plan->floor_id,
                    'location_id' => $plan->location_id,
                    'price' => null,
                    'content_amount' => 0,
                    'container_amount' => 0,
                    'expiration_date' => null,
                    'alert_date' => null,
                    'initial_quantity' => $delta,
                    'current_quantity' => $delta,
                    'reserved_quantity' => 0,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'mode' => 'create',
                'qty' => $delta,
                'lot_ids' => [$lotId],
                'location_id' => $plan->location_id,
                'location_code' => $this->formatLocationCode($plan),
                'location_source' => $plan->location_source,
            ];
        });
    }

    private function applyDecrease(int $realStockId, AvailableQuantityFlag $quantityFlag): array
    {
        return DB::connection('sakemaru')->transaction(function () use ($realStockId, $quantityFlag): array {
            $delta = $this->lockedDelta($realStockId, $quantityFlag);
            if ($delta >= 0) {
                return ['mode' => 'skip', 'qty' => 0, 'lot_ids' => []];
            }

            $remaining = abs($delta);
            $lotIds = [];

            $lots = DB::connection('sakemaru')
                ->table('real_stock_lots as rsl')
                ->join('locations as l', 'l.id', '=', 'rsl.location_id')
                ->where('rsl.real_stock_id', $realStockId)
                ->where('rsl.status', 'ACTIVE')
                ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
                ->whereRaw('rsl.current_quantity > rsl.reserved_quantity')
                ->orderByRaw('rsl.expiration_date IS NULL DESC')
                ->orderBy('rsl.expiration_date', 'desc')
                ->orderBy('rsl.created_at', 'desc')
                ->orderBy('rsl.id', 'desc')
                ->lockForUpdate()
                ->get([
                    'rsl.id',
                    'rsl.current_quantity',
                    'rsl.reserved_quantity',
                ]);

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $available = max(0, (int) $lot->current_quantity - (int) $lot->reserved_quantity);
                $take = min($available, $remaining);
                if ($take <= 0) {
                    continue;
                }

                $newCurrent = (int) $lot->current_quantity - $take;
                DB::connection('sakemaru')
                    ->table('real_stock_lots')
                    ->where('id', $lot->id)
                    ->update([
                        'current_quantity' => $newCurrent,
                        'status' => $newCurrent <= 0 ? 'DEPLETED' : 'ACTIVE',
                        'updated_at' => now(),
                    ]);

                $lotIds[] = $lot->id;
                $remaining -= $take;
            }

            $applied = abs($delta) - $remaining;

            return [
                'mode' => 'decrease',
                'qty' => $applied,
                'lot_ids' => $lotIds,
            ];
        });
    }

    private function lockedDelta(int $realStockId, AvailableQuantityFlag $quantityFlag): int
    {
        $stock = DB::connection('sakemaru')
            ->table('real_stocks')
            ->where('id', $realStockId)
            ->lockForUpdate()
            ->first(['id', 'available_quantity']);

        if (! $stock) {
            return 0;
        }

        $pickableAvailable = (int) DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rsl.real_stock_id', $realStockId)
            ->where('rsl.status', 'ACTIVE')
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
            ->selectRaw('COALESCE(SUM(GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0)), 0) as qty')
            ->lockForUpdate()
            ->value('qty');

        return max((int) $stock->available_quantity, 0) - $pickableAvailable;
    }

    private function initialPickableLot(int $realStockId, AvailableQuantityFlag $quantityFlag): ?object
    {
        return DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rsl.real_stock_id', $realStockId)
            ->whereNull('rsl.purchase_id')
            ->whereNull('rsl.trade_item_id')
            ->where('rsl.status', 'ACTIVE')
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
            ->whereNotNull('l.floor_id')
            ->orderByDesc(DB::raw('GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0)'))
            ->orderByDesc('rsl.current_quantity')
            ->orderBy('rsl.id')
            ->first([
                'rsl.id as lot_id',
                'rsl.location_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
            ]);
    }

    private function existingPickableLotLocation(int $realStockId, AvailableQuantityFlag $quantityFlag): ?object
    {
        return DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rsl.real_stock_id', $realStockId)
            ->where('rsl.status', 'ACTIVE')
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
            ->whereNotNull('l.floor_id')
            ->orderByDesc(DB::raw('GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0)'))
            ->orderByDesc('rsl.current_quantity')
            ->orderBy('rsl.id')
            ->first([
                DB::raw('NULL as lot_id'),
                'rsl.location_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
            ]);
    }

    private function defaultLocation(int $warehouseId, int $itemId, AvailableQuantityFlag $quantityFlag): ?object
    {
        return $this->pickableLocationQuery($warehouseId, $quantityFlag)
            ->join('item_incoming_default_locations as idl', 'idl.location_id', '=', 'l.id')
            ->where('idl.warehouse_id', $warehouseId)
            ->where('idl.item_id', $itemId)
            ->orderBy('idl.id')
            ->first();
    }

    private function sameItemPickableLotLocation(int $warehouseId, int $itemId, AvailableQuantityFlag $quantityFlag): ?object
    {
        return DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->where('rsl.status', 'ACTIVE')
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
            ->whereNotNull('l.floor_id')
            ->orderByDesc(DB::raw('GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0)'))
            ->orderByDesc('rsl.updated_at')
            ->orderBy('rsl.id')
            ->first([
                DB::raw('NULL as lot_id'),
                'rsl.location_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
            ]);
    }

    private function z00Location(int $warehouseId, AvailableQuantityFlag $quantityFlag): ?object
    {
        return $this->pickableLocationQuery($warehouseId, $quantityFlag)
            ->where('l.code1', 'Z')
            ->where('l.code2', '0')
            ->where('l.code3', '0')
            ->first();
    }

    private function pickableLocationQuery(int $warehouseId, AvailableQuantityFlag $quantityFlag): \Illuminate\Database\Query\Builder
    {
        return DB::connection('sakemaru')
            ->table('locations as l')
            ->where('l.warehouse_id', $warehouseId)
            ->whereNotNull('l.floor_id')
            ->where('l.available_quantity_flags', '!=', AvailableQuantityFlag::UNKNOWN->value)
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
            ->select([
                DB::raw('NULL as lot_id'),
                'l.id as location_id',
                'l.floor_id',
                'l.code1',
                'l.code2',
                'l.code3',
            ]);
    }

    private function formatLocationCode(?object $location): ?string
    {
        if (! $location) {
            return null;
        }

        return collect([$location->code1 ?? null, $location->code2 ?? null, $location->code3 ?? null])
            ->filter(fn ($code) => $code !== null && $code !== '')
            ->implode('-');
    }

    private function writeCsv(array $rows): string
    {
        $path = $this->option('output');
        if (! $path) {
            $path = storage_path('reports/'.now()->format('Ymd-His').'-sync-pickable-lots-to-real-stocks.csv');
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $file = fopen($path, 'w');
        if ($file === false) {
            throw new \RuntimeException("Cannot write CSV: {$path}");
        }

        if ($rows !== []) {
            fputcsv($file, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($file, $row);
            }
        }

        fclose($file);

        return $path;
    }
}
