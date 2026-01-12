<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     */
    public function confirmIncoming(
        WmsOrderIncomingSchedule $schedule,
        int $confirmedBy,
        ?int $receivedQuantity = null,
        ?string $actualDate = null
    ): WmsOrderIncomingSchedule {
        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            throw new \RuntimeException("Schedule {$schedule->id} is already confirmed");
        }

        if ($schedule->status === IncomingScheduleStatus::CANCELLED) {
            throw new \RuntimeException("Schedule {$schedule->id} is cancelled");
        }

        $receivedQuantity = $receivedQuantity ?? $schedule->expected_quantity;
        $actualDate = $actualDate ?? now()->format('Y-m-d');

        return DB::connection('sakemaru')->transaction(function () use ($schedule, $confirmedBy, $receivedQuantity, $actualDate) {
            // 1. 入庫予定を更新
            $schedule->update([
                'received_quantity' => $receivedQuantity,
                'actual_arrival_date' => $actualDate,
                'status' => IncomingScheduleStatus::CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $confirmedBy,
            ]);

            // 2. 仕入れデータ作成キューに登録
            $queueId = $this->createPurchaseQueue($schedule, $actualDate);

            // 3. キューIDを記録
            $schedule->update([
                'purchase_queue_id' => $queueId,
            ]);

            Log::info('Incoming confirmed and purchase queue created', [
                'schedule_id' => $schedule->id,
                'received_quantity' => $receivedQuantity,
                'actual_date' => $actualDate,
                'purchase_queue_id' => $queueId,
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
     */
    public function recordPartialIncoming(
        WmsOrderIncomingSchedule $schedule,
        int $receivedQuantity,
        int $confirmedBy,
        ?string $actualDate = null
    ): WmsOrderIncomingSchedule {
        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            throw new \RuntimeException("Schedule {$schedule->id} is already fully confirmed");
        }

        if ($schedule->status === IncomingScheduleStatus::CANCELLED) {
            throw new \RuntimeException("Schedule {$schedule->id} is cancelled");
        }

        $actualDate = $actualDate ?? now()->format('Y-m-d');
        $newReceivedQty = $schedule->received_quantity + $receivedQuantity;

        return DB::connection('sakemaru')->transaction(function () use ($schedule, $newReceivedQty, $receivedQuantity, $confirmedBy, $actualDate) {
            // ステータス判定
            $status = IncomingScheduleStatus::PARTIAL;
            if ($newReceivedQty >= $schedule->expected_quantity) {
                $status = IncomingScheduleStatus::CONFIRMED;
            }

            // 入庫予定を更新
            $schedule->update([
                'received_quantity' => $newReceivedQty,
                'actual_arrival_date' => $actualDate,
                'status' => $status,
                'confirmed_at' => $status === IncomingScheduleStatus::CONFIRMED ? now() : null,
                'confirmed_by' => $status === IncomingScheduleStatus::CONFIRMED ? $confirmedBy : null,
            ]);

            // 仕入れデータ作成キューに登録（一部入庫分）
            $queueId = $this->createPurchaseQueueForPartial($schedule, $receivedQuantity, $actualDate);

            Log::info('Partial incoming recorded', [
                'schedule_id' => $schedule->id,
                'received_quantity' => $receivedQuantity,
                'total_received' => $newReceivedQty,
                'expected_quantity' => $schedule->expected_quantity,
                'status' => $status->value,
                'purchase_queue_id' => $queueId,
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
        if ($schedule->status === IncomingScheduleStatus::CONFIRMED) {
            throw new \RuntimeException("Cannot cancel confirmed schedule {$schedule->id}");
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
     * purchase_create_queueにデータを登録
     */
    private function createPurchaseQueue(WmsOrderIncomingSchedule $schedule, string $deliveredDate): int
    {
        // マスタ情報を取得
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $schedule->warehouse_id)
            ->first();

        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('id', $schedule->item_id)
            ->first();

        $supplier = null;
        if ($schedule->supplier_id) {
            $supplier = DB::connection('sakemaru')
                ->table('suppliers as s')
                ->join('partners as p', 's.partner_id', '=', 'p.id')
                ->where('s.id', $schedule->supplier_id)
                ->select('p.code')
                ->first();
        }

        // 仕入データを構築
        $purchaseData = [
            'process_date' => $deliveredDate,
            'delivered_date' => $deliveredDate,
            'account_date' => $deliveredDate,
            'supplier_code' => $supplier?->code ?? '',
            'warehouse_code' => $warehouse->code,
            'note' => $this->buildPurchaseNote($schedule),
            'details' => [
                [
                    'item_code' => $item->code,
                    'quantity' => $schedule->received_quantity,
                    'quantity_type' => $schedule->quantity_type->value,
                ],
            ],
        ];

        // キューに挿入
        $queueId = DB::connection('sakemaru')->table('purchase_create_queue')->insertGetId([
            'request_uuid' => Str::uuid()->toString(),
            'delivered_date' => $deliveredDate,
            'items' => json_encode($purchaseData, JSON_UNESCAPED_UNICODE),
            'status' => 'BEFORE',
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queueId;
    }

    /**
     * 一部入庫用のpurchase_create_queueデータを登録
     */
    private function createPurchaseQueueForPartial(WmsOrderIncomingSchedule $schedule, int $quantity, string $deliveredDate): int
    {
        // マスタ情報を取得
        $warehouse = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $schedule->warehouse_id)
            ->first();

        $item = DB::connection('sakemaru')
            ->table('items')
            ->where('id', $schedule->item_id)
            ->first();

        $supplier = null;
        if ($schedule->supplier_id) {
            $supplier = DB::connection('sakemaru')
                ->table('suppliers as s')
                ->join('partners as p', 's.partner_id', '=', 'p.id')
                ->where('s.id', $schedule->supplier_id)
                ->select('p.code')
                ->first();
        }

        // 仕入データを構築
        $purchaseData = [
            'process_date' => $deliveredDate,
            'delivered_date' => $deliveredDate,
            'account_date' => $deliveredDate,
            'supplier_code' => $supplier?->code ?? '',
            'warehouse_code' => $warehouse->code,
            'note' => $this->buildPurchaseNote($schedule).' (一部入庫)',
            'details' => [
                [
                    'item_code' => $item->code,
                    'quantity' => $quantity,
                    'quantity_type' => $schedule->quantity_type->value,
                ],
            ],
        ];

        // キューに挿入
        $queueId = DB::connection('sakemaru')->table('purchase_create_queue')->insertGetId([
            'request_uuid' => Str::uuid()->toString(),
            'delivered_date' => $deliveredDate,
            'items' => json_encode($purchaseData, JSON_UNESCAPED_UNICODE),
            'status' => 'BEFORE',
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queueId;
    }

    /**
     * 仕入れ伝票の備考を構築
     */
    private function buildPurchaseNote(WmsOrderIncomingSchedule $schedule): string
    {
        $parts = [];

        if ($schedule->order_source->value === 'AUTO') {
            $parts[] = '自動発注';
            if ($schedule->order_candidate_id) {
                $parts[] = "候補ID:{$schedule->order_candidate_id}";
            }
        } else {
            $parts[] = '手動発注';
            if ($schedule->manual_order_number) {
                $parts[] = "発注番号:{$schedule->manual_order_number}";
            }
        }

        $parts[] = "入庫予定ID:{$schedule->id}";

        return implode(' / ', $parts);
    }
}
