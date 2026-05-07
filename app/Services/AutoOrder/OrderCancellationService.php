<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 発注キャンセルサービス
 *
 * 確定済みの発注・移動をキャンセルし、入荷予定を取消する。
 * - PENDING → CANCELLED（全キャンセル）
 * - PARTIAL → PARTIAL_CANCELLED（一部入庫後キャンセル、received_quantity維持）
 */
class OrderCancellationService
{
    public function __construct(
        private readonly OrderAuditService $auditService = new OrderAuditService,
    ) {}

    /**
     * 発注確定をキャンセル（CONFIRMED → APPROVED）
     *
     * 確定済みで未送信の発注候補を承認済み状態に戻し、
     * 関連するPENDING入庫予定を削除する。
     *
     * @param  WmsOrderCandidate  $candidate  対象の発注候補
     * @param  int  $userId  キャンセル実行者ID
     * @param  string  $reason  キャンセル理由
     * @return int 削除された入庫予定の件数
     */
    public function cancelConfirmation(
        WmsOrderCandidate $candidate,
        int $userId,
        string $reason
    ): int {
        if (! in_array($candidate->status, [CandidateStatus::CONFIRMED, CandidateStatus::EXECUTED])) {
            throw new \RuntimeException(
                'この発注候補は確定取消できません（ステータス: '.$candidate->status->label().'）'
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $userId, $reason) {
            // 1. 関連するPENDING入庫予定を削除
            $deletedSchedules = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)
                ->where('status', IncomingScheduleStatus::PENDING)
                ->delete();

            // 2. JXドキュメント参照をクリア
            if ($candidate->wms_order_jx_document_id) {
                $candidate->wms_order_jx_document_id = null;
            }

            // 3. ステータスをAPPROVEDに戻す
            $candidate->update([
                'status' => CandidateStatus::APPROVED,
                'wms_order_jx_document_id' => null,
                'modified_by' => $userId,
                'modified_at' => now(),
            ]);

            // 4. 監査ログ
            $this->auditService->logConfirmationCancellation($candidate, $reason);

            Log::info('発注確定を取消', [
                'candidate_id' => $candidate->id,
                'batch_code' => $candidate->batch_code,
                'item_id' => $candidate->item_id,
                'cancelled_by' => $userId,
                'reason' => $reason,
                'deleted_schedules' => $deletedSchedules,
            ]);

            return $deletedSchedules;
        });
    }

    /**
     * 入庫予定をキャンセル
     *
     * @param  WmsOrderIncomingSchedule  $schedule  対象の入庫予定
     * @param  int  $userId  キャンセル実行者ID
     * @param  string  $reason  キャンセル理由
     */
    public function cancelIncomingSchedule(
        WmsOrderIncomingSchedule $schedule,
        int $userId,
        string $reason
    ): void {
        // キャンセル可能チェック
        if (! in_array($schedule->status, [
            IncomingScheduleStatus::PENDING,
            IncomingScheduleStatus::PARTIAL,
        ])) {
            throw new \RuntimeException('この入荷予定はキャンセルできません（ステータス: '.$schedule->status->label().'）');
        }

        DB::connection('sakemaru')->transaction(function () use ($schedule, $userId, $reason) {
            // 1. 入庫予定をキャンセル（PARTIALの場合はPARTIAL_CANCELLED）
            $cancelStatus = $schedule->status === IncomingScheduleStatus::PARTIAL
                ? IncomingScheduleStatus::PARTIAL_CANCELLED
                : IncomingScheduleStatus::CANCELLED;

            $schedule->update([
                'status' => $cancelStatus,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            // 2. 移動候補の場合、stock_transfer_queue に CANCEL を追加
            if ($schedule->transfer_candidate_id && $schedule->stock_transfer_id) {
                $this->createCancelQueue($schedule);
            }

            Log::info('入庫予定をキャンセル', [
                'schedule_id' => $schedule->id,
                'cancel_status' => $cancelStatus->value,
                'cancelled_by' => $userId,
                'reason' => $reason,
                'received_quantity' => $schedule->received_quantity,
            ]);
        });
    }

    /**
     * stock_transfer_queue に CANCEL レコードを作成
     */
    private function createCancelQueue(WmsOrderIncomingSchedule $schedule): void
    {
        $schedule->loadMissing(['sourceWarehouse', 'warehouse']);

        $fromWarehouseCode = $schedule->sourceWarehouse?->code ?? '';
        $toWarehouseCode = $schedule->warehouse?->code ?? '';

        $requestId = "transfer-cancel-{$schedule->id}-".now()->format('YmdHis');

        DB::connection('sakemaru')->table('stock_transfer_queue')->insert([
            'client_id' => config('app.client_id'),
            'request_id' => $requestId,
            'stock_transfer_id' => $schedule->stock_transfer_id,
            'process_date' => now()->format('Y-m-d'),
            'delivered_date' => now()->format('Y-m-d'),
            'from_warehouse_code' => $fromWarehouseCode,
            'to_warehouse_code' => $toWarehouseCode,
            'items' => json_encode([]),
            'note' => "キャンセル Schedule ID: {$schedule->id}",
            'status' => 'BEFORE',
            'action_type' => 'CANCEL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('stock_transfer_queue CANCEL作成', [
            'schedule_id' => $schedule->id,
            'stock_transfer_id' => $schedule->stock_transfer_id,
            'request_id' => $requestId,
        ]);
    }
}
