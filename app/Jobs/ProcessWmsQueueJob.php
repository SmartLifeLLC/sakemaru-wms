<?php

namespace App\Jobs;

use App\Enums\AutoOrder\QueueJobType;
use App\Models\WmsQueueJob;
use App\Services\AutoOrder\DemandDistributionJobHandler;
use App\Services\AutoOrder\OrderCreateJobHandler;
use App\Services\AutoOrder\TransferCreateJobHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessWmsQueueJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $queueJobId) {}

    public function handle(
        OrderCreateJobHandler $orderHandler,
        TransferCreateJobHandler $transferHandler,
        DemandDistributionJobHandler $demandHandler,
    ): void {
        $job = WmsQueueJob::find($this->queueJobId);
        if (! $job) {
            return;
        }

        // 排他チェック: pending以外ならスキップ（他Workerが処理中）
        if ($job->status->value !== 'pending') {
            return;
        }

        try {
            $result = match ($job->job_type) {
                QueueJobType::ORDER_CREATE => $orderHandler->handle($job),
                QueueJobType::TRANSFER_CREATE => $transferHandler->handle($job),
                QueueJobType::DEMAND_DISTRIBUTION => $demandHandler->handle($job),
                default => throw new \RuntimeException("Unknown job type: {$job->job_type->value}"),
            };

            Log::info('WMS queue job processed', [
                'job_id' => $job->id,
                'job_type' => $job->job_type->value,
                'result' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('WMS queue job failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
