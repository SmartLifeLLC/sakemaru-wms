<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 入庫完了データの仕入連携サービス
 *
 * 仕様書: storage/specifications/inbound/purchase-create-queue-batching.md
 */
class IncomingTransmissionService
{
    /**
     * 入庫完了データを purchase_create_queue にバッチ登録
     *
     * グルーピング基準:
     * - warehouse_code (倉庫コード)
     * - supplier_code (仕入先コード)
     * - actual_arrival_date (入庫日)
     *
     * @return array ['success' => bool, 'queue_count' => int, 'schedule_count' => int, 'errors' => array]
     */
    public function transmitConfirmedIncomings(): array
    {
        // CONFIRMED状態の入庫データを取得
        $schedules = WmsOrderIncomingSchedule::where('status', IncomingScheduleStatus::CONFIRMED)
            ->with(['warehouse', 'item', 'contractor', 'supplier'])
            ->get();

        if ($schedules->isEmpty()) {
            Log::info('No confirmed incoming schedules to transmit');

            return [
                'success' => true,
                'queue_count' => 0,
                'schedule_count' => 0,
                'errors' => [],
            ];
        }

        // グルーピング: 倉庫 + 仕入先 + 入庫日
        $grouped = $schedules->groupBy(function ($schedule) {
            $warehouseCode = $schedule->warehouse?->code ?? 'UNKNOWN';
            // supplier_id があればそちらを優先、なければ contractor から取得
            $supplierCode = $this->getSupplierCode($schedule);
            $deliveredDate = $schedule->actual_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

            return "{$warehouseCode}_{$supplierCode}_{$deliveredDate}";
        });

        $queueCount = 0;
        $scheduleCount = 0;
        $errors = [];

        foreach ($grouped as $groupKey => $groupSchedules) {
            try {
                // 100件以下で分割（仕様推奨）
                $chunks = $groupSchedules->chunk(100);

                foreach ($chunks as $chunk) {
                    $queueId = $this->createPurchaseQueueRecord($chunk);

                    // ステータスをTRANSMITTEDに更新
                    foreach ($chunk as $schedule) {
                        $schedule->update([
                            'status' => IncomingScheduleStatus::TRANSMITTED,
                            'purchase_queue_id' => $queueId,
                        ]);
                    }

                    $queueCount++;
                    $scheduleCount += $chunk->count();

                    Log::info('Purchase queue created from incoming', [
                        'group_key' => $groupKey,
                        'queue_id' => $queueId,
                        'schedule_count' => $chunk->count(),
                    ]);
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to create purchase queue from incoming', [
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => empty($errors),
            'queue_count' => $queueCount,
            'schedule_count' => $scheduleCount,
            'errors' => $errors,
        ];
    }

    /**
     * 仕入先コードを取得
     */
    private function getSupplierCode(WmsOrderIncomingSchedule $schedule): string
    {
        // supplier_id があればそのコードを取得
        if ($schedule->supplier_id) {
            $supplier = DB::connection('sakemaru')
                ->table('suppliers as s')
                ->join('partners as p', 's.partner_id', '=', 'p.id')
                ->where('s.id', $schedule->supplier_id)
                ->select('p.code')
                ->first();

            if ($supplier) {
                return $supplier->code;
            }
        }

        // contractor（発注先）から取得
        return $schedule->contractor?->code ?? '';
    }

    /**
     * purchase_create_queue にレコードを作成
     *
     * @param  Collection  $schedules  同一グループの入庫データ
     */
    private function createPurchaseQueueRecord(Collection $schedules): int
    {
        $first = $schedules->first();

        // マスタ情報を取得
        $warehouse = $first->warehouse;
        $supplierCode = $this->getSupplierCode($first);
        $deliveredDate = $first->actual_arrival_date?->format('Y-m-d') ?? now()->format('Y-m-d');

        // 明細を構築
        $details = $schedules->map(function ($schedule) {
            $detail = [
                'item_code' => $schedule->item?->code ?? '',
                'quantity' => $schedule->received_quantity,
                'quantity_type' => $schedule->quantity_type?->value ?? 'PIECE',
            ];

            // 賞味期限がある場合のみ追加（仕様書: 指定がない場合は基幹側で自動計算）
            if ($schedule->expiration_date) {
                $detail['expiration_date'] = $schedule->expiration_date->format('Y-m-d');
            }

            return $detail;
        })->toArray();

        // 仕入データを構築
        $purchaseData = [
            'process_date' => $deliveredDate,
            'delivered_date' => $deliveredDate,
            'account_date' => $deliveredDate,
            'supplier_code' => $supplierCode,
            'warehouse_code' => $warehouse?->code ?? '',
            'note' => $this->buildPurchaseNote($first),
            'details' => $details,
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

        return implode(' / ', $parts);
    }
}
