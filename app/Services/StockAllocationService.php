<?php

namespace App\Services;

use App\Enums\AvailableQuantityFlag;
use App\Support\DbMutex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optimized stock allocation service using GET_LOCK and bitmask filtering
 *
 * Implements specification: 02_picking_3.md
 * - FEFO (First Expiry, First Out) for expiration-managed items
 * - FIFO (First In, First Out) for non-expiration items
 * - Bitmask filtering for location quantity types
 * - Optimistic locking with lock_version
 * - Batch processing (50 rows/batch, max 2 pages)
 */
class StockAllocationService
{
    protected const BATCH_SIZE = 50;

    protected const MAX_PAGES = 2;

    protected const LOCK_TIMEOUT = 1; // seconds

    /**
     * Allocate stock for a specific item in a wave
     *
     * @param  int  $needQty  Required quantity (in PIECE)
     * @param  string  $quantityType  Order quantity type (CASE|PIECE|CARTON)
     * @param  int  $sourceId  Source record ID (earning_id or trade_item_id)
     * @param  string  $sourceType  Source type (EARNING|TRADE_ITEM)
     * @param  int|null  $buyerId  Buyer ID for lot restriction check (null = no restriction)
     * @return array ['allocated' => int, 'shortage' => int, 'elapsed_ms' => float, 'race_count' => int]
     */
    public function allocateForItem(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $needQty,
        string $quantityType,
        int $sourceId,
        string $sourceType = 'EARNING',
        ?int $buyerId = null
    ): array {
        $startTime = microtime(true);
        $lockKey = "alloc:{$warehouseId}:{$itemId}";

        // Acquire named lock
        if (! DbMutex::acquire($lockKey, self::LOCK_TIMEOUT, 'sakemaru')) {
            Log::warning('Stock allocation lock timeout', [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'wave_id' => $waveId,
            ]);

            return [
                'allocated' => 0,
                'shortage' => $needQty,
                'elapsed_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'race_count' => 0,
                'lock_failed' => true,
            ];
        }

        try {
            return $this->doAllocate(
                $waveId,
                $warehouseId,
                $itemId,
                $needQty,
                $quantityType,
                $sourceId,
                $sourceType,
                $startTime,
                $buyerId
            );
        } finally {
            DbMutex::release($lockKey, 'sakemaru');
        }
    }

    /**
     * Main allocation logic (protected by GET_LOCK)
     */
    protected function doAllocate(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $needQty,
        string $quantityType,
        int $sourceId,
        string $sourceType,
        float $startTime,
        ?int $buyerId = null
    ): array {
        $totalAllocated = 0;
        $raceCount = 0;
        $reservations = [];

        // Get item info for expiration management
        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('id', $itemId)
            ->first(['uses_expiration_date']);

        $usesExpiration = $item->uses_expiration_date ?? false;

        // Get quantity flag for bitmask filtering
        $quantityFlag = match (strtoupper($quantityType)) {
            'CASE' => AvailableQuantityFlag::CASE,
            'PIECE' => AvailableQuantityFlag::PIECE,
            'CARTON' => AvailableQuantityFlag::CARTON,
            default => AvailableQuantityFlag::PIECE,
        };

        // Process in batches (max 2 pages)
        for ($page = 0; $page < self::MAX_PAGES && $totalAllocated < $needQty; $page++) {
            $offset = $page * self::BATCH_SIZE;

            // Get candidate stocks
            $candidates = $this->getCandidateStocks(
                $warehouseId,
                $itemId,
                $usesExpiration,
                $quantityFlag,
                self::BATCH_SIZE,
                $offset,
                $buyerId
            );

            if ($candidates->isEmpty()) {
                break;
            }

            // Try to allocate from candidates
            foreach ($candidates as $stock) {
                if ($totalAllocated >= $needQty) {
                    break;
                }

                // available_quantity は生成カラム (= current_quantity - reserved_quantity)
                // Sakemaru側で売上時に reserved_quantity が増加済み
                $available = $stock->available_quantity;
                if ($available <= 0) {
                    continue;
                }

                $takeQty = min($needQty - $totalAllocated, $available);

                // Note: real_stocks の数量更新は行わない（Sakemaru側で管理）
                // wms_reservations の作成のみ行う

                // Success - record reservation
                $reservations[] = [
                    'warehouse_id' => $warehouseId,
                    'location_id' => $stock->location_id,
                    'real_stock_id' => $stock->real_stock_id,
                    'item_id' => $itemId,
                    'expiry_date' => $stock->expiration_date,
                    'received_at' => null,
                    'purchase_id' => $stock->purchase_id,
                    'unit_cost' => $stock->unit_cost,
                    'qty_each' => $takeQty,
                    'qty_type' => $quantityType,
                    'shortage_qty' => 0,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'source_line_id' => $sourceId,
                    'wave_id' => $waveId,
                    'status' => 'RESERVED',
                    'created_by' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $totalAllocated += $takeQty;
            }
        }

        // Insert reservations in batch (if any)
        if (! empty($reservations)) {
            DB::connection('sakemaru')
                ->table('wms_reservations')
                ->insert($reservations);
        }

        // Handle shortage
        $shortageQty = $needQty - $totalAllocated;
        if ($shortageQty > 0) {
            DB::connection('sakemaru')->table('wms_reservations')->insert([
                'warehouse_id' => $warehouseId,
                'location_id' => null,
                'real_stock_id' => null,
                'item_id' => $itemId,
                'expiry_date' => null,
                'received_at' => null,
                'purchase_id' => null,
                'unit_cost' => null,
                'qty_each' => 0,
                'qty_type' => $quantityType,
                'shortage_qty' => $shortageQty,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_id' => $sourceId,
                'wave_id' => $waveId,
                'status' => $totalAllocated > 0 ? 'PARTIAL' : 'SHORTAGE',
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('Stock allocation completed', [
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'wave_id' => $waveId,
            'need_qty' => $needQty,
            'allocated' => $totalAllocated,
            'shortage' => $shortageQty,
            'elapsed_ms' => $elapsed,
            'race_count' => $raceCount,
        ]);

        return [
            'allocated' => $totalAllocated,
            'shortage' => $shortageQty,
            'elapsed_ms' => $elapsed,
            'race_count' => $raceCount,
            'lock_failed' => false,
        ];
    }

    /**
     * Get candidate stocks for allocation
     * real_stock_lots経由でlocation, expiration_date, price等を取得
     *
     * @param  int|null  $buyerId  Buyer ID for lot restriction check (null = no restriction)
     */
    protected function getCandidateStocks(
        int $warehouseId,
        int $itemId,
        bool $usesExpiration,
        AvailableQuantityFlag $quantityFlag,
        int $limit,
        int $offset,
        ?int $buyerId = null
    ): \Illuminate\Support\Collection {
        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('real_stock_lots as rsl', function ($join) {
                $join->on('rsl.real_stock_id', '=', 'rs.id')
                    ->where('rsl.status', '=', 'ACTIVE')
                    ->whereRaw('rsl.current_quantity > rsl.reserved_quantity');
            })
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0");

        // Apply buyer restriction filter if buyerId is provided
        if ($buyerId !== null) {
            $query->where(function ($q) use ($buyerId) {
                // ロットに制限がない場合は全得意先に販売可能
                $q->whereNotExists(function ($subQuery) {
                    $subQuery->select(DB::raw(1))
                        ->from('real_stock_lot_buyer_restrictions')
                        ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id');
                })
                // または制限があり、対象得意先が許可されている場合
                    ->orWhereExists(function ($subQuery) use ($buyerId) {
                        $subQuery->select(DB::raw(1))
                            ->from('real_stock_lot_buyer_restrictions')
                            ->whereColumn('real_stock_lot_buyer_restrictions.real_stock_lot_id', 'rsl.id')
                            ->where('real_stock_lot_buyer_restrictions.buyer_id', $buyerId);
                    });
            });
        }

        $query->select([
            'rs.id as real_stock_id',
            'rsl.id as lot_id',
            'rsl.location_id',
            'rsl.purchase_id',
            'rsl.expiration_date',
            'rsl.price as unit_cost',
            DB::raw('(rsl.current_quantity - rsl.reserved_quantity) as available_quantity'),
        ]);

        // Order by FEFO or FIFO
        if ($usesExpiration) {
            $query->orderByRaw('rsl.expiration_date IS NULL') // NULL last
                ->orderBy('rsl.expiration_date', 'asc');
        } else {
            // FIFO: Order by creation date
            $query->orderBy('rsl.created_at', 'asc');
        }

        $query->orderBy('rsl.id', 'asc')
            ->limit($limit)
            ->offset($offset);

        return $query->get();
    }
}
