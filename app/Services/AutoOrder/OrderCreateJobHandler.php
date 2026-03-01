<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\AutoOrder\QueueJobLogLevel;
use App\Enums\AutoOrder\QueueJobType;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 発注候補作成ジョブハンドラ
 *
 * WMS管理画面の「発注追加」ボタンと同じ処理をQueue経由で実行
 */
class OrderCreateJobHandler
{
    public function __construct(
        private StockSnapshotService $snapshotService,
        private ContractorLeadTimeService $leadTimeService
    ) {}

    /**
     * ジョブを処理
     */
    public function handle(WmsQueueJob $job): array
    {
        $job->markAsProcessing();
        $job->addLog(QueueJobLogLevel::INFO->value, 'ジョブ処理を開始');

        $payload = $job->payload;
        $items = $payload['items'] ?? [];

        if (empty($items)) {
            $job->addLog(QueueJobLogLevel::ERROR->value, 'payloadにitemsが含まれていません');
            $job->markAsFailed('payloadにitemsが含まれていません');

            return ['success' => false, 'error' => 'No items in payload'];
        }

        try {
            $result = DB::connection('sakemaru')->transaction(function () use ($job, $items, $payload) {
                return $this->processItems($job, $items, $payload);
            });

            if ($result['success_count'] > 0) {
                $job->markAsCompleted($result);
                $job->addLog(QueueJobLogLevel::INFO->value, 'ジョブ処理が完了しました', $result);
            } else {
                $job->markAsFailed('全てのアイテムがスキップされました', $result);
                $job->addLog(QueueJobLogLevel::WARNING->value, '全てのアイテムがスキップされました', $result);
            }

            return $result;

        } catch (\Exception $e) {
            $job->addLog(QueueJobLogLevel::ERROR->value, 'ジョブ処理でエラーが発生: '.$e->getMessage());
            $job->markAsFailed($e->getMessage());
            Log::error('OrderCreateJobHandler failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * DemandDistributionJobHandler から委譲される公開メソッド
     */
    public function handleItems(WmsQueueJob $job, array $items): array
    {
        $batchCode = $this->getOrCreateBatchCode($job);
        $results = [];
        $successCount = 0;
        $skipCount = 0;

        foreach ($items as $itemData) {
            $result = $this->processItem($job, $itemData, $batchCode);
            $results[] = $result;
            if ($result['status'] === 'created') {
                $successCount++;
            } else {
                $skipCount++;
            }
        }

        return [
            'batch_code' => $batchCode,
            'success_count' => $successCount,
            'skip_count' => $skipCount,
            'results' => $results,
        ];
    }

    /**
     * アイテムを処理
     */
    private function processItems(WmsQueueJob $job, array $items, array $payload): array
    {
        // batch_code を取得または作成
        $batchCode = $this->getOrCreateBatchCode($job);

        $results = [];
        $successCount = 0;
        $skipCount = 0;

        foreach ($items as $itemData) {
            $result = $this->processItem($job, $itemData, $batchCode);
            $results[] = $result;

            if ($result['status'] === 'created') {
                $successCount++;
            } else {
                $skipCount++;
            }
        }

        return [
            'batch_code' => $batchCode,
            'total_items' => count($items),
            'success_count' => $successCount,
            'skip_count' => $skipCount,
            'demand_request_id' => $payload['demand_request_id'] ?? null,
            'results' => $results,
        ];
    }

    /**
     * batch_code を取得または作成
     */
    private function getOrCreateBatchCode(WmsQueueJob $job): string
    {
        // 確定待ち（PENDING）のジョブを検索し、あればそのbatch_codeを使用
        $pendingJob = WmsAutoOrderJobControl::findPendingSettlement();

        if ($pendingJob) {
            $job->addLog(QueueJobLogLevel::INFO->value, '既存のbatch_codeを使用: '.$pendingJob->batch_code);

            return $pendingJob->batch_code;
        }

        // 新規スナップショットを生成（ジョブ管理も自動作成される）
        $snapshotJob = $this->snapshotService->generateAll();
        $job->addLog(QueueJobLogLevel::INFO->value, '新規batch_codeを作成: '.$snapshotJob->batch_code);

        return $snapshotJob->batch_code;
    }

    /**
     * 個別アイテムを処理
     */
    private function processItem(WmsQueueJob $job, array $itemData, string $batchCode): array
    {
        $warehouseId = $itemData['warehouse_id'] ?? null;
        $itemId = $itemData['item_id'] ?? null;
        $quantity = $itemData['quantity'] ?? 0;
        $note = $itemData['note'] ?? null;

        // バリデーション: 必須フィールド
        if (! $warehouseId || ! $itemId || $quantity <= 0) {
            $reason = 'warehouse_id, item_id, quantity（1以上）は必須です';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", $itemData);

            return [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 倉庫の存在確認
        $warehouse = Warehouse::find($warehouseId);
        if (! $warehouse) {
            $reason = '倉庫が存在しません';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", ['warehouse_id' => $warehouseId]);

            return [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 商品の存在確認
        $item = Item::find($itemId);
        if (! $item) {
            $reason = '商品が存在しません';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", ['item_id' => $itemId]);

            return [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 入数チェック
        $capacityCase = $item->capacity_case ?? 1;
        if ($quantity % $capacityCase !== 0) {
            $reason = "発注数量は入数({$capacityCase})の倍数である必要があります";
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'quantity' => $quantity,
                'capacity_case' => $capacityCase,
            ]);

            return [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 重複チェック
        $existsCandidate = WmsOrderCandidate::where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('status', CandidateStatus::PENDING)
            ->exists();

        if ($existsCandidate) {
            $reason = 'この倉庫・商品の組み合わせは既に発注候補に存在します';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
            ]);

            return [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 発注先を取得（仮想倉庫の場合は入庫倉庫で検索）
        $itemContractorWarehouseId = $warehouse->is_virtual
            ? ($warehouse->stock_warehouse_id ?? $warehouseId)
            : $warehouseId;

        $itemContractor = ItemContractor::where('warehouse_id', $itemContractorWarehouseId)
            ->where('item_id', $itemId)
            ->first();

        if (! $itemContractor) {
            $reason = '発注先が設定されていません';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'item_contractor_warehouse_id' => $itemContractorWarehouseId,
            ]);

            return [
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // リードタイムから入荷予定日を計算
        $contractor = Contractor::find($itemContractor->contractor_id);
        $arrivalInfo = $this->leadTimeService->calculateArrivalDate($contractor, now());
        $expectedArrivalDate = $arrivalInfo['arrival_date'];
        $leadTimeDays = $arrivalInfo['lead_time_days'] ?? 0;

        // 仕入先と仕入単価を取得
        $supplierId = $itemContractor->supplier_id;
        $item = Item::with('current_price')->find($itemId);
        $purchaseUnitPrice = $item?->current_price?->purchase_unit_price;

        // 発注候補を作成
        $candidate = WmsOrderCandidate::create([
            'batch_code' => $batchCode,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'contractor_id' => $itemContractor->contractor_id,
            'supplier_id' => $supplierId,
            'purchase_unit_price' => $purchaseUnitPrice,
            'self_shortage_qty' => 0,
            'satellite_demand_qty' => 0,
            'suggested_quantity' => $quantity,
            'order_quantity' => $quantity,
            'quantity_type' => QuantityType::PIECE,
            'expected_arrival_date' => $expectedArrivalDate,
            'original_arrival_date' => $expectedArrivalDate,
            'status' => CandidateStatus::PENDING,
            'lot_status' => LotStatus::RAW,
            'origin_type' => OriginType::DIST,
            'is_manually_modified' => true,
            'modified_by' => $job->source_user_id,
            'modified_at' => now(),
        ]);

        // 計算ログを作成（Queue経由として記録）
        WmsOrderCalculationLog::create([
            'batch_code' => $batchCode,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'calculation_type' => CalculationType::EXTERNAL,
            'contractor_id' => $itemContractor->contractor_id,
            'source_warehouse_id' => null,
            'current_effective_stock' => 0,
            'incoming_quantity' => 0,
            'safety_stock_setting' => 0,
            'lead_time_days' => $leadTimeDays,
            'calculated_shortage_qty' => $quantity,
            'calculated_order_quantity' => $quantity,
            'calculation_details' => [
                'queue_job_id' => $job->id,
                'queue_job_type' => QueueJobType::ORDER_CREATE->value,
                'source_system' => $job->source_system,
                'source_user_id' => $job->source_user_id,
                'source_reference_type' => $job->source_reference_type,
                'source_reference_id' => $job->source_reference_id,
                'created_at' => now()->toDateTimeString(),
                'formula' => 'Queue経由発注追加',
                'capacity_case' => $capacityCase,
                'total_pieces' => $quantity,
                'note' => $note,
            ],
        ]);

        $job->addLog(QueueJobLogLevel::INFO->value, '発注候補を作成しました', [
            'candidate_id' => $candidate->id,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'quantity' => $quantity,
        ]);

        return [
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'status' => 'created',
            'candidate_id' => $candidate->id,
        ];
    }

    /**
     * 待機中のorder_createジョブを処理
     */
    public function processPendingJobs(): array
    {
        $results = [];

        while ($job = WmsQueueJob::getPendingByType(QueueJobType::ORDER_CREATE)) {
            $result = $this->handle($job);
            $results[] = [
                'job_id' => $job->id,
                'result' => $result,
            ];
        }

        return $results;
    }
}
