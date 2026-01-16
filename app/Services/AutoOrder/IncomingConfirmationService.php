<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 入庫確定サービス
 *
 * 入庫予定の確定処理と仕入れデータ作成
 */
class IncomingConfirmationService
{
    /**
     * 入庫予定を確定し、仕入れデータ作成キューに登録
     *
     * @param  WmsOrderIncomingSchedule  $schedule  入庫予定
     * @param  int  $confirmedBy  確定者ID
     * @param  int|null  $receivedQuantity  実際の入庫数量（nullの場合は予定数量）
     * @param  string|null  $actualDate  実際の入庫日（nullの場合は本日）
     * @param  string|null  $expirationDate  賞味期限（任意）
     */
    public function confirmIncoming(
        WmsOrderIncomingSchedule $schedule,
        int $confirmedBy,
        ?int $receivedQuantity = null,
        ?string $actualDate = null,
        ?string $expirationDate = null
    ): WmsOrderIncomingSchedule {
        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            throw new \RuntimeException("Schedule {$schedule->id} is already confirmed");
        }

        if ($schedule->status === IncomingScheduleStatus::CANCELLED) {
            throw new \RuntimeException("Schedule {$schedule->id} is cancelled");
        }

        $receivedQuantity = $receivedQuantity ?? $schedule->expected_quantity;
        $actualDate = $actualDate ?? now()->format('Y-m-d');

        return DB::connection('sakemaru')->transaction(function () use ($schedule, $confirmedBy, $receivedQuantity, $actualDate, $expirationDate) {
            // 入庫予定を更新（仕入れ連携は別途行う）
            $updateData = [
                'received_quantity' => $receivedQuantity,
                'actual_arrival_date' => $actualDate,
                'status' => IncomingScheduleStatus::CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $confirmedBy,
            ];

            // 賞味期限が指定された場合のみ更新
            if ($expirationDate !== null) {
                $updateData['expiration_date'] = $expirationDate;
            }

            $schedule->update($updateData);

            Log::info('Incoming confirmed', [
                'schedule_id' => $schedule->id,
                'received_quantity' => $receivedQuantity,
                'actual_date' => $actualDate,
                'expiration_date' => $expirationDate,
            ]);

            return $schedule->fresh();
        });
    }

    /**
     * 一部入庫を記録
     *
     * @param  WmsOrderIncomingSchedule  $schedule  入庫予定
     * @param  int  $receivedQuantity  入庫数量
     * @param  int  $confirmedBy  確定者ID
     * @param  string|null  $actualDate  入庫日
     * @param  string|null  $expirationDate  賞味期限（任意）
     */
    public function recordPartialIncoming(
        WmsOrderIncomingSchedule $schedule,
        int $receivedQuantity,
        int $confirmedBy,
        ?string $actualDate = null,
        ?string $expirationDate = null
    ): WmsOrderIncomingSchedule {
        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            throw new \RuntimeException("Schedule {$schedule->id} is already fully confirmed");
        }

        if ($schedule->status === IncomingScheduleStatus::CANCELLED) {
            throw new \RuntimeException("Schedule {$schedule->id} is cancelled");
        }

        $actualDate = $actualDate ?? now()->format('Y-m-d');
        $newReceivedQty = $schedule->received_quantity + $receivedQuantity;

        return DB::connection('sakemaru')->transaction(function () use ($schedule, $newReceivedQty, $receivedQuantity, $confirmedBy, $actualDate, $expirationDate) {
            // ステータス判定
            $status = IncomingScheduleStatus::PARTIAL;
            if ($newReceivedQty >= $schedule->expected_quantity) {
                $status = IncomingScheduleStatus::CONFIRMED;
            }

            // 入庫予定を更新（仕入れ連携は別途行う）
            $updateData = [
                'received_quantity' => $newReceivedQty,
                'actual_arrival_date' => $actualDate,
                'status' => $status,
                'confirmed_at' => $status === IncomingScheduleStatus::CONFIRMED ? now() : null,
                'confirmed_by' => $status === IncomingScheduleStatus::CONFIRMED ? $confirmedBy : null,
            ];

            // 賞味期限が指定された場合のみ更新
            if ($expirationDate !== null) {
                $updateData['expiration_date'] = $expirationDate;
            }

            $schedule->update($updateData);

            Log::info('Partial incoming recorded', [
                'schedule_id' => $schedule->id,
                'received_quantity' => $receivedQuantity,
                'total_received' => $newReceivedQty,
                'expected_quantity' => $schedule->expected_quantity,
                'status' => $status->value,
                'expiration_date' => $expirationDate,
            ]);

            return $schedule->fresh();
        });
    }

    /**
     * 複数の入庫予定を一括確定
     *
     * @param  Collection|array  $scheduleIds  入庫予定IDの配列
     * @param  int  $confirmedBy  確定者ID
     * @param  string|null  $actualDate  入庫日
     * @return array ['success' => int, 'failed' => int, 'errors' => array]
     */
    public function confirmMultiple(array|Collection $scheduleIds, int $confirmedBy, ?string $actualDate = null): array
    {
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($scheduleIds as $scheduleId) {
            try {
                $schedule = WmsOrderIncomingSchedule::findOrFail($scheduleId);
                $this->confirmIncoming($schedule, $confirmedBy, null, $actualDate);
                $success++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'schedule_id' => $scheduleId,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to confirm incoming', [
                    'schedule_id' => $scheduleId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * 入庫予定をキャンセル
     */
    public function cancelIncoming(WmsOrderIncomingSchedule $schedule, int $cancelledBy, string $reason = ''): WmsOrderIncomingSchedule
    {
        if (in_array($schedule->status, [IncomingScheduleStatus::CONFIRMED, IncomingScheduleStatus::TRANSMITTED])) {
            throw new \RuntimeException("Cannot cancel confirmed/transmitted schedule {$schedule->id}");
        }

        $schedule->update([
            'status' => IncomingScheduleStatus::CANCELLED,
            'note' => $schedule->note
                ? $schedule->note."\n[キャンセル] ".$reason
                : '[キャンセル] '.$reason,
        ]);

        Log::info('Incoming schedule cancelled', [
            'schedule_id' => $schedule->id,
            'cancelled_by' => $cancelledBy,
            'reason' => $reason,
        ]);

        return $schedule->fresh();
    }
}
