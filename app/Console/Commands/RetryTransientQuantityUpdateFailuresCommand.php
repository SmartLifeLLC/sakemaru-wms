<?php

namespace App\Console\Commands;

use App\Models\QuantityUpdateQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryTransientQuantityUpdateFailuresCommand extends Command
{
    protected $signature = 'wms:retry-transient-quantity-update-failures
                            {--limit=20 : Maximum rows to reset per run}
                            {--min-age-seconds=10 : Minimum age after failure before retry}
                            {--cooldown-minutes=60 : Minimum minutes before retrying the same queue again}
                            {--dry-run : Show retry candidates without updating}';

    protected $description = 'Reset transient quantity_update_queue failures back to BEFORE for retry.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $minAgeSeconds = max(0, (int) $this->option('min-age-seconds'));
        $cooldownMinutes = max(1, (int) $this->option('cooldown-minutes'));
        $dryRun = (bool) $this->option('dry-run');

        $candidates = QuantityUpdateQueue::query()
            ->where('status', QuantityUpdateQueue::STATUS_FINISHED)
            ->where('is_success', false)
            ->where('error_message', 'like', '%SAVEPOINT trans2 does not exist%')
            ->where('updated_at', '<=', now()->subSeconds($minAgeSeconds))
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id',
                'request_id',
                'status',
                'is_success',
                'error_message',
                'updated_at',
            ]);

        if ($candidates->isEmpty()) {
            $this->info('No transient quantity_update_queue failures found.');

            return self::SUCCESS;
        }

        $retried = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $cacheKey = $this->cooldownCacheKey((int) $candidate->id);

            if (Cache::has($cacheKey)) {
                $skipped++;
                $this->line("skip queue_id={$candidate->id}: retry cooldown active");

                continue;
            }

            if ($dryRun) {
                $this->line("dry-run queue_id={$candidate->id} request_id={$candidate->request_id}");
                $retried++;

                continue;
            }

            $updated = DB::connection($candidate->getConnectionName())->transaction(function () use ($candidate): int {
                $locked = QuantityUpdateQueue::query()
                    ->whereKey($candidate->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked
                    || $locked->status !== QuantityUpdateQueue::STATUS_FINISHED
                    || $locked->is_success !== false
                    || ! str_contains((string) $locked->error_message, 'SAVEPOINT trans2 does not exist')
                ) {
                    return 0;
                }

                return QuantityUpdateQueue::query()
                    ->whereKey($locked->id)
                    ->update([
                        'status' => QuantityUpdateQueue::STATUS_BEFORE,
                        'is_success' => null,
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);
            });

            if ($updated === 0) {
                $skipped++;
                $this->line("skip queue_id={$candidate->id}: state changed");

                continue;
            }

            Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));
            $retried++;

            Log::warning('Reset transient quantity_update_queue failure for retry', [
                'queue_id' => $candidate->id,
                'request_id' => $candidate->request_id,
                'cooldown_minutes' => $cooldownMinutes,
            ]);

            $this->info("retry queued queue_id={$candidate->id} request_id={$candidate->request_id}");
        }

        $this->info("Completed. retried={$retried} skipped={$skipped}");

        return self::SUCCESS;
    }

    private function cooldownCacheKey(int $queueId): string
    {
        return "quantity-update-queue:transient-retry:{$queueId}";
    }
}
