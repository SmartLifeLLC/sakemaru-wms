<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockTransferLotAllocationService
{
    public function __construct(private ?WaveAllocationBudget $budget = null) {}

    /**
     * Allocate WMS reservations from core stock transfer lot allocations.
     *
     * stock_transfer_lot_allocations.quantity is stored in pieces and is used
     * only as the lot source proof. WMS reservation quantities must follow the
     * original stock transfer line quantity.
     */
    public function allocateForTradeItem(
        int $waveId,
        int $warehouseId,
        int $stockTransferId,
        object $tradeItem
    ): array {
        return DB::connection('sakemaru')->transaction(
            fn () => $this->doAllocateForTradeItem($waveId, $warehouseId, $stockTransferId, $tradeItem)
        );
    }

    private function doAllocateForTradeItem(
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

        $item = $this->itemForCapacity($itemId);
        $unitSize = $this->unitSizeFor($quantityType, $tradeItem, $item);
        $expectedPieces = $this->expectedPieces($tradeItem, $needQty, $unitSize);

        $allocations = $this->stockTransferLotAllocations($stockTransferId, $tradeItemId);
        if ($allocations->isEmpty()) {
            Log::warning('Stock transfer lot allocations are missing; marking transfer line as shortage.', [
                'stock_transfer_id' => $stockTransferId,
                'trade_item_id' => $tradeItemId,
                'item_id' => $itemId,
                'need_qty' => $needQty,
                'qty_type' => $quantityType,
            ]);

            $this->insertShortageReservation(
                $waveId,
                $warehouseId,
                $itemId,
                $needQty,
                $quantityType,
                $stockTransferId,
                $tradeItemId
            );

            return $this->result(0, $needQty) + ['allocation_issue' => '店舗移動のロット引当情報を確認してください'];
        }

        $stlaPieces = 0;
        $primaryAllocation = null;

        foreach ($allocations as $allocation) {
            if (! $this->allocationMatchesTradeItem($allocation, $warehouseId, $itemId)) {
                Log::warning('Stock transfer lot allocation does not match transfer line; marking transfer line as shortage.', [
                    'stock_transfer_id' => $stockTransferId,
                    'trade_item_id' => $tradeItemId,
                    'allocation_id' => $allocation->allocation_id,
                    'from_real_stock_lot_id' => $allocation->from_real_stock_lot_id,
                    'expected_warehouse_id' => $warehouseId,
                    'expected_item_id' => $itemId,
                    'actual_warehouse_id' => $allocation->warehouse_id,
                    'actual_item_id' => $allocation->item_id,
                ]);

                $this->insertShortageReservation(
                    $waveId,
                    $warehouseId,
                    $itemId,
                    $needQty,
                    $quantityType,
                    $stockTransferId,
                    $tradeItemId
                );

                return $this->result(0, $needQty) + ['allocation_issue' => '店舗移動のロット引当情報を確認してください'];
            }

            $pieces = (int) $allocation->quantity;
            if ($pieces <= 0) {
                continue;
            }

            $stlaPieces += $pieces;

            $primaryAllocation ??= $allocation;
        }

        if ($stlaPieces !== $expectedPieces || $allocations->pluck('real_stock_id')->unique()->count() !== 1) {
            Log::warning('Stock transfer lot allocation quantity mismatch; marking transfer line as shortage.', [
                'stock_transfer_id' => $stockTransferId,
                'trade_item_id' => $tradeItemId,
                'item_id' => $itemId,
                'stla_pieces' => $stlaPieces,
                'expected_pieces' => $expectedPieces,
                'need_qty' => $needQty,
                'qty_type' => $quantityType,
            ]);

            $this->insertShortageReservation(
                $waveId,
                $warehouseId,
                $itemId,
                $needQty,
                $quantityType,
                $stockTransferId,
                $tradeItemId
            );

            return $this->result(0, $needQty) + ['allocation_issue' => '店舗移動のロット引当情報を確認してください'];
        }

        $budget = $this->budget ?? new WaveAllocationBudget([], [$stockTransferId]);
        $managed = (int) ($item->is_managed_stock ?? 1) !== 0;
        $piecesByStock = $allocations->groupBy('real_stock_id')
            ->map(fn ($rows) => max(0, (int) $rows->sum('quantity')));
        $availablePieces = $managed
            ? (int) $piecesByStock->map(fn ($pieces, $stockId) => min($pieces, $budget->remainingPieces((int) $stockId)))->sum()
            : $expectedPieces;
        $allocatedPieces = min($expectedPieces, $availablePieces);
        $remainingPieces = $allocatedPieces;
        $segments = [];
        foreach ($allocations as $allocation) {
            $take = min($remainingPieces, max(0, (int) $allocation->quantity));
            if ($take <= 0) {
                continue;
            }
            if ($managed) {
                $budget->consume((int) $allocation->real_stock_id, $take, (int) $allocation->from_real_stock_lot_id);
            }
            $key = $allocation->real_stock_id.':'.($allocation->location_id ?? 'null');
            $segments[$key] ??= ['source' => $allocation, 'pieces' => 0];
            $segments[$key]['pieces'] += $take;
            $remainingPieces -= $take;
        }
        // A reservation cannot hold a fractional CASE/CARTON at any shelf.
        if ($allocatedPieces % $unitSize !== 0 || ! collect($segments)->every(fn ($segment) => $segment['pieces'] % $unitSize === 0)) {
            $quantityType = 'PIECE';
            $needQty = $expectedPieces;
            $unitSize = 1;
        }
        $allocatedQty = intdiv($allocatedPieces, $unitSize);
        $shortageQty = max(0, $needQty - $allocatedQty);
        foreach ($segments as $segment) {
            $source = $segment['source'];
            $inserted = DB::connection('sakemaru')->table('wms_reservations')->insertOrIgnore([
                'warehouse_id' => $warehouseId, 'location_id' => $source->location_id,
                'real_stock_id' => $source->real_stock_id, 'item_id' => $itemId,
                'expiry_date' => $source->expiration_date, 'received_at' => null,
                'purchase_id' => $source->purchase_id, 'unit_cost' => $source->unit_cost,
                'qty_each' => intdiv($segment['pieces'], $unitSize), 'qty_type' => $quantityType,
                'shortage_qty' => 0, 'source_type' => 'STOCK_TRANSFER',
                'source_id' => $stockTransferId, 'source_line_id' => $tradeItemId,
                'wave_id' => $waveId, 'status' => 'RESERVED', 'created_by' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted !== 1) {
                throw new \RuntimeException('店舗移動の在庫予約を保存できませんでした');
            }
        }

        if ($shortageQty > 0) {
            $this->insertShortageReservation(
                $waveId,
                $warehouseId,
                $itemId,
                $shortageQty,
                $quantityType,
                $stockTransferId,
                $tradeItemId,
                $allocatedQty > 0 ? 'PARTIAL' : 'SHORTAGE'
            );
        }

        return $this->result($allocatedQty, $shortageQty) + [
            'qty_type' => $quantityType,
            'ordered_qty' => $needQty,
        ];
    }

    protected function insertShortageReservation(
        int $waveId,
        int $warehouseId,
        int $itemId,
        int $shortageQty,
        string $quantityType,
        int $stockTransferId,
        int $tradeItemId,
        string $status = 'SHORTAGE'
    ): void {
        if ($shortageQty <= 0) {
            return;
        }

        $inserted = DB::connection('sakemaru')->table('wms_reservations')->insertOrIgnore([
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
            'status' => $status,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted !== 1) {
            throw new \RuntimeException('店舗移動の欠品予約を保存できませんでした');
        }
    }

    protected function result(int $allocatedQty, int $shortageQty): array
    {
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
            ->get(['qty_each', 'shortage_qty', 'qty_type']);

        if ($existing->isEmpty()) {
            return null;
        }

        return [
            'allocated' => (int) $existing->sum('qty_each'),
            'shortage' => (int) $existing->sum('shortage_qty'),
            'elapsed_ms' => 0.0,
            'race_count' => 0,
            'lock_failed' => false,
            'qty_type' => $existing->first()->qty_type,
            'ordered_qty' => (int) $existing->sum('qty_each') + (int) $existing->sum('shortage_qty'),
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
            ->first(['capacity_case', 'capacity_carton', 'is_managed_stock']);
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

    protected function shortageQuantityFromPieces(int $shortagePieces, int $unitSize, int $needQty): int
    {
        if ($shortagePieces <= 0) {
            return 0;
        }

        if ($shortagePieces % $unitSize !== 0) {
            return $needQty;
        }

        return min($needQty, intdiv($shortagePieces, $unitSize));
    }

    protected function sourceLotRepresentsShortage(object $allocation, ?object $item = null): bool
    {
        if ((int) ($item->is_managed_stock ?? 1) === 0) {
            return false;
        }

        return (int) ($allocation->source_lot_current_quantity ?? 0) < 0;
    }

    protected function allocationMatchesTradeItem(
        object $allocation,
        int $warehouseId,
        int $itemId
    ): bool {
        if ($allocation->lot_id === null || $allocation->real_stock_id === null) {
            return false;
        }

        if ((int) $allocation->warehouse_id !== $warehouseId || (int) $allocation->item_id !== $itemId) {
            return false;
        }

        return true;
    }
}
