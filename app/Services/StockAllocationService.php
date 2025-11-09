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
     * @param int $waveId
     * @param int $warehouseId
     * @param int $itemId
     * @param int $needQty Required quantity (in PIECE)
     * @param string $quantityType Order quantity type (CASE|PIECE|CARTON)
     * @param int $sourceId Source record ID (earning_id or trade_item_id)
     * @param string $sourceType Source type (EARNING|TRADE_ITEM)
     * @return array ['allocated' => int, 'shortage' => int, 'elapsed_ms' => float, 'race_count' => int]
     */
    public function allocateForItem(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $needQty,
        string $quantityType,
        int $sourceId,
        string $sourceType = 'EARNING'
    ): array {
        $startTime = microtime(true);
        $lockKey = "alloc:{$warehouseId}:{$itemId}";

        // Acquire named lock
        if (!DbMutex::acquire($lockKey, self::LOCK_TIMEOUT, 'sakemaru')) {
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
                $startTime
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
        float $startTime
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
                $offset
            );

            if ($candidates->isEmpty()) {
                break;
            }

            // Try to allocate from candidates
            foreach ($candidates as $stock) {
                if ($totalAllocated >= $needQty) {
                    break;
                }

                $available = $stock->available_quantity - $stock->reserved_quantity - $stock->picking_quantity;
                if ($available <= 0) {
                    continue;
                }

                $takeQty = min($needQty - $totalAllocated, $available);

                // Optimistic lock: Update real_stocks
                $updated = DB::connection('sakemaru')
                    ->table('real_stocks')
                    ->where('id', $stock->real_stock_id)
                    ->where('available_quantity', '>=', $takeQty)
                    ->update([
                        'available_quantity' => DB::raw('available_quantity - ' . $takeQty),
                        'updated_at' => now(),
                    ]);

                if ($updated === 0) {
                    // Race condition - stock already taken
                    $raceCount++;
                    continue;
                }

                // Update wms_real_stocks with lock_version check
                $wmsUpdated = DB::connection('sakemaru')
                    ->table('wms_real_stocks')
                    ->where('real_stock_id', $stock->real_stock_id)
                    ->where('lock_version', $stock->lock_version)
                    ->update([
                        'reserved_quantity' => DB::raw('reserved_quantity + ' . $takeQty),
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => now(),
                    ]);

                if ($wmsUpdated === 0) {
                    // Lock version mismatch - rollback real_stocks update
                    DB::connection('sakemaru')
                        ->table('real_stocks')
                        ->where('id', $stock->real_stock_id)
                        ->update([
                            'available_quantity' => DB::raw('available_quantity + ' . $takeQty),
                            'updated_at' => now(),
                        ]);

                    $raceCount++;
                    continue;
                }

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
        if (!empty($reservations)) {
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
     *
     * @param int $warehouseId
     * @param int $itemId
     * @param bool $usesExpiration
     * @param AvailableQuantityFlag $quantityFlag
     * @param int $limit
     * @param int $offset
     * @return \Illuminate\Support\Collection
     */
    protected function getCandidateStocks(
        int $warehouseId,
        int $itemId,
        bool $usesExpiration,
        AvailableQuantityFlag $quantityFlag,
        int $limit,
        int $offset
    ): \Illuminate\Support\Collection {
        $query = DB::connection('sakemaru')
            ->table('real_stocks as rs')
            ->join('wms_real_stocks as wrs', 'wrs.real_stock_id', '=', 'rs.id')
            ->leftJoin('wms_locations as wl', 'wl.location_id', '=', 'rs.location_id')
            ->leftJoin('locations as l', 'l.id', '=', 'rs.location_id')
            ->where('rs.warehouse_id', $warehouseId)
            ->where('rs.item_id', $itemId)
            ->where('rs.available_quantity', '>', 0)
            ->whereRaw("(l.available_quantity_flags & {$quantityFlag->value}) != 0")
            ->select([
                'rs.id as real_stock_id',
                'rs.location_id',
                'rs.purchase_id',
                'rs.expiration_date',
                'rs.price as unit_cost',
                'rs.available_quantity',
                'wrs.reserved_quantity',
                'wrs.picking_quantity',
                'wrs.lock_version',
                // DB::raw('COALESCE(wl.walking_order, 999999) as walking_order'), // Removed: walking_order is no longer used
            ]);

        // Order by FEFO or FIFO
        if ($usesExpiration) {
            $query->orderByRaw('rs.expiration_date IS NULL') // NULL last
                ->orderBy('rs.expiration_date', 'asc');
        } else {
            // FIFO: Order by creation date since received_at doesn't exist
            $query->orderBy('rs.created_at', 'asc');
        }

        // Removed: walking_order sorting is no longer used. Sorting by location will be calculated based on x_pos, y_pos
        $query->orderBy('rs.id', 'asc')
            ->limit($limit)
            ->offset($offset);

        return $query->get();
    }
}
