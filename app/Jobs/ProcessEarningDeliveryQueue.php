<?php

namespace App\Jobs;

use App\Models\Sakemaru\EarningDeliveryQueue;
use App\Services\LotAllocationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessEarningDeliveryQueue implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onConnection('sakemaru');
        $this->onQueue('earning-delivery');
    }

    /**
     * Execute the job.
     */
    public function handle(LotAllocationService $lotAllocationService): void
    {
        // 処理待ちのキューを取得
        $queues = EarningDeliveryQueue::pending()
            ->orderBy('created_at', 'asc')
            ->limit(100)
            ->get();

        foreach ($queues as $queue) {
            $this->processQueue($queue, $lotAllocationService);
        }
    }

    /**
     * 個別のキューを処理
     */
    protected function processQueue(EarningDeliveryQueue $queue, LotAllocationService $lotAllocationService): void
    {
        try {
            $queue->markAsProcessing();

            $items = $queue->getItemsArray();

            foreach ($items as $item) {
                $earningId = $item['earning_id'] ?? null;
                $itemId = $item['item_id'] ?? null;
                $quantity = $item['quantity'] ?? 0;
                $tradeItemId = $item['trade_item_id'] ?? null;

                if ($earningId && $tradeItemId && $quantity > 0) {
                    $lotAllocationService->confirmDelivery($earningId, $tradeItemId, $quantity);
                }
            }

            $queue->markAsCompleted();

            Log::info('Earning delivery queue processed', [
                'queue_id' => $queue->id,
                'earning_ids' => $queue->getEarningIdsArray(),
                'items_count' => count($items),
            ]);
        } catch (\Exception $e) {
            Log::error('Earning delivery queue processing failed', [
                'queue_id' => $queue->id,
                'error' => $e->getMessage(),
            ]);

            $queue->markAsFailed($e->getMessage());
        }
    }
}
