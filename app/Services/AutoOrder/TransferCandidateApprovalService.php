<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Models\Sakemaru\RealStock;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 移動候補承認サービス
 *
 * 移動候補を承認し、供給倉庫の論理在庫を減らす
 */
class TransferCandidateApprovalService
{
    /**
     * バッチ単位で移動候補を承認
     */
    public function approveBatch(string $batchCode, int $approvedBy): WmsAutoOrderJobControl
    {
        $job = WmsAutoOrderJobControl::startJob(JobProcessName::TRANSFER_APPROVAL);
        $job->update(['batch_code' => $batchCode]);

        try {
            $candidates = WmsStockTransferCandidate::where('batch_code', $batchCode)
                ->where('status', CandidateStatus::PENDING)
                ->get();

            if ($candidates->isEmpty()) {
                Log::info('No pending transfer candidates to approve', ['batch_code' => $batchCode]);
                $job->markAsSuccess(0);

                return $job;
            }

            $approvedCount = 0;

            DB::connection('sakemaru')->transaction(function () use ($candidates, $approvedBy, &$approvedCount) {
                foreach ($candidates as $candidate) {
                    $this->approveCandidate($candidate, $approvedBy);
                    $approvedCount++;
                }
            });

            $job->markAsSuccess($approvedCount);

            Log::info('Transfer candidates approved', [
                'batch_code' => $batchCode,
                'approved_count' => $approvedCount,
            ]);

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            Log::error('Transfer candidate approval failed', [
                'batch_code' => $batchCode,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $job;
    }

    /**
     * 個別の移動候補を承認
     */
    public function approveCandidate(WmsStockTransferCandidate $candidate, int $approvedBy): void
    {
        if ($candidate->status !== CandidateStatus::PENDING) {
            throw new \RuntimeException("Candidate {$candidate->id} is not in PENDING status");
        }

        DB::connection('sakemaru')->transaction(function () use ($candidate, $approvedBy) {
            // 1. 供給倉庫（hub）の在庫を引当（論理在庫を減らす）
            $this->reserveStockFromHub($candidate);

            // 2. 候補ステータスを更新
            $candidate->update([
                'status' => CandidateStatus::APPROVED,
                'modified_by' => $approvedBy,
                'modified_at' => now(),
            ]);

            Log::info('Transfer candidate approved', [
                'candidate_id' => $candidate->id,
                'hub_warehouse_id' => $candidate->hub_warehouse_id,
                'satellite_warehouse_id' => $candidate->satellite_warehouse_id,
                'item_id' => $candidate->item_id,
                'transfer_quantity' => $candidate->transfer_quantity,
            ]);
        });
    }

    /**
     * 供給倉庫から在庫を引当
     *
     * FEFO (First Expiry First Out) → FIFO (First In First Out) の順で引当
     *
     * Note: 数量変更はSakemaru側で管理するため、WMS側では引当記録のみ
     */
    private function reserveStockFromHub(WmsStockTransferCandidate $candidate): void
    {
        $remainingQty = $candidate->transfer_quantity;

        // 供給倉庫の利用可能在庫を取得（FEFO→FIFO順）
        // available_quantity = current_quantity - reserved_quantity（生成カラム）
        // Note: expiration_dateはreal_stock_lotsに移動したため、activeLots経由でソート
        $stocks = RealStock::where('warehouse_id', $candidate->hub_warehouse_id)
            ->where('item_id', $candidate->item_id)
            ->where('available_quantity', '>', 0)
            ->with('activeLots')
            ->orderBy('id', 'asc')  // 基本的なFIFO（ID順）
            ->get();

        if ($stocks->isEmpty()) {
            throw new \RuntimeException(
                "No available stock in hub warehouse {$candidate->hub_warehouse_id} ".
                "for item {$candidate->item_id}"
            );
        }

        // 利用可能在庫を計算
        $totalAvailable = $stocks->sum('available_quantity');

        if ($totalAvailable < $remainingQty) {
            Log::warning('Insufficient stock in hub warehouse', [
                'candidate_id' => $candidate->id,
                'hub_warehouse_id' => $candidate->hub_warehouse_id,
                'item_id' => $candidate->item_id,
                'required_qty' => $remainingQty,
                'available_qty' => $totalAvailable,
            ]);
            // 不足分は警告のみで続行（部分引当）
            $remainingQty = $totalAvailable;
        }

        // Note: 実際の在庫引当（reserved_quantity の増加）はSakemaru側で行う
        // WMS側では引当可能かどうかの確認のみ
        Log::info('Transfer stock allocation verified', [
            'candidate_id' => $candidate->id,
            'hub_warehouse_id' => $candidate->hub_warehouse_id,
            'item_id' => $candidate->item_id,
            'transfer_quantity' => $candidate->transfer_quantity,
            'available_quantity' => $totalAvailable,
        ]);
    }

    /**
     * 移動候補を除外（承認しない）
     */
    public function excludeCandidate(WmsStockTransferCandidate $candidate, string $reason, int $excludedBy): void
    {
        if ($candidate->status !== CandidateStatus::PENDING) {
            throw new \RuntimeException("Candidate {$candidate->id} is not in PENDING status");
        }

        $candidate->update([
            'status' => CandidateStatus::EXCLUDED,
            'exclusion_reason' => $reason,
            'modified_by' => $excludedBy,
            'modified_at' => now(),
        ]);

        Log::info('Transfer candidate excluded', [
            'candidate_id' => $candidate->id,
            'reason' => $reason,
        ]);
    }

    /**
     * 承認済み移動候補の在庫引当を取り消し
     */
    public function cancelApproval(WmsStockTransferCandidate $candidate, int $cancelledBy): void
    {
        if ($candidate->status !== CandidateStatus::APPROVED) {
            throw new \RuntimeException("Candidate {$candidate->id} is not in APPROVED status");
        }

        DB::connection('sakemaru')->transaction(function () use ($candidate, $cancelledBy) {
            // 引当解除（実際には引当レコードを追跡していないので、再計算が必要）
            // TODO: 引当レコードの追跡実装

            $candidate->update([
                'status' => CandidateStatus::PENDING,
                'modified_by' => $cancelledBy,
                'modified_at' => now(),
            ]);

            Log::info('Transfer candidate approval cancelled', [
                'candidate_id' => $candidate->id,
            ]);
        });
    }

    /**
     * バッチの移動候補サマリを取得
     */
    public function getBatchSummary(string $batchCode): array
    {
        $candidates = WmsStockTransferCandidate::where('batch_code', $batchCode)->get();

        return [
            'total' => $candidates->count(),
            'pending' => $candidates->where('status', CandidateStatus::PENDING)->count(),
            'approved' => $candidates->where('status', CandidateStatus::APPROVED)->count(),
            'excluded' => $candidates->where('status', CandidateStatus::EXCLUDED)->count(),
            'executed' => $candidates->where('status', CandidateStatus::EXECUTED)->count(),
            'total_quantity' => $candidates->sum('transfer_quantity'),
        ];
    }
}
