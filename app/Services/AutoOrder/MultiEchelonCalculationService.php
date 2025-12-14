<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\SupplyType;
use App\Enums\QuantityType;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsItemSupplySetting;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsStockTransferCandidate;
use App\Models\WmsWarehouseCalendar;
use App\Models\WmsWarehouseItemTotalStock;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 多段階供給ネットワーク計算サービス (Multi-Echelon)
 *
 * 階層レベル0（最下流）から順に計算し、
 * 内部移動需要を上位階層に伝播させる
 */
class MultiEchelonCalculationService
{
    /**
     * 内部移動需要の一時保存
     * [source_warehouse_id][item_id] => demand_qty
     */
    private array $internalDemands = [];

    /**
     * 全階層の計算を実行
     */
    public function calculateAll(): WmsAutoOrderJobControl
    {
        if (WmsAutoOrderJobControl::hasRunningJob(JobProcessName::SATELLITE_CALC)) {
            throw new \RuntimeException('Calculation job is already running');
        }

        $job = WmsAutoOrderJobControl::startJob(JobProcessName::SATELLITE_CALC);

        try {
            // 階層レベルの再計算（オプション）
            // WmsItemSupplySetting::recalculateHierarchyLevels();

            // 最大階層レベルを取得
            $maxLevel = WmsItemSupplySetting::enabled()->max('hierarchy_level') ?? 0;

            Log::info('Multi-Echelon calculation started', [
                'batch_code' => $job->batch_code,
                'max_level' => $maxLevel,
            ]);

            $this->internalDemands = [];
            $totalProcessed = 0;

            // Level 0 から最上位まで順次計算
            for ($level = 0; $level <= $maxLevel; $level++) {
                $processed = $this->calculateLevel($level, $job->batch_code);
                $totalProcessed += $processed;

                Log::info("Level {$level} completed", [
                    'processed' => $processed,
                    'total' => $totalProcessed,
                ]);

                $job->updateProgress($level + 1, $maxLevel + 1);
            }

            $job->markAsSuccess($totalProcessed);

            Log::info('Multi-Echelon calculation completed', [
                'batch_code' => $job->batch_code,
                'total_processed' => $totalProcessed,
            ]);

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            Log::error('Multi-Echelon calculation failed', [
                'batch_code' => $job->batch_code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $job;
    }

    /**
     * 特定階層レベルの計算
     */
    private function calculateLevel(int $level, string $batchCode): int
    {
        $settings = WmsItemSupplySetting::enabled()
            ->atLevel($level)
            ->with(['warehouse', 'item', 'sourceWarehouse', 'itemContractor.contractor'])
            ->get();

        $processedCount = 0;

        foreach ($settings as $setting) {
            $result = $this->calculateForSetting($setting, $batchCode);
            if ($result) {
                $processedCount++;
            }
        }

        return $processedCount;
    }

    /**
     * 個別設定の計算
     */
    private function calculateForSetting(WmsItemSupplySetting $setting, string $batchCode): bool
    {
        // 在庫スナップショットを取得
        $stock = WmsWarehouseItemTotalStock::where('warehouse_id', $setting->warehouse_id)
            ->where('item_id', $setting->item_id)
            ->first();

        $effectiveStock = $stock?->total_effective_piece ?? 0;
        $incomingStock = $stock?->total_incoming_piece ?? 0;

        // 下位階層からの内部移動需要を取得
        $internalDemand = $this->getInternalDemand($setting->warehouse_id, $setting->item_id);

        // 必要数計算
        $consumptionDuringLT = $setting->getConsumptionDuringLeadTime();
        $requiredQty = ($setting->safety_stock_qty + $consumptionDuringLT + $internalDemand)
            - ($effectiveStock + $incomingStock);

        // 不足がない場合はスキップ
        if ($requiredQty <= 0) {
            return false;
        }

        // 入荷予定日を計算
        $arrivalResult = $this->calculateArrivalDate(
            $setting->warehouse_id,
            $setting->lead_time_days,
            today()
        );

        // 計算ログを記録
        $this->logCalculation($setting, $batchCode, [
            'effective_stock' => $effectiveStock,
            'incoming_stock' => $incomingStock,
            'internal_demand' => $internalDemand,
            'consumption_during_lt' => $consumptionDuringLT,
            'required_qty' => $requiredQty,
            'arrival_result' => $arrivalResult,
        ]);

        // 供給タイプに応じて候補を作成
        if ($setting->isInternal()) {
            $this->createTransferCandidate($setting, $requiredQty, $batchCode, $arrivalResult);

            // 供給元倉庫への需要を登録（次の階層で使用）
            $this->addInternalDemand(
                $setting->source_warehouse_id,
                $setting->item_id,
                $requiredQty
            );
        } else {
            $this->createOrderCandidate($setting, $requiredQty, $internalDemand, $batchCode, $arrivalResult);
        }

        return true;
    }

    /**
     * 移動候補（内部移動）を作成
     */
    private function createTransferCandidate(
        WmsItemSupplySetting $setting,
        int $quantity,
        string $batchCode,
        array $arrivalResult
    ): WmsStockTransferCandidate {
        return WmsStockTransferCandidate::create([
            'batch_code' => $batchCode,
            'satellite_warehouse_id' => $setting->warehouse_id,
            'hub_warehouse_id' => $setting->source_warehouse_id,
            'item_id' => $setting->item_id,
            'contractor_id' => $setting->itemContractor?->contractor_id,
            'suggested_quantity' => $quantity,
            'transfer_quantity' => $quantity,
            'quantity_type' => QuantityType::PIECE,
            'expected_arrival_date' => $arrivalResult['arrival_date'],
            'original_arrival_date' => $arrivalResult['original_date'],
            'status' => CandidateStatus::PENDING,
            'lot_status' => LotStatus::RAW,
        ]);
    }

    /**
     * 発注候補（外部発注）を作成
     */
    private function createOrderCandidate(
        WmsItemSupplySetting $setting,
        int $totalRequired,
        int $internalDemand,
        string $batchCode,
        array $arrivalResult
    ): WmsOrderCandidate {
        $selfShortage = max(0, $totalRequired - $internalDemand);

        $contractorId = $setting->itemContractor?->contractor_id;

        return WmsOrderCandidate::create([
            'batch_code' => $batchCode,
            'warehouse_id' => $setting->warehouse_id,
            'item_id' => $setting->item_id,
            'contractor_id' => $contractorId,
            'self_shortage_qty' => $selfShortage,
            'satellite_demand_qty' => $internalDemand,
            'suggested_quantity' => $totalRequired,
            'order_quantity' => $totalRequired,
            'quantity_type' => QuantityType::PIECE,
            'expected_arrival_date' => $arrivalResult['arrival_date'],
            'original_arrival_date' => $arrivalResult['original_date'],
            'status' => CandidateStatus::PENDING,
            'lot_status' => $contractorId ? LotStatus::RAW : LotStatus::NEED_APPROVAL,
        ]);
    }

    /**
     * 内部移動需要を取得
     */
    private function getInternalDemand(int $warehouseId, int $itemId): int
    {
        return $this->internalDemands[$warehouseId][$itemId] ?? 0;
    }

    /**
     * 内部移動需要を追加
     */
    private function addInternalDemand(int $sourceWarehouseId, int $itemId, int $quantity): void
    {
        if (!isset($this->internalDemands[$sourceWarehouseId])) {
            $this->internalDemands[$sourceWarehouseId] = [];
        }

        if (!isset($this->internalDemands[$sourceWarehouseId][$itemId])) {
            $this->internalDemands[$sourceWarehouseId][$itemId] = 0;
        }

        $this->internalDemands[$sourceWarehouseId][$itemId] += $quantity;
    }

    /**
     * 計算ログを記録
     */
    private function logCalculation(WmsItemSupplySetting $setting, string $batchCode, array $details): void
    {
        WmsOrderCalculationLog::create([
            'batch_code' => $batchCode,
            'warehouse_id' => $setting->warehouse_id,
            'item_id' => $setting->item_id,
            'calculation_type' => $setting->isInternal() ? CalculationType::SATELLITE : CalculationType::HUB,
            'current_effective_stock' => $details['effective_stock'],
            'incoming_quantity' => $details['incoming_stock'],
            'safety_stock_setting' => $setting->safety_stock_qty,
            'lead_time_days' => $setting->lead_time_days,
            'calculated_shortage_qty' => $details['required_qty'],
            'calculated_order_quantity' => $details['required_qty'],
            'calculation_details' => [
                'supply_type' => $setting->supply_type->value,
                'hierarchy_level' => $setting->hierarchy_level,
                'internal_demand' => $details['internal_demand'],
                'consumption_during_lt' => $details['consumption_during_lt'],
                'source_warehouse_id' => $setting->source_warehouse_id,
                'arrival_date_shifted' => $details['arrival_result']['shifted_days'] > 0,
                'shifted_days' => $details['arrival_result']['shifted_days'],
            ],
        ]);
    }

    /**
     * 入荷予定日を計算（休日考慮）
     */
    private function calculateArrivalDate(int $warehouseId, int $leadTimeDays, Carbon $baseDate): array
    {
        $tempDate = $baseDate->copy()->addDays($leadTimeDays);
        $originalDate = $tempDate->copy();
        $shiftedDays = 0;

        $maxIterations = 30;
        for ($i = 0; $i < $maxIterations; $i++) {
            if (!WmsWarehouseCalendar::isHoliday($warehouseId, $tempDate)) {
                break;
            }
            $tempDate->addDay();
            $shiftedDays++;
        }

        return [
            'arrival_date' => $tempDate,
            'original_date' => $originalDate,
            'shifted_days' => $shiftedDays,
        ];
    }
}
