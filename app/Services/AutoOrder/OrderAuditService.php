<?php

namespace App\Services\AutoOrder;

use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderCandidateAuditLog;
use Illuminate\Support\Facades\Log;

/**
 * 発注候補監査ログサービス
 *
 * 発注候補に対する変更を監査ログとして記録
 */
class OrderAuditService
{
    /**
     * ステータス変更をログに記録
     */
    public function logStatusChange(
        WmsOrderCandidate $candidate,
        string $oldStatus,
        string $newStatus,
        ?string $reason = null
    ): WmsOrderCandidateAuditLog {
        $action = $this->determineActionFromStatusChange($oldStatus, $newStatus);

        return $this->createLog($candidate, $action, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
        ]);
    }

    /**
     * 数量変更をログに記録
     */
    public function logQuantityChange(
        WmsOrderCandidate $candidate,
        int $oldQuantity,
        int $newQuantity
    ): WmsOrderCandidateAuditLog {
        return $this->createLog($candidate, WmsOrderCandidateAuditLog::ACTION_QUANTITY_CHANGED, [
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
        ]);
    }

    /**
     * 承認をログに記録
     */
    public function logApproval(WmsOrderCandidate $candidate): WmsOrderCandidateAuditLog
    {
        return $this->createLog($candidate, WmsOrderCandidateAuditLog::ACTION_APPROVED, [
            'old_status' => 'PENDING',
            'new_status' => 'APPROVED',
        ]);
    }

    /**
     * 除外をログに記録
     */
    public function logExclusion(WmsOrderCandidate $candidate, ?string $reason = null): WmsOrderCandidateAuditLog
    {
        return $this->createLog($candidate, WmsOrderCandidateAuditLog::ACTION_EXCLUDED, [
            'old_status' => $candidate->status->value,
            'new_status' => 'EXCLUDED',
            'reason' => $reason,
        ]);
    }

    /**
     * 発注確定をログに記録
     */
    public function logConfirmation(WmsOrderCandidate $candidate): WmsOrderCandidateAuditLog
    {
        return $this->createLog($candidate, WmsOrderCandidateAuditLog::ACTION_CONFIRMED, [
            'old_status' => $candidate->status->value,
            'new_status' => 'CONFIRMED',
        ]);
    }

    /**
     * 送信をログに記録
     */
    public function logTransmission(WmsOrderCandidate $candidate): WmsOrderCandidateAuditLog
    {
        return $this->createLog($candidate, WmsOrderCandidateAuditLog::ACTION_TRANSMITTED, [
            'old_status' => $candidate->status->value,
            'new_status' => 'EXECUTED',
        ]);
    }

    /**
     * 承認取消をログに記録
     */
    public function logApprovalCancellation(WmsOrderCandidate $candidate): WmsOrderCandidateAuditLog
    {
        return $this->createLog($candidate, WmsOrderCandidateAuditLog::ACTION_APPROVAL_CANCELLED, [
            'old_status' => $candidate->status->value,
            'new_status' => 'PENDING',
        ]);
    }

    /**
     * 監査ログを作成
     */
    private function createLog(WmsOrderCandidate $candidate, string $action, array $data = []): WmsOrderCandidateAuditLog
    {
        $user = auth()->user();

        $log = WmsOrderCandidateAuditLog::create([
            'order_candidate_id' => $candidate->id,
            'batch_code' => $candidate->batch_code,
            'action' => $action,
            'old_status' => $data['old_status'] ?? null,
            'new_status' => $data['new_status'] ?? null,
            'old_quantity' => $data['old_quantity'] ?? null,
            'new_quantity' => $data['new_quantity'] ?? null,
            'changes' => $data['changes'] ?? null,
            'reason' => $data['reason'] ?? null,
            'performed_by' => $user?->id,
            'performed_by_name' => $user?->name,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        Log::info('Order candidate audit log created', [
            'candidate_id' => $candidate->id,
            'action' => $action,
            'performed_by' => $user?->id,
        ]);

        return $log;
    }

    /**
     * ステータス変更からアクションを判定
     */
    private function determineActionFromStatusChange(string $oldStatus, string $newStatus): string
    {
        return match ($newStatus) {
            'APPROVED' => WmsOrderCandidateAuditLog::ACTION_APPROVED,
            'CONFIRMED' => WmsOrderCandidateAuditLog::ACTION_CONFIRMED,
            'EXCLUDED' => WmsOrderCandidateAuditLog::ACTION_EXCLUDED,
            'EXECUTED' => WmsOrderCandidateAuditLog::ACTION_TRANSMITTED,
            'PENDING' => WmsOrderCandidateAuditLog::ACTION_APPROVAL_CANCELLED,
            default => WmsOrderCandidateAuditLog::ACTION_STATUS_CHANGED,
        };
    }
}
