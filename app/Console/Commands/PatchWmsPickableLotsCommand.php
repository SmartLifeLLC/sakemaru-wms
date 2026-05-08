<?php

namespace App\Console\Commands;

use App\Enums\AvailableQuantityFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PatchWmsPickableLotsCommand extends Command
{
    protected $signature = 'stock:patch-wms-pickable-lots
                            {--warehouse-id= : 対象倉庫ID}
                            {--item-code= : 対象商品CD}
                            {--item-id= : 対象商品ID}
                            {--quantity-type=PIECE : CASE|PIECE|CARTON}
                            {--location-id= : 補正先ロケーションID。未指定時は商品デフォルト、次に倉庫内の最小ID引当可能ロケーション}
                            {--limit=50 : 最大処理件数}
                            {--apply : 実際にreal_stock_lotsへ作成する}';

    protected $description = 'Patch existing real_stocks that have available stock but no WMS-pickable lot.';

    public function handle(): int
    {
        $warehouseId = (int) $this->option('warehouse-id');
        if ($warehouseId <= 0) {
            $this->error('--warehouse-id is required.');

            return self::FAILURE;
        }

        $itemId = $this->resolveItemId();
        $quantityFlag = $this->resolveQuantityFlag();
        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN: no rows will be inserted. Add --apply to patch data.');
        }

        $targets = $this->findTargets($warehouseId, $quantityFlag, $itemId, $limit);
        if ($targets->isEmpty()) {
            $this->info('No patch targets found.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($targets as $target) {
            $location = $this->resolveLocation($warehouseId, $target->item_id, $quantityFlag);
            if (! $location) {
                $skipped++;
                $this->warn("SKIP item_id={$target->item_id} real_stock_id={$target->real_stock_id}: no pickable location");

                continue;
            }

            $patchQty = min((int) $target->real_available, (int) $target->missing_pickable_qty);
            if ($patchQty <= 0) {
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '%s item=%s real_stock_id=%d qty=%d location=%s-%s-%s(%d)',
                $apply ? 'PATCH' : 'DRY',
                $target->item_code,
                $target->real_stock_id,
                $patchQty,
                $location->code1,
                $location->code2,
                $location->code3,
                $location->id
            ));

            if ($apply) {
                DB::connection('sakemaru')->table('real_stock_lots')->insert([
                    'real_stock_id' => $target->real_stock_id,
                    'purchase_id' => null,
                    'trade_item_id' => null,
                    'purchase_price' => null,
                    'floor_id' => $location->floor_id,
                    'location_id' => $location->id,
                    'price' => null,
                    'content_amount' => 0,
                    'container_amount' => 0,
                    'expiration_date' => null,
                    'alert_date' => null,
                    'initial_quantity' => $patchQty,
                    'current_quantity' => $patchQty,
                    'reserved_quantity' => 0,
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $created++;
        }

        $this->info(($apply ? 'Patch complete' : 'Dry run complete').": created={$created}, skipped={$skipped}");

        return self::SUCCESS;
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

    private function findTargets(int $warehouseId, AvailableQuantityFlag $quantityFlag, ?int $itemId, int $limit): \Illuminate\Support\Collection
    {
        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('items as i', 'i.id', '=', 'rs.item_id')
            ->leftJoin('real_stock_lots as rsl', function ($join) {
                $join->on('rsl.real_stock_id', '=', 'rs.id')
                    ->where('rsl.status', '=', 'ACTIVE');
            })
            ->leftJoin('locations as l', function ($join) use ($quantityFlag) {
                $join->on('l.id', '=', 'rsl.location_id')
                    ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0");
            })
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.available_quantity', '>', 0);

        if ($itemId !== null) {
            $query->where('rs.item_id', $itemId);
        }

        return $query
            ->groupBy('rs.id', 'rs.item_id', 'i.code', 'rs.available_quantity')
            ->havingRaw('COALESCE(SUM(CASE WHEN l.id IS NOT NULL THEN GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0) ELSE 0 END), 0) < rs.available_quantity')
            ->orderBy('rs.id')
            ->limit($limit)
            ->get([
                'rs.id as real_stock_id',
                'rs.item_id',
                'i.code as item_code',
                'rs.available_quantity as real_available',
                DB::raw('COALESCE(SUM(CASE WHEN l.id IS NOT NULL THEN GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0) ELSE 0 END), 0) as pickable_available'),
                DB::raw('(rs.available_quantity - COALESCE(SUM(CASE WHEN l.id IS NOT NULL THEN GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0) ELSE 0 END), 0)) as missing_pickable_qty'),
            ]);
    }

    private function resolveLocation(int $warehouseId, int $itemId, AvailableQuantityFlag $quantityFlag): ?object
    {
        if ($this->option('location-id')) {
            return $this->pickableLocationQuery($warehouseId, $quantityFlag)
                ->where('l.id', (int) $this->option('location-id'))
                ->first();
        }

        $defaultLocation = $this->pickableLocationQuery($warehouseId, $quantityFlag)
            ->join('item_incoming_default_locations as idl', 'idl.location_id', '=', 'l.id')
            ->where('idl.warehouse_id', $warehouseId)
            ->where('idl.item_id', $itemId)
            ->orderBy('idl.id')
            ->first();

        if ($defaultLocation) {
            return $defaultLocation;
        }

        return $this->pickableLocationQuery($warehouseId, $quantityFlag)
            ->orderByRaw("CASE WHEN l.code1 IN ('Z', 'ZZ') THEN 1 ELSE 0 END")
            ->orderBy('l.id')
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
            ->where(function ($query) {
                $query->where('l.code1', '!=', 'Z')
                    ->orWhere('l.code2', '!=', '0')
                    ->orWhere('l.code3', '!=', '0')
                    ->orWhereNull('l.code1')
                    ->orWhereNull('l.code2')
                    ->orWhereNull('l.code3');
            })
            ->select('l.id', 'l.floor_id', 'l.code1', 'l.code2', 'l.code3', 'l.name', 'l.available_quantity_flags');
    }
}
