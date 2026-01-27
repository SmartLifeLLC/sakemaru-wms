<?php

namespace App\Jobs;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\OrderExecutionService;
use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 発注確定ジョブ
 *
 * 承認済み発注候補を確定し、入庫予定・CSVファイル・JXファイルを生成
 */
class ProcessOrderConfirmationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * ジョブの最大試行回数
     */
    public int $tries = 1;

    /**
     * ジョブのタイムアウト（秒）
     */
    public int $timeout = 3600; // 1時間

    public function __construct(
        public string $progressId,
        public int $userId
    ) {
        $this->onQueue('default');
    }

    public function handle(
        OrderExecutionService $executionService,
        OrderDataFileService $dataFileService,
        OrderTransmissionService $transmissionService
    ): void {
        $progress = WmsQueueProgress::findByJobId($this->progressId);

        if (! $progress) {
            Log::error('Progress record not found', ['progress_id' => $this->progressId]);

            return;
        }

        try {
            // 全ての承認済みバッチを取得
            $batchCodes = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->distinct()
                ->pluck('batch_code')
                ->toArray();

            if (empty($batchCodes)) {
                $progress->markAsCompleted([
                    'total_schedules' => 0,
                    'total_csv_files' => 0,
                    'total_jx_files' => 0,
                ], '確定対象がありません');

                return;
            }

            // バッチ数を総数として使用（各バッチ3ステップ: 確定、CSV、JX）
            $totalBatches = count($batchCodes);
            $totalSteps = $totalBatches * 3;

            $progress->markAsProcessing($totalSteps, '発注確定処理を開始しています...');

            $totalSchedules = 0;
            $totalCsvFiles = 0;
            $totalJxFiles = 0;

            foreach ($batchCodes as $index => $batchCode) {
                $baseStep = $index * 3;

                // 1. 発注確定（入庫予定作成）
                $progress->updateProgress(
                    $baseStep,
                    "バッチ {$batchCode} の発注確定処理中... (".($index + 1)."/{$totalBatches})"
                );

                $schedules = $executionService->confirmBatch($batchCode, $this->userId);
                $totalSchedules += $schedules->count();

                // 2. 共通CSVファイル生成
                $progress->updateProgress(
                    $baseStep + 1,
                    "バッチ {$batchCode} のCSVファイル生成中... (".($index + 1)."/{$totalBatches})"
                );

                $csvResult = $dataFileService->generateCsvFiles($batchCode);
                $totalCsvFiles += $csvResult['total_files'] ?? 0;

                // 3. JX送信ファイル生成
                $progress->updateProgress(
                    $baseStep + 2,
                    "バッチ {$batchCode} のJXファイル生成中... (".($index + 1)."/{$totalBatches})"
                );

                $jxResult = $transmissionService->generateOrderFiles($batchCode);
                $totalJxFiles += count($jxResult['files'] ?? []);

                // バッチ完了
                $progress->updateProgress(
                    $baseStep + 3,
                    "バッチ {$batchCode} 完了 (".($index + 1)."/{$totalBatches})"
                );

                Log::info('Batch processed in job', [
                    'batch_code' => $batchCode,
                    'schedules' => $schedules->count(),
                    'csv_files' => $csvResult['total_files'] ?? 0,
                    'jx_files' => count($jxResult['files'] ?? []),
                ]);
            }

            $progress->markAsCompleted([
                'total_schedules' => $totalSchedules,
                'total_csv_files' => $totalCsvFiles,
                'total_jx_files' => $totalJxFiles,
            ], "入庫予定 {$totalSchedules}件、CSVファイル {$totalCsvFiles}件、JXファイル {$totalJxFiles}件 を生成しました。");

            Log::info('Order confirmation job completed', [
                'progress_id' => $this->progressId,
                'total_schedules' => $totalSchedules,
                'total_csv_files' => $totalCsvFiles,
                'total_jx_files' => $totalJxFiles,
            ]);

        } catch (\Exception $e) {
            Log::error('Order confirmation job failed', [
                'progress_id' => $this->progressId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $progress->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $progress = WmsQueueProgress::findByJobId($this->progressId);

        if ($progress) {
            $progress->markAsFailed('ジョブが失敗しました: '.$exception->getMessage());
        }

        Log::error('Order confirmation job failed', [
            'progress_id' => $this->progressId,
            'error' => $exception->getMessage(),
        ]);
    }
}
