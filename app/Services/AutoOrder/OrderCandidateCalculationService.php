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

    /** @var array 実倉庫（is_virtual=false）のIDリスト */
    private array $realWarehouseIds = [];

    /** @var array [item_id] => ['code' => ..., 'name' => ..., 'packaging' => ...] */
    private array $itemMaster = [];

    /** @var array [warehouse_id] => ['code' => ..., 'name' => ...] */
    private array $warehouseMaster = [];

    /** @var array [contractor_id] => ['code' => ..., 'name' => ...] */
    private array $contractorMaster = [];

    /** @var array [supplier_id] => ['code' => ..., 'name' => ...] */
    private array $supplierMaster = [];

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
        // 実倉庫（is_virtual=false）のIDをロード
        $this->realWarehouseIds = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_virtual', false)
            ->pluck('id')
            ->toArray();

        Log::info('実倉庫をロード', ['count' => count($this->realWarehouseIds)]);

        // JOINでsafety_stock > 0の商品のスナップショットのみを取得（実倉庫のみ）
        $snapshots = DB::connection('sakemaru')
            ->table('wms_item_stock_snapshots as s')
            ->join('item_contractors as ic', function ($join) {
                $join->on('s.warehouse_id', '=', 'ic.warehouse_id')
                    ->on('s.item_id', '=', 'ic.item_id');
            })
            ->whereIn('s.warehouse_id', $this->realWarehouseIds)
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

        Log::info('在庫スナップショットをロード（実倉庫のみ）', ['count' => $snapshots->count()]);

        // INTERNAL発注先設定をメモリにロード
        $settings = WmsContractorSetting::where('transmission_type', TransmissionType::INTERNAL)
            ->whereNotNull('supply_warehouse_id')
            ->get();

        foreach ($settings as $s) {
            $this->internalSettings[$s->contractor_id] = $s->supply_warehouse_id;
            $this->internalContractorIds[] = $s->contractor_id;
        }

        Log::info('内部移動設定をロード', ['count' => count($this->internalSettings)]);

        // 商品マスタをメモリにロード
        $items = DB::connection('sakemaru')
            ->table('items')
            ->select('id', 'code', 'name', 'packaging')
            ->get();

        foreach ($items as $item) {
            $this->itemMaster[$item->id] = [
                'code' => $item->code,
                'name' => $item->name,
                'packaging' => $item->packaging,
            ];
        }

        Log::info('商品マスタをロード', ['count' => count($this->itemMaster)]);

        // 倉庫マスタをメモリにロード
        $warehouses = DB::connection('sakemaru')
            ->table('warehouses')
            ->select('id', 'code', 'name')
            ->get();

        foreach ($warehouses as $w) {
            $this->warehouseMaster[$w->id] = [
                'code' => $w->code,
                'name' => $w->name,
            ];
        }

        Log::info('倉庫マスタをロード', ['count' => count($this->warehouseMaster)]);

        // 発注先マスタをメモリにロード
        $contractors = DB::connection('sakemaru')
            ->table('contractors')
            ->select('id', 'code', 'name')
            ->get();

        foreach ($contractors as $c) {
            $this->contractorMaster[$c->id] = [
                'code' => $c->code,
                'name' => $c->name,
            ];
        }

        Log::info('発注先マスタをロード', ['count' => count($this->contractorMaster)]);

        // 仕入先マスタをメモリにロード（suppliers + partners結合）
        $suppliers = DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 's.partner_id', '=', 'p.id')
            ->select('s.id', 'p.code', 'p.name')
            ->get();

        foreach ($suppliers as $s) {
            $this->supplierMaster[$s->id] = [
                'code' => $s->code,
                'name' => $s->name,
            ];
        }

        Log::info('仕入先マスタをロード', ['count' => count($this->supplierMaster)]);
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
            Log::info('INTERNAL発注先が見つかりません');
            return 0;
        }

        // INTERNAL発注先の商品を取得（safety_stock > 0、実倉庫のみ）
        $itemContractors = DB::connection('sakemaru')
            ->table('item_contractors')
            ->whereIn('contractor_id', $this->internalContractorIds)
            ->whereIn('warehouse_id', $this->realWarehouseIds)
            ->where('is_auto_order', true)
            ->where('safety_stock', '>', 0)
            ->select('id', 'warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'safety_stock', 'purchase_unit')
            ->get();

        $leadTimeDays = 1; // 内部移動は1日と仮定
        $arrivalDate = $now->copy()->addDays($leadTimeDays)->format('Y-m-d');
        $insertData = [];

        foreach ($itemContractors as $ic) {
            $supplyWarehouseId = $this->internalSettings[$ic->contractor_id] ?? null;
            if (!$supplyWarehouseId) {
                continue;
            }

            // 依頼倉庫と横持ち出荷倉庫が同じ場合はスキップ（意味がないため）
            if ($ic->warehouse_id === $supplyWarehouseId) {
                Log::warning('移動候補スキップ: 同一倉庫', [
                    'warehouse_id' => $ic->warehouse_id,
                    'item_id' => $ic->item_id,
                    'contractor_id' => $ic->contractor_id,
                ]);
                continue;
            }

            // 在庫を取得
            $stock = $this->stockSnapshots[$ic->warehouse_id][$ic->item_id] ?? null;
            $effectiveStock = $stock['effective'] ?? 0;
            $incomingStock = $stock['incoming'] ?? 0;

            // 必要数計算（不足数）
            $shortageQty = $ic->safety_stock - ($effectiveStock + $incomingStock);

            if ($shortageQty <= 0) {
                continue;
            }

            // 最小仕入単位で切り上げ
            $purchaseUnit = max(1, (int) ($ic->purchase_unit ?? 1));
            $orderQty = $this->roundUpToUnit($shortageQty, $purchaseUnit);

            $insertData[] = [
                'batch_code' => $batchCode,
                'satellite_warehouse_id' => $ic->warehouse_id,
                'hub_warehouse_id' => $supplyWarehouseId,
                'item_id' => $ic->item_id,
                'contractor_id' => $ic->contractor_id,
                'suggested_quantity' => $orderQty,
                'transfer_quantity' => $orderQty,
                'quantity_type' => QuantityType::PIECE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $arrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // マスタ情報を取得
            $itemInfo = $this->itemMaster[$ic->item_id] ?? null;
            $warehouseInfo = $this->warehouseMaster[$ic->warehouse_id] ?? null;
            $contractorInfo = $this->contractorMaster[$ic->contractor_id] ?? null;
            $supplierInfo = $this->supplierMaster[$ic->supplier_id] ?? null;
            $supplyWarehouseInfo = $this->warehouseMaster[$supplyWarehouseId] ?? null;

            // 計算ログを追加（日本語）
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
                'calculated_shortage_qty' => $shortageQty,
                'calculated_order_quantity' => $orderQty,
                'calculation_details' => json_encode([
                    '商品コード' => $itemInfo['code'] ?? null,
                    '商品名' => $itemInfo['name'] ?? null,
                    '規格' => $itemInfo['packaging'] ?? null,
                    '仕入先コード' => $supplierInfo['code'] ?? null,
                    '仕入先名' => $supplierInfo['name'] ?? null,
                    '発注先コード' => $contractorInfo['code'] ?? null,
                    '発注先名' => $contractorInfo['name'] ?? null,
                    '発注倉庫コード' => $warehouseInfo['code'] ?? null,
                    '発注倉庫名' => $warehouseInfo['name'] ?? null,
                    '供給元倉庫コード' => $supplyWarehouseInfo['code'] ?? null,
                    '供給元倉庫名' => $supplyWarehouseInfo['name'] ?? null,
                    '計算式' => '安全在庫 - (有効在庫 + 入庫予定)',
                    '有効在庫' => $effectiveStock,
                    '入庫予定数' => $incomingStock,
                    '安全在庫' => $ic->safety_stock,
                    '利用可能在庫' => $effectiveStock + $incomingStock,
                    '不足数' => $shortageQty,
                    '最小仕入単位' => $purchaseUnit,
                    '単位調整後数量' => $orderQty,
                    '単位調整説明' => $purchaseUnit > 1
                        ? "不足数{$shortageQty}を最小仕入単位{$purchaseUnit}で切り上げ → {$orderQty}"
                        : '最小仕入単位が1のため調整なし',
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        // バルクインサート（1000件ずつ）
        $chunks = array_chunk($insertData, 1000);
        foreach ($chunks as $chunk) {
            WmsStockTransferCandidate::insert($chunk);
        }

        $count = count($insertData);
        Log::info('内部移動候補を作成', ['count' => $count]);

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
        // EXTERNAL発注先の商品を取得（safety_stock > 0、実倉庫のみ）
        $itemContractors = DB::connection('sakemaru')
            ->table('item_contractors')
            ->whereNotIn('contractor_id', $this->internalContractorIds ?: [0])
            ->whereIn('warehouse_id', $this->realWarehouseIds)
            ->where('is_auto_order', true)
            ->where('safety_stock', '>', 0)
            ->select('id', 'warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'safety_stock', 'purchase_unit')
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

            // 必要数計算（不足数）
            $shortageQty = $ic->safety_stock - $calculatedStock;

            if ($shortageQty <= 0) {
                continue;
            }

            // 最小仕入単位で切り上げ
            $purchaseUnit = max(1, (int) ($ic->purchase_unit ?? 1));
            $orderQty = $this->roundUpToUnit($shortageQty, $purchaseUnit);

            $insertData[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'contractor_id' => $ic->contractor_id,
                'self_shortage_qty' => $shortageQty,
                'satellite_demand_qty' => $outgoingToTransfer,
                'suggested_quantity' => $orderQty,
                'order_quantity' => $orderQty,
                'quantity_type' => QuantityType::PIECE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $arrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // マスタ情報を取得
            $itemInfo = $this->itemMaster[$ic->item_id] ?? null;
            $warehouseInfo = $this->warehouseMaster[$ic->warehouse_id] ?? null;
            $contractorInfo = $this->contractorMaster[$ic->contractor_id] ?? null;
            $supplierInfo = $this->supplierMaster[$ic->supplier_id] ?? null;

            // 計算ログを追加（日本語）
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
                'calculated_shortage_qty' => $shortageQty,
                'calculated_order_quantity' => $orderQty,
                'calculation_details' => json_encode([
                    '商品コード' => $itemInfo['code'] ?? null,
                    '商品名' => $itemInfo['name'] ?? null,
                    '規格' => $itemInfo['packaging'] ?? null,
                    '仕入先コード' => $supplierInfo['code'] ?? null,
                    '仕入先名' => $supplierInfo['name'] ?? null,
                    '発注先コード' => $contractorInfo['code'] ?? null,
                    '発注先名' => $contractorInfo['name'] ?? null,
                    '発注倉庫コード' => $warehouseInfo['code'] ?? null,
                    '発注倉庫名' => $warehouseInfo['name'] ?? null,
                    '計算式' => '安全在庫 - (有効在庫 + 入庫予定 + 移動入庫 - 移動出庫)',
                    '有効在庫' => $effectiveStock,
                    '入庫予定数' => $incomingStock,
                    '移動入庫予定' => $incomingFromTransfer,
                    '移動出庫予定' => $outgoingToTransfer,
                    '安全在庫' => $ic->safety_stock,
                    '利用可能在庫' => $calculatedStock,
                    '不足数' => $shortageQty,
                    '最小仕入単位' => $purchaseUnit,
                    '単位調整後数量' => $orderQty,
                    '単位調整説明' => $purchaseUnit > 1
                        ? "不足数{$shortageQty}を最小仕入単位{$purchaseUnit}で切り上げ → {$orderQty}"
                        : '最小仕入単位が1のため調整なし',
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        // バルクインサート（1000件ずつ）
        $chunks = array_chunk($insertData, 1000);
        foreach ($chunks as $chunk) {
            WmsOrderCandidate::insert($chunk);
        }

        $count = count($insertData);
        Log::info('外部発注候補を作成', ['count' => $count]);

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

        Log::info('計算ログを保存', ['count' => count($this->calculationLogs)]);
    }

    /**
     * 数量を指定単位で切り上げ
     *
     * @param int $quantity 数量
     * @param int $unit 単位（1以上）
     * @return int 切り上げ後の数量
     */
    private function roundUpToUnit(int $quantity, int $unit): int
    {
        if ($unit <= 1) {
            return $quantity;
        }

        return (int) ceil($quantity / $unit) * $unit;
    }
}
