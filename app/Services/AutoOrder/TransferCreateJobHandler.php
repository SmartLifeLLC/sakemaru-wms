<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\AutoOrder\QueueJobLogLevel;
use App\Enums\AutoOrder\QueueJobType;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsQueueJob;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 移動候補作成ジョブハンドラ
 *
 * WMS管理画面の「移動追加」ボタンと同じ処理をQueue経由で実行
 */
class TransferCreateJobHandler
{
    public function __construct() {}

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
            Log::error('TransferCreateJobHandler failed', [
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
        $defaultArrivalDate = now()->addDay()->format('Y-m-d');
        $results = [];
        $successCount = 0;
        $skipCount = 0;

        foreach ($items as $itemData) {
            $result = $this->processItem($job, $itemData, $batchCode, $defaultArrivalDate);
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

        // デフォルトの移動出荷日
        $defaultArrivalDate = $payload['expected_arrival_date'] ?? now()->addDay()->format('Y-m-d');

        $results = [];
        $successCount = 0;
        $skipCount = 0;

        foreach ($items as $itemData) {
            $result = $this->processItem($job, $itemData, $batchCode, $defaultArrivalDate);
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

        // 新規ジョブを作成
        $newJob = WmsAutoOrderJobControl::startJob(
            processName: \App\Enums\AutoOrder\JobProcessName::ORDER_CALC,
            createdBy: null,
        );
        $newJob->markAsSuccess(0);
        $job->addLog(QueueJobLogLevel::INFO->value, '新規batch_codeを作成: '.$newJob->batch_code);

        return $newJob->batch_code;
    }

    /**
     * 個別アイテムを処理
     */
    private function processItem(WmsQueueJob $job, array $itemData, string $batchCode, string $defaultArrivalDate): array
    {
        $satelliteWarehouseId = $itemData['satellite_warehouse_id'] ?? null;
        $hubWarehouseId = $itemData['hub_warehouse_id'] ?? null;
        $itemId = $itemData['item_id'] ?? null;
        $transferQuantity = $itemData['transfer_quantity'] ?? 0;
        $expectedArrivalDate = $itemData['expected_arrival_date'] ?? $defaultArrivalDate;
        $deliveryCourseId = $itemData['delivery_course_id'] ?? null;
        $note = $itemData['note'] ?? null;

        // バリデーション: 必須フィールド
        if (! $satelliteWarehouseId || ! $hubWarehouseId || ! $itemId || $transferQuantity <= 0) {
            $reason = 'satellite_warehouse_id, hub_warehouse_id, item_id, transfer_quantity（1以上）は必須です';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", $itemData);

            return [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 依頼倉庫と移動元倉庫が同じ場合はエラー
        if ($satelliteWarehouseId === $hubWarehouseId) {
            $reason = '依頼倉庫と移動元倉庫を同じにすることはできません';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
            ]);

            return [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 依頼倉庫の存在確認
        $satelliteWarehouse = Warehouse::find($satelliteWarehouseId);
        if (! $satelliteWarehouse) {
            $reason = '依頼倉庫が存在しません';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", ['satellite_warehouse_id' => $satelliteWarehouseId]);

            return [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 移動元倉庫の存在確認
        $hubWarehouse = Warehouse::find($hubWarehouseId);
        if (! $hubWarehouse) {
            $reason = '移動元倉庫が存在しません';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", ['hub_warehouse_id' => $hubWarehouseId]);

            return [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
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
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 販売終了品チェック
        if ($item->end_of_sale_type !== 'NORMAL') {
            $reason = '販売終了品のため移動対象外です';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", ['item_id' => $itemId, 'end_of_sale_type' => $item->end_of_sale_type]);

            return [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 重複チェック
        $existsCandidate = WmsStockTransferCandidate::where('satellite_warehouse_id', $satelliteWarehouseId)
            ->where('hub_warehouse_id', $hubWarehouseId)
            ->where('item_id', $itemId)
            ->where('status', CandidateStatus::PENDING)
            ->exists();

        if ($existsCandidate) {
            $reason = 'この倉庫・商品の組み合わせは既に移動候補に存在します';
            $job->addLog(QueueJobLogLevel::WARNING->value, "アイテムをスキップ: {$reason}", [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
                'item_id' => $itemId,
            ]);

            return [
                'satellite_warehouse_id' => $satelliteWarehouseId,
                'hub_warehouse_id' => $hubWarehouseId,
                'item_id' => $itemId,
                'status' => 'skipped',
                'reason' => $reason,
            ];
        }

        // 移動候補を作成
        $candidate = WmsStockTransferCandidate::create([
            'batch_code' => $batchCode,
            'satellite_warehouse_id' => $satelliteWarehouseId,
            'hub_warehouse_id' => $hubWarehouseId,
            'item_id' => $itemId,
            'item_code' => $item->code,
            'search_code' => $searchCode = $this->getSearchCodeForItem($itemId),
            'ordering_code' => $searchCode ? str_pad($searchCode, 13, '0', STR_PAD_LEFT) : null,
            'contractor_id' => null,
            'delivery_course_id' => $deliveryCourseId,
            'suggested_quantity' => $transferQuantity,
            'transfer_quantity' => $transferQuantity,
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
            'warehouse_id' => $satelliteWarehouseId,
            'item_id' => $itemId,
            'calculation_type' => CalculationType::INTERNAL,
            'contractor_id' => null,
            'source_warehouse_id' => $hubWarehouseId,
            'current_effective_stock' => 0,
            'incoming_quantity' => 0,
            'safety_stock_setting' => 0,
            'lead_time_days' => 1,
            'calculated_shortage_qty' => $transferQuantity,
            'calculated_order_quantity' => $transferQuantity,
            'calculation_details' => [
                'queue_job_id' => $job->id,
                'queue_job_type' => QueueJobType::TRANSFER_CREATE->value,
                'source_system' => $job->source_system,
                'source_user_id' => $job->source_user_id,
                'source_reference_type' => $job->source_reference_type,
                'source_reference_id' => $job->source_reference_id,
                'created_at' => now()->toDateTimeString(),
                'formula' => 'Queue経由移動追加',
                'note' => $note,
            ],
        ]);

        $job->addLog(QueueJobLogLevel::INFO->value, '移動候補を作成しました', [
            'candidate_id' => $candidate->id,
            'satellite_warehouse_id' => $satelliteWarehouseId,
            'hub_warehouse_id' => $hubWarehouseId,
            'item_id' => $itemId,
            'transfer_quantity' => $transferQuantity,
        ]);

        return [
            'satellite_warehouse_id' => $satelliteWarehouseId,
            'hub_warehouse_id' => $hubWarehouseId,
            'item_id' => $itemId,
            'status' => 'created',
            'candidate_id' => $candidate->id,
        ];
    }

    /**
     * 待機中のtransfer_createジョブを処理
     */
    public function processPendingJobs(): array
    {
        $results = [];

        while ($job = WmsQueueJob::getPendingByType(QueueJobType::TRANSFER_CREATE)) {
            $result = $this->handle($job);
            $results[] = [
                'job_id' => $job->id,
                'result' => $result,
            ];
        }

        return $results;
    }

    private function getSearchCodeForItem(int $itemId): ?string
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->value('search_string');
    }
}
