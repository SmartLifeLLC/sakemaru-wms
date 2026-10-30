<?php

namespace App\Services\Statistics;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Filament\Pages\WarehouseOrderReview;
use App\Jobs\GenerateWarehouseOrderReview;
use App\Models\WmsExportLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseOrderReviewRunService
{
    public const RESOURCE = '倉庫別発注検討データ';

    public const FAILURE = 'Excelを作成できませんでした。時間をおいて再度作成してください。';

    public function owned(int $userId, int $clientId): Builder
    {
        return WmsExportLog::query()->forResource(self::RESOURCE)->forUser($userId)
            ->where('filters->client_id', $clientId);
    }

    public function start(int $userId, int $clientId, array $conditions): WmsExportLog
    {
        $connection = config('warehouse-order-review.connection');
        if (in_array(config("queue.connections.{$connection}.driver"), ['sync', 'null', 'deferred', 'background', null], true)) {
            throw new \RuntimeException('An asynchronous queue worker is required for warehouse order review.');
        }

        // Serialize requests per user, including the first request with no existing log.
        return DB::connection('sakemaru')->transaction(function () use ($userId, $clientId, $conditions, $connection) {
            $user = DB::connection('sakemaru')->table('users')->where('id', $userId)
                ->where('client_id', $clientId)->lockForUpdate()->first(['id']);
            abort_unless($user && $clientId > 0, 403);
            $active = $this->owned($userId, $clientId)
                ->whereIn('status', [ExportStatus::PENDING, ExportStatus::PROCESSING])->latest('id')->first();
            if ($active) {
                return $active;
            }

            $log = WmsExportLog::create([
                'resource_name' => self::RESOURCE,
                'format' => ExportFormat::XLSX,
                'status' => ExportStatus::PENDING,
                'file_name' => '倉庫別発注検討データ_'.$conditions['warehouse_code'].'_'.str_replace('-', '', $conditions['date']).'.xlsx',
                'user_id' => $userId,
                'filters' => array_merge($conditions, [
                    'client_id' => $clientId,
                    'disk' => config('warehouse-order-review.disk'),
                    'progress' => ['stage' => '生成の受付が完了しました', 'percent' => 0],
                ]),
            ]);
            // Dispatch only after the log is durable. Record transport failures for the UI.
            DB::connection('sakemaru')->afterCommit(function () use ($log, $connection) {
                try {
                    Bus::dispatch((new GenerateWarehouseOrderReview($log->id))
                        ->onConnection($connection)->onQueue(config('warehouse-order-review.queue')));
                } catch (\Throwable $e) {
                    $this->fail($log->id);
                    report($e);
                }
            });

            return $log;
        });
    }

    public function generate(int $id): void
    {
        // A duplicate delivery must not overwrite a completed file or run the query twice.
        $claimed = WmsExportLog::query()->forResource(self::RESOURCE)->whereKey($id)
            ->where('status', ExportStatus::PENDING)->update(['status' => ExportStatus::PROCESSING->value]);
        if (! $claimed) {
            return;
        }
        $log = WmsExportLog::findOrFail($id);
        $conditions = $log->filters;
        $book = null;
        $temporary = null;
        try {
            $this->progress($log, '対象データを集計中', 10);
            $rows = app(WarehouseOrderReviewService::class)->rows(
                (int) $conditions['client_id'], $conditions['date'], (int) $conditions['warehouse_code']
            );
            $this->progress($log, 'Excelを作成中', 35);
            $book = app(WarehouseOrderReviewExport::class)->spreadsheet($rows, (int) $conditions['warehouse_code'],
                fn (int $done, int $total) => $this->progress($log, 'Excelを作成中', 35 + (int) floor(50 * $done / max(1, $total)), $done, $total));
            $count = max(0, $book->getActiveSheet()->getHighestRow() - 2);
            unset($rows);
            $this->progress($log, 'Excelファイルを書き出し中', 90);
            $temporary = tempnam(sys_get_temp_dir(), 'warehouse_review_');
            if ($temporary === false) {
                throw new \RuntimeException('Cannot create export temporary file.');
            }
            (new Xlsx($book))->save($temporary);
            $this->progress($log, 'ファイルを保存中', 95);
            $path = 'exports/warehouse-order-review/'.$log->user_id.'/'.$log->id.'/'.$log->file_name;
            $stream = fopen($temporary, 'rb');
            try {
                if (! Storage::disk($conditions['disk'])->put($path, $stream)) {
                    throw new \RuntimeException('Cannot store warehouse order review export.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $log->markAsCompleted($path, filesize($temporary), $count);
            $this->progress($log, 'Excelの作成が完了しました', 100, $count, $count);
        } catch (\Throwable $e) {
            $this->fail($id);
            throw $e;
        } finally {
            $book?->disconnectWorksheets();
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function progress(WmsExportLog $log, string $stage, int $percent, int $done = 0, int $total = 0): void
    {
        $filters = $log->filters;
        $filters['progress'] = compact('stage', 'percent', 'done', 'total');
        $log->update(['filters' => $filters]);
    }

    public function fail(int $id): void
    {
        WmsExportLog::query()->forResource(self::RESOURCE)->whereKey($id)
            ->whereIn('status', [ExportStatus::PENDING, ExportStatus::PROCESSING])
            ->update(['status' => ExportStatus::FAILED->value, 'error_message' => self::FAILURE]);
    }

    public function download(WmsExportLog $log): StreamedResponse
    {
        abort_unless(WarehouseOrderReview::canAccess(), 403);
        abort_unless($log->resource_name === self::RESOURCE && (int) $log->user_id === (int) auth()->id()
            && (int) ($log->filters['client_id'] ?? 0) === (int) auth()->user()?->client_id, 403);
        abort_unless($log->isCompleted() && $log->file_path, 404);
        $stream = Storage::disk($log->filters['disk'])->readStream($log->file_path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('ファイルを取得できませんでした。もう一度Excelを作成してください。');
        }
        $log->markAsDownloaded();

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $log->file_name, ['Content-Type' => ExportFormat::XLSX->mimeType()]);
    }
}
