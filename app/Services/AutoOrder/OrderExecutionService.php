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
 * 発注候補を確定（EXECUTED）し、入庫予定を作成する
 */
class OrderExecutionService
{
    /**
     * 発注候補を確定し、入庫予定を作成
     *
     * @param  WmsOrderCandidate  $candidate  発注候補
     * @param  int  $executedBy  確定者ID
     * @return WmsOrderIncomingSchedule 作成された入庫予定
     */
    public function executeCandidate(WmsOrderCandidate $candidate, int $executedBy): WmsOrderIncomingSchedule
    {
        if ($candidate->status !== CandidateStatus::APPROVED) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} must be APPROVED before execution. Current status: {$candidate->status->value}"
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $executedBy) {
            // 1. 発注候補のステータスを更新
            $candidate->update([
                'status' => CandidateStatus::EXECUTED,
                'modified_by' => $executedBy,
                'modified_at' => now(),
            ]);

            // 2. 入庫予定を作成
            $incomingSchedule = WmsOrderIncomingSchedule::create([
                'warehouse_id' => $candidate->warehouse_id,
                'item_id' => $candidate->item_id,
                'contractor_id' => $candidate->contractor_id,
                'supplier_id' => $this->getSupplierIdFromCandidate($candidate),
                'order_candidate_id' => $candidate->id,
                'order_source' => OrderSource::AUTO,
                'expected_quantity' => $candidate->order_quantity,
                'received_quantity' => 0,
                'quantity_type' => $candidate->quantity_type,
                'order_date' => now()->format('Y-m-d'),
                'expected_arrival_date' => $candidate->expected_arrival_date,
                'status' => IncomingScheduleStatus::PENDING,
            ]);

            Log::info('Order candidate executed and incoming schedule created', [
                'candidate_id' => $candidate->id,
                'incoming_schedule_id' => $incomingSchedule->id,
                'warehouse_id' => $candidate->warehouse_id,
                'item_id' => $candidate->item_id,
                'quantity' => $candidate->order_quantity,
                'expected_arrival_date' => $candidate->expected_arrival_date,
            ]);

            return $incomingSchedule;
        });
    }

    /**
     * バッチ単位で発注候補を確定
     *
     * @param  string  $batchCode  バッチコード
     * @param  int  $executedBy  確定者ID
     * @return Collection 作成された入庫予定のコレクション
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

        foreach ($candidates as $candidate) {
            try {
                $schedule = $this->executeCandidate($candidate, $executedBy);
                $incomingSchedules->push($schedule);
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
            'executed_count' => $incomingSchedules->count(),
            'total_approved' => $candidates->count(),
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
        $incomingSchedule = WmsOrderIncomingSchedule::create([
            'warehouse_id' => $data['warehouse_id'],
            'item_id' => $data['item_id'],
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
}
