<?php

namespace App\Jobs;

use App\Models\WmsAutoOrderRun;
use App\Services\AutoReplenishment\ExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAutoReplenishmentRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId, public readonly ?int $lineId = null) {}

    public function handle(ExecutionService $service): void
    {
        $run = WmsAutoOrderRun::findOrFail($this->runId);
        throw_unless((int) $run->client_id === (int) config('app.client_id'), \RuntimeException::class, '実行顧客が一致しません。');
        if ($this->lineId !== null) {
            $line = \App\Models\WmsAutoOrderRunLine::where('run_id', $run->id)->findOrFail($this->lineId);
            $service->resumeLine($line);

            return;
        }
        $processed = $service->process($run);
        if ($processed && $run->refresh()->status === 'RUNNING') {
            self::dispatch($this->runId)->delay(now()->addSecond())->afterCommit();
        }
    }

    public function failed(\Throwable $error): void
    {
        $run = WmsAutoOrderRun::find($this->runId);
        if ($run && (int) $run->client_id === (int) config('app.client_id')) {
            if ($this->lineId === null && $run->status === 'RUNNING') {
                $run->update(['status' => 'FAILED', 'finished_at' => now()]);
            }
            app(\App\Services\AutoReplenishment\ReplenishmentGuard::class)->event((int) $run->client_id, 'WORKER_FAILED', ['run_id' => $run->id, 'error' => mb_substr($error->getMessage(), 0, 500)], $this->lineId);
        }
    }
}
