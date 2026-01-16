<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 移動候補確定サービス
 *
 * 承認済みの移動候補を確定し、stock_transfer_queueを作成する
 * 基幹システム（sakemaru-ai-core）が stock_transfer_queue を処理して
 * 実際の移動伝票（stock_transfers）を生成する
 */
class TransferCandidateExecutionService
{
    /**
     * 移動候補を確定し、stock_transfer_queueを作成
     *
     * @param  WmsStockTransferCandidate  $candidate  移動候補
     * @param  int  $executedBy  確定者ID
     * @return int|null 作成されたqueue ID
     */
    public function executeCandidate(WmsStockTransferCandidate $candidate, int $executedBy): ?int
    {
        if ($candidate->status !== CandidateStatus::APPROVED) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} must be APPROVED before execution. Current status: {$candidate->status->value}"
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $executedBy) {
            // 1. stock_transfer_queueを作成
            $queueId = $this->createStockTransferQueue($candidate);

            // 2. 候補ステータスを更新
            $candidate->update([
                'status' => CandidateStatus::EXECUTED,
                'modified_by' => $executedBy,
                'modified_at' => now(),
            ]);

            Log::info('Transfer candidate executed and stock_transfer_queue created', [
                'candidate_id' => $candidate->id,
                'queue_id' => $queueId,
                'hub_warehouse_id' => $candidate->hub_warehouse_id,
                'satellite_warehouse_id' => $candidate->satellite_warehouse_id,
                'item_id' => $candidate->item_id,
                'transfer_quantity' => $candidate->transfer_quantity,
            ]);

            return $queueId;
        });
    }

    /**
     * バッチ単位で移動候補を確定
     *
     * @param  string  $batchCode  バッチコード
     * @param  int  $executedBy  確定者ID
     * @return Collection 作成されたqueue IDのコレクション
     */
    public function executeBatch(string $batchCode, int $executedBy): Collection
    {
        $candidates = WmsStockTransferCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('No approved transfer candidates to execute', ['batch_code' => $batchCode]);

            return collect();
        }

        $queueIds = collect();
        $executedCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $queueId = $this->executeCandidate($candidate, $executedBy);
                if ($queueId) {
                    $queueIds->push($queueId);
                }
                $executedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to execute transfer candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
                // 個別のエラーはスキップして続行
            }
        }

        Log::info('Batch transfer execution completed', [
            'batch_code' => $batchCode,
            'executed_count' => $executedCount,
            'total_approved' => $candidates->count(),
            'queue_count' => $queueIds->count(),
        ]);

        return $queueIds;
    }

    /**
     * stock_transfer_queueレコードを作成
     *
     * @param  WmsStockTransferCandidate  $candidate  移動候補
     * @return int 作成されたqueue ID
     */
    private function createStockTransferQueue(WmsStockTransferCandidate $candidate): int
    {
        // 倉庫情報を取得
        $hubWarehouse = $candidate->hubWarehouse;
        $satelliteWarehouse = $candidate->satelliteWarehouse;

        if (! $hubWarehouse || ! $satelliteWarehouse) {
            throw new \RuntimeException(
                "Warehouse not found: hub={$candidate->hub_warehouse_id}, satellite={$candidate->satellite_warehouse_id}"
            );
        }

        // 商品情報を取得
        $item = $candidate->item;
        if (! $item) {
            throw new \RuntimeException("Item not found: {$candidate->item_id}");
        }

        // items配列を作成
        $items = [
            [
                'item_code' => $item->code,
                'quantity' => $candidate->transfer_quantity,
                'quantity_type' => $candidate->quantity_type->value,
                'stock_allocation_code' => '1', // デフォルトの在庫区分コード（通常在庫）
                'note' => "移動候補ID: {$candidate->id}",
            ],
        ];

        // request_idは候補IDを使用（ユニーク制約対応）
        $requestId = "transfer-{$candidate->id}";

        // 処理日は入荷予定日を使用
        $processDate = $candidate->expected_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
            'client_id' => config('app.client_id'),
            'slip_number' => null, // 自動採番
            'process_date' => $processDate,
            'delivered_date' => $processDate,
            'note' => "自動発注移動 バッチ:{$candidate->batch_code}",
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'from_warehouse_code' => $hubWarehouse->code,    // 移動元（Hub）
            'to_warehouse_code' => $satelliteWarehouse->code, // 移動先（Satellite）
            'delivery_course_id' => $candidate->delivery_course_id, // 配送コースID
            'request_id' => $requestId,
            'status' => 'BEFORE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Stock transfer queue created for transfer candidate', [
            'queue_id' => $queueId,
            'candidate_id' => $candidate->id,
            'request_id' => $requestId,
            'from_warehouse' => $hubWarehouse->code,
            'to_warehouse' => $satelliteWarehouse->code,
            'delivery_course_id' => $candidate->delivery_course_id,
            'transfer_quantity' => $candidate->transfer_quantity,
        ]);

        return $queueId;
    }

    /**
     * 複数の移動候補を一括確定
     *
     * @param  array  $candidateIds  候補IDの配列
     * @param  int  $executedBy  確定者ID
     * @return Collection 作成されたqueue IDのコレクション
     */
    public function executeMultiple(array $candidateIds, int $executedBy): Collection
    {
        $candidates = WmsStockTransferCandidate::whereIn('id', $candidateIds)
            ->where('status', CandidateStatus::APPROVED)
            ->get();

        $queueIds = collect();

        foreach ($candidates as $candidate) {
            try {
                $queueId = $this->executeCandidate($candidate, $executedBy);
                if ($queueId) {
                    $queueIds->push($queueId);
                }
            } catch (\Exception $e) {
                Log::error('Failed to execute transfer candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $queueIds;
    }

    /**
     * 全ての承認済み移動候補を一括確定（グループ化してqueue作成）
     *
     * hub_warehouse + satellite_warehouse + delivery_course_id でグループ化し、
     * 1グループ1queueで複数商品をまとめて作成する
     *
     * @param  int  $executedBy  確定者ID
     * @return array{queue_count: int, candidate_count: int, errors: array}
     */
    public function executeAllApprovedGrouped(int $executedBy): array
    {
        $candidates = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED)
            ->with(['hubWarehouse', 'satelliteWarehouse', 'item'])
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('No approved transfer candidates to execute');

            return [
                'queue_count' => 0,
                'candidate_count' => 0,
                'errors' => [],
            ];
        }

        // hub_warehouse + satellite_warehouse + delivery_course_id でグループ化
        $grouped = $candidates->groupBy(function ($candidate) {
            return "{$candidate->hub_warehouse_id}_{$candidate->satellite_warehouse_id}_{$candidate->delivery_course_id}";
        });

        $queueCount = 0;
        $candidateCount = 0;
        $errors = [];

        return DB::connection('sakemaru')->transaction(function () use ($grouped, $executedBy, &$queueCount, &$candidateCount, &$errors) {
            foreach ($grouped as $groupKey => $groupCandidates) {
                try {
                    $queueId = $this->createGroupedStockTransferQueue($groupCandidates, $executedBy);

                    // グループ内の全候補をEXECUTEDに更新
                    foreach ($groupCandidates as $candidate) {
                        $candidate->update([
                            'status' => CandidateStatus::EXECUTED,
                            'modified_by' => $executedBy,
                            'modified_at' => now(),
                        ]);
                        $candidateCount++;
                    }

                    $queueCount++;

                    Log::info('Grouped transfer candidates executed', [
                        'group_key' => $groupKey,
                        'queue_id' => $queueId,
                        'candidate_count' => $groupCandidates->count(),
                    ]);
                } catch (\Exception $e) {
                    $errors[] = [
                        'group_key' => $groupKey,
                        'error' => $e->getMessage(),
                    ];
                    Log::error('Failed to execute grouped transfer candidates', [
                        'group_key' => $groupKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'queue_count' => $queueCount,
                'candidate_count' => $candidateCount,
                'errors' => $errors,
            ];
        });
    }

    /**
     * グループ化された移動候補からstock_transfer_queueを作成
     *
     * @param  Collection  $candidates  同一グループの移動候補
     * @param  int  $executedBy  確定者ID
     * @return int 作成されたqueue ID
     */
    private function createGroupedStockTransferQueue(Collection $candidates, int $executedBy): int
    {
        $firstCandidate = $candidates->first();
        $hubWarehouse = $firstCandidate->hubWarehouse;
        $satelliteWarehouse = $firstCandidate->satelliteWarehouse;

        if (! $hubWarehouse || ! $satelliteWarehouse) {
            throw new \RuntimeException(
                "Warehouse not found: hub={$firstCandidate->hub_warehouse_id}, satellite={$firstCandidate->satellite_warehouse_id}"
            );
        }

        // 全候補の商品をitems配列に統合
        $items = [];
        $candidateIds = [];
        foreach ($candidates as $candidate) {
            $item = $candidate->item;
            if (! $item) {
                throw new \RuntimeException("Item not found: {$candidate->item_id}");
            }

            $items[] = [
                'item_code' => $item->code,
                'quantity' => $candidate->transfer_quantity,
                'quantity_type' => $candidate->quantity_type->value,
                'stock_allocation_code' => '1',
                'note' => "移動候補ID: {$candidate->id}",
            ];
            $candidateIds[] = $candidate->id;
        }

        // request_idは複数候補IDを連結（ユニーク制約対応）
        $requestId = 'transfer-group-'.implode('-', $candidateIds);
        if (strlen($requestId) > 100) {
            // 長すぎる場合はハッシュ化
            $requestId = 'transfer-group-'.md5(implode('-', $candidateIds));
        }

        // 処理日は最初の候補の入荷予定日を使用
        $processDate = $firstCandidate->expected_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        // バッチコードを収集
        $batchCodes = $candidates->pluck('batch_code')->unique()->implode(',');

        $queueId = DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
            'client_id' => config('app.client_id'),
            'slip_number' => null,
            'process_date' => $processDate,
            'delivered_date' => $processDate,
            'note' => "自動発注移動 バッチ:{$batchCodes} ({$candidates->count()}件)",
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'from_warehouse_code' => $hubWarehouse->code,
            'to_warehouse_code' => $satelliteWarehouse->code,
            'delivery_course_id' => $firstCandidate->delivery_course_id,
            'request_id' => $requestId,
            'status' => 'BEFORE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Grouped stock transfer queue created', [
            'queue_id' => $queueId,
            'candidate_ids' => $candidateIds,
            'request_id' => $requestId,
            'from_warehouse' => $hubWarehouse->code,
            'to_warehouse' => $satelliteWarehouse->code,
            'delivery_course_id' => $firstCandidate->delivery_course_id,
            'item_count' => count($items),
        ]);

        return $queueId;
    }
}
