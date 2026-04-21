<?php

namespace App\Jobs;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\ConfirmationLevel;
use App\Enums\AutoOrder\OriginType;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Services\AutoOrder\OrderCandidateCalculationService;
use App\Services\AutoOrder\OrderExecutionService;
use App\Services\AutoOrder\TransferCandidateExecutionService;
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
        public ?int $contractorId = null,
        public ?int $executionLogId = null,
        public bool $transferOnly = false,
        public ?int $warehouseId = null,
        public ?int $createdBy = null,
        public ?array $contractorIds = null,
        public ?string $batchCode = null,
        public ?string $originType = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        ini_set('memory_limit', '-1');

        $this->createdBy ??= \App\Models\Sakemaru\User::resolveAutomatorId();

        $progress = WmsQueueProgress::findByJobId($this->jobId);

        if (! $progress) {
            Log::error('Queue progress not found', ['job_id' => $this->jobId]);

            return;
        }

        try {
            // 全体で4ステップ
            // 1. 削除（10%）
            // 2. 発注候補計算（60%）
            // 3. 確定レベル適用（85%）
            // 4. 完了（100%）

            $progress->markAsProcessing(100, '処理を開始しています...');

            $results = [
                'deleted' => 0,
                'calculated' => 0,
                'batchCode' => null,
                'byWarehouse' => [],
            ];

            // Step 1: 未承認の候補を削除（オプション）
            if ($this->deletePending) {
                $progress->update([
                    'progress' => 5,
                    'message' => $this->transferOnly ? '未承認の移動候補を削除中...' : '未承認の発注候補を削除中...',
                ]);

                // 仕入先配列が指定されている場合、該当仕入先のみ削除
                $scopeContractorIds = $this->getExpandedContractorIds();

                if ($this->transferOnly) {
                    $deleteQuery = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING);
                    if ($this->warehouseId) {
                        $deleteQuery->where('satellite_warehouse_id', $this->warehouseId);
                    }
                    if ($scopeContractorIds) {
                        $deleteQuery->whereIn('contractor_id', $scopeContractorIds);
                    }
                    $results['deletedTransfers'] = $deleteQuery->delete();
                } else {
                    $deleteOrderQuery = WmsOrderCandidate::where('status', CandidateStatus::PENDING);
                    $deleteTransferQuery = WmsStockTransferCandidate::where('status', CandidateStatus::PENDING);
                    if ($this->warehouseId) {
                        $deleteOrderQuery->where('warehouse_id', $this->warehouseId);
                        $deleteTransferQuery->where('satellite_warehouse_id', $this->warehouseId);
                    }
                    if ($scopeContractorIds) {
                        $deleteOrderQuery->whereIn('contractor_id', $scopeContractorIds);
                        $deleteTransferQuery->whereIn('contractor_id', $scopeContractorIds);
                    }
                    $results['deleted'] = $deleteOrderQuery->delete();
                    $results['deletedTransfers'] = $deleteTransferQuery->delete();
                }
            }

            $progress->update([
                'progress' => 15,
                'message' => $this->transferOnly ? '移動候補を計算中...' : '発注候補を計算中...',
            ]);

            // Step 2: 発注候補計算（在庫はwms_v_stock_availableから直接読み込み）
            $calculationService = app(OrderCandidateCalculationService::class);
            $calcJob = $calculationService->calculate(
                contractorId: $this->contractorId,
                transferOnly: $this->transferOnly,
                warehouseId: $this->warehouseId,
                createdBy: $this->createdBy,
                contractorIds: $this->contractorIds,
                batchCode: $this->batchCode,
                originType: $this->originType ? OriginType::from($this->originType) : null,
            );

            $results['batchCode'] = $calcJob->batch_code;
            $results['calculated'] = $calcJob->processed_records;
            // 移動候補数・発注候補数をresult_dataから取得
            $results['transferCandidates'] = $calcJob->result_data['summary']['internal_candidates'] ?? 0;
            $results['orderCandidates'] = $calcJob->result_data['summary']['external_candidates'] ?? 0;

            // Step 5: 確定レベル自動適用
            $progress->update([
                'progress' => 85,
                'message' => '確定レベルを適用中...',
            ]);

            $this->applyConfirmationLevels($calcJob->batch_code);

            $progress->update([
                'progress' => 90,
                'message' => '集計中...',
            ]);

            // 倉庫別内訳を取得
            if ($this->transferOnly) {
                $results['byWarehouse'] = DB::connection('sakemaru')
                    ->table('wms_stock_transfer_candidates')
                    ->join('warehouses', 'wms_stock_transfer_candidates.satellite_warehouse_id', '=', 'warehouses.id')
                    ->where('wms_stock_transfer_candidates.batch_code', $calcJob->batch_code)
                    ->groupBy('wms_stock_transfer_candidates.satellite_warehouse_id', 'warehouses.name')
                    ->selectRaw('warehouses.name as warehouse_name, COUNT(*) as count')
                    ->orderBy('warehouses.name')
                    ->get()
                    ->map(fn ($row) => [
                        'warehouse_name' => $row->warehouse_name,
                        'count' => $row->count,
                    ])
                    ->toArray();
            } else {
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
            }

            // 完了
            $completionMessage = $this->transferOnly ? '移動候補の生成が完了しました' : '発注候補の生成が完了しました';
            $progress->markAsCompleted($results, $completionMessage);

            // 実行ログを更新（スケジューラー/手動再実行からの場合）
            if ($this->executionLogId) {
                $executionLog = WmsAutoOrderExecutionLog::find($this->executionLogId);
                $executionLog?->markAsSuccess($calcJob->id);
            }

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

            // 実行ログを更新（スケジューラー/手動再実行からの場合）
            if ($this->executionLogId) {
                $executionLog = WmsAutoOrderExecutionLog::find($this->executionLogId);
                $executionLog?->markAsFailed($e->getMessage());

                // スケジューラー経由: 例外を再throwしない（チェーン内の後続ジョブを止めない）
                return;
            }

            throw $e;
        }
    }

    /**
     * contractorIds を親+子に展開した配列を返す（未指定時はnull）
     */
    private function getExpandedContractorIds(): ?array
    {
        if ($this->contractorIds !== null && ! empty($this->contractorIds)) {
            $expanded = [];
            foreach ($this->contractorIds as $cId) {
                $expanded = array_merge($expanded, WmsContractorSetting::getContractorIdsWithChildren($cId));
            }

            return array_unique($expanded);
        }

        return null;
    }

    /**
     * 確定レベルに応じて候補を自動承認・自動確定
     *
     * 倉庫単位の設定（wms_warehouse_auto_order_settings）を参照する
     */
    private function applyConfirmationLevels(string $batchCode): void
    {
        // 倉庫別の確定レベル設定を一括取得
        $levels = WmsWarehouseAutoOrderSetting::all()
            ->keyBy('warehouse_id');

        if ($levels->isEmpty()) {
            Log::info('確定レベル設定なし、全候補STATUS1（候補表示のみ）として扱う');

            return;
        }

        // 発注候補の自動レベル適用
        $orderCandidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::PENDING)
            ->get();

        $orderExecutionService = app(OrderExecutionService::class);
        $transferExecutionService = app(TransferCandidateExecutionService::class);
        $systemUserId = 0; // システム自動実行

        foreach ($orderCandidates as $candidate) {
            $level = $levels[$candidate->warehouse_id]->confirmation_level ?? ConfirmationLevel::STATUS1;

            if ($level === ConfirmationLevel::STATUS1) {
                continue;
            }

            // STATUS2 or STATUS3: 自動承認
            $candidate->update(['status' => CandidateStatus::APPROVED]);

            if ($level === ConfirmationLevel::STATUS3) {
                // 自動確定（入荷予定作成含む）
                try {
                    $orderExecutionService->confirmCandidate($candidate, $systemUserId);
                } catch (\Exception $e) {
                    Log::warning('自動確定失敗（発注候補）、APPROVEDで停止', [
                        'candidate_id' => $candidate->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 移動候補の自動レベル適用
        $transferCandidates = WmsStockTransferCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::PENDING)
            ->get();

        foreach ($transferCandidates as $candidate) {
            $level = $levels[$candidate->satellite_warehouse_id]->confirmation_level ?? ConfirmationLevel::STATUS1;

            if ($level === ConfirmationLevel::STATUS1) {
                continue;
            }

            // STATUS2 or STATUS3: 自動承認
            $candidate->update(['status' => CandidateStatus::APPROVED]);

            if ($level === ConfirmationLevel::STATUS3) {
                // 自動確定（stock_transfer_queue作成含む）
                try {
                    $transferExecutionService->executeCandidate($candidate, $systemUserId);
                } catch (\Exception $e) {
                    Log::warning('自動確定失敗（移動候補）、APPROVEDで停止', [
                        'candidate_id' => $candidate->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('確定レベル自動適用完了', [
            'order_candidates' => $orderCandidates->count(),
            'transfer_candidates' => $transferCandidates->count(),
        ]);
    }
}
