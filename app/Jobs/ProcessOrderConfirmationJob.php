<?php

namespace App\Jobs;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\SettlementStatus;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\OrderExecutionService;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoOrder\TransferCandidateExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 発注・移動確定ジョブ
 *
 * 承認済みの移動候補と発注候補を確定し、入庫予定・CSVファイル・JXファイルを生成
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
        TransferCandidateExecutionService $transferExecutionService,
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
            // 承認済み移動候補の件数を取得
            $transferApprovedCount = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)->count();

            // 全ての承認済み発注バッチを取得
            $batchCodes = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->distinct()
                ->pluck('batch_code')
                ->toArray();

            if (empty($batchCodes) && $transferApprovedCount === 0) {
                $progress->markAsCompleted([
                    'total_transfer_queues' => 0,
                    'total_transfer_candidates' => 0,
                    'total_schedules' => 0,
                    'total_csv_files' => 0,
                    'total_jx_files' => 0,
                ], '確定対象がありません');

                return;
            }

            // ステップ数を計算
            // 移動候補処理: 1ステップ
            // 発注バッチ処理: 各バッチ3ステップ (確定、CSV、JX)
            $totalBatches = count($batchCodes);
            $hasTransfers = $transferApprovedCount > 0;
            $totalSteps = ($hasTransfers ? 1 : 0) + ($totalBatches * 3);

            $progress->markAsProcessing($totalSteps, '発注・移動確定処理を開始しています...');

            $currentStep = 0;
            $totalTransferQueues = 0;
            $totalTransferCandidates = 0;
            $totalSchedules = 0;
            $totalCsvFiles = 0;
            $totalJxFiles = 0;

            // 1. 移動候補の確定処理（先に実行）
            if ($hasTransfers) {
                $progress->updateProgress(
                    $currentStep,
                    "移動候補を確定処理中... ({$transferApprovedCount}件)"
                );

                $transferResult = $transferExecutionService->executeAllApprovedGrouped($this->userId);
                $totalTransferQueues = $transferResult['queue_count'];
                $totalTransferCandidates = $transferResult['candidate_count'];

                Log::info('Transfer candidates executed in job', [
                    'queue_count' => $totalTransferQueues,
                    'candidate_count' => $totalTransferCandidates,
                    'errors' => $transferResult['errors'],
                ]);

                $currentStep++;
            }

            // 2. 発注候補の確定処理
            foreach ($batchCodes as $index => $batchCode) {
                $baseStep = $currentStep + ($index * 3);

                // 2-1. 発注確定（入庫予定作成）
                $progress->updateProgress(
                    $baseStep,
                    "バッチ {$batchCode} の発注確定処理中... (".($index + 1)."/{$totalBatches})"
                );

                $schedules = $executionService->confirmBatch($batchCode, $this->userId);
                $totalSchedules += $schedules->count();

                // 2-2. 共通CSVファイル生成
                $progress->updateProgress(
                    $baseStep + 1,
                    "バッチ {$batchCode} のCSVファイル生成中... (".($index + 1)."/{$totalBatches})"
                );

                $csvResult = $dataFileService->generateCsvFiles($batchCode);
                $totalCsvFiles += $csvResult['total_files'] ?? 0;

                // 2-3. JX送信ファイル生成
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

            // 3. 関連するジョブ管理レコードの確定状態を更新
            $this->updateSettlementStatus($batchCodes);

            // 完了メッセージを構築
            $completionMessages = [];
            if ($totalTransferCandidates > 0) {
                $completionMessages[] = "移動伝票 {$totalTransferQueues}件（{$totalTransferCandidates}商品）";
            }
            if ($totalSchedules > 0) {
                $completionMessages[] = "入庫予定 {$totalSchedules}件";
            }
            if ($totalCsvFiles > 0) {
                $completionMessages[] = "CSVファイル {$totalCsvFiles}件";
            }
            if ($totalJxFiles > 0) {
                $completionMessages[] = "JXファイル {$totalJxFiles}件";
            }

            $completionMessage = ! empty($completionMessages)
                ? implode('、', $completionMessages).'を生成しました。'
                : '処理が完了しました。';

            $progress->markAsCompleted([
                'total_transfer_queues' => $totalTransferQueues,
                'total_transfer_candidates' => $totalTransferCandidates,
                'total_schedules' => $totalSchedules,
                'total_csv_files' => $totalCsvFiles,
                'total_jx_files' => $totalJxFiles,
            ], $completionMessage);

            Log::info('Order confirmation job completed', [
                'progress_id' => $this->progressId,
                'total_transfer_queues' => $totalTransferQueues,
                'total_transfer_candidates' => $totalTransferCandidates,
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
            $progress->markAsFailed('発注・移動確定ジョブが失敗しました: '.$exception->getMessage());
        }

        Log::error('Transfer/Order confirmation job failed', [
            'progress_id' => $this->progressId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * 関連するジョブ管理レコードの確定状態を CONFIRMED に更新
     *
     * @param  array  $batchCodes  処理対象のバッチコード配列
     */
    private function updateSettlementStatus(array $batchCodes): void
    {
        if (empty($batchCodes)) {
            // バッチコードがない場合でも、PENDING状態のジョブがあれば確定済みにする
            WmsAutoOrderJobControl::where('settlement_status', SettlementStatus::PENDING)
                ->update(['settlement_status' => SettlementStatus::CONFIRMED]);

            return;
        }

        // バッチコードに関連するジョブを確定済みに更新
        $updatedCount = WmsAutoOrderJobControl::whereIn('batch_code', $batchCodes)
            ->where('settlement_status', SettlementStatus::PENDING)
            ->update(['settlement_status' => SettlementStatus::CONFIRMED]);

        Log::info('Updated settlement status to CONFIRMED', [
            'batch_codes' => $batchCodes,
            'updated_count' => $updatedCount,
        ]);
    }
}
