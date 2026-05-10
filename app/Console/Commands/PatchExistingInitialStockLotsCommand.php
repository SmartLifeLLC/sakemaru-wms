<?php

namespace App\Console\Commands;

use App\Enums\AvailableQuantityFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PatchExistingInitialStockLotsCommand extends Command
{
    protected $signature = 'stock:patch-existing-initial-lots
                            {--warehouse-id= : Target warehouse ID}
                            {--item-code= : Target item code}
                            {--item-id= : Target item ID}
                            {--quantity-type=PIECE : CASE|PIECE|CARTON}
                            {--limit=100 : Maximum target rows}
                            {--output= : CSV output path. Defaults to storage/reports/current timestamp}
                            {--include-empty-containers : Include items whose name contains 空容器}
                            {--include-negative-reserved : Include real_stocks with negative reserved_quantity}
                            {--apply : Update existing NULL/NULL active lots}';

    protected $description = 'Patch parent/lot available gaps by adding quantity to existing initial real_stock_lots.';

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
            $this->warn('DRY RUN: no lots will be updated. Add --apply to patch data.');
        }

        $targets = $this->findTargets($warehouseId, $quantityFlag, $itemId, $limit);
        if ($targets->isEmpty()) {
            $this->info('No patch targets found.');

            return self::SUCCESS;
        }

        $rows = [];
        $updated = 0;
        $skipped = 0;
        $totalPatchQty = 0;

        foreach ($targets as $target) {
            $lot = $this->findPatchLot((int) $target->real_stock_id, $quantityFlag);
            $patchQty = (int) $target->missing_current_qty;

            $status = 'dry';
            $message = '';

            if (! $lot) {
                $status = 'skipped';
                $message = 'no active NULL/NULL pickable lot';
                $skipped++;
            } elseif ($patchQty <= 0) {
                $status = 'skipped';
                $message = 'no missing quantity';
                $skipped++;
            } elseif ($apply) {
                $appliedQty = $this->applyPatch((int) $target->real_stock_id, (int) $lot->id, $quantityFlag);
                if ($appliedQty > 0) {
                    $status = 'updated';
                    $patchQty = $appliedQty;
                    $updated++;
                    $totalPatchQty += $appliedQty;
                } else {
                    $status = 'skipped';
                    $message = 'no missing quantity after recheck';
                    $patchQty = 0;
                    $skipped++;
                }
            } else {
                $totalPatchQty += $patchQty;
            }

            $this->line(sprintf(
                '%s item=%s real_stock_id=%d lot=%s qty=%d parent_available=%d pickable=%d %s',
                strtoupper($status),
                $target->item_code,
                $target->real_stock_id,
                $lot?->id ?? '-',
                $patchQty,
                $target->parent_available,
                $target->pickable_available,
                $message
            ));

            $rows[] = [
                'status' => $status,
                'message' => $message,
                'real_stock_id' => $target->real_stock_id,
                'item_id' => $target->item_id,
                'item_code' => $target->item_code,
                'item_name' => $target->item_name,
                'current_quantity' => $target->current_quantity,
                'reserved_quantity' => $target->reserved_quantity,
                'parent_available' => $target->parent_available,
                'pickable_available' => $target->pickable_available,
                'missing_pickable_qty' => $target->missing_pickable_qty,
                'patch_lot_id' => $lot?->id,
                'patch_lot_location_id' => $lot?->location_id,
                'pickable_current' => $target->pickable_current,
                'missing_current_qty' => $target->missing_current_qty,
                'patch_lot_current_before' => $lot?->current_quantity,
                'patch_lot_reserved_before' => $lot?->reserved_quantity,
                'patch_qty' => $patchQty,
            ];
        }

        $outputPath = $this->writeCsv($rows);

        $this->info(($apply ? 'Patch complete' : 'Dry run complete')
            .": updated={$updated}, skipped={$skipped}, total_patch_qty={$totalPatchQty}");
        $this->info("CSV: {$outputPath}");

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
            ->whereRaw('rs.current_quantity - rs.reserved_quantity > 0');

        if (! $this->option('include-empty-containers')) {
            $query->where('i.name', 'not like', '%空容器%');
        }

        if (! $this->option('include-negative-reserved')) {
            $query->where('rs.reserved_quantity', '>=', 0);
        }

        if ($itemId !== null) {
            $query->where('rs.item_id', $itemId);
        }

        $pickableCurrentSql = 'COALESCE(SUM(CASE WHEN l.id IS NOT NULL THEN rsl.current_quantity ELSE 0 END), 0)';
        $pickableAvailableSql = 'COALESCE(SUM(CASE WHEN l.id IS NOT NULL THEN GREATEST(rsl.current_quantity - rsl.reserved_quantity, 0) ELSE 0 END), 0)';
        $parentAvailableSql = 'rs.current_quantity - rs.reserved_quantity';

        return $query
            ->groupBy('rs.id', 'rs.item_id', 'i.code', 'i.name', 'rs.current_quantity', 'rs.reserved_quantity')
            ->havingRaw("{$pickableCurrentSql} < rs.current_quantity")
            ->orderByRaw("(rs.current_quantity - {$pickableCurrentSql}) DESC")
            ->limit($limit)
            ->get([
                'rs.id as real_stock_id',
                'rs.item_id',
                'i.code as item_code',
                'i.name as item_name',
                'rs.current_quantity',
                'rs.reserved_quantity',
                DB::raw("{$parentAvailableSql} as parent_available"),
                DB::raw("{$pickableCurrentSql} as pickable_current"),
                DB::raw("{$pickableAvailableSql} as pickable_available"),
                DB::raw("(rs.current_quantity - {$pickableCurrentSql}) as missing_current_qty"),
                DB::raw("({$parentAvailableSql} - {$pickableAvailableSql}) as missing_pickable_qty"),
            ]);
    }

    private function findPatchLot(int $realStockId, AvailableQuantityFlag $quantityFlag): ?object
    {
        return DB::connection('sakemaru')
            ->table('real_stock_lots as rsl')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rsl.real_stock_id', $realStockId)
            ->whereNull('rsl.purchase_id')
            ->whereNull('rsl.trade_item_id')
            ->where('rsl.status', 'ACTIVE')
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
            ->orderByDesc('rsl.current_quantity')
            ->orderBy('rsl.id')
            ->first([
                'rsl.id',
                'rsl.location_id',
                'rsl.current_quantity',
                'rsl.reserved_quantity',
            ]);
    }

    private function applyPatch(int $realStockId, int $lotId, AvailableQuantityFlag $quantityFlag): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($realStockId, $lotId, $quantityFlag): int {
            $stock = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('id', $realStockId)
                ->lockForUpdate()
                ->first(['id', 'current_quantity', 'reserved_quantity']);

            if (! $stock) {
                return 0;
            }

            $lot = DB::connection('sakemaru')
                ->table('real_stock_lots as rsl')
                ->join('locations as l', 'l.id', '=', 'rsl.location_id')
                ->where('rsl.id', $lotId)
                ->where('rsl.real_stock_id', $realStockId)
                ->whereNull('rsl.purchase_id')
                ->whereNull('rsl.trade_item_id')
                ->where('rsl.status', 'ACTIVE')
                ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
                ->lockForUpdate()
                ->first(['rsl.id']);

            if (! $lot) {
                return 0;
            }

            $pickableCurrent = DB::connection('sakemaru')
                ->table('real_stock_lots as rsl')
                ->join('locations as l', 'l.id', '=', 'rsl.location_id')
                ->where('rsl.real_stock_id', $realStockId)
                ->where('rsl.status', 'ACTIVE')
                ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
                ->selectRaw('COALESCE(SUM(rsl.current_quantity), 0) as qty')
                ->lockForUpdate()
                ->value('qty');

            $missingQty = (int) $stock->current_quantity - (int) $pickableCurrent;
            if ($missingQty <= 0) {
                return 0;
            }

            DB::connection('sakemaru')
                ->table('real_stock_lots')
                ->where('id', $lotId)
                ->update([
                    'initial_quantity' => DB::raw("initial_quantity + {$missingQty}"),
                    'current_quantity' => DB::raw("current_quantity + {$missingQty}"),
                    'updated_at' => now(),
                ]);

            return $missingQty;
        });
    }

    private function writeCsv(array $rows): string
    {
        $path = $this->option('output');
        if (! $path) {
            $path = storage_path('reports/'.now()->format('Ymd-His').'-existing-initial-lot-patch.csv');
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
