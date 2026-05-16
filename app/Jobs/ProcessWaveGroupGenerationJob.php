<?php

namespace App\Jobs;

use App\Models\WaveGroup;
use App\Models\WmsQueueProgress;
use App\Services\WaveGroupGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessWaveGroupGenerationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $waveGroupId,
        public string $progressJobId,
        public int $userId
    ) {
        $this->onQueue('default');
    }

    public function handle(WaveGroupGenerationService $service): void
    {
        $progress = WmsQueueProgress::findByJobId($this->progressJobId);
        $waveGroup = WaveGroup::findOrFail($this->waveGroupId);

        try {
            $service->generate($waveGroup, $this->userId, $progress);
        } catch (Throwable $e) {
            $progress?->markAsFailed($e->getMessage(), [
                'wave_group_id' => $this->waveGroupId,
                'error_class' => $e::class,
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        WmsQueueProgress::findByJobId($this->progressJobId)?->markAsFailed(
            $exception?->getMessage() ?? '波動生成ジョブが失敗しました',
            ['wave_group_id' => $this->waveGroupId]
        );
    }
}
