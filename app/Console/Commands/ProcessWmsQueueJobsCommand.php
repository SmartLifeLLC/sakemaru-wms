<?php

namespace App\Console\Commands;

use App\Enums\AutoOrder\QueueJobType;
use App\Models\WmsQueueJob;
use App\Services\AutoOrder\OrderCreateJobHandler;
use App\Services\AutoOrder\TransferCreateJobHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessWmsQueueJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wms:process-queue
                            {--type= : Job type to process (order_create, transfer_create, demand_distribution)}
                            {--once : Process only one job then exit}
                            {--limit=100 : Maximum number of jobs to process per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending WMS queue jobs';

    public function __construct(
        private OrderCreateJobHandler $orderCreateHandler,
        private TransferCreateJobHandler $transferCreateHandler
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = now();
        $this->info('WMS Queue Job Processor started...');

        $jobType = $this->option('type');
        $once = $this->option('once');
        $limit = (int) $this->option('limit');

        if ($jobType) {
            $type = QueueJobType::tryFrom($jobType);
            if (! $type) {
                $this->error("Invalid job type: {$jobType}");
                $this->info('Valid types: '.implode(', ', array_column(QueueJobType::cases(), 'value')));

                return 1;
            }
            $this->info("Processing job type: {$type->label()} ({$type->value})");
        } else {
            $this->info('Processing all job types');
        }

        $processedCount = 0;
        $successCount = 0;
        $failedCount = 0;

        while ($processedCount < $limit) {
            // 次のジョブを取得
            $job = $this->getNextJob($jobType ? QueueJobType::from($jobType) : null);

            if (! $job) {
                if ($processedCount === 0) {
                    $this->info('No pending jobs found.');
                }
                break;
            }

            $processedCount++;
            $this->info("[{$processedCount}] Processing job #{$job->id} ({$job->job_type->value})...");

            try {
                $result = $this->processJob($job);

                if ($result['success'] ?? true) {
                    $successCount++;
                    $this->info("  ✓ Job #{$job->id} completed successfully");

                    // 結果サマリーを表示
                    if (isset($result['success_count'])) {
                        $this->info("    Created: {$result['success_count']}, Skipped: {$result['skip_count']}");
                    }
                } else {
                    $failedCount++;
                    $this->error("  ✗ Job #{$job->id} failed: ".($result['error'] ?? 'Unknown error'));
                }

            } catch (\Exception $e) {
                $failedCount++;
                $this->error("  ✗ Job #{$job->id} exception: ".$e->getMessage());
                Log::error('Queue job processing exception', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            if ($once) {
                break;
            }
        }

        // サマリー出力
        $this->newLine();
        $duration = $startTime->diffInSeconds(now());
        $this->info("Processing completed in {$duration} seconds");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $processedCount],
                ['Successful', $successCount],
                ['Failed', $failedCount],
            ]
        );

        return $failedCount > 0 ? 1 : 0;
    }

    /**
     * 次のジョブを取得
     */
    private function getNextJob(?QueueJobType $type = null): ?WmsQueueJob
    {
        if ($type) {
            return WmsQueueJob::getPendingByType($type);
        }

        return WmsQueueJob::getNextPending();
    }

    /**
     * ジョブを処理
     */
    private function processJob(WmsQueueJob $job): array
    {
        return match ($job->job_type) {
            QueueJobType::ORDER_CREATE => $this->orderCreateHandler->handle($job),
            QueueJobType::TRANSFER_CREATE => $this->transferCreateHandler->handle($job),
            QueueJobType::DEMAND_DISTRIBUTION => $this->handleDemandDistribution($job),
            default => throw new \RuntimeException("Unknown job type: {$job->job_type->value}"),
        };
    }

    /**
     * 需要分配ジョブを処理（未実装）
     */
    private function handleDemandDistribution(WmsQueueJob $job): array
    {
        // TODO: DemandDistributionJobHandler を実装後に差し替え
        $job->markAsFailed('DemandDistributionJobHandler is not implemented yet');

        return [
            'success' => false,
            'error' => 'DemandDistributionJobHandler is not implemented yet',
        ];
    }
}
