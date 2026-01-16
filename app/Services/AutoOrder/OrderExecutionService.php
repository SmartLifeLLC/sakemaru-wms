<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 発注確定サービス
 *
 * 発注候補を確定（CONFIRMED）し、入庫予定を作成する
 * demand_breakdownがある場合は、各倉庫ごとに入庫予定を作成
 * 送信時にEXECUTEDに変更する
 */
class OrderExecutionService
{
    public function __construct(
        private readonly OrderAuditService $auditService
    ) {}
    /**
     * 発注候補を確定し、入庫予定を作成（何回でも実行可能）
     *
     * 既存の入庫予定があれば削除して再作成する
     * ステータスはCONFIRMEDに変更（APPROVEDまたはCONFIRMEDから）
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @param  int  $confirmedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     */
    public function confirmCandidate(WmsOrderCandidate $candidate, int $confirmedBy): Collection
    {
        if (! $candidate->status->canConfirm()) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} cannot be confirmed. Current status: {$candidate->status->value}"
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $confirmedBy) {
            // 1. 既存の入庫予定を削除（PENDING状態のもののみ）
            $deletedCount = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)
                ->where('status', IncomingScheduleStatus::PENDING)
                ->delete();

            if ($deletedCount > 0) {
                Log::info('Deleted existing incoming schedules for re-confirmation', [
                    'candidate_id' => $candidate->id,
                    'deleted_count' => $deletedCount,
                ]);
            }

            // 2. 発注候補のステータスを更新
            $candidate->update([
                'status' => CandidateStatus::CONFIRMED,
                'modified_by' => $confirmedBy,
                'modified_at' => now(),
            ]);

            // 3. 監査ログ
            $this->auditService->logConfirmation($candidate);

            // 4. 入庫予定を作成（demand_breakdownの有無で分岐）
            $incomingSchedules = $this->createIncomingSchedulesFromCandidate($candidate);

            Log::info('Order candidate confirmed and incoming schedules created', [
                'candidate_id' => $candidate->id,
                'schedule_count' => $incomingSchedules->count(),
                'warehouse_id' => $candidate->warehouse_id,
                'item_id' => $candidate->item_id,
                'total_quantity' => $candidate->order_quantity,
                'expected_arrival_date' => $candidate->expected_arrival_date,
            ]);

            return $incomingSchedules;
        });
    }

    /**
     * バッチ単位で発注候補を確定（入庫予定作成）
     *
     * @param  string  $batchCode  バッチコード
     * @param  int  $confirmedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     */
    public function confirmBatch(string $batchCode, int $confirmedBy): Collection
    {
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->whereIn('status', [CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('No candidates to confirm', ['batch_code' => $batchCode]);

            return collect();
        }

        $incomingSchedules = collect();
        $confirmedCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $schedules = $this->confirmCandidate($candidate, $confirmedBy);
                $incomingSchedules = $incomingSchedules->merge($schedules);
                $confirmedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to confirm candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Batch confirmation completed', [
            'batch_code' => $batchCode,
            'confirmed_count' => $confirmedCount,
            'total_candidates' => $candidates->count(),
            'incoming_schedule_count' => $incomingSchedules->count(),
        ]);

        return $incomingSchedules;
    }

    /**
     * 発注候補を送信済みに変更（入庫予定を作成）
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @param  int  $executedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     *
     * @deprecated Use confirmCandidate() instead for confirmation, this is for transmission only
     */
    public function executeCandidate(WmsOrderCandidate $candidate, int $executedBy): Collection
    {
        if (! in_array($candidate->status, [CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} must be APPROVED or CONFIRMED before execution. Current status: {$candidate->status->value}"
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $executedBy) {
            // 1. 発注候補のステータスを更新
            $candidate->update([
                'status' => CandidateStatus::EXECUTED,
                'modified_by' => $executedBy,
                'modified_at' => now(),
            ]);

            // 2. 入庫予定を作成（demand_breakdownの有無で分岐）
            $incomingSchedules = $this->createIncomingSchedulesFromCandidate($candidate);

            Log::info('Order candidate executed and incoming schedules created', [
                'candidate_id' => $candidate->id,
                'schedule_count' => $incomingSchedules->count(),
                'warehouse_id' => $candidate->warehouse_id,
                'item_id' => $candidate->item_id,
                'total_quantity' => $candidate->order_quantity,
                'expected_arrival_date' => $candidate->expected_arrival_date,
            ]);

            return $incomingSchedules;
        });
    }

    /**
     * 発注候補から入庫予定を作成
     *
     * demand_breakdownがある場合は各倉庫ごとに作成
     * ない場合は従来通り発注元倉庫に一括作成
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @return Collection<WmsOrderIncomingSchedule>
     */
    private function createIncomingSchedulesFromCandidate(WmsOrderCandidate $candidate): Collection
    {
        $incomingSchedules = collect();
        $supplierId = $this->getSupplierIdFromCandidate($candidate);
        $searchCode = $this->getSearchCodeForItem($candidate->item_id);

        // demand_breakdownがある場合は各倉庫ごとに入庫予定を作成
        if (! empty($candidate->demand_breakdown)) {
            foreach ($candidate->demand_breakdown as $breakdown) {
                $warehouseId = $breakdown['warehouse_id'];
                $quantity = $breakdown['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                $schedule = WmsOrderIncomingSchedule::create([
                    'warehouse_id' => $warehouseId,
                    'item_id' => $candidate->item_id,
                    'search_code' => $searchCode,
                    'contractor_id' => $candidate->contractor_id,
                    'supplier_id' => $supplierId,
                    'order_candidate_id' => $candidate->id,
                    'order_source' => OrderSource::AUTO,
                    'expected_quantity' => $quantity,
                    'received_quantity' => 0,
                    'quantity_type' => $candidate->quantity_type,
                    'order_date' => now()->format('Y-m-d'),
                    'expected_arrival_date' => $candidate->expected_arrival_date,
                    'status' => IncomingScheduleStatus::PENDING,
                ]);

                $incomingSchedules->push($schedule);

                Log::debug('Incoming schedule created for warehouse breakdown', [
                    'candidate_id' => $candidate->id,
                    'schedule_id' => $schedule->id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $quantity,
                ]);
            }
        } else {
            // demand_breakdownがない場合は従来通り発注元倉庫に入庫予定を作成
            $schedule = WmsOrderIncomingSchedule::create([
                'warehouse_id' => $candidate->warehouse_id,
                'item_id' => $candidate->item_id,
                'search_code' => $searchCode,
                'contractor_id' => $candidate->contractor_id,
                'supplier_id' => $supplierId,
                'order_candidate_id' => $candidate->id,
                'order_source' => OrderSource::AUTO,
                'expected_quantity' => $candidate->order_quantity,
                'received_quantity' => 0,
                'quantity_type' => $candidate->quantity_type,
                'order_date' => now()->format('Y-m-d'),
                'expected_arrival_date' => $candidate->expected_arrival_date,
                'status' => IncomingScheduleStatus::PENDING,
            ]);

            $incomingSchedules->push($schedule);
        }

        return $incomingSchedules;
    }

    /**
     * バッチ単位で発注候補を確定
     *
     * @param  string  $batchCode  バッチコード
     * @param  int  $executedBy  確定者ID
     * @return Collection<WmsOrderIncomingSchedule> 作成された入庫予定のコレクション
     */
    public function executeBatch(string $batchCode, int $executedBy): Collection
    {
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->get();

        if ($candidates->isEmpty()) {
            Log::info('No approved candidates to execute', ['batch_code' => $batchCode]);

            return collect();
        }

        $incomingSchedules = collect();
        $executedCandidateCount = 0;

        foreach ($candidates as $candidate) {
            try {
                $schedules = $this->executeCandidate($candidate, $executedBy);
                $incomingSchedules = $incomingSchedules->merge($schedules);
                $executedCandidateCount++;
            } catch (\Exception $e) {
                Log::error('Failed to execute candidate', [
                    'candidate_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
                // 個別のエラーはスキップして続行
            }
        }

        Log::info('Batch execution completed', [
            'batch_code' => $batchCode,
            'executed_candidate_count' => $executedCandidateCount,
            'total_approved' => $candidates->count(),
            'incoming_schedule_count' => $incomingSchedules->count(),
        ]);

        return $incomingSchedules;
    }

    /**
     * 手動発注から入庫予定を作成
     *
     * @param  array  $data  発注データ
     * @param  int  $createdBy  作成者ID
     */
    public function createManualIncomingSchedule(array $data, int $createdBy): WmsOrderIncomingSchedule
    {
        $searchCode = $this->getSearchCodeForItem($data['item_id']);

        $incomingSchedule = WmsOrderIncomingSchedule::create([
            'warehouse_id' => $data['warehouse_id'],
            'item_id' => $data['item_id'],
            'search_code' => $searchCode,
            'contractor_id' => $data['contractor_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'manual_order_number' => $data['order_number'] ?? null,
            'order_source' => OrderSource::MANUAL,
            'expected_quantity' => $data['expected_quantity'],
            'received_quantity' => 0,
            'quantity_type' => $data['quantity_type'] ?? 'PIECE',
            'order_date' => $data['order_date'] ?? now()->format('Y-m-d'),
            'expected_arrival_date' => $data['expected_arrival_date'],
            'status' => IncomingScheduleStatus::PENDING,
            'note' => $data['note'] ?? null,
        ]);

        Log::info('Manual incoming schedule created', [
            'incoming_schedule_id' => $incomingSchedule->id,
            'warehouse_id' => $data['warehouse_id'],
            'item_id' => $data['item_id'],
            'quantity' => $data['expected_quantity'],
            'created_by' => $createdBy,
        ]);

        return $incomingSchedule;
    }

    /**
     * 発注候補から仕入先IDを取得
     */
    private function getSupplierIdFromCandidate(WmsOrderCandidate $candidate): ?int
    {
        // item_contractors から supplier_id を取得
        $itemContractor = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $candidate->warehouse_id)
            ->where('item_id', $candidate->item_id)
            ->where('contractor_id', $candidate->contractor_id)
            ->first();

        return $itemContractor?->supplier_id;
    }

    /**
     * 商品IDから検索コードを取得
     *
     * item_search_information.search_string をカンマ区切りで連結
     */
    private function getSearchCodeForItem(int $itemId): ?string
    {
        $searchStrings = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->orderBy('priority')
            ->pluck('search_string')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return ! empty($searchStrings) ? implode(',', $searchStrings) : null;
    }
}
