<?php

namespace App\Services\QuantityUpdate;

use App\Models\QuantityUpdateQueue;
use App\Models\WmsPickingItemResult;
use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuantityUpdateQueueService
{
    public function createQueueForPickingQuantityCorrection(WmsPickingItemResult $pickResult): ?QuantityUpdateQueue
    {
        if ($pickResult->source_type === WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER) {
            return null;
        }

        $pickResult->loadMissing(['trade', 'pickingTask']);

        $clientId = $pickResult->trade?->client_id;
        if (! $clientId) {
            Log::warning('Cannot create quantity_update_queue: client_id not found for picking correction', [
                'pick_result_id' => $pickResult->id,
                'trade_id' => $pickResult->trade_id,
            ]);

            return null;
        }

        $requestId = "picking-quantity-correction-{$pickResult->id}";
        $existing = QuantityUpdateQueue::where('request_id', $requestId)->first();
        $payload = [
            'client_id' => $clientId,
            'trade_category' => QuantityUpdateQueue::TRADE_CATEGORY_EARNING,
            'trade_id' => $pickResult->trade_id,
            'trade_item_id' => $pickResult->trade_item_id,
            'update_qty' => (int) $pickResult->picked_qty,
            'quantity_type' => $pickResult->picked_qty_type ?: $pickResult->ordered_qty_type,
            'shipment_date' => $pickResult->pickingTask?->shipment_date,
        ];

        if ($existing) {
            if ($existing->status === QuantityUpdateQueue::STATUS_BEFORE) {
                $existing->update($payload);
            }

            return $existing;
        }

        return QuantityUpdateQueue::create($payload + [
            'request_id' => $requestId,
            'status' => QuantityUpdateQueue::STATUS_BEFORE,
        ]);
    }

    public function createQueueForShortageApproval(WmsShortage $shortage): ?QuantityUpdateQueue
    {
        if (! $shortage->source_pick_result_id) {
            Log::warning('Cannot create quantity_update_queue: source_pick_result_id is null', [
                'shortage_id' => $shortage->id,
            ]);

            return null;
        }

        $shortage->loadMissing(['trade', 'wave', 'sourcePickResult']);

        $clientId = $shortage->trade?->client_id;
        if (! $clientId) {
            Log::warning('Cannot create quantity_update_queue: client_id not found', [
                'shortage_id' => $shortage->id,
                'trade_id' => $shortage->trade_id,
            ]);

            return null;
        }

        $allocatedQty = (int) $shortage->allocations()->sum('assign_qty');

        return $this->createOrUpdateQueue(
            shortage: $shortage,
            requestId: (string) $shortage->source_pick_result_id,
            updateQty: $this->calculateFinalQuantity($shortage, $allocatedQty),
            clientId: $clientId,
            shipmentDate: $shortage->wave?->shipping_date,
            tradeCategory: $this->tradeCategoryForShortage($shortage),
            context: 'shortage approval',
            extraLogContext: ['allocated_qty' => $allocatedQty],
        );
    }

    /**
     * 横持ち出荷実績確定後にquantity_update_queueレコードを作成
     */
    public function createQueueForAllocationSync(WmsShortage $shortage, string $requestId): ?QuantityUpdateQueue
    {
        if (! $shortage->source_pick_result_id) {
            Log::warning('Cannot create quantity_update_queue: source_pick_result_id is null', [
                'shortage_id' => $shortage->id,
                'request_id' => $requestId,
            ]);

            return null;
        }

        $shortage->loadMissing(['trade', 'wave', 'allocations', 'sourcePickResult']);

        $clientId = $shortage->trade?->client_id;
        if (! $clientId) {
            Log::warning('Cannot create quantity_update_queue: client_id not found', [
                'shortage_id' => $shortage->id,
                'trade_id' => $shortage->trade_id,
                'request_id' => $requestId,
            ]);

            return null;
        }

        $pickedAllocationQty = (int) $shortage->allocations()->sum('picked_qty');

        return $this->createOrUpdateQueue(
            shortage: $shortage,
            requestId: $requestId,
            updateQty: $this->calculateFinalQuantity($shortage, $pickedAllocationQty),
            clientId: $clientId,
            shipmentDate: $shortage->wave?->shipping_date,
            tradeCategory: $this->tradeCategoryForShortage($shortage),
            context: 'allocation sync',
            extraLogContext: ['picked_allocation_qty' => $pickedAllocationQty],
        );
    }

    private function tradeCategoryForShortage(WmsShortage $shortage): string
    {
        return match ($shortage->sourcePickResult?->source_type) {
            WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER => QuantityUpdateQueue::TRADE_CATEGORY_STOCK_TRANSFER,
            default => QuantityUpdateQueue::TRADE_CATEGORY_EARNING,
        };
    }

    private function calculateFinalQuantity(WmsShortage $shortage, int $proxyQty): int
    {
        return min(
            (int) $shortage->order_qty,
            max(0, (int) $shortage->picked_qty + $proxyQty)
        );
    }

    private function createOrUpdateQueue(
        WmsShortage $shortage,
        string $requestId,
        int $updateQty,
        int $clientId,
        mixed $shipmentDate,
        string $tradeCategory,
        string $context,
        array $extraLogContext = [],
    ): QuantityUpdateQueue {
        $existing = QuantityUpdateQueue::where('request_id', $requestId)->first();

        if ($existing) {
            if ($existing->status === QuantityUpdateQueue::STATUS_BEFORE) {
                $existing->update([
                    'client_id' => $clientId,
                    'trade_category' => $tradeCategory,
                    'trade_id' => $shortage->trade_id,
                    'trade_item_id' => $shortage->trade_item_id,
                    'update_qty' => $updateQty,
                    'quantity_type' => $shortage->qty_type_at_order,
                    'shipment_date' => $shipmentDate,
                ]);

                Log::info('Updated pending quantity_update_queue', [
                    'queue_id' => $existing->id,
                    'shortage_id' => $shortage->id,
                    'request_id' => $requestId,
                    'context' => $context,
                    'update_qty' => $updateQty,
                    'picked_qty' => $shortage->picked_qty,
                ] + $extraLogContext);

                return $existing;
            }

            Log::info('Quantity update queue already processed or in progress', [
                'queue_id' => $existing->id,
                'shortage_id' => $shortage->id,
                'request_id' => $requestId,
                'context' => $context,
                'status' => $existing->status,
            ]);

            return $existing;
        }

        // quantity_update_queueレコードを作成
        $queue = QuantityUpdateQueue::create([
            'client_id' => $clientId,
            'trade_category' => $tradeCategory,
            'trade_id' => $shortage->trade_id,
            'trade_item_id' => $shortage->trade_item_id,
            'update_qty' => $updateQty,
            'quantity_type' => $shortage->qty_type_at_order,
            'shipment_date' => $shipmentDate,
            'request_id' => $requestId,
            'status' => QuantityUpdateQueue::STATUS_BEFORE,
        ]);

        Log::info('Created quantity_update_queue', [
            'queue_id' => $queue->id,
            'shortage_id' => $shortage->id,
            'request_id' => $requestId,
            'context' => $context,
            'update_qty' => $updateQty,
            'picked_qty' => $shortage->picked_qty,
            'quantity_type' => $shortage->qty_type_at_order,
        ] + $extraLogContext);

        return $queue;
    }

    /**
     * 複数の欠品を一括でキューに登録
     *
     * @param  iterable  $shortages  承認された欠品レコードのコレクション
     * @return array 作成されたレコードの配列
     */
    public function createQueueForMultipleShortages(iterable $shortages): array
    {
        $queues = [];

        DB::connection('sakemaru')->transaction(function () use ($shortages, &$queues) {
            foreach ($shortages as $shortage) {
                $queue = $this->createQueueForShortageApproval($shortage);
                if ($queue) {
                    $queues[] = $queue;
                }
            }
        });

        return $queues;
    }
}
