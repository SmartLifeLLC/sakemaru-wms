<?php

namespace App\Jobs;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\SettlementStatus;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\OrderExecutionService;
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
 * 承認済みの移動候補と発注候補を確定し、入庫予定を生成する。
 * JXファイル生成・送信・CSV生成は別途JX送信画面から実行する。
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
        public int $userId,
        public bool $splitByWarehouse = true,
        public ?int $warehouseId = null
    ) {
        $this->onQueue('default');
    }

    public function handle(
        TransferCandidateExecutionService $transferExecutionService,
        OrderExecutionService $executionService,
    ): void {
        $progress = WmsQueueProgress::findByJobId($this->progressId);

        if (! $progress) {
            Log::error('Progress record not found', ['progress_id' => $this->progressId]);

            return;
        }

        try {
            $zeroQuantityCandidatesQuery = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->forCreatedBy($this->userId)
                ->where('order_quantity', '<=', 0);

            if ($this->warehouseId !== null) {
                $zeroQuantityCandidatesQuery->where('warehouse_id', $this->warehouseId);
            }

            $deletedZeroQuantityCandidates = $zeroQuantityCandidatesQuery->delete();

            if ($deletedZeroQuantityCandidates > 0) {
                Log::info('Deleted zero-quantity approved order candidates before confirmation', [
                    'warehouse_id' => $this->warehouseId,
                    'deleted_count' => $deletedZeroQuantityCandidates,
                ]);
            }

            $zeroQuantityTransferCandidatesQuery = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)
                ->where('transfer_quantity', '<=', 0);

            if ($this->warehouseId !== null) {
                $zeroQuantityTransferCandidatesQuery->where('satellite_warehouse_id', $this->warehouseId);
            }

            $deletedZeroQuantityTransferCandidates = $zeroQuantityTransferCandidatesQuery->delete();

            if ($deletedZeroQuantityTransferCandidates > 0) {
                Log::info('Deleted zero-quantity approved transfer candidates before confirmation', [
                    'warehouse_id' => $this->warehouseId,
                    'deleted_count' => $deletedZeroQuantityTransferCandidates,
                ]);
            }

            // 承認済み移動候補の件数を取得（移動候補は倉庫単位のため作成者フィルタなし）
            $transferApprovedQuery = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED);
            if ($this->warehouseId !== null) {
                $transferApprovedQuery->where('satellite_warehouse_id', $this->warehouseId);
            }
            $transferApprovedCount = $transferApprovedQuery->count();

            // 全ての承認済み発注バッチを取得
            $batchCodesQuery = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->forCreatedBy($this->userId);
            if ($this->warehouseId !== null) {
                $batchCodesQuery->where('warehouse_id', $this->warehouseId);
            }
            $batchCodes = $batchCodesQuery
                ->distinct()
                ->pluck('batch_code')
                ->toArray();

            if (empty($batchCodes) && $transferApprovedCount === 0) {
                // 確定対象がないので、PENDING状態のジョブをキャンセル
                WmsAutoOrderJobControl::cancelPendingSettlements($this->warehouseId, $this->userId);

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
            // 発注バッチ処理: 各バッチ1ステップ (確定のみ)
            $totalBatches = count($batchCodes);
            $hasTransfers = $transferApprovedCount > 0;
            $totalSteps = ($hasTransfers ? 1 : 0) + $totalBatches;

            $progress->markAsProcessing($totalSteps, '発注・移動確定処理を開始しています...');

            $currentStep = 0;
            $totalTransferQueues = 0;
            $totalTransferCandidates = 0;
            $totalSchedules = 0;

            // 1. 移動候補の確定処理（先に実行）
            if ($hasTransfers) {
                $progress->updateProgress(
                    $currentStep,
                    "移動候補を確定処理中... ({$transferApprovedCount}件)"
                );

                $transferResult = $transferExecutionService->executeAllApprovedGrouped($this->userId, $this->warehouseId);
                $totalTransferQueues = $transferResult['queue_count'];
                $totalTransferCandidates = $transferResult['candidate_count'];

                Log::info('Transfer candidates executed in job', [
                    'queue_count' => $totalTransferQueues,
                    'candidate_count' => $totalTransferCandidates,
                    'errors' => $transferResult['errors'],
                ]);

                $currentStep++;
            }

            // 2. 発注候補の確定処理（入庫予定作成のみ）
            foreach ($batchCodes as $index => $batchCode) {
                $progress->updateProgress(
                    $currentStep + $index,
                    "バッチ {$batchCode} の発注確定処理中... (".($index + 1)."/{$totalBatches})"
                );

                $schedules = $executionService->confirmBatch($batchCode, $this->userId, $this->warehouseId);
                $totalSchedules += $schedules->count();

                Log::info('Batch processed in job', [
                    'batch_code' => $batchCode,
                    'schedules' => $schedules->count(),
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
                $completionMessages[] = "入荷予定 {$totalSchedules}件";
            }

            $completionMessage = ! empty($completionMessages)
                ? implode('、', $completionMessages).'を生成しました。'
                : '処理が完了しました。';

            $progress->markAsCompleted([
                'total_transfer_queues' => $totalTransferQueues,
                'total_transfer_candidates' => $totalTransferCandidates,
                'total_schedules' => $totalSchedules,
            ], $completionMessage);

            Log::info('Order confirmation job completed', [
                'progress_id' => $this->progressId,
                'total_transfer_queues' => $totalTransferQueues,
                'total_transfer_candidates' => $totalTransferCandidates,
                'total_schedules' => $totalSchedules,
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

        // ジョブ失敗時は確定待ちをキャンセルして永久PENDINGを防止
        WmsAutoOrderJobControl::cancelPendingSettlements($this->warehouseId, $this->userId);

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
            $query = WmsAutoOrderJobControl::where('settlement_status', SettlementStatus::PENDING)
                ->whereIn('process_name', [JobProcessName::ORDER_CALC, JobProcessName::SALES_BASED_CALC])
                ->where('created_by', $this->userId);
            if ($this->warehouseId !== null) {
                $query->where('warehouse_id', $this->warehouseId);
            }
            $query->update(['settlement_status' => SettlementStatus::CONFIRMED]);

            return;
        }

        // バッチコードに関連するジョブを確定済みに更新
        $query = WmsAutoOrderJobControl::whereIn('batch_code', $batchCodes)
            ->whereIn('process_name', [JobProcessName::ORDER_CALC, JobProcessName::SALES_BASED_CALC])
            ->where('created_by', $this->userId)
            ->where('settlement_status', SettlementStatus::PENDING);

        if ($this->warehouseId !== null) {
            $query->where('warehouse_id', $this->warehouseId);
        }

        $updatedCount = $query->update(['settlement_status' => SettlementStatus::CONFIRMED]);

        Log::info('Updated settlement status to CONFIRMED', [
            'batch_codes' => $batchCodes,
            'updated_count' => $updatedCount,
        ]);
    }
}
