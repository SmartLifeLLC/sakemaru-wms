<?php

namespace App\Jobs;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Services\AutoOrder\OrderCandidateCalculationService;
use App\Services\AutoOrder\StockSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 発注候補生成ジョブ
 *
 * 進捗管理付きで発注候補生成処理を実行
 */
class ProcessOrderCandidateGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10分

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $jobId,
        public bool $deletePending = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $progress = WmsQueueProgress::findByJobId($this->jobId);

        if (! $progress) {
            Log::error('Queue progress not found', ['job_id' => $this->jobId]);

            return;
        }

        try {
            // 全体で5ステップ
            // 1. 削除（10%）
            // 2. スナップショット準備（20%）
            // 3. スナップショット実行（40%）
            // 4. 発注候補計算（80%）
            // 5. 完了（100%）

            $progress->markAsProcessing(100, '処理を開始しています...');

            $results = [
                'deleted' => 0,
                'snapshot' => 0,
                'calculated' => 0,
                'batchCode' => null,
                'byWarehouse' => [],
            ];

            // Step 1: 未承認の発注候補を削除（オプション）
            if ($this->deletePending) {
                $progress->update([
                    'progress' => 5,
                    'message' => '未承認の発注候補を削除中...',
                ]);

                $results['deleted'] = WmsOrderCandidate::where('status', CandidateStatus::PENDING)->delete();
            }

            $progress->update([
                'progress' => 10,
                'message' => 'スナップショットを準備中...',
            ]);

            // Step 2-3: スナップショット生成
            $progress->update([
                'progress' => 20,
                'message' => 'スナップショットを生成中...',
            ]);

            $snapshotService = app(StockSnapshotService::class);
            $snapshotJob = $snapshotService->generateAll();
            $results['snapshot'] = $snapshotJob->processed_records;

            $progress->update([
                'progress' => 40,
                'message' => '発注候補を計算中...',
            ]);

            // Step 4: 発注候補計算
            $calculationService = app(OrderCandidateCalculationService::class);
            $calcJob = $calculationService->calculate();

            $results['batchCode'] = $calcJob->batch_code;
            $results['calculated'] = $calcJob->processed_records;

            $progress->update([
                'progress' => 90,
                'message' => '集計中...',
            ]);

            // 倉庫別内訳を取得
            $results['byWarehouse'] = DB::connection('sakemaru')
                ->table('wms_order_candidates')
                ->join('warehouses', 'wms_order_candidates.warehouse_id', '=', 'warehouses.id')
                ->where('wms_order_candidates.batch_code', $calcJob->batch_code)
                ->groupBy('wms_order_candidates.warehouse_id', 'warehouses.name')
                ->selectRaw('warehouses.name as warehouse_name, COUNT(*) as count')
                ->orderBy('warehouses.name')
                ->get()
                ->map(fn ($row) => [
                    'warehouse_name' => $row->warehouse_name,
                    'count' => $row->count,
                ])
                ->toArray();

            // 完了
            $progress->markAsCompleted($results, '発注候補の生成が完了しました');

            Log::info('Order candidate generation job completed', [
                'job_id' => $this->jobId,
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Order candidate generation job failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            $progress->markAsFailed($e->getMessage());

            throw $e;
        }
    }
}
