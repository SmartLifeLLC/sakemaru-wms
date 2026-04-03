<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 入庫確定サービス
 *
 * 入庫予定の確定処理と仕入れデータ作成
 * TRANSFER タイプの場合は stock_transfer_queue (action_type=DELIVER) を作成
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
     * @param  int|null  $locationId  入庫ロケーションID（任意）
     */
    public function confirmIncoming(
        WmsOrderIncomingSchedule $schedule,
        int $confirmedBy,
        ?int $receivedQuantity = null,
        ?string $actualDate = null,
        ?string $expirationDate = null,
        ?int $locationId = null,
        ?int $pickerId = null
    ): WmsOrderIncomingSchedule {
        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            throw new \RuntimeException("Schedule {$schedule->id} is already confirmed");
        }

        if ($schedule->status === IncomingScheduleStatus::CANCELLED) {
            throw new \RuntimeException("Schedule {$schedule->id} is cancelled");
        }

        $receivedQuantity = $receivedQuantity ?? $schedule->expected_quantity;
        $actualDate = $actualDate ?? now()->format('Y-m-d');

        return DB::connection('sakemaru')->transaction(function () use ($schedule, $confirmedBy, $receivedQuantity, $actualDate, $expirationDate, $locationId, $pickerId) {
            // 入庫予定を更新（仕入れ連携は別途行う）
            $updateData = [
                'received_quantity' => $receivedQuantity,
                'actual_arrival_date' => $actualDate,
                'status' => IncomingScheduleStatus::CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $pickerId ? null : $confirmedBy,
                'confirmed_picker_id' => $pickerId,
            ];

            // 賞味期限が指定された場合のみ更新
            if ($expirationDate !== null) {
                $updateData['expiration_date'] = $expirationDate;
            }

            // ロケーションが指定された場合のみ更新
            if ($locationId !== null) {
                $updateData['location_id'] = $locationId;
            }

            $schedule->update($updateData);

            // TRANSFER タイプの場合、納品確定キューを作成
            if ($schedule->order_source === OrderSource::TRANSFER) {
                $this->createDeliverQueue($schedule, $receivedQuantity);
            }

            Log::info('Incoming confirmed', [
                'schedule_id' => $schedule->id,
                'received_quantity' => $receivedQuantity,
                'actual_date' => $actualDate,
                'expiration_date' => $expirationDate,
                'order_source' => $schedule->order_source->value,
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
     * @param  int|null  $locationId  入庫ロケーションID（任意）
     */
    public function recordPartialIncoming(
        WmsOrderIncomingSchedule $schedule,
        int $receivedQuantity,
        int $confirmedBy,
        ?string $actualDate = null,
        ?string $expirationDate = null,
        ?int $locationId = null,
        ?int $pickerId = null
    ): WmsOrderIncomingSchedule {
        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            throw new \RuntimeException("Schedule {$schedule->id} is already fully confirmed");
        }

        if ($schedule->status === IncomingScheduleStatus::CANCELLED) {
            throw new \RuntimeException("Schedule {$schedule->id} is cancelled");
        }

        $actualDate = $actualDate ?? now()->format('Y-m-d');
        $newReceivedQty = $schedule->received_quantity + $receivedQuantity;

        return DB::connection('sakemaru')->transaction(function () use ($schedule, $newReceivedQty, $receivedQuantity, $confirmedBy, $actualDate, $expirationDate, $locationId, $pickerId) {
            // ステータス判定
            $status = IncomingScheduleStatus::PARTIAL;
            if ($newReceivedQty >= $schedule->expected_quantity) {
                $status = IncomingScheduleStatus::CONFIRMED;
            }

            $isConfirmed = $status === IncomingScheduleStatus::CONFIRMED;

            // 入庫予定を更新（仕入れ連携は別途行う）
            $updateData = [
                'received_quantity' => $newReceivedQty,
                'actual_arrival_date' => $actualDate,
                'status' => $status,
                'confirmed_at' => $isConfirmed ? now() : null,
                'confirmed_by' => $isConfirmed ? ($pickerId ? null : $confirmedBy) : null,
                'confirmed_picker_id' => $isConfirmed ? $pickerId : null,
            ];

            // 賞味期限が指定された場合のみ更新
            if ($expirationDate !== null) {
                $updateData['expiration_date'] = $expirationDate;
            }

            // ロケーションが指定された場合のみ更新
            if ($locationId !== null) {
                $updateData['location_id'] = $locationId;
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
    public function confirmMultiple(array|Collection $scheduleIds, int $confirmedBy, ?string $actualDate = null, ?int $pickerId = null): array
    {
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($scheduleIds as $scheduleId) {
            try {
                $schedule = WmsOrderIncomingSchedule::findOrFail($scheduleId);
                $this->confirmIncoming($schedule, $confirmedBy, null, $actualDate, pickerId: $pickerId);
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

    /**
     * stock_transfer_queue (action_type=DELIVER) を作成
     *
     * @param  WmsOrderIncomingSchedule  $schedule  入庫予定
     * @param  int|null  $receivedQuantity  実際の入庫数量
     */
    private function createDeliverQueue(
        WmsOrderIncomingSchedule $schedule,
        ?int $receivedQuantity
    ): void {
        // stock_transfer_id が未設定の場合、動的に取得
        if (! $schedule->stock_transfer_id) {
            $this->syncStockTransferId($schedule);
        }

        if (! $schedule->stock_transfer_id) {
            throw new \RuntimeException(
                "Stock transfer ID not found for schedule {$schedule->id}"
            );
        }

        // 倉庫情報を取得
        $schedule->loadMissing(['sourceWarehouse', 'warehouse', 'transferCandidate']);

        $fromWarehouseCode = $schedule->sourceWarehouse?->code;
        $toWarehouseCode = $schedule->warehouse?->code;
        $deliveryCourseId = $schedule->transferCandidate?->delivery_course_id;

        if (! $fromWarehouseCode || ! $toWarehouseCode) {
            throw new \RuntimeException(
                "Warehouse codes not found for schedule {$schedule->id}"
            );
        }

        $requestId = "transfer-deliver-{$schedule->id}-".now()->format('YmdHis');

        DB::connection('sakemaru')->table('stock_transfer_queue')->insert([
            'client_id' => config('app.client_id'),
            'request_id' => $requestId,
            'stock_transfer_id' => $schedule->stock_transfer_id,
            'process_date' => now()->format('Y-m-d'),
            'delivered_date' => now()->format('Y-m-d'),
            'from_warehouse_code' => $fromWarehouseCode,
            'to_warehouse_code' => $toWarehouseCode,
            'delivery_course_id' => $deliveryCourseId,
            'items' => json_encode([
                'schedule_id' => $schedule->id,
                'received_quantity' => $receivedQuantity ?? $schedule->expected_quantity,
                'quantity_type' => $schedule->quantity_type->value,
            ], JSON_UNESCAPED_UNICODE),
            'note' => "入荷検品確定 Schedule ID: {$schedule->id}",
            'status' => 'BEFORE',
            'action_type' => 'DELIVER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Deliver queue created', [
            'schedule_id' => $schedule->id,
            'stock_transfer_id' => $schedule->stock_transfer_id,
            'request_id' => $requestId,
            'from_warehouse' => $fromWarehouseCode,
            'to_warehouse' => $toWarehouseCode,
            'delivery_course_id' => $deliveryCourseId,
            'received_quantity' => $receivedQuantity ?? $schedule->expected_quantity,
        ]);
    }

    /**
     * stock_transfer_id を動的に取得・設定
     *
     * 単一移動の場合: request_id = "transfer-create-{candidate_id}"
     * グループ移動の場合: request_id = "transfer-create-group-{id1}-{id2}-..." または MD5ハッシュ
     *
     * @param  WmsOrderIncomingSchedule  $schedule  入庫予定
     */
    private function syncStockTransferId(WmsOrderIncomingSchedule $schedule): void
    {
        $candidateId = $schedule->transfer_candidate_id;

        // 1. 単一移動の形式で検索
        $queue = DB::connection('sakemaru')
            ->table('stock_transfer_queue')
            ->where('action_type', 'CREATE')
            ->where('request_id', "transfer-create-{$candidateId}")
            ->where('status', 'FINISHED')
            ->where('is_success', true)
            ->first();

        // 2. グループ移動の形式で検索（候補IDを含む request_id を検索）
        if (! $queue) {
            $queue = DB::connection('sakemaru')
                ->table('stock_transfer_queue')
                ->where('action_type', 'CREATE')
                ->where('status', 'FINISHED')
                ->where('is_success', true)
                ->where(function ($q) use ($candidateId) {
                    $q->where('request_id', 'LIKE', "transfer-create-group-%-{$candidateId}-%")
                        ->orWhere('request_id', 'LIKE', "transfer-create-group-{$candidateId}-%")
                        ->orWhere('request_id', 'LIKE', "transfer-create-group-%-{$candidateId}");
                })
                ->first();
        }

        // 3. まだ見つからない場合、items JSON 内の候補IDで検索
        if (! $queue) {
            $queue = DB::connection('sakemaru')
                ->table('stock_transfer_queue')
                ->where('action_type', 'CREATE')
                ->where('request_id', 'LIKE', 'transfer-create-group-%')
                ->where('status', 'FINISHED')
                ->where('is_success', true)
                ->where('items', 'LIKE', "%移動候補ID: {$candidateId}%")
                ->first();
        }

        if ($queue && $queue->stock_transfer_id) {
            $schedule->update(['stock_transfer_id' => $queue->stock_transfer_id]);
            $schedule->refresh();

            Log::info('Stock transfer ID synced from queue', [
                'schedule_id' => $schedule->id,
                'transfer_candidate_id' => $candidateId,
                'queue_request_id' => $queue->request_id,
                'stock_transfer_id' => $queue->stock_transfer_id,
            ]);
        }
    }
}
