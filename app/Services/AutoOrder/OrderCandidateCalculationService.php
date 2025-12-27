<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Models\Sakemaru\ItemContractor;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsStockTransferCandidate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 発注候補計算サービス（最適化版）
 *
 * item_contractors + wms_contractor_settings ベースで計算
 * 1. 全データをメモリにロード
 * 2. INTERNAL発注先の移動候補を計算
 * 3. EXTERNAL発注先の発注候補を計算（移動候補を考慮）
 * 4. バルクインサート
 */
class OrderCandidateCalculationService
{
    /** @var array [warehouse_id][item_id] => stock_data */
    private array $stockSnapshots = [];

    /** @var array [contractor_id] => setting */
    private array $internalSettings = [];

    /** @var array contractor_ids */
    private array $internalContractorIds = [];

    /** @var array 計算ログデータ */
    private array $calculationLogs = [];

    /**
     * 発注候補計算を実行
     */
    public function calculate(): WmsAutoOrderJobControl
    {
        if (WmsAutoOrderJobControl::hasRunningJob(JobProcessName::ORDER_CALC)) {
            throw new \RuntimeException('Order calculation job is already running');
        }

        $job = WmsAutoOrderJobControl::startJob(JobProcessName::ORDER_CALC);

        try {
            $batchCode = $job->batch_code;
            $now = now();

            Log::info('Order candidate calculation started', ['batch_code' => $batchCode]);

            // 既存の同バッチコードの候補を削除
            $this->deleteCandidatesByBatch($batchCode);

            // Step 1: 全データをメモリにロード
            $this->loadAllDataToMemory();

            $job->updateProgress(1, 4);

            // Step 2: INTERNAL移動候補を計算・バルクインサート
            $transferCount = $this->createInternalTransferCandidatesBulk($batchCode, $now);

            $job->updateProgress(2, 4);

            // Step 3: 移動候補をメモリにロード（EXTERNAL計算用）
            $transferCandidates = $this->loadTransferCandidatesToMemory($batchCode);

            $job->updateProgress(3, 4);

            // Step 4: EXTERNAL発注候補を計算・バルクインサート
            $orderCount = $this->createExternalOrderCandidatesBulk($batchCode, $now, $transferCandidates);

            // Step 5: 計算ログをバルクインサート
            $this->insertCalculationLogs();

            $job->updateProgress(4, 4);
            $job->markAsSuccess($transferCount + $orderCount);

            Log::info('Order candidate calculation completed', [
                'batch_code' => $batchCode,
                'transfer_candidates' => $transferCount,
                'order_candidates' => $orderCount,
            ]);

        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            Log::error('Order candidate calculation failed', [
                'batch_code' => $job->batch_code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $job;
    }

    /**
     * 必要なデータのみをメモリにロード（safety_stock > 0の商品のみ）
     */
    private function loadAllDataToMemory(): void
    {
        // JOINでsafety_stock > 0の商品のスナップショットのみを取得
        $snapshots = DB::connection('sakemaru')
            ->table('wms_item_stock_snapshots as s')
            ->join('item_contractors as ic', function ($join) {
                $join->on('s.warehouse_id', '=', 'ic.warehouse_id')
                    ->on('s.item_id', '=', 'ic.item_id');
            })
            ->where('ic.is_auto_order', true)
            ->where('ic.safety_stock', '>', 0)
            ->select('s.warehouse_id', 's.item_id', 's.total_effective_piece', 's.total_incoming_piece')
            ->distinct()
            ->get();

        foreach ($snapshots as $s) {
            if (!isset($this->stockSnapshots[$s->warehouse_id])) {
                $this->stockSnapshots[$s->warehouse_id] = [];
            }
            $this->stockSnapshots[$s->warehouse_id][$s->item_id] = [
                'effective' => $s->total_effective_piece,
                'incoming' => $s->total_incoming_piece,
            ];
        }

        Log::info('Stock snapshots loaded (filtered)', ['count' => $snapshots->count()]);

        // INTERNAL発注先設定をメモリにロード
        $settings = WmsContractorSetting::where('transmission_type', TransmissionType::INTERNAL)
            ->whereNotNull('supply_warehouse_id')
            ->get();

        foreach ($settings as $s) {
            $this->internalSettings[$s->contractor_id] = $s->supply_warehouse_id;
            $this->internalContractorIds[] = $s->contractor_id;
        }

        Log::info('Internal settings loaded', ['count' => count($this->internalSettings)]);
    }

    /**
     * バッチコードで候補とログを削除
     */
    public function deleteCandidatesByBatch(string $batchCode): void
    {
        WmsStockTransferCandidate::where('batch_code', $batchCode)->delete();
        WmsOrderCandidate::where('batch_code', $batchCode)->delete();
        WmsOrderCalculationLog::where('batch_code', $batchCode)->delete();
    }

    /**
     * INTERNAL移動候補をバルク作成
     */
    private function createInternalTransferCandidatesBulk(string $batchCode, Carbon $now): int
    {
        if (empty($this->internalContractorIds)) {
            Log::info('No INTERNAL contractors found');
            return 0;
        }

        // INTERNAL発注先の商品を取得（safety_stock > 0のみ）
        $itemContractors = DB::connection('sakemaru')
            ->table('item_contractors')
            ->whereIn('contractor_id', $this->internalContractorIds)
            ->where('is_auto_order', true)
            ->where('safety_stock', '>', 0)
            ->select('id', 'warehouse_id', 'item_id', 'contractor_id', 'safety_stock')
            ->get();

        $leadTimeDays = 1; // 内部移動は1日と仮定
        $arrivalDate = $now->copy()->addDays($leadTimeDays)->format('Y-m-d');
        $insertData = [];

        foreach ($itemContractors as $ic) {
            $supplyWarehouseId = $this->internalSettings[$ic->contractor_id] ?? null;
            if (!$supplyWarehouseId) {
                continue;
            }

            // 在庫を取得
            $stock = $this->stockSnapshots[$ic->warehouse_id][$ic->item_id] ?? null;
            $effectiveStock = $stock['effective'] ?? 0;
            $incomingStock = $stock['incoming'] ?? 0;

            // 必要数計算
            $requiredQty = $ic->safety_stock - ($effectiveStock + $incomingStock);

            if ($requiredQty <= 0) {
                continue;
            }

            $insertData[] = [
                'batch_code' => $batchCode,
                'satellite_warehouse_id' => $ic->warehouse_id,
                'hub_warehouse_id' => $supplyWarehouseId,
                'item_id' => $ic->item_id,
                'contractor_id' => $ic->contractor_id,
                'suggested_quantity' => $requiredQty,
                'transfer_quantity' => $requiredQty,
                'quantity_type' => QuantityType::PIECE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $arrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // 計算ログを追加
            $this->calculationLogs[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'calculation_type' => CalculationType::INTERNAL->value,
                'contractor_id' => $ic->contractor_id,
                'source_warehouse_id' => $supplyWarehouseId,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'safety_stock_setting' => $ic->safety_stock,
                'lead_time_days' => $leadTimeDays,
                'calculated_shortage_qty' => $requiredQty,
                'calculated_order_quantity' => $requiredQty,
                'calculation_details' => json_encode([
                    'formula' => 'safety_stock - (effective_stock + incoming_stock)',
                    'effective_stock' => $effectiveStock,
                    'incoming_stock' => $incomingStock,
                    'safety_stock' => $ic->safety_stock,
                    'calculated_available' => $effectiveStock + $incomingStock,
                    'shortage_qty' => $requiredQty,
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        // バルクインサート（1000件ずつ）
        $chunks = array_chunk($insertData, 1000);
        foreach ($chunks as $chunk) {
            WmsStockTransferCandidate::insert($chunk);
        }

        $count = count($insertData);
        Log::info('Internal transfer candidates created', ['count' => $count]);

        return $count;
    }

    /**
     * 移動候補をメモリにロード
     * @return array [warehouse_id][item_id] => ['incoming' => qty, 'outgoing' => qty]
     */
    private function loadTransferCandidatesToMemory(string $batchCode): array
    {
        $candidates = DB::connection('sakemaru')
            ->table('wms_stock_transfer_candidates')
            ->where('batch_code', $batchCode)
            ->select('satellite_warehouse_id', 'hub_warehouse_id', 'item_id', 'transfer_quantity')
            ->get();

        $result = [];

        foreach ($candidates as $c) {
            // 移動先（入庫）
            if (!isset($result[$c->satellite_warehouse_id][$c->item_id])) {
                $result[$c->satellite_warehouse_id][$c->item_id] = ['incoming' => 0, 'outgoing' => 0];
            }
            $result[$c->satellite_warehouse_id][$c->item_id]['incoming'] += $c->transfer_quantity;

            // 移動元（出庫）
            if (!isset($result[$c->hub_warehouse_id][$c->item_id])) {
                $result[$c->hub_warehouse_id][$c->item_id] = ['incoming' => 0, 'outgoing' => 0];
            }
            $result[$c->hub_warehouse_id][$c->item_id]['outgoing'] += $c->transfer_quantity;
        }

        return $result;
    }

    /**
     * EXTERNAL発注候補をバルク作成
     */
    private function createExternalOrderCandidatesBulk(string $batchCode, Carbon $now, array $transferCandidates): int
    {
        // EXTERNAL発注先の商品を取得（safety_stock > 0のみ）
        $itemContractors = DB::connection('sakemaru')
            ->table('item_contractors')
            ->whereNotIn('contractor_id', $this->internalContractorIds)
            ->where('is_auto_order', true)
            ->where('safety_stock', '>', 0)
            ->select('id', 'warehouse_id', 'item_id', 'contractor_id', 'safety_stock')
            ->get();

        $defaultLeadTime = 3;
        $arrivalDate = $now->copy()->addDays($defaultLeadTime)->format('Y-m-d');
        $insertData = [];

        foreach ($itemContractors as $ic) {
            // 在庫を取得
            $stock = $this->stockSnapshots[$ic->warehouse_id][$ic->item_id] ?? null;
            $effectiveStock = $stock['effective'] ?? 0;
            $incomingStock = $stock['incoming'] ?? 0;

            // 移動候補の影響を取得
            $transfer = $transferCandidates[$ic->warehouse_id][$ic->item_id] ?? null;
            $incomingFromTransfer = $transfer['incoming'] ?? 0;
            $outgoingToTransfer = $transfer['outgoing'] ?? 0;

            // 計算用在庫
            $calculatedStock = $effectiveStock + $incomingStock + $incomingFromTransfer - $outgoingToTransfer;

            // 必要数計算
            $requiredQty = $ic->safety_stock - $calculatedStock;

            if ($requiredQty <= 0) {
                continue;
            }

            $insertData[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'contractor_id' => $ic->contractor_id,
                'self_shortage_qty' => $requiredQty,
                'satellite_demand_qty' => $outgoingToTransfer,
                'suggested_quantity' => $requiredQty,
                'order_quantity' => $requiredQty,
                'quantity_type' => QuantityType::PIECE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $arrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // 計算ログを追加
            $this->calculationLogs[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'calculation_type' => CalculationType::EXTERNAL->value,
                'contractor_id' => $ic->contractor_id,
                'source_warehouse_id' => null,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'safety_stock_setting' => $ic->safety_stock,
                'lead_time_days' => $defaultLeadTime,
                'calculated_shortage_qty' => $requiredQty,
                'calculated_order_quantity' => $requiredQty,
                'calculation_details' => json_encode([
                    'formula' => 'safety_stock - (effective_stock + incoming_stock + transfer_in - transfer_out)',
                    'effective_stock' => $effectiveStock,
                    'incoming_stock' => $incomingStock,
                    'transfer_incoming' => $incomingFromTransfer,
                    'transfer_outgoing' => $outgoingToTransfer,
                    'safety_stock' => $ic->safety_stock,
                    'calculated_available' => $calculatedStock,
                    'shortage_qty' => $requiredQty,
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        // バルクインサート（1000件ずつ）
        $chunks = array_chunk($insertData, 1000);
        foreach ($chunks as $chunk) {
            WmsOrderCandidate::insert($chunk);
        }

        $count = count($insertData);
        Log::info('External order candidates created', ['count' => $count]);

        return $count;
    }

    /**
     * 計算ログをバルクインサート
     */
    private function insertCalculationLogs(): void
    {
        if (empty($this->calculationLogs)) {
            return;
        }

        $chunks = array_chunk($this->calculationLogs, 1000);
        foreach ($chunks as $chunk) {
            WmsOrderCalculationLog::insert($chunk);
        }

        Log::info('Calculation logs inserted', ['count' => count($this->calculationLogs)]);
    }
}
