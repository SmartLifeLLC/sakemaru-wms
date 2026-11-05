<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OriginType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use App\Models\WmsStockTransferCandidate;
use App\Services\Distribution\DistributionStockTransferSlipService;
use Illuminate\Support\Facades\DB;

class OrderCandidateToTransferCandidateService
{
    private const INTERNAL_CONTRACTOR_CODE = '9012';

    private const HUB_WAREHOUSE_CODE = '91';

    /**
     * @var array<int, bool>
     */
    private static array $distributionWarehouseTransferGeneratedCache = [];

    private static bool $hubWarehouseIdLoaded = false;

    private static ?int $hubWarehouseIdCache = null;

    public function __construct(
        private readonly DistributionStockTransferSlipService $distributionStockTransferSlipService
    ) {}

    public function canConvert(WmsOrderCandidate $candidate): bool
    {
        $candidate->loadMissing(['contractor', 'warehouse']);

        return $candidate->status === CandidateStatus::PENDING
            && (string) $candidate->contractor?->code === self::INTERNAL_CONTRACTOR_CODE
            && (int) $candidate->order_quantity > 0
            && ! $this->isHubWarehouseCandidate($candidate)
            && ! $this->hasGeneratedDistributionWarehouseTransfer($candidate);
    }

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

        if ($this->hasGeneratedDistributionWarehouseTransfer($candidate)) {
            throw new \RuntimeException('分配画面で倉庫移動生成済みのため、移動候補へ変更できません。');
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
            $deliveryCourseId = $this->resolveTransferDeliveryCourseId(
                (int) $hubWarehouse->id,
                (int) $candidate->warehouse_id,
                $candidate->delivery_course_id ? (int) $candidate->delivery_course_id : null,
            );

            $transferCandidate = WmsStockTransferCandidate::create([
                'batch_code' => $candidate->batch_code,
                'satellite_warehouse_id' => $candidate->warehouse_id,
                'hub_warehouse_id' => $hubWarehouse->id,
                'item_id' => $candidate->item_id,
                'item_code' => $candidate->item_code,
                'search_code' => $candidate->search_code,
                'ordering_code' => $candidate->ordering_code,
                'contractor_id' => $candidate->contractor_id,
                'delivery_course_id' => $deliveryCourseId,
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

    private function resolveTransferDeliveryCourseId(int $fromWarehouseId, int $toWarehouseId, ?int $currentDeliveryCourseId): ?int
    {
        $deliveryCourseId = DB::connection('sakemaru')
            ->table('warehouse_stock_transfer_delivery_courses')
            ->where('from_warehouse_id', $fromWarehouseId)
            ->where('to_warehouse_id', $toWarehouseId)
            ->value('delivery_course_id');

        return $deliveryCourseId !== null ? (int) $deliveryCourseId : $currentDeliveryCourseId;
    }

    private function hasGeneratedDistributionWarehouseTransfer(WmsOrderCandidate $candidate): bool
    {
        $candidateId = (int) $candidate->id;
        if (array_key_exists($candidateId, self::$distributionWarehouseTransferGeneratedCache)) {
            return self::$distributionWarehouseTransferGeneratedCache[$candidateId];
        }

        if ($candidate->origin_type !== OriginType::DIST) {
            return self::$distributionWarehouseTransferGeneratedCache[$candidateId] = false;
        }

        $sourceWarehouseId = $this->hubWarehouseId();

        if (! $sourceWarehouseId) {
            return self::$distributionWarehouseTransferGeneratedCache[$candidateId] = false;
        }

        $dedupeKeys = $this->distributionWarehouseTransferDedupeKeys($candidate, (int) $sourceWarehouseId);
        if ($dedupeKeys === []) {
            return self::$distributionWarehouseTransferGeneratedCache[$candidateId] = false;
        }

        return self::$distributionWarehouseTransferGeneratedCache[$candidateId] =
            $this->distributionStockTransferSlipService->hasExistingDistributionDedupeKeys($dedupeKeys);
    }

    /**
     * @return array<int, string>
     */
    private function distributionWarehouseTransferDedupeKeys(WmsOrderCandidate $candidate, int $sourceWarehouseId): array
    {
        $logs = DB::connection('sakemaru')
            ->table('wms_order_calculation_logs')
            ->where('batch_code', $candidate->batch_code)
            ->where('warehouse_id', (int) $candidate->warehouse_id)
            ->where('item_id', (int) $candidate->item_id)
            ->where('contractor_id', (int) $candidate->contractor_id)
            ->where('calculation_type', CalculationType::EXTERNAL->value)
            ->get(['calculation_details']);

        $candidateId = (int) $candidate->id;
        $supplierId = (int) ($candidate->supplier_id ?? 0);
        $quantityType = $candidate->quantity_type?->value;
        $dedupeKeys = [];

        foreach ($logs as $log) {
            $details = json_decode((string) ($log->calculation_details ?? ''), true);
            if (! is_array($details) || (string) ($details['source'] ?? '') !== 'distribution') {
                continue;
            }

            $logCandidateId = (int) ($details['candidate_id'] ?? 0);
            if ($logCandidateId > 0 && $logCandidateId !== $candidateId) {
                continue;
            }

            if (isset($details['supplier_id']) && (int) $details['supplier_id'] !== $supplierId) {
                continue;
            }

            if (isset($details['quantity_type']) && $quantityType !== null && (string) $details['quantity_type'] !== $quantityType) {
                continue;
            }

            $rowId = trim((string) ($details['distribution_business_key'] ?? ''));
            if ($rowId === '') {
                $rowId = trim((string) ($details['row_id'] ?? ''));
            }
            if ($rowId === '') {
                continue;
            }

            foreach (($details['demand_breakdown'] ?? []) as $demand) {
                if (! is_array($demand)) {
                    continue;
                }

                $destinationWarehouseId = (int) ($demand['warehouse_id'] ?? 0);
                if ($destinationWarehouseId <= 0) {
                    continue;
                }

                $dedupeKey = $this->distributionStockTransferSlipService->makeDistributionLineDedupeKey(
                    $sourceWarehouseId,
                    $destinationWarehouseId,
                    $rowId
                );
                $dedupeKeys[$dedupeKey] = $dedupeKey;
            }
        }

        return array_values($dedupeKeys);
    }

    private function isHubWarehouseCandidate(WmsOrderCandidate $candidate): bool
    {
        if ((string) $candidate->warehouse?->code === self::HUB_WAREHOUSE_CODE) {
            return true;
        }

        $hubWarehouseId = $this->hubWarehouseId();

        return $hubWarehouseId !== null && (int) $candidate->warehouse_id === $hubWarehouseId;
    }

    private function hubWarehouseId(): ?int
    {
        if (! self::$hubWarehouseIdLoaded) {
            $hubWarehouseId = Warehouse::query()
                ->where('code', self::HUB_WAREHOUSE_CODE)
                ->value('id');

            self::$hubWarehouseIdCache = $hubWarehouseId === null ? null : (int) $hubWarehouseId;
            self::$hubWarehouseIdLoaded = true;
        }

        return self::$hubWarehouseIdCache;
    }
}
