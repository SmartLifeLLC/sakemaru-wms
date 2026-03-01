<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\QueueJobLogLevel;
use App\Models\WmsQueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DemandDistributionJobHandler
{
    public function __construct(
        private OrderCreateJobHandler $orderHandler,
        private TransferCreateJobHandler $transferHandler,
    ) {}

    public function handle(WmsQueueJob $job): array
    {
        $job->markAsProcessing();
        $job->addLog(QueueJobLogLevel::INFO->value, '需要分配ジョブを開始');

        $payload = $job->payload;
        $items = $payload['items'] ?? [];

        if (empty($items)) {
            $job->addLog(QueueJobLogLevel::ERROR->value, 'payloadにitemsが含まれていません');
            $job->markAsFailed('payloadにitemsが含まれていません');

            return ['success' => false, 'error' => 'No items in payload'];
        }

        try {
            $result = DB::connection('sakemaru')->transaction(function () use ($job, $items, $payload) {
                $orderItems = [];
                $transferItems = [];

                foreach ($items as $item) {
                    match ($item['type'] ?? null) {
                        'order' => $orderItems[] = $item,
                        'transfer' => $transferItems[] = $item,
                        default => $job->addLog(
                            QueueJobLogLevel::WARNING->value,
                            '不明なtype: '.($item['type'] ?? 'null'),
                            $item
                        ),
                    };
                }

                $orderResult = [];
                $transferResult = [];

                if (! empty($orderItems)) {
                    $orderResult = $this->orderHandler->handleItems($job, $orderItems);
                }

                if (! empty($transferItems)) {
                    $transferResult = $this->transferHandler->handleItems($job, $transferItems);
                }

                return [
                    'demand_request_id' => $payload['demand_request_id'] ?? null,
                    'order_results' => $orderResult,
                    'transfer_results' => $transferResult,
                    'total_orders' => count($orderItems),
                    'total_transfers' => count($transferItems),
                ];
            });

            $job->markAsCompleted($result);
            $job->addLog(QueueJobLogLevel::INFO->value, '需要分配ジョブが完了', $result);

            return $result;

        } catch (\Exception $e) {
            $job->addLog(QueueJobLogLevel::ERROR->value, '需要分配ジョブでエラー: '.$e->getMessage());
            $job->markAsFailed($e->getMessage());
            Log::error('DemandDistributionJobHandler failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
