<?php

namespace App\Services\QuantityUpdate;

use App\Models\QuantityUpdateQueue;
use App\Models\WmsShortage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuantityUpdateQueueService
{
    /**
     * 欠品承認時にquantity_update_queueレコードを作成
     *
     * @param WmsShortage $shortage 承認された欠品レコード
     * @return QuantityUpdateQueue|null 作成されたレコード（スキップ時はnull）
     */
    public function createQueueForShortageApproval(WmsShortage $shortage): ?QuantityUpdateQueue
    {
        // source_pick_result_idが必須
        if (!$shortage->source_pick_result_id) {
            Log::warning('Cannot create quantity_update_queue: source_pick_result_id is null', [
                'shortage_id' => $shortage->id,
            ]);
            return null;
        }

        // 必要なデータをロード
        $shortage->load(['trade', 'wave']);

        // client_idを取得
        $clientId = $shortage->trade?->client_id;
        if (!$clientId) {
            Log::warning('Cannot create quantity_update_queue: client_id not found', [
                'shortage_id' => $shortage->id,
                'trade_id' => $shortage->trade_id,
            ]);
            return null;
        }

        // shipment_dateを取得
        $shipmentDate = $shortage->wave?->shipping_date;

        // request_idとしてsource_pick_result_idを使用
        $requestId = (string) $shortage->source_pick_result_id;

        // 既に同じrequest_idのレコードが存在する場合はスキップ
        $existing = QuantityUpdateQueue::where('request_id', $requestId)->first();
        if ($existing) {
            Log::info('Quantity update queue already exists for this request_id', [
                'request_id' => $requestId,
                'queue_id' => $existing->id,
            ]);
            return $existing;
        }

        // 欠品後の数量を計算（受注数量 - 欠品数量）
        $updateQty = max(0, $shortage->order_qty - $shortage->shortage_qty);

        // quantity_update_queueレコードを作成
        $queue = QuantityUpdateQueue::create([
            'client_id' => $clientId,
            'trade_category' => QuantityUpdateQueue::TRADE_CATEGORY_EARNING,
            'trade_id' => $shortage->trade_id,
            'trade_item_id' => $shortage->trade_item_id,
            'update_qty' => $updateQty,
            'quantity_type' => $shortage->qty_type_at_order,
            'shipment_date' => $shipmentDate,
            'request_id' => $requestId,
            'status' => QuantityUpdateQueue::STATUS_BEFORE,
        ]);

        Log::info('Created quantity_update_queue for shortage approval', [
            'queue_id' => $queue->id,
            'shortage_id' => $shortage->id,
            'request_id' => $requestId,
            'update_qty' => $updateQty,
            'quantity_type' => $shortage->qty_type_at_order,
        ]);

        return $queue;
    }

    /**
     * 複数の欠品を一括でキューに登録
     *
     * @param iterable $shortages 承認された欠品レコードのコレクション
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
