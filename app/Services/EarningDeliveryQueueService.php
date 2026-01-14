<?php

namespace App\Services;

use App\Models\Sakemaru\EarningDeliveryQueue;
use App\Models\WmsPickingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WMS出荷完了時にearning_delivery_queueへ登録するサービス
 *
 * 【処理フロー】
 * 1. WMS: ピッキングタスク完了時にこのサービスを呼び出し
 * 2. WMS: earning_delivery_queueにレコード作成
 * 3. Sakemaru: ProcessEarningDeliveryQueue Jobがポーリング
 * 4. Sakemaru: LotAllocationService.confirmDelivery()でロット単位の在庫更新
 */
class EarningDeliveryQueueService
{
    /**
     * ピッキングタスク完了時にキューに登録
     *
     * @param  WmsPickingTask  $task  完了したピッキングタスク
     * @return EarningDeliveryQueue|null 作成されたキューレコード
     */
    public function registerFromPickingTask(WmsPickingTask $task): ?EarningDeliveryQueue
    {
        $task->load('pickingItemResults');

        $earningIds = [];
        $items = [];

        foreach ($task->pickingItemResults as $itemResult) {
            // ピッキング数量が0の場合はスキップ
            if ($itemResult->picked_qty <= 0) {
                continue;
            }

            // earning_idを取得（wms_reservationsから）
            $reservation = DB::connection('sakemaru')
                ->table('wms_reservations')
                ->where('id', $itemResult->reservation_id)
                ->first();

            if (! $reservation) {
                continue;
            }

            $earningId = null;
            $tradeItemId = null;

            if ($reservation->source_type === 'EARNING') {
                $earningId = $reservation->source_id;
            } elseif ($reservation->source_type === 'TRADE_ITEM') {
                $tradeItemId = $reservation->source_id;
                // trade_itemからearning_idを取得
                $tradeItem = DB::connection('sakemaru')
                    ->table('trade_items')
                    ->where('id', $tradeItemId)
                    ->first();
                if ($tradeItem) {
                    $earningId = $tradeItem->trade_id; // trade_id = earning_id for sales
                }
            }

            if ($earningId) {
                $earningIds[] = $earningId;
                $items[] = [
                    'earning_id' => $earningId,
                    'item_id' => $itemResult->item_id,
                    'quantity' => $itemResult->picked_qty,
                    'trade_item_id' => $tradeItemId,
                    'real_stock_id' => $itemResult->real_stock_id,
                ];
            }
        }

        if (empty($earningIds)) {
            Log::warning('No earnings found for picking task', [
                'task_id' => $task->id,
            ]);

            return null;
        }

        // 重複を除去
        $earningIds = array_values(array_unique($earningIds));

        // キューレコード作成
        $queue = EarningDeliveryQueue::create([
            'earning_ids' => $earningIds,
            'items' => $items,
            'status' => EarningDeliveryQueue::STATUS_PENDING,
            'retry_count' => 0,
        ]);

        Log::info('Earning delivery queue created from picking task', [
            'queue_id' => $queue->id,
            'task_id' => $task->id,
            'earning_count' => count($earningIds),
            'item_count' => count($items),
        ]);

        return $queue;
    }

    /**
     * 欠品承認完了時にキューに登録（代理出荷分）
     *
     * @param  array  $earningIds  対象売上ID配列
     * @param  array  $items  出荷アイテム配列
     */
    public function registerFromShortageApproval(array $earningIds, array $items): ?EarningDeliveryQueue
    {
        if (empty($earningIds) || empty($items)) {
            return null;
        }

        $queue = EarningDeliveryQueue::create([
            'earning_ids' => $earningIds,
            'items' => $items,
            'status' => EarningDeliveryQueue::STATUS_PENDING,
            'retry_count' => 0,
        ]);

        Log::info('Earning delivery queue created from shortage approval', [
            'queue_id' => $queue->id,
            'earning_count' => count($earningIds),
            'item_count' => count($items),
        ]);

        return $queue;
    }
}
