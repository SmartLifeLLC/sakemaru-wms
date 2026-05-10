<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Support\Facades\DB;

class OrderCandidateToTransferCandidateService
{
    private const INTERNAL_CONTRACTOR_CODE = '9012';

    private const HUB_WAREHOUSE_CODE = '91';

    public function convert(WmsOrderCandidate $candidate, ?int $modifiedBy = null): WmsStockTransferCandidate
    {
        $candidate->loadMissing('contractor');

        if ($candidate->status !== CandidateStatus::PENDING) {
            throw new \RuntimeException('承認前の発注候補のみ移動候補へ変更できます。');
        }

        if ((string) $candidate->contractor?->code !== self::INTERNAL_CONTRACTOR_CODE) {
            throw new \RuntimeException('発注先CDが9012の発注候補のみ移動候補へ変更できます。');
        }

        if ((int) $candidate->order_quantity <= 0) {
            throw new \RuntimeException('発注数が0以下の候補は移動候補へ変更できません。');
        }

        $hubWarehouse = Warehouse::query()
            ->where('code', self::HUB_WAREHOUSE_CODE)
            ->first();

        if (! $hubWarehouse) {
            throw new \RuntimeException('移動元の91倉庫が見つかりません。');
        }

        if ((int) $candidate->warehouse_id === (int) $hubWarehouse->id) {
            throw new \RuntimeException('発注倉庫が91倉庫の候補は移動候補へ変更できません。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $hubWarehouse, $modifiedBy) {
            $transferCandidate = WmsStockTransferCandidate::create([
                'batch_code' => $candidate->batch_code,
                'satellite_warehouse_id' => $candidate->warehouse_id,
                'hub_warehouse_id' => $hubWarehouse->id,
                'item_id' => $candidate->item_id,
                'item_code' => $candidate->item_code,
                'search_code' => $candidate->search_code,
                'ordering_code' => $candidate->ordering_code,
                'contractor_id' => $candidate->contractor_id,
                'delivery_course_id' => $candidate->delivery_course_id,
                'suggested_quantity' => $candidate->suggested_quantity,
                'transfer_quantity' => $candidate->order_quantity,
                'current_effective_stock' => $candidate->current_effective_stock,
                'incoming_quantity' => $candidate->incoming_quantity,
                'calculated_available' => $candidate->calculated_available,
                'shortage_qty' => $candidate->calculated_shortage_qty,
                'safety_stock' => $candidate->safety_stock,
                'purchase_unit' => $candidate->purchase_unit,
                'quantity_type' => $candidate->quantity_type,
                'expected_arrival_date' => $candidate->expected_arrival_date,
                'original_arrival_date' => $candidate->original_arrival_date,
                'status' => CandidateStatus::PENDING,
                'lot_status' => $candidate->lot_status,
                'lot_rule_id' => $candidate->lot_rule_id,
                'lot_exception_id' => $candidate->lot_exception_id,
                'lot_before_qty' => $candidate->lot_before_qty,
                'lot_after_qty' => $candidate->lot_after_qty,
                'lot_fee_type' => $candidate->lot_fee_type,
                'lot_fee_amount' => $candidate->lot_fee_amount,
                'is_manually_modified' => true,
                'modified_by' => $modifiedBy,
                'modified_at' => now(),
                'origin_type' => $candidate->origin_type,
                'exclusion_reason' => '発注先CD9012を91倉庫からの移動候補へ変更',
            ]);

            $candidate->updateWithLock([
                'status' => CandidateStatus::EXCLUDED,
                'exclusion_reason' => '移動候補へ変更済み',
                'is_manually_modified' => true,
                'modified_by' => $modifiedBy,
                'modified_at' => now(),
            ]);

            return $transferCandidate;
        });
    }
}
