<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockTransferLotAllocationService
{
    /**
     * Allocate WMS reservations from core stock transfer lot allocations.
     *
     * stock_transfer_lot_allocations.quantity is stored in pieces. WMS
     * reservations keep the trade item quantity type, so pieces must be
     * converted safely before writing qty_each.
     */
    public function allocateForTradeItem(
        int $waveId,
        int $warehouseId,
        int $stockTransferId,
        object $tradeItem
    ): array {
        $itemId = (int) $tradeItem->item_id;
        $needQty = (int) $tradeItem->quantity;
        $quantityType = (string) ($tradeItem->quantity_type ?? 'PIECE');
        $tradeItemId = (int) $tradeItem->id;

        $existing = $this->existingReservationResult($waveId, $warehouseId, $itemId, $stockTransferId, $tradeItemId);
        if ($existing !== null) {
            return $existing;
        }

        $allocations = $this->stockTransferLotAllocations($stockTransferId, $tradeItemId);
        if ($allocations->isEmpty()) {
            return (new StockAllocationService)->allocateForItem(
                $waveId,
                $warehouseId,
                $itemId,
                $needQty,
                $quantityType,
                $stockTransferId,
                $tradeItemId,
                'STOCK_TRANSFER',
                null
            );
        }

        $item = $this->itemForCapacity($itemId);
        $unitSize = $this->unitSizeFor($quantityType, $tradeItem, $item);
        $expectedPieces = $this->expectedPieces($tradeItem, $needQty, $unitSize);

        $reservationPieces = [];
        $allocatedPieces = 0;
        $stlaPieces = 0;

        foreach ($allocations as $allocation) {
            $this->assertAllocationMatchesTradeItem($allocation, $warehouseId, $itemId, $stockTransferId, $tradeItemId);

            $pieces = (int) $allocation->quantity;
            if ($pieces <= 0) {
                continue;
            }

            $stlaPieces += $pieces;

            if ($this->sourceLotRepresentsShortage($allocation)) {
                continue;
            }

            $key = implode(':', [
                $allocation->real_stock_id,
                $allocation->location_id ?? 'null',
                $allocation->purchase_id ?? 'null',
                $allocation->expiration_date ?? 'null',
            ]);

            if (! isset($reservationPieces[$key])) {
                $reservationPieces[$key] = [
                    'allocation' => $allocation,
                    'pieces' => 0,
                ];
            }

            $reservationPieces[$key]['pieces'] += $pieces;
            $allocatedPieces += $pieces;
        }

        if ($stlaPieces > $expectedPieces) {
            throw new RuntimeException(
                "stock_transfer_lot_allocations quantity exceeds trade_item total pieces. stock_transfer_id={$stockTransferId}, trade_item_id={$tradeItemId}, stla_pieces={$stlaPieces}, expected_pieces={$expectedPieces}"
            );
        }

        $reservations = [];
        $allocatedQty = 0;

        foreach ($reservationPieces as $reservationPiece) {
            $allocation = $reservationPiece['allocation'];
            $pieces = $reservationPiece['pieces'];
            $qtyEach = $this->reservationQuantityFromPieces($pieces, $unitSize, $quantityType, $stockTransferId, $tradeItemId);

            $reservations[] = [
                'warehouse_id' => $warehouseId,
                'location_id' => $allocation->location_id,
                'real_stock_id' => $allocation->real_stock_id,
                'item_id' => $itemId,
                'expiry_date' => $allocation->expiration_date,
                'received_at' => null,
                'purchase_id' => $allocation->purchase_id,
                'unit_cost' => $allocation->unit_cost,
                'qty_each' => $qtyEach,
                'qty_type' => $quantityType,
                'shortage_qty' => 0,
                'source_type' => 'STOCK_TRANSFER',
                'source_id' => $stockTransferId,
                'source_line_id' => $tradeItemId,
                'wave_id' => $waveId,
                'status' => 'RESERVED',
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $allocatedQty += $qtyEach;
        }

        if (! empty($reservations)) {
            DB::connection('sakemaru')
                ->table('wms_reservations')
                ->insertOrIgnore($reservations);
        }

        $shortageQty = max(0, $needQty - $allocatedQty);
        if ($shortageQty > 0) {
            DB::connection('sakemaru')->table('wms_reservations')->insertOrIgnore([
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
                'source_type' => 'STOCK_TRANSFER',
                'source_id' => $stockTransferId,
                'source_line_id' => $tradeItemId,
                'wave_id' => $waveId,
                'status' => $allocatedQty > 0 ? 'PARTIAL' : 'SHORTAGE',
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'allocated' => $allocatedQty,
            'shortage' => $shortageQty,
            'elapsed_ms' => 0.0,
            'race_count' => 0,
            'lock_failed' => false,
        ];
    }

    protected function existingReservationResult(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $stockTransferId,
        int $tradeItemId
    ): ?array {
        $existing = DB::connection('sakemaru')
            ->table('wms_reservations')
            ->where('wave_id', $waveId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('source_type', 'STOCK_TRANSFER')
            ->where('source_id', $stockTransferId)
            ->where('source_line_id', $tradeItemId)
            ->whereIn('status', ['RESERVED', 'PARTIAL', 'SHORTAGE'])
            ->get(['qty_each', 'shortage_qty']);

        if ($existing->isEmpty()) {
            return null;
        }

        return [
            'allocated' => (int) $existing->sum('qty_each'),
            'shortage' => (int) $existing->sum('shortage_qty'),
            'elapsed_ms' => 0.0,
            'race_count' => 0,
            'lock_failed' => false,
        ];
    }

    protected function stockTransferLotAllocations(int $stockTransferId, int $tradeItemId): Collection
    {
        return DB::connection('sakemaru')
            ->table('stock_transfer_lot_allocations as stla')
            ->leftJoin('real_stock_lots as rsl', 'rsl.id', '=', 'stla.from_real_stock_lot_id')
            ->leftJoin('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->leftJoin('locations as l', 'l.id', '=', 'rsl.location_id')
            ->where('stla.stock_transfer_id', $stockTransferId)
            ->where('stla.trade_item_id', $tradeItemId)
            ->select([
                'stla.id as allocation_id',
                'stla.quantity',
                'stla.from_real_stock_lot_id',
                'rsl.id as lot_id',
                'rsl.real_stock_id',
                'rsl.location_id',
                'rsl.purchase_id',
                'rsl.expiration_date',
                'rsl.current_quantity as source_lot_current_quantity',
                'rsl.price as unit_cost',
                'rs.warehouse_id',
                'rs.item_id',
                'l.is_disabled as location_is_disabled',
            ])
            ->orderBy('stla.id')
            ->get();
    }

    protected function itemForCapacity(int $itemId): ?object
    {
        return DB::connection('sakemaru')
            ->table('items')
            ->where('id', $itemId)
            ->first(['capacity_case', 'capacity_carton']);
    }

    protected function unitSizeFor(string $quantityType, object $tradeItem, ?object $item): int
    {
        return match (strtoupper($quantityType)) {
            'CASE' => max(1, (int) ($tradeItem->capacity_case ?? $item->capacity_case ?? 1)),
            'CARTON' => max(1, (int) ($tradeItem->capacity_carton ?? $item->capacity_carton ?? $tradeItem->capacity_case ?? $item->capacity_case ?? 1)),
            default => 1,
        };
    }

    protected function expectedPieces(object $tradeItem, int $needQty, int $unitSize): int
    {
        $totalPieceQuantity = (int) ($tradeItem->total_piece_quantity ?? 0);

        return $totalPieceQuantity !== 0
            ? abs($totalPieceQuantity)
            : $needQty * $unitSize;
    }

    protected function reservationQuantityFromPieces(
        int $pieces,
        int $unitSize,
        string $quantityType,
        int $stockTransferId,
        int $tradeItemId
    ): int {
        if ($pieces % $unitSize !== 0) {
            throw new RuntimeException(
                "stock_transfer_lot_allocations quantity cannot be converted to {$quantityType}. stock_transfer_id={$stockTransferId}, trade_item_id={$tradeItemId}, pieces={$pieces}, unit_size={$unitSize}"
            );
        }

        return intdiv($pieces, $unitSize);
    }

    protected function sourceLotRepresentsShortage(object $allocation): bool
    {
        return (int) ($allocation->source_lot_current_quantity ?? 0) < 0;
    }

    protected function assertAllocationMatchesTradeItem(
        object $allocation,
        int $warehouseId,
        int $itemId,
        int $stockTransferId,
        int $tradeItemId
    ): void {
        if ($allocation->lot_id === null || $allocation->real_stock_id === null) {
            throw new RuntimeException(
                "stock_transfer_lot_allocations references missing source lot. stock_transfer_id={$stockTransferId}, trade_item_id={$tradeItemId}, allocation_id={$allocation->allocation_id}, lot_id={$allocation->from_real_stock_lot_id}"
            );
        }

        if ((int) $allocation->warehouse_id !== $warehouseId || (int) $allocation->item_id !== $itemId) {
            throw new RuntimeException(
                "stock_transfer_lot_allocations source lot does not match the stock transfer line. stock_transfer_id={$stockTransferId}, trade_item_id={$tradeItemId}, allocation_id={$allocation->allocation_id}"
            );
        }
    }
}
