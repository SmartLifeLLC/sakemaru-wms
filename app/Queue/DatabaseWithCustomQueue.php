<?php

namespace App\Queue;

use App\Jobs\ProcessWmsQueueJob;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseWithCustomQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        $this->processPendingWmsQueueJobs();

        return parent::pop($queue);
    }

    protected function processPendingWmsQueueJobs(): void
    {
        $jobs = DB::connection('sakemaru')
            ->table('wms_queue_jobs')
            ->where('status', 'pending')
            ->where('attempts', '<', DB::raw('max_attempts'))
            ->orderBy('priority', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->get();

        foreach ($jobs as $job) {
            $cacheKey = "wms_queue_job_dispatched:{$job->id}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            ProcessWmsQueueJob::dispatch($job->id);

            Cache::put($cacheKey, true, now()->addMinutes(10));

            Log::info('WMS queue job auto-dispatched', [
                'job_id' => $job->id,
                'job_type' => $job->job_type,
            ]);
        }
    }
}
