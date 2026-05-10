<?php

namespace App\Filament\Support;

use App\Enums\QuantityType;
use App\Models\Sakemaru\StockTransfer;
use App\Models\Sakemaru\StockTransferQueue;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Support\Facades\DB;

class StockTransferSlipHistory
{
    public static function resolveForBatchCode(string $batchCode): array
    {
        $queue = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('note', 'like', "%{$batchCode}%")
            ->orderByDesc('id')
            ->first();

        return self::resolve($queue, $batchCode);
    }

    public static function resolveForQueue(StockTransferQueue $queue): array
    {
        return self::resolve($queue, $queue->batch_code);
    }

    public static function resolveForTransfer(StockTransfer $stockTransfer): array
    {
        $queue = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('stock_transfer_id', $stockTransfer->id)
            ->orderByDesc('id')
            ->first();

        return self::resolve($queue, $queue?->batch_code ?? self::extractBatchCode($stockTransfer->trade?->note), $stockTransfer->id);
    }

    private static function resolve(?object $queue, ?string $batchCode, ?int $stockTransferId = null): array
    {
        $transfer = null;
        $items = collect();

        $resolvedStockTransferId = $queue?->stock_transfer_id ?? $stockTransferId;

        if ($resolvedStockTransferId) {
            $transfer = DB::connection('sakemaru')
                ->table('stock_transfers as st')
                ->join('trades as t', 't.id', '=', 'st.trade_id')
                ->leftJoin('warehouses as fw', 'fw.id', '=', 'st.from_warehouse_id')
                ->leftJoin('warehouses as tw', 'tw.id', '=', 'st.to_warehouse_id')
                ->where('st.id', $resolvedStockTransferId)
                ->select([
                    'st.id',
                    'st.trade_id',
                    'st.delivered_date',
                    'st.picking_status',
                    'st.is_delivered',
                    'st.is_confirmed',
                    'st.is_active',
                    'st.created_at',
                    't.slip_number',
                    't.process_date',
                    't.note',
                    'fw.code as from_warehouse_code',
                    'fw.name as from_warehouse_name',
                    'tw.code as to_warehouse_code',
                    'tw.name as to_warehouse_name',
                ])
                ->first();

            if ($transfer) {
                $items = DB::connection('sakemaru')
                    ->table('trade_items as ti')
                    ->leftJoin('items as i', 'i.id', '=', 'ti.item_id')
                    ->where('ti.trade_id', $transfer->trade_id)
                    ->orderBy('ti.order_of_items_in_slip')
                    ->orderBy('ti.id')
                    ->select([
                        'ti.id',
                        'ti.order_of_items_in_slip',
                        'i.code as item_code',
                        DB::raw('COALESCE(ti.item_name, i.name) as item_name'),
                        'i.packaging',
                        'ti.quantity',
                        'ti.quantity_type',
                        'ti.order_quantity',
                        'ti.order_quantity_type',
                        'ti.capacity_case',
                        'ti.capacity_carton',
                        'ti.note',
                    ])
                    ->get();
            }
        }

        if ($items->isEmpty() && $queue?->items) {
            $decodedItems = is_array($queue->items)
                ? $queue->items
                : (json_decode($queue->items, true) ?: []);
            $queueItems = collect($decodedItems);
            $itemCodes = $queueItems->pluck('item_code')->filter()->unique()->values();
            $itemsByCode = DB::connection('sakemaru')
                ->table('items')
                ->whereIn('code', $itemCodes)
                ->get(['code', 'name', 'packaging', 'capacity_case', 'capacity_carton'])
                ->keyBy('code');

            $items = $queueItems->values()->map(function (array $item, int $index) use ($itemsByCode) {
                $master = $itemsByCode->get($item['item_code'] ?? null);

                return (object) [
                    'id' => null,
                    'order_of_items_in_slip' => $index + 1,
                    'item_code' => $item['item_code'] ?? '-',
                    'item_name' => $master->name ?? '-',
                    'packaging' => $master->packaging ?? '-',
                    'quantity' => $item['quantity'] ?? 0,
                    'quantity_type' => $item['quantity_type'] ?? QuantityType::PIECE->value,
                    'order_quantity' => $item['quantity'] ?? 0,
                    'order_quantity_type' => $item['quantity_type'] ?? QuantityType::PIECE->value,
                    'capacity_case' => $master->capacity_case ?? null,
                    'capacity_carton' => $master->capacity_carton ?? null,
                    'note' => $item['note'] ?? null,
                ];
            });
        }

        $items = self::attachCandidateChangeInfo($items);

        $candidateCount = $batchCode
            ? WmsStockTransferCandidate::query()->where('batch_code', $batchCode)->count()
            : 0;

        return [
            'batchCode' => $batchCode ?? '-',
            'queue' => $queue,
            'transfer' => $transfer,
            'items' => $items,
            'candidateCount' => $candidateCount,
        ];
    }

    private static function extractBatchCode(?string $note): ?string
    {
        preg_match('/バッチ:([0-9]+)/u', (string) $note, $matches);

        return $matches[1] ?? null;
    }

    private static function attachCandidateChangeInfo($items)
    {
        $candidateIds = $items
            ->map(fn ($item): ?int => self::extractCandidateId($item->note ?? null))
            ->filter()
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            return $items;
        }

        $candidates = WmsStockTransferCandidate::query()
            ->with('modifiedByUser')
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        return $items->map(function ($item) use ($candidates) {
            $candidateId = self::extractCandidateId($item->note ?? null);
            $candidate = $candidateId ? $candidates->get($candidateId) : null;

            $item->transfer_candidate_id = $candidateId;
            $item->candidate_suggested_quantity = $candidate?->suggested_quantity;
            $item->candidate_transfer_quantity = $candidate?->transfer_quantity;
            $item->candidate_is_manually_modified = (bool) ($candidate?->is_manually_modified ?? false);
            $item->candidate_modified_by_name = $candidate?->modifiedByUser?->name;
            $item->candidate_modified_at = $candidate?->modified_at;

            return $item;
        });
    }

    private static function extractCandidateId(?string $note): ?int
    {
        preg_match('/移動候補ID:\s*([0-9]+)/u', (string) $note, $matches);

        return isset($matches[1]) ? (int) $matches[1] : null;
    }
}
