<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CalculationType;
use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\AutoOrder\SettlementStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\QuantityType;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCalculationLog;
use App\Models\WmsOrderCandidate;
use App\Models\WmsStockTransferCandidate;
use App\Models\WmsWarehouseAutoOrderSetting;
use App\Support\DbMutex;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesBasedOrderCandidateService
{
    private array $stockSnapshots = [];

    private array $internalSettings = [];

    private array $internalContractorIds = [];

    private array $calculationLogs = [];

    private array $realWarehouseIds = [];

    private array $itemMaster = [];

    private array $warehouseMaster = [];

    private array $contractorMaster = [];

    private array $supplierMaster = [];

    private array $transferDeliveryCourses = [];

    private array $contractorLeadTimes = [];

    private array $deliveryDaySettings = [];

    private array $warehouseHolidays = [];

    private array $orderingCodes = [];

    /** @var array [item_id] => 発注荷姿入数 (item_quantity_information.quantity) */
    private array $orderingUnitQuantities = [];

    private array $searchCodes = [];

    private array $supplierItemPrices = [];

    private array $supplierItemCasePrices = [];

    private array $salesSummaries3d = [];

    private string $salesBasis = 'last_3d';

    private string $orderPointFilter = 'ignore';

    private string $autoOrderFlagFilter = 'ignore';

    private ?array $targetContractorIds = null;

    private ?int $targetWarehouseId = null;

    private OriginType $originType = OriginType::MANUAL_SALES_BASED;

    public function calculate(
        ?int $warehouseId,
        int $createdBy,
        ?array $contractorIds = null,
        ?string $batchCode = null,
        ?OriginType $originType = null,
        string $salesBasis = 'last_3d',
        string $orderPointFilter = 'ignore',
        string $autoOrderFlagFilter = 'ignore',
    ): WmsAutoOrderJobControl {
        $lockKey = $this->salesBasedGenerationLockKey($warehouseId);

        if (! DbMutex::acquire($lockKey, 5, 'sakemaru')) {
            throw new \RuntimeException('同じ倉庫の実績ベース発注候補生成が実行中です。完了後に再実行してください。');
        }

        try {
            return $this->calculateLocked(
                warehouseId: $warehouseId,
                createdBy: $createdBy,
                contractorIds: $contractorIds,
                batchCode: $batchCode,
                originType: $originType,
                salesBasis: $salesBasis,
                orderPointFilter: $orderPointFilter,
                autoOrderFlagFilter: $autoOrderFlagFilter,
            );
        } finally {
            DbMutex::release($lockKey, 'sakemaru');
        }
    }

    private function calculateLocked(
        ?int $warehouseId,
        int $createdBy,
        ?array $contractorIds = null,
        ?string $batchCode = null,
        ?OriginType $originType = null,
        string $salesBasis = 'last_3d',
        string $orderPointFilter = 'ignore',
        string $autoOrderFlagFilter = 'ignore',
    ): WmsAutoOrderJobControl {
        if ($this->hasRunningSalesBasedJobForWarehouse($warehouseId)) {
            throw new \RuntimeException('同じ倉庫の実績ベース発注候補生成が実行中です。完了後に再実行してください。');
        }

        $salesBasis = $this->normalizeSalesBasis($salesBasis);
        $orderPointFilter = $this->normalizeOrderPointFilter($orderPointFilter);
        $autoOrderFlagFilter = $this->normalizeAutoOrderFlagFilter($autoOrderFlagFilter);

        $targetContractorIds = null;
        if ($contractorIds !== null && ! empty($contractorIds)) {
            $targetContractorIds = array_values(array_unique(array_map('intval', $contractorIds)));
        }

        $job = WmsAutoOrderJobControl::startJob(
            processName: JobProcessName::SALES_BASED_CALC,
            scope: [
                'contractor_ids' => $contractorIds,
                'source' => 'sales_based',
                'sales_basis' => $salesBasis,
                'order_point_filter' => $orderPointFilter,
                'auto_order_flag_filter' => $autoOrderFlagFilter,
            ],
            batchCode: $batchCode,
            settlementStatus: SettlementStatus::PENDING,
            createdBy: $createdBy,
            warehouseId: $warehouseId,
        );

        $this->targetContractorIds = $targetContractorIds;
        $this->targetWarehouseId = $warehouseId;
        $this->originType = $originType ?? OriginType::MANUAL_SALES_BASED;
        $this->salesBasis = $salesBasis;
        $this->orderPointFilter = $orderPointFilter;
        $this->autoOrderFlagFilter = $autoOrderFlagFilter;

        try {
            $batchCode = $job->batch_code;
            $now = now();

            Log::info('Sales-based order candidate calculation started', [
                'batch_code' => $batchCode,
                'sales_basis' => $this->salesBasis,
                'order_point_filter' => $this->orderPointFilter,
                'auto_order_flag_filter' => $this->autoOrderFlagFilter,
            ]);

            $this->loadAllDataToMemory();
            $job->updateProgress(1, 4);

            $transferCount = $this->createInternalTransferCandidatesBulk($batchCode, $now);
            $job->updateProgress(2, 4);

            $transferCandidates = $this->loadTransferCandidatesToMemory($batchCode);
            $job->updateProgress(3, 4);

            $orderCount = $this->createExternalOrderCandidatesBulk($batchCode, $now, $transferCandidates);

            $this->insertCalculationLogs();
            $job->updateProgress(4, 4);

            $resultData = $this->buildResultData($batchCode, $transferCount, $orderCount);
            $job->markAsSuccess($transferCount + $orderCount, $resultData);

            Log::info('Sales-based order candidate calculation completed', [
                'batch_code' => $batchCode,
                'transfer_candidates' => $transferCount,
                'order_candidates' => $orderCount,
            ]);
        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
            Log::error('Sales-based order candidate calculation failed', [
                'batch_code' => $job->batch_code,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $job;
    }

    private function salesBasedGenerationLockKey(?int $warehouseId): string
    {
        return 'wms:sales_based_order_candidate:warehouse:'.($warehouseId ?? 'all');
    }

    private function hasRunningSalesBasedJobForWarehouse(?int $warehouseId): bool
    {
        return WmsAutoOrderJobControl::query()
            ->where('process_name', JobProcessName::SALES_BASED_CALC)
            ->where('status', \App\Enums\AutoOrder\JobStatus::RUNNING)
            ->when(
                $warehouseId !== null,
                fn ($query) => $query->where('warehouse_id', $warehouseId),
                fn ($query) => $query->whereNull('warehouse_id'),
            )
            ->exists();
    }

    private function loadAllDataToMemory(): void
    {
        $enabledWarehouseIds = WmsWarehouseAutoOrderSetting::enabled()
            ->pluck('warehouse_id')
            ->toArray();

        if ($this->targetWarehouseId !== null) {
            $satelliteWarehouseIds = DB::connection('sakemaru')
                ->table('item_contractors')
                ->join('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'item_contractors.contractor_id')
                ->where('wcs.transmission_type', TransmissionType::INTERNAL->value)
                ->where('wcs.supply_warehouse_id', $this->targetWarehouseId)
                ->pluck('item_contractors.warehouse_id')
                ->unique()
                ->toArray();

            $enabledWarehouseIds = array_intersect(
                $enabledWarehouseIds,
                array_values(array_unique(array_merge([$this->targetWarehouseId], $satelliteWarehouseIds))),
            );
        }

        $this->realWarehouseIds = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_virtual', false)
            ->whereIn('id', $enabledWarehouseIds)
            ->pluck('id')
            ->toArray();

        Log::info('[SalesBased] 実倉庫をロード', ['count' => count($this->realWarehouseIds)]);

        $warehouseIdsList = implode(',', $this->realWarehouseIds);
        if (empty($this->realWarehouseIds)) {
            Log::info('[SalesBased] 対象倉庫なし、処理終了');

            return;
        }

        $effectiveStocks = DB::connection('sakemaru')
            ->select("
                SELECT warehouse_id, item_id, SUM(stock_qty) as total_effective
                FROM (
                    SELECT DISTINCT warehouse_id, item_id, real_stock_id, available_for_wms as stock_qty
                    FROM wms_v_stock_available
                    WHERE warehouse_id IN ({$warehouseIdsList})
                ) dedup
                GROUP BY warehouse_id, item_id
            ");

        foreach ($effectiveStocks as $s) {
            if (! isset($this->stockSnapshots[$s->warehouse_id])) {
                $this->stockSnapshots[$s->warehouse_id] = [];
            }
            $this->stockSnapshots[$s->warehouse_id][$s->item_id] = [
                'effective' => (int) $s->total_effective,
                'incoming' => 0,
            ];
        }

        $incomingStocks = DB::connection('sakemaru')
            ->table('wms_order_incoming_schedules')
            ->whereIn('warehouse_id', $this->realWarehouseIds)
            ->whereIn('status', ['PENDING', 'PARTIAL'])
            ->selectRaw('warehouse_id, item_id, SUM(expected_quantity - received_quantity) as total_incoming')
            ->groupBy('warehouse_id', 'item_id')
            ->get();

        foreach ($incomingStocks as $s) {
            if (isset($this->stockSnapshots[$s->warehouse_id][$s->item_id])) {
                $this->stockSnapshots[$s->warehouse_id][$s->item_id]['incoming'] = (int) $s->total_incoming;
            } else {
                if (! isset($this->stockSnapshots[$s->warehouse_id])) {
                    $this->stockSnapshots[$s->warehouse_id] = [];
                }
                $this->stockSnapshots[$s->warehouse_id][$s->item_id] = [
                    'effective' => 0,
                    'incoming' => (int) $s->total_incoming,
                ];
            }
        }

        $settings = WmsContractorSetting::where('transmission_type', TransmissionType::INTERNAL)
            ->whereNotNull('supply_warehouse_id')
            ->get();

        foreach ($settings as $s) {
            $this->internalSettings[$s->contractor_id] = $s->supply_warehouse_id;
            $this->internalContractorIds[] = $s->contractor_id;
        }

        $items = DB::connection('sakemaru')
            ->table('items')
            ->select('id', 'code', 'name', 'packaging', 'capacity_case')
            ->get();

        foreach ($items as $item) {
            $this->itemMaster[$item->id] = [
                'code' => $item->code,
                'name' => $item->name,
                'packaging' => $item->packaging,
                'capacity_case' => (int) ($item->capacity_case ?? 1),
            ];
        }

        $this->searchCodes = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->whereRaw("search_string REGEXP '[1-9]'")
            ->pluck('search_string', 'item_id')
            ->toArray();

        $systemDate = now()->format('Y-m-d');
        $supplierItemPrices = DB::connection('sakemaru')
            ->select('
                SELECT
                    ipp.item_id,
                    s.id AS supplier_id,
                    ipp.unit_price,
                    ipp.case_price
                FROM item_partner_prices ipp
                INNER JOIN (
                    SELECT item_id, partner_id, MAX(start_date) AS max_start_date
                    FROM item_partner_prices
                    WHERE partner_category = "SUPPLIER"
                      AND is_active = true
                      AND start_date <= ?
                    GROUP BY item_id, partner_id
                ) latest
                  ON ipp.item_id = latest.item_id
                 AND ipp.partner_id = latest.partner_id
                 AND ipp.start_date = latest.max_start_date
                INNER JOIN suppliers s
                  ON s.partner_id = ipp.partner_id
                 AND s.partner_category = ipp.partner_category
                INNER JOIN partners p
                  ON p.id = s.partner_id
                WHERE ipp.partner_category = "SUPPLIER"
                  AND ipp.is_active = true
                  AND p.is_supplier = true
            ', [$systemDate]);

        foreach ($supplierItemPrices as $row) {
            $this->supplierItemPrices[$row->item_id][$row->supplier_id] = $row->unit_price;
            $this->supplierItemCasePrices[$row->item_id][$row->supplier_id] = $row->case_price;
        }

        $warehouses = DB::connection('sakemaru')
            ->table('warehouses')
            ->select('id', 'code', 'name')
            ->get();

        foreach ($warehouses as $w) {
            $this->warehouseMaster[$w->id] = ['code' => $w->code, 'name' => $w->name];
        }

        $contractors = DB::connection('sakemaru')
            ->table('contractors')
            ->select('id', 'code', 'name')
            ->get();

        foreach ($contractors as $c) {
            $this->contractorMaster[$c->id] = ['code' => $c->code, 'name' => $c->name];
        }

        $suppliers = DB::connection('sakemaru')
            ->table('suppliers as s')
            ->join('partners as p', 's.partner_id', '=', 'p.id')
            ->where('s.partner_category', 'SUPPLIER')
            ->where('p.is_supplier', true)
            ->select('s.id', 'p.code', 'p.name')
            ->get();

        foreach ($suppliers as $s) {
            $this->supplierMaster[$s->id] = ['code' => $s->code, 'name' => $s->name];
        }

        $transferCourses = DB::connection('sakemaru')
            ->table('warehouse_stock_transfer_delivery_courses')
            ->select('from_warehouse_id', 'to_warehouse_id', 'delivery_course_id')
            ->get();

        foreach ($transferCourses as $tc) {
            if (! isset($this->transferDeliveryCourses[$tc->from_warehouse_id])) {
                $this->transferDeliveryCourses[$tc->from_warehouse_id] = [];
            }
            $this->transferDeliveryCourses[$tc->from_warehouse_id][$tc->to_warehouse_id] = $tc->delivery_course_id;
        }

        $contractorsWithLeadTime = DB::connection('sakemaru')
            ->table('contractors as c')
            ->join('lead_times as lt', 'c.lead_time_id', '=', 'lt.id')
            ->select('c.id as contractor_id', 'lt.lead_time_mon as lead_time')
            ->get();

        foreach ($contractorsWithLeadTime as $c) {
            $this->contractorLeadTimes[$c->contractor_id] = $c->lead_time;
        }

        $deliveryDays = DB::connection('sakemaru')
            ->table('wms_contractor_warehouse_delivery_days')
            ->get();

        foreach ($deliveryDays as $dd) {
            if (! isset($this->deliveryDaySettings[$dd->contractor_id])) {
                $this->deliveryDaySettings[$dd->contractor_id] = [];
            }
            $this->deliveryDaySettings[$dd->contractor_id][$dd->warehouse_id] = [
                'mon' => (bool) $dd->delivery_mon,
                'tue' => (bool) $dd->delivery_tue,
                'wed' => (bool) $dd->delivery_wed,
                'thu' => (bool) $dd->delivery_thu,
                'fri' => (bool) $dd->delivery_fri,
                'sat' => (bool) $dd->delivery_sat,
                'sun' => (bool) $dd->delivery_sun,
            ];
        }

        $startDate = now()->format('Y-m-d');
        $endDate = now()->addDays(30)->format('Y-m-d');
        $holidays = DB::connection('sakemaru')
            ->table('wms_warehouse_calendars')
            ->where('is_holiday', true)
            ->whereBetween('target_date', [$startDate, $endDate])
            ->get();

        foreach ($holidays as $h) {
            if (! isset($this->warehouseHolidays[$h->warehouse_id])) {
                $this->warehouseHolidays[$h->warehouse_id] = [];
            }
            $this->warehouseHolidays[$h->warehouse_id][$h->target_date] = true;
        }

        $orderingCodes = DB::connection('sakemaru')
            ->table('item_search_information as isi')
            ->leftJoin('item_quantity_information as iqi', 'iqi.id', '=', 'isi.item_quantity_information_id')
            ->where('isi.is_used_for_ordering', true)
            ->where('isi.is_active', true)
            ->whereRaw("isi.search_string REGEXP '[1-9]'")
            ->select('isi.item_id', 'isi.search_string', 'iqi.quantity as ordering_unit_qty')
            ->get();

        foreach ($orderingCodes as $oc) {
            $this->orderingCodes[$oc->item_id] = str_pad($oc->search_string, 13, '0', STR_PAD_LEFT);

            if ($oc->ordering_unit_qty !== null && (int) $oc->ordering_unit_qty > 1) {
                $this->orderingUnitQuantities[$oc->item_id] = (int) $oc->ordering_unit_qty;
            }
        }

        $salesSummaries = DB::connection('sakemaru')
            ->table('stats_item_warehouse_sales_summaries')
            ->whereIn('warehouse_id', $this->realWarehouseIds)
            ->where($this->salesBasisColumn(), '>', 0)
            ->select('warehouse_id', 'item_id', 'sales_today_qty', 'sales_yesterday_qty', 'last_3d_qty')
            ->get();

        foreach ($salesSummaries as $s) {
            if (! isset($this->salesSummaries3d[$s->warehouse_id])) {
                $this->salesSummaries3d[$s->warehouse_id] = [];
            }
            $this->salesSummaries3d[$s->warehouse_id][$s->item_id] = (int) match ($this->salesBasis) {
                'today' => $s->sales_today_qty,
                'yesterday' => $s->sales_yesterday_qty,
                default => $s->last_3d_qty,
            };
        }

        Log::info('[SalesBased] データロード完了');
    }

    private function createInternalTransferCandidatesBulk(string $batchCode, Carbon $now): int
    {
        if (empty($this->internalContractorIds)) {
            return 0;
        }

        $internalContractorIds = $this->internalContractorIds;
        if ($this->targetContractorIds !== null) {
            $internalContractorIds = array_values(array_intersect($internalContractorIds, $this->targetContractorIds));
            if (empty($internalContractorIds)) {
                return 0;
            }
        }

        $itemContractorQuery = DB::connection('sakemaru')
            ->table('item_contractors')
            ->join('items', 'item_contractors.item_id', '=', 'items.id')
            ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
            ->whereIn('item_contractors.contractor_id', $internalContractorIds)
            ->whereIn('item_contractors.warehouse_id', $this->realWarehouseIds)
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($q) => $q->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true);

        if ($this->autoOrderFlagFilter === 'on') {
            $itemContractorQuery->where('item_contractors.is_auto_order', true);
        } elseif ($this->autoOrderFlagFilter === 'off') {
            $itemContractorQuery->where('item_contractors.is_auto_order', false);
        }

        $itemContractors = $itemContractorQuery
            ->select('item_contractors.id', 'item_contractors.warehouse_id', 'item_contractors.item_id', 'item_contractors.contractor_id', 'item_contractors.supplier_id', 'item_contractors.safety_stock', 'item_contractors.purchase_unit')
            ->get();

        $insertData = [];
        $existingTransferKeys = $this->loadExistingTransferCandidateKeys($batchCode);
        $seenTransferKeys = $existingTransferKeys;
        $skippedExistingCount = 0;

        foreach ($itemContractors as $ic) {
            if (! isset($this->orderingCodes[$ic->item_id])) {
                continue;
            }

            $safetyStock = (int) $ic->safety_stock;
            $sales3dQty = $this->salesSummaries3d[$ic->warehouse_id][$ic->item_id] ?? 0;
            if ($sales3dQty <= 0) {
                continue;
            }

            $stock = $this->stockSnapshots[$ic->warehouse_id][$ic->item_id] ?? null;
            $effectiveStock = $stock['effective'] ?? 0;

            $incomingStock = $stock['incoming'] ?? 0;
            $projectedStock = $effectiveStock + $incomingStock;

            if ($projectedStock >= $sales3dQty) {
                continue;
            }

            $shortageQty = $sales3dQty - $projectedStock;
            $orderQty = $shortageQty;

            $supplyWarehouseId = $this->internalSettings[$ic->contractor_id] ?? null;
            if (! $supplyWarehouseId) {
                continue;
            }

            $candidateKey = "{$ic->warehouse_id}:{$supplyWarehouseId}:{$ic->item_id}:{$ic->contractor_id}";
            if (isset($seenTransferKeys[$candidateKey])) {
                $skippedExistingCount++;

                continue;
            }
            $seenTransferKeys[$candidateKey] = true;

            $deliveryCourseId = $this->transferDeliveryCourses[$supplyWarehouseId][$ic->warehouse_id] ?? null;

            $arrivalInfo = $this->calculateArrivalDate($ic->contractor_id, $ic->warehouse_id, $now->copy(), true);
            $arrivalDate = $arrivalInfo['arrival_date']->format('Y-m-d');
            $originalArrivalDate = $now->copy()->addDays($arrivalInfo['lead_time_days'])->format('Y-m-d');
            $leadTimeDays = $arrivalInfo['lead_time_days'];

            $insertData[] = [
                'batch_code' => $batchCode,
                'satellite_warehouse_id' => $ic->warehouse_id,
                'hub_warehouse_id' => $supplyWarehouseId,
                'item_id' => $ic->item_id,
                'item_code' => $this->itemMaster[$ic->item_id]['code'] ?? null,
                'search_code' => $this->searchCodes[$ic->item_id] ?? null,
                'contractor_id' => $ic->contractor_id,
                'delivery_course_id' => $deliveryCourseId,
                'suggested_quantity' => $orderQty,
                'transfer_quantity' => $orderQty,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'calculated_available' => $projectedStock,
                'shortage_qty' => $shortageQty,
                'safety_stock' => $safetyStock,
                'purchase_unit' => max(1, (int) ($ic->purchase_unit ?? 1)),
                'quantity_type' => QuantityType::PIECE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $originalArrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'origin_type' => $this->originType->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $itemInfo = $this->itemMaster[$ic->item_id] ?? null;
            $warehouseInfo = $this->warehouseMaster[$ic->warehouse_id] ?? null;
            $contractorInfo = $this->contractorMaster[$ic->contractor_id] ?? null;
            $supplierInfo = $this->supplierMaster[$ic->supplier_id] ?? null;
            $supplyWarehouseInfo = $this->warehouseMaster[$supplyWarehouseId] ?? null;

            $this->calculationLogs[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'calculation_type' => CalculationType::INTERNAL->value,
                'contractor_id' => $ic->contractor_id,
                'source_warehouse_id' => $supplyWarehouseId,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'safety_stock_setting' => $safetyStock,
                'lead_time_days' => $leadTimeDays,
                'calculated_shortage_qty' => $shortageQty,
                'calculated_order_quantity' => $orderQty,
                'calculation_details' => json_encode(array_merge([
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
                ], [
                    '計算タイプ' => '実績ベース',
                    '計算式' => '3日実績 - (有効在庫 + 入荷予定)',
                    '3日実績' => $sales3dQty,
                    '発注点' => $safetyStock,
                    '有効在庫' => $effectiveStock,
                    '入荷予定' => $incomingStock,
                    '見込み在庫' => $projectedStock,
                    '不足数' => $shortageQty,
                    '発注数量計算元' => '不足数',
                    '発注数量' => $orderQty,
                ], [
                    '到着日調整' => $arrivalInfo['shifted_days'],
                    '調整理由' => implode(', ', $arrivalInfo['shift_reasons']),
                ]), JSON_UNESCAPED_UNICODE),
            ];
        }

        $chunks = array_chunk($insertData, 1000);
        foreach ($chunks as $chunk) {
            WmsStockTransferCandidate::insert($chunk);
        }

        $count = count($insertData);
        Log::info('[SalesBased] 内部移動候補を作成', [
            'count' => $count,
            'skipped_existing' => $skippedExistingCount,
        ]);

        return $count;
    }

    private function createExternalOrderCandidatesBulk(string $batchCode, Carbon $now, array $transferCandidates): int
    {
        $externalQuery = DB::connection('sakemaru')
            ->table('item_contractors')
            ->join('items', 'item_contractors.item_id', '=', 'items.id')
            ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
            ->whereNotIn('item_contractors.contractor_id', $this->internalContractorIds ?: [0])
            ->whereIn('item_contractors.warehouse_id', $this->realWarehouseIds)
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($q) => $q->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true);

        if ($this->targetContractorIds !== null) {
            $externalQuery->whereIn('item_contractors.contractor_id', $this->targetContractorIds);
        }

        if ($this->autoOrderFlagFilter === 'on') {
            $externalQuery->where('item_contractors.is_auto_order', true);
        } elseif ($this->autoOrderFlagFilter === 'off') {
            $externalQuery->where('item_contractors.is_auto_order', false);
        }

        $selectColumns = ['item_contractors.id', 'item_contractors.warehouse_id', 'item_contractors.item_id', 'item_contractors.contractor_id', 'item_contractors.supplier_id', 'item_contractors.safety_stock', 'item_contractors.max_stock', 'item_contractors.purchase_unit'];

        $itemContractors = $externalQuery
            ->select($selectColumns)
            ->get();

        $existingCandidateKeys = $this->loadExistingOrderCandidateKeys($batchCode);
        $insertData = [];
        $skippedExistingCount = 0;
        $seenCandidateKeys = $existingCandidateKeys;

        foreach ($itemContractors as $ic) {
            if (! isset($this->orderingCodes[$ic->item_id])) {
                continue;
            }

            $safetyStock = (int) $ic->safety_stock;
            $sales3dQty = $this->salesSummaries3d[$ic->warehouse_id][$ic->item_id] ?? 0;
            if ($sales3dQty <= 0) {
                continue;
            }

            $stock = $this->stockSnapshots[$ic->warehouse_id][$ic->item_id] ?? null;
            $effectiveStock = $stock['effective'] ?? 0;

            $incomingStock = $stock['incoming'] ?? 0;

            $transfer = $transferCandidates[$ic->warehouse_id][$ic->item_id] ?? null;
            $incomingFromTransfer = $transfer['incoming'] ?? 0;
            $outgoingToTransfer = $transfer['outgoing'] ?? 0;

            $calculatedStock = $effectiveStock + $incomingStock + $incomingFromTransfer - $outgoingToTransfer;
            $shortageQty = max(0, $sales3dQty - $calculatedStock);

            if ($this->orderPointFilter === 'below_or_equal' && $calculatedStock > $safetyStock) {
                continue;
            }

            $candidateKey = "{$ic->warehouse_id}:{$ic->item_id}:{$ic->contractor_id}:{$ic->supplier_id}";
            if (isset($seenCandidateKeys[$candidateKey])) {
                $skippedExistingCount++;

                continue;
            }
            $seenCandidateKeys[$candidateKey] = true;

            $purchaseUnit = max(1, (int) ($ic->purchase_unit ?? 1));
            $quantityCalculation = $this->calculateOrderQuantity(
                $sales3dQty,
                $purchaseUnit,
                0,
                0,
                $calculatedStock,
                $this->orderingUnitQuantities[$ic->item_id] ?? null,
            );
            $suggestedQty = $quantityCalculation['order_quantity'];

            $demandBreakdown = [];
            $originWarehouseIds = [];

            $satelliteDemandQty = max(0, $outgoingToTransfer);
            $selfShortageOnly = $sales3dQty;

            if ($selfShortageOnly > 0) {
                $demandBreakdown[] = ['warehouse_id' => $ic->warehouse_id, 'quantity' => $selfShortageOnly];
                $originWarehouseIds[] = $ic->warehouse_id;
            }

            foreach ($transfer['outgoing_breakdown'] ?? [] as $breakdown) {
                $qty = (int) ($breakdown['quantity'] ?? 0);
                if ($qty > 0) {
                    $demandBreakdown[] = ['warehouse_id' => $breakdown['warehouse_id'], 'quantity' => $qty];
                    $originWarehouseIds[] = $breakdown['warehouse_id'];
                }
            }

            $capacityCase = max(1, (int) ($this->itemMaster[$ic->item_id]['capacity_case'] ?? 1));
            $suggestedQtyCase = (int) ceil($suggestedQty / $capacityCase);

            $purchaseUnitPrice = $this->supplierItemPrices[$ic->item_id][$ic->supplier_id] ?? null;
            $orderingCode = $this->orderingCodes[$ic->item_id] ?? null;

            if ($orderingCode === null) {
                continue;
            }

            $arrivalInfo = $this->calculateArrivalDate($ic->contractor_id, $ic->warehouse_id, $now->copy());
            $arrivalDate = $arrivalInfo['arrival_date']->format('Y-m-d');
            $originalArrivalDate = $now->copy()->addDays($arrivalInfo['lead_time_days'])->format('Y-m-d');
            $leadTimeDays = $arrivalInfo['lead_time_days'];

            $insertData[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'item_code' => $this->itemMaster[$ic->item_id]['code'] ?? null,
                'search_code' => $this->searchCodes[$ic->item_id] ?? null,
                'contractor_id' => $ic->contractor_id,
                'supplier_id' => $ic->supplier_id,
                'purchase_unit_price' => $purchaseUnitPrice,
                'ordering_code' => $orderingCode,
                'self_shortage_qty' => $selfShortageOnly,
                'satellite_demand_qty' => $satelliteDemandQty,
                'demand_breakdown' => ! empty($demandBreakdown) ? json_encode($demandBreakdown, JSON_UNESCAPED_UNICODE) : null,
                'origin_warehouse_ids' => ! empty($originWarehouseIds) ? implode(',', $originWarehouseIds) : null,
                'suggested_quantity' => $suggestedQty,
                'order_quantity' => 0,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'safety_stock' => $safetyStock,
                'calculated_shortage_qty' => $shortageQty,
                'purchase_unit' => $purchaseUnit,
                'quantity_type' => QuantityType::CASE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $originalArrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'origin_type' => $this->originType->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $itemInfo = $this->itemMaster[$ic->item_id] ?? null;
            $warehouseInfo = $this->warehouseMaster[$ic->warehouse_id] ?? null;
            $contractorInfo = $this->contractorMaster[$ic->contractor_id] ?? null;
            $supplierInfo = $this->supplierMaster[$ic->supplier_id] ?? null;

            $this->calculationLogs[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'calculation_type' => CalculationType::EXTERNAL->value,
                'contractor_id' => $ic->contractor_id,
                'source_warehouse_id' => null,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'safety_stock_setting' => $safetyStock,
                'lead_time_days' => $leadTimeDays,
                'calculated_shortage_qty' => $shortageQty,
                'calculated_order_quantity' => 0,
                'calculation_details' => json_encode(array_merge([
                    '商品コード' => $itemInfo['code'] ?? null,
                    '商品名' => $itemInfo['name'] ?? null,
                    '規格' => $itemInfo['packaging'] ?? null,
                    '発注コード' => $orderingCode,
                    '仕入先コード' => $supplierInfo['code'] ?? null,
                    '仕入先名' => $supplierInfo['name'] ?? null,
                    '発注先コード' => $contractorInfo['code'] ?? null,
                    '発注先名' => $contractorInfo['name'] ?? null,
                    '発注倉庫コード' => $warehouseInfo['code'] ?? null,
                    '発注倉庫名' => $warehouseInfo['name'] ?? null,
                ], [
                    '計算タイプ' => '実績ベース',
                    '計算式' => "{$this->salesBasisLabel()}販売あり商品を候補表示（初期発注数0）",
                    '販売実績条件' => $this->salesBasisLabel(),
                    '発注点条件' => $this->orderPointFilterLabel(),
                    '自動発注フラグ条件' => $this->autoOrderFlagFilterLabel(),
                    '対象販売実績' => $sales3dQty,
                    '発注点' => $safetyStock,
                    '有効在庫' => $effectiveStock,
                    '入荷予定' => $incomingStock,
                    '移動入庫予定' => $incomingFromTransfer,
                    '移動出庫予定' => $outgoingToTransfer,
                    '見込み在庫' => $calculatedStock,
                    '不足数' => $shortageQty,
                    '最大発注点' => $quantityCalculation['max_stock'],
                    '最大発注可能数量(バラ)' => $quantityCalculation['max_order_quantity'],
                    '発注数量計算元' => $quantityCalculation['source_label'],
                    '発注数量計算元数量(バラ)' => $quantityCalculation['base_quantity'],
                    '最小仕入単位' => $purchaseUnit,
                    '有効発注単位(バラ)' => $quantityCalculation['valid_order_unit'],
                    '最大発注点調整前数量(バラ)' => $quantityCalculation['before_max_stock_quantity'],
                    '最大発注点調整あり' => $quantityCalculation['max_stock_adjusted'],
                    '参考数量(バラ)' => $suggestedQty,
                    '参考数量(ケース)' => $suggestedQtyCase,
                    '発注数量(ケース)' => 0,
                    'ケース入数' => $capacityCase,
                    '単位調整説明' => $quantityCalculation['description']." → 参考{$suggestedQtyCase}ケース / 初期発注数0",
                ], [
                    '到着日調整' => $arrivalInfo['shifted_days'],
                    '調整理由' => implode(', ', $arrivalInfo['shift_reasons']),
                ]), JSON_UNESCAPED_UNICODE),
            ];
        }

        $chunks = array_chunk($insertData, 1000);
        foreach ($chunks as $chunk) {
            WmsOrderCandidate::insert($chunk);
        }

        $count = count($insertData);
        Log::info('[SalesBased] 外部発注候補を作成', [
            'count' => $count,
            'skipped_existing' => $skippedExistingCount,
        ]);

        return $count;
    }

    private function loadExistingOrderCandidateKeys(string $batchCode): array
    {
        $query = WmsOrderCandidate::query()
            ->where('batch_code', $batchCode)
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
            ->whereIn('warehouse_id', $this->realWarehouseIds)
            ->select('warehouse_id', 'item_id', 'contractor_id', 'supplier_id');

        if ($this->targetContractorIds !== null) {
            $query->whereIn('contractor_id', $this->targetContractorIds);
        }

        return $query
            ->get()
            ->mapWithKeys(fn ($candidate) => [
                "{$candidate->warehouse_id}:{$candidate->item_id}:{$candidate->contractor_id}:{$candidate->supplier_id}" => true,
            ])
            ->all();
    }

    private function loadExistingTransferCandidateKeys(string $batchCode): array
    {
        $query = WmsStockTransferCandidate::query()
            ->where('batch_code', $batchCode)
            ->whereIn('status', [CandidateStatus::PENDING->value, CandidateStatus::APPROVED->value])
            ->whereIn('satellite_warehouse_id', $this->realWarehouseIds)
            ->select('satellite_warehouse_id', 'hub_warehouse_id', 'item_id', 'contractor_id');

        if ($this->targetContractorIds !== null) {
            $query->whereIn('contractor_id', $this->targetContractorIds);
        }

        return $query
            ->get()
            ->mapWithKeys(fn ($candidate) => [
                "{$candidate->satellite_warehouse_id}:{$candidate->hub_warehouse_id}:{$candidate->item_id}:{$candidate->contractor_id}" => true,
            ])
            ->all();
    }

    private function loadTransferCandidatesToMemory(string $batchCode): array
    {
        $candidates = DB::connection('sakemaru')
            ->table('wms_stock_transfer_candidates')
            ->where('batch_code', $batchCode)
            ->select('satellite_warehouse_id', 'hub_warehouse_id', 'item_id', 'transfer_quantity')
            ->get();

        $result = [];

        foreach ($candidates as $c) {
            if (! isset($result[$c->satellite_warehouse_id][$c->item_id])) {
                $result[$c->satellite_warehouse_id][$c->item_id] = ['incoming' => 0, 'outgoing' => 0, 'outgoing_breakdown' => []];
            }
            $result[$c->satellite_warehouse_id][$c->item_id]['incoming'] += $c->transfer_quantity;

            if (! isset($result[$c->hub_warehouse_id][$c->item_id])) {
                $result[$c->hub_warehouse_id][$c->item_id] = ['incoming' => 0, 'outgoing' => 0, 'outgoing_breakdown' => []];
            }
            $result[$c->hub_warehouse_id][$c->item_id]['outgoing'] += $c->transfer_quantity;
            $result[$c->hub_warehouse_id][$c->item_id]['outgoing_breakdown'][] = [
                'warehouse_id' => $c->satellite_warehouse_id,
                'quantity' => $c->transfer_quantity,
            ];
        }

        return $result;
    }

    private function insertCalculationLogs(): void
    {
        if (empty($this->calculationLogs)) {
            return;
        }

        $chunks = array_chunk($this->calculationLogs, 1000);
        foreach ($chunks as $chunk) {
            WmsOrderCalculationLog::insert($chunk);
        }

        Log::info('[SalesBased] 計算ログを保存', ['count' => count($this->calculationLogs)]);
    }

    private function calculateArrivalDate(int $contractorId, int $warehouseId, Carbon $orderDate, bool $isInternal = false): array
    {
        $leadTimeDays = $this->contractorLeadTimes[$contractorId] ?? 1;
        $arrivalDate = $orderDate->copy()->addDays($leadTimeDays);
        $shiftedDays = 0;
        $shiftReasons = [];

        $deliverySetting = $this->deliveryDaySettings[$contractorId][$warehouseId] ?? null;
        if ($deliverySetting) {
            $hasDeliveryDays = array_filter($deliverySetting, fn ($v) => $v === true);
            if (! empty($hasDeliveryDays)) {
                $deliveryShift = 0;
                for ($i = 0; $i < 14; $i++) {
                    if ($this->canDeliverOn($deliverySetting, $arrivalDate->dayOfWeek)) {
                        break;
                    }
                    $arrivalDate->addDay();
                    $deliveryShift++;
                }
                if ($deliveryShift > 0) {
                    $shiftedDays += $deliveryShift;
                    $shiftReasons[] = "納品可能曜日調整(+{$deliveryShift}日)";
                }
            }
        }

        $warehouseShift = 0;
        for ($i = 0; $i < 14; $i++) {
            $dateStr = $arrivalDate->format('Y-m-d');
            if (! isset($this->warehouseHolidays[$warehouseId][$dateStr])) {
                break;
            }
            $arrivalDate->addDay();
            $warehouseShift++;
        }
        if ($warehouseShift > 0) {
            $shiftedDays += $warehouseShift;
            $shiftReasons[] = "倉庫休日(+{$warehouseShift}日)";
        }

        return [
            'arrival_date' => $arrivalDate,
            'lead_time_days' => $leadTimeDays,
            'shifted_days' => $shiftedDays,
            'shift_reasons' => $shiftReasons,
        ];
    }

    private function canDeliverOn(array $setting, int $dayOfWeek): bool
    {
        return match ($dayOfWeek) {
            0 => $setting['sun'] ?? false,
            1 => $setting['mon'] ?? false,
            2 => $setting['tue'] ?? false,
            3 => $setting['wed'] ?? false,
            4 => $setting['thu'] ?? false,
            5 => $setting['fri'] ?? false,
            6 => $setting['sat'] ?? false,
            default => false,
        };
    }

    private function normalizeSalesBasis(string $salesBasis): string
    {
        return in_array($salesBasis, ['today', 'yesterday', 'last_3d'], true) ? $salesBasis : 'last_3d';
    }

    private function normalizeOrderPointFilter(string $orderPointFilter): string
    {
        return in_array($orderPointFilter, ['ignore', 'below_or_equal'], true) ? $orderPointFilter : 'ignore';
    }

    private function normalizeAutoOrderFlagFilter(string $autoOrderFlagFilter): string
    {
        return in_array($autoOrderFlagFilter, ['ignore', 'on', 'off'], true) ? $autoOrderFlagFilter : 'ignore';
    }

    private function salesBasisColumn(): string
    {
        return match ($this->salesBasis) {
            'today' => 'sales_today_qty',
            'yesterday' => 'sales_yesterday_qty',
            default => 'last_3d_qty',
        };
    }

    private function salesBasisLabel(): string
    {
        return match ($this->salesBasis) {
            'today' => '当日',
            'yesterday' => '前日',
            default => '3日間',
        };
    }

    private function orderPointFilterLabel(): string
    {
        return match ($this->orderPointFilter) {
            'below_or_equal' => '見込み在庫が発注点以下',
            default => '考慮しない',
        };
    }

    private function autoOrderFlagFilterLabel(): string
    {
        return match ($this->autoOrderFlagFilter) {
            'on' => 'ONのもの',
            'off' => 'OFFのもの',
            default => '考慮しない',
        };
    }

    private function roundUpToUnit(int $quantity, int $unit): int
    {
        if ($unit <= 1) {
            return $quantity;
        }

        return (int) ceil($quantity / $unit) * $unit;
    }

    /**
     * 問屋発注数量をバラ数量で算出する。
     *
     * 実績ベースでは不足数を初期値として採用し、仕入単位・発注コード入数で調整する。
     */
    private function calculateOrderQuantity(
        int $shortageQty,
        int $purchaseUnit,
        int $autoOrderQuantity,
        int $maxStock,
        int $calculatedStock,
        ?int $orderingUnitQty = null,
    ): array {
        return app(OrderQuantityAdjustmentService::class)->calculate(
            shortageQty: $shortageQty,
            purchaseUnit: $purchaseUnit,
            autoOrderQuantity: $autoOrderQuantity,
            maxStock: $maxStock,
            calculatedStock: $calculatedStock,
            orderingUnitQty: $orderingUnitQty,
        );
    }

    private function buildResultData(string $batchCode, int $transferCount, int $orderCount): array
    {
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->with(['warehouse', 'contractor'])
            ->get();

        $totalQuantity = $candidates->sum('order_quantity');

        $byWarehouse = $candidates->groupBy('warehouse_id')->map(function ($group) {
            $warehouse = $group->first()->warehouse;

            return [
                'warehouse_id' => $group->first()->warehouse_id,
                'warehouse_code' => $warehouse?->code ?? '',
                'warehouse_name' => $warehouse?->name ?? '不明',
                'count' => $group->count(),
                'quantity' => $group->sum('order_quantity'),
            ];
        })->sortBy('warehouse_code')->values()->toArray();

        $byContractor = $candidates->groupBy('contractor_id')->map(function ($group) {
            $contractor = $group->first()->contractor;

            return [
                'contractor_id' => $group->first()->contractor_id,
                'contractor_code' => $contractor?->code ?? '',
                'contractor_name' => $contractor?->name ?? '不明',
                'count' => $group->count(),
                'quantity' => $group->sum('order_quantity'),
            ];
        })->sortBy('contractor_code')->values()->toArray();

        return [
            'summary' => [
                'internal_candidates' => $transferCount,
                'external_candidates' => $orderCount,
                'total_order_quantity' => $totalQuantity,
            ],
            'by_warehouse' => $byWarehouse,
            'by_contractor' => $byContractor,
        ];
    }
}
