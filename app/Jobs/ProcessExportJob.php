<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Models\WmsExportLog;
use App\Services\Export\ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $exportLogId,
        public string $modelClass,
        public array $queryConstraints,
        public array $columns,
        public ExportFormat $format,
    ) {
        $this->onQueue('default');
    }

    public function handle(ExportService $exportService): void
    {
        $exportLog = WmsExportLog::find($this->exportLogId);

        if (! $exportLog) {
            Log::error('[Export] Export log not found', ['export_log_id' => $this->exportLogId]);

            return;
        }

        Log::info('[Export] ジョブ開始', [
            'export_log_id' => $this->exportLogId,
            'resource_name' => $exportLog->resource_name,
            'format' => $this->format->value,
        ]);

        try {
            $query = $exportService->rebuildQuery($this->modelClass, $this->queryConstraints);

            $exportService->executeAsyncExport(
                $query,
                $this->columns,
                $this->format,
                $exportLog
            );

            Log::info('[Export] ジョブ完了', [
                'export_log_id' => $this->exportLogId,
                'row_count' => $exportLog->fresh()->row_count,
            ]);
        } catch (\Exception $e) {
            Log::error('[Export] ジョブ失敗', [
                'export_log_id' => $this->exportLogId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $exportLog = WmsExportLog::find($this->exportLogId);

        if ($exportLog) {
            $exportLog->markAsFailed('ジョブが失敗しました: '.$exception->getMessage());
        }

        Log::error('[Export] ジョブ失敗(failed handler)', [
            'export_log_id' => $this->exportLogId,
            'error' => $exception->getMessage(),
        ]);
    }
}
