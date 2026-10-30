<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Piece budgets shared by every course in one generation transaction.
 * Core earnings reserve stock; transfers have already deducted it.
 * Only the selected sources are added back before WMS consumes the budget.
 */
class WaveAllocationBudget
{
    private array $remainingByStock = [];

    private bool $scopePrepared = false;

    private array $consumedByLot = [];

    private array $consumedByStock = [];

    private array $recalculations = [];

    private array $warnings = [];

    private array $transferPiecesByLot = [];

    private array $transferLotItems = [];

    public function __construct(
        private array $earningIds = [],
        private array $stockTransferIds = [],
        private array $sourceSnapshots = [],
    ) {}

    public function earningIds(): array
    {
        return $this->earningIds;
    }

    public function transferPiecesByLot(int $itemId): array
    {
        return array_filter($this->transferPiecesByLot, fn ($lotId) => ($this->transferLotItems[$lotId] ?? null) === $itemId, ARRAY_FILTER_USE_KEY);
    }

    public function stockTransferIds(): array
    {
        return $this->stockTransferIds;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function checkpoint(): array
    {
        return [$this->remainingByStock, $this->consumedByLot, $this->transferPiecesByLot, $this->transferLotItems, $this->consumedByStock];
    }

    public function restore(array $checkpoint): void
    {
        [$this->remainingByStock, $this->consumedByLot, $this->transferPiecesByLot, $this->transferLotItems, $this->consumedByStock] = $checkpoint;
    }

    public function recalculations(): array
    {
        return array_values($this->recalculations);
    }

    /**
     * Reload only this warehouse/item, at most once in this generation.
     * Successful earlier allocations remain consumed; failed attempts roll back.
     * The retry marker deliberately survives restoring a budget checkpoint.
     */
    public function recalculateItem(int $warehouseId, int $itemId): bool
    {
        if (! $this->scopePrepared || DB::connection('sakemaru')->transactionLevel() === 0) {
            throw new RuntimeException('再計算は生成対象を準備したトランザクション内の共有在庫予算で実行してください');
        }
        $key = $warehouseId.':'.$itemId;
        if (isset($this->recalculations[$key])) {
            return false;
        }
        $this->recalculations[$key] = ['warehouse_id' => $warehouseId, 'item_id' => $itemId, 'completed' => false];
        $checkpoint = $this->checkpoint();
        try {
            DB::connection('sakemaru')->transaction(function () use ($warehouseId, $itemId) {
                $stocks = DB::connection('sakemaru')->table('real_stocks')
                    ->where('warehouse_id', $warehouseId)->where('item_id', $itemId)
                    ->orderBy('id')->lockForUpdate()->get(['id']);
                foreach ($this->transferLotItems as $lotId => $lotItemId) {
                    if ($lotItemId === $itemId) {
                        unset($this->transferLotItems[$lotId], $this->transferPiecesByLot[$lotId]);
                    }
                }
                foreach ($stocks as $stock) {
                    $id = (int) $stock->id;
                    $freshPieces = $this->loadRemainingPieces($id);
                    // The prepared scope started without WMS claims. Keep all
                    // earlier successful allocations consumed after the reload.
                    $this->remainingByStock[$id] = max(0, $freshPieces - ($this->consumedByStock[$id] ?? 0));
                }
            });
            $this->recalculations[$key]['completed'] = true;

            return true;
        } catch (\Throwable $e) {
            $this->restore($checkpoint);
            throw $e;
        }
    }

    public function prepare(int $warehouseId, bool $skipChangedSources = false): void
    {
        $db = DB::connection('sakemaru');
        if ($db->transactionLevel() === 0) {
            throw new RuntimeException('波動の引当予算はトランザクション内で準備してください');
        }
        $itemIdsByTrade = [];
        foreach ([
            ['earnings', $this->earningIds],
            ['stock_transfers', $this->stockTransferIds],
        ] as [$table, $ids]) {
            $sources = $db->table($table)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $duplicateIds = $db->table('wms_reservations')
                ->where('source_type', $table === 'earnings' ? 'EARNING' : 'STOCK_TRANSFER')
                ->whereIn('source_id', $ids)->whereIn('status', ['RESERVED', 'PARTIAL', 'SHORTAGE'])
                ->pluck('source_id')->flip();
            $trades = $db->table('trades')->whereIn('id', $sources->pluck('trade_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $linesByTrade = $db->table('trade_items')->whereIn('trade_id', $sources->pluck('trade_id'))
                ->orderBy('id')->lockForUpdate()->get(['trade_id', 'item_id', 'is_active'])->where('is_active', true)->groupBy('trade_id');
            $validIds = [];
            foreach (array_unique($ids) as $id) {
                $source = $sources->firstWhere('id', $id);
                $changed = ! $source || ! $source->is_active || $source->is_delivered || $source->picking_status !== 'BEFORE' || ($table === 'stock_transfers' && $source->is_confirmed);
                $type = $table === 'earnings' ? 'EARNING' : 'STOCK_TRANSFER';
                foreach ($this->sourceSnapshots[$type.':'.$id] ?? [] as $field => $value) {
                    $changed = $changed || ! $source || (string) $source->{$field} !== (string) $value;
                }
                $duplicate = $duplicateIds->has($id);
                if (! $changed && ! $duplicate) {
                    $trade = $trades->get($source->trade_id);
                    $changed = ! $trade || ! $trade->is_active || ! $linesByTrade->has($source->trade_id);
                }
                if ($changed || $duplicate) {
                    $message = $duplicate ? '生成対象の伝票には既に在庫予約があります' : '生成対象の伝票が変更されたため、波動生成をやり直してください';
                    if (! $skipChangedSources) {
                        throw new RuntimeException($message);
                    }
                    $this->warnings[] = ['source_type' => $table === 'earnings' ? 'EARNING' : 'STOCK_TRANSFER', 'source_id' => (int) $id, 'message' => $message.'（この伝票は今回の生成対象から除外）'];

                    continue;
                }
                $validIds[] = $id;
                $itemIdsByTrade[$source->trade_id] = $linesByTrade->get($source->trade_id)->pluck('item_id')->all();
            }
            if ($table === 'earnings') {
                $this->earningIds = $validIds;
            } else {
                $this->stockTransferIds = $validIds;
            }
        }

        $itemIds = array_merge([], ...array_values($itemIdsByTrade));
        // Validated sources have no existing WMS claims. Avoid scanning historical
        // reservations again for every item during initial budget preparation.
        $this->scopePrepared = true;
        $stocks = $db->table('real_stocks')->where('warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)->orderBy('id')->lockForUpdate()->get(['id']);
        foreach ($stocks as $stock) {
            $this->remainingPieces((int) $stock->id);
        }
    }

    public function remainingPieces(int $realStockId): int
    {
        return $this->remainingByStock[$realStockId] ??= $this->loadRemainingPieces($realStockId);
    }

    public function lotAvailablePieces(int $lotId, int $availablePieces): int
    {
        return max(0, $availablePieces - ($this->consumedByLot[$lotId] ?? 0));
    }

    public function consume(int $realStockId, int $pieces, ?int $lotId = null): void
    {
        if ($pieces < 0 || $pieces > $this->remainingPieces($realStockId)) {
            throw new RuntimeException('波動の引当数量が確保可能在庫を超えています');
        }

        $this->remainingByStock[$realStockId] -= $pieces;
        $this->consumedByStock[$realStockId] = ($this->consumedByStock[$realStockId] ?? 0) + $pieces;
        if ($lotId !== null) {
            $this->consumedByLot[$lotId] = ($this->consumedByLot[$lotId] ?? 0) + $pieces;
        }
    }

    private function loadRemainingPieces(int $realStockId): int
    {
        $db = DB::connection('sakemaru');
        $stock = $db->table('real_stocks')->where('id', $realStockId)->lockForUpdate()->first();
        if (! $stock) {
            return 0;
        }

        $lots = $db->table('real_stock_lots')
            ->where('real_stock_id', $realStockId)
            ->orderBy('id')->lockForUpdate()
            ->get(['id', 'status', 'current_quantity', 'reserved_quantity']);
        $activeLots = $lots->where('status', 'ACTIVE');
        $lotIds = $lots->pluck('id')->all();
        $reserved = $db->table('real_stock_lot_earnings')
            ->whereIn('real_stock_lot_id', $lotIds)
            ->where('status', 'RESERVED')
            ->lockForUpdate()->get(['earning_id', 'quantity']);

        $currentPieces = min((int) $stock->current_quantity, (int) $activeLots->sum('current_quantity'));
        $reservedPieces = max(
            (int) $stock->reserved_quantity,
            (int) $lots->sum('reserved_quantity'),
            (int) $reserved->sum('quantity'),
            0,
        );
        $selectedReserved = (int) $reserved->whereIn('earning_id', $this->earningIds)->sum('quantity');
        $transferAllocations = $db->table('stock_transfer_lot_allocations')
            ->whereIn('from_real_stock_lot_id', $lotIds)
            ->whereIn('stock_transfer_id', $this->stockTransferIds)
            ->where('is_set_component', false)
            ->lockForUpdate()->get(['trade_item_id', 'from_real_stock_lot_id', 'quantity']);
        $transferLines = $db->table('trade_items')
            ->whereIn('id', $transferAllocations->pluck('trade_item_id'))
            ->where('is_active', true)
            ->where('item_id', $stock->item_id)
            ->where('stock_allocation_id', $stock->stock_allocation_id)
            ->lockForUpdate()->get()->keyBy('id');
        $selectedTransfers = (int) $transferAllocations->groupBy('trade_item_id')->map(function ($rows, $lineId) use ($transferLines, $stock) {
            $line = $transferLines->get($lineId);
            if (! $line) {
                return 0;
            }
            $unitSize = match ($line->quantity_type) {
                'CASE' => max(1, (int) $line->capacity_case),
                'CARTON' => max(1, (int) ($line->capacity_carton ?? $line->capacity_case)),
                default => 1,
            };
            $expected = abs((int) $line->total_piece_quantity) ?: (int) $line->quantity * $unitSize;
            $pieces = (int) $rows->sum('quantity');

            if ($pieces !== $expected) {
                return 0;
            }
            foreach ($rows as $row) {
                $lotId = (int) $row->from_real_stock_lot_id;
                $this->transferLotItems[$lotId] = (int) $stock->item_id;
                $this->transferPiecesByLot[$lotId] = ($this->transferPiecesByLot[$lotId] ?? 0) + (int) $row->quantity;
            }

            return $pieces;
        })->sum();
        // Also protect callers that allocate multiple lines with separate
        // service instances. Reservations already saved for this scope consume
        // its restored budget; core reservations are never decremented here.
        $existingReservations = $this->scopePrepared ? collect() : $db->table('wms_reservations as wr')
            ->leftJoin('trade_items as ti', 'ti.id', '=', 'wr.source_line_id')
            ->leftJoin('items as i', 'i.id', '=', 'wr.item_id')
            ->where('wr.real_stock_id', $realStockId)
            ->whereIn('wr.status', ['RESERVED', 'PARTIAL'])
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('wr.source_type', 'EARNING')->whereIn('wr.source_id', $this->earningIds))
                    ->orWhere(fn ($q) => $q->where('wr.source_type', 'STOCK_TRANSFER')->whereIn('wr.source_id', $this->stockTransferIds));
            })
            ->selectRaw('wr.qty_each, wr.qty_type, COALESCE(ti.capacity_case, i.capacity_case, 1) AS case_size, COALESCE(ti.capacity_carton, i.capacity_carton, ti.capacity_case, i.capacity_case, 1) AS carton_size')
            ->lockForUpdate()->get();
        $alreadyAllocated = (int) $existingReservations->sum(fn ($row) => (int) $row->qty_each * match ($row->qty_type) {
            'CASE' => max(1, (int) $row->case_size),
            'CARTON' => max(1, (int) $row->carton_size),
            default => 1,
        });

        $availablePieces = $currentPieces - $reservedPieces;
        if ($stock->available_quantity !== null) {
            $availablePieces = min($availablePieces, (int) $stock->available_quantity);
        }

        return max(0, $availablePieces + $selectedReserved + $selectedTransfers - $alreadyAllocated);
    }
}
