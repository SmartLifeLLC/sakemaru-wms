<?php

namespace App\Jobs;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 発注送信テストデータ生成ジョブ
 *
 * 承認済み発注候補からテスト用の発注ファイルを生成
 * 進捗は発注候補レコード単位で管理
 */
class ProcessTestOrderFilesJob implements ShouldQueue
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
    public int $timeout = 1800; // 30分

    public function __construct(
        public string $progressId,
        public int $userId
    ) {
        $this->onQueue('default');
    }

    public function handle(OrderTransmissionService $transmissionService): void
    {
        $progress = WmsQueueProgress::findByJobId($this->progressId);

        if (! $progress) {
            Log::error('Progress record not found', ['progress_id' => $this->progressId]);

            return;
        }

        $startTime = microtime(true);
        Log::info('[TestOrderFiles] ジョブ開始', ['progress_id' => $this->progressId]);

        try {
            // Step 1: 総候補数を取得（これが進捗の分母になる）
            $totalCandidates = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)->count();

            if ($totalCandidates === 0) {
                $progress->markAsCompleted([
                    'total_files' => 0,
                    'total_orders' => 0,
                ], '生成対象がありません');

                return;
            }

            // 発注先数を取得（ファイル数の概算）
            $contractorCount = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->distinct('contractor_id')
                ->count('contractor_id');

            Log::info('[TestOrderFiles] 全体像把握完了', [
                'total_candidates' => $totalCandidates,
                'contractor_count' => $contractorCount,
            ]);

            // 進捗の分母を総候補数に設定
            $progress->markAsProcessing(
                $totalCandidates,
                "準備中... (発注候補 {$totalCandidates}件, 発注先 {$contractorCount}件)"
            );

            // 進捗コールバックを作成
            $processedOrders = 0;
            $totalFiles = 0;
            $fileDetails = [];

            $progressCallback = function (array $fileResult) use ($progress, $totalCandidates, &$processedOrders, &$totalFiles, &$fileDetails) {
                $processedOrders += $fileResult['order_count'];
                $totalFiles++;

                $fileDetails[] = [
                    'contractor_code' => $fileResult['contractor_code'] ?? $fileResult['contractor_id'],
                    'record_count' => $fileResult['record_count'] ?? 0,
                    'order_count' => $fileResult['order_count'] ?? 0,
                ];

                // 進捗を更新（発注候補数ベース）
                $progress->updateProgress(
                    $processedOrders,
                    "ファイル生成中... ({$totalFiles}ファイル, {$processedOrders}/{$totalCandidates}件)"
                );

                Log::info('[TestOrderFiles] ファイル生成完了', [
                    'file_index' => $totalFiles,
                    'contractor_code' => $fileResult['contractor_code'] ?? $fileResult['contractor_id'],
                    'order_count' => $fileResult['order_count'],
                    'processed_total' => $processedOrders,
                ]);
            };

            // 発注ファイル生成（進捗コールバック付き）
            $result = $transmissionService->generateTestOrderFilesWithProgress($progressCallback);

            $totalElapsed = round((microtime(true) - $startTime) * 1000);

            $progress->markAsCompleted([
                'total_files' => $totalFiles,
                'total_orders' => $processedOrders,
                'total_candidates' => $totalCandidates,
                'file_details' => $fileDetails,
            ], "テストファイル {$totalFiles}件（発注 {$processedOrders}件）を生成しました。");

            Log::info('[TestOrderFiles] ジョブ完了', [
                'progress_id' => $this->progressId,
                'total_files' => $totalFiles,
                'total_orders' => $processedOrders,
                'total_elapsed_ms' => $totalElapsed,
            ]);

        } catch (\Exception $e) {
            $totalElapsed = round((microtime(true) - $startTime) * 1000);

            Log::error('[TestOrderFiles] ジョブ失敗', [
                'progress_id' => $this->progressId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'total_elapsed_ms' => $totalElapsed,
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

        Log::error('[TestOrderFiles] ジョブ失敗(failed handler)', [
            'progress_id' => $this->progressId,
            'error' => $exception->getMessage(),
        ]);
    }
}
