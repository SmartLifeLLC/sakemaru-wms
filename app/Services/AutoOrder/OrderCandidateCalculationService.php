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

    /** @var array [from_warehouse_id][to_warehouse_id] => delivery_course_id */
    private array $transferDeliveryCourses = [];

    /** @var array [contractor_id] => lead_time_days */
    private array $contractorLeadTimes = [];

    /** @var array [contractor_id][warehouse_id] => delivery_day_setting */
    private array $deliveryDaySettings = [];

    /** @var array [warehouse_id][date_string] => true */
    private array $warehouseHolidays = [];

    /** @var array [item_id] => ordering_code (13桁ゼロパディング済み) */
    private array $orderingCodes = [];

    /** @var array [item_id][supplier_id] => unit_price (仕入先別商品仕入単価) */
    private array $supplierItemPrices = [];

    /** @var int|null 参照する在庫スナップショットのjob_control_id */
    private ?int $snapshotJobId = null;

    /** @var array|null 仕入先ID指定（null=全仕入先、親+子仕入先を含む） */
    private ?array $targetContractorIds = null;

    /** @var int|null 倉庫ID指定（null=全有効倉庫） */
    private ?int $targetWarehouseId = null;

    /**
     * 発注候補計算を実行
     *
     * @param  int|null  $snapshotJobId  参照する在庫スナップショットのjob_id
     * @param  int|null  $contractorId  仕入先指定（nullなら全仕入先一括）
     * @param  bool  $transferOnly  trueの場合、INTERNAL移動候補のみ生成（EXTERNAL発注候補はスキップ）
     * @param  int|null  $warehouseId  倉庫指定（nullなら全有効倉庫）
     */
    public function calculate(?int $snapshotJobId = null, ?int $contractorId = null, bool $transferOnly = false, ?int $warehouseId = null, ?int $createdBy = null): WmsAutoOrderJobControl
    {
        if (WmsAutoOrderJobControl::hasRunningJob(JobProcessName::ORDER_CALC)) {
            throw new \RuntimeException('Order calculation job is already running');
        }

        // 排他制御: 仕入先指定あり/なしで2パターン
        if ($contractorId !== null) {
            // パターンA: 仕入先指定あり（スケジューラー/手動強制）
            // 親+子仕入先を展開してチェック
            $allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($contractorId);

            if (! $transferOnly) {
                $hasOrderCandidates = WmsOrderCandidate::query()
                    ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
                    ->whereIn('contractor_id', $allContractorIds)
                    ->exists();
            } else {
                $hasOrderCandidates = false;
            }

            $hasTransferCandidates = WmsStockTransferCandidate::query()
                ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
                ->whereIn('contractor_id', $allContractorIds)
                ->exists();

            if ($hasOrderCandidates || $hasTransferCandidates) {
                throw new \RuntimeException("仕入先ID:{$contractorId}（+子仕入先）に未処理の候補が存在します");
            }
        } else {
            // パターンB: 仕入先指定なし
            if (! $transferOnly) {
                $approvedOrderQuery = WmsOrderCandidate::query()
                    ->where('status', CandidateStatus::APPROVED);
                if ($warehouseId) {
                    $approvedOrderQuery->where('warehouse_id', $warehouseId);
                }
                $hasApprovedOrders = $approvedOrderQuery->exists();
            } else {
                $hasApprovedOrders = false;
            }

            $approvedTransferQuery = WmsStockTransferCandidate::query()
                ->where('status', CandidateStatus::APPROVED);
            if ($warehouseId) {
                $approvedTransferQuery->where('satellite_warehouse_id', $warehouseId);
            }
            $hasApprovedTransfers = $approvedTransferQuery->exists();

            if ($hasApprovedOrders || $hasApprovedTransfers) {
                $warehouseSuffix = $warehouseId ? "（倉庫ID:{$warehouseId}）" : '';
                if ($transferOnly) {
                    $countQuery = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED);
                    if ($warehouseId) {
                        $countQuery->where('satellite_warehouse_id', $warehouseId);
                    }
                    $approvedTransferCount = $countQuery->count();
                    throw new \RuntimeException("確定待ちの移動候補が {$approvedTransferCount}件 あります{$warehouseSuffix}。先に確定を行ってください。");
                }
                $orderCountQuery = WmsOrderCandidate::where('status', CandidateStatus::APPROVED);
                $transferCountQuery = WmsStockTransferCandidate::where('status', CandidateStatus::APPROVED);
                if ($warehouseId) {
                    $orderCountQuery->where('warehouse_id', $warehouseId);
                    $transferCountQuery->where('satellite_warehouse_id', $warehouseId);
                }
                $approvedOrderCount = $orderCountQuery->count();
                $approvedTransferCount = $transferCountQuery->count();
                $totalCount = $approvedOrderCount + $approvedTransferCount;
                throw new \RuntimeException("確定待ちの候補が {$totalCount}件 あります{$warehouseSuffix}。先に確定を行ってください。");
            }
        }

        $job = WmsAutoOrderJobControl::startJob(
            processName: JobProcessName::ORDER_CALC,
            scope: null,
            batchCode: null,
            settlementStatus: SettlementStatus::PENDING,
            snapshotJobId: $snapshotJobId,
            createdBy: $createdBy,
            warehouseId: $warehouseId,
        );

        // スナップショットJob IDを保持（loadAllDataToMemoryで使用）
        $this->snapshotJobId = $snapshotJobId;
        $this->targetContractorIds = $contractorId !== null
            ? WmsContractorSetting::getContractorIdsWithChildren($contractorId)
            : null;
        $this->targetWarehouseId = $warehouseId;

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

            $orderCount = 0;

            if (! $transferOnly) {
                // Step 3: 移動候補をメモリにロード（EXTERNAL計算用）
                $transferCandidates = $this->loadTransferCandidatesToMemory($batchCode);

                $job->updateProgress(3, 4);

                // Step 4: EXTERNAL発注候補を計算・バルクインサート
                $orderCount = $this->createExternalOrderCandidatesBulk($batchCode, $now, $transferCandidates);
            } else {
                $job->updateProgress(3, 4);
                Log::info('transferOnlyモード: EXTERNAL発注候補の生成をスキップ');
            }

            // Step 5: 計算ログをバルクインサート
            $this->insertCalculationLogs();

            $job->updateProgress(4, 4);

            // Step 6: 結果データを収集
            $resultData = $this->buildResultData($batchCode, $transferCount, $orderCount);
            $job->markAsSuccess($transferCount + $orderCount, $resultData);

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
     * 必要なデータのみをメモリにロード（is_auto_order有効な商品）
     */
    private function loadAllDataToMemory(): void
    {
        // 自動発注有効な実倉庫のIDをロード
        // wms_warehouse_auto_order_settings で is_auto_order_enabled = true の倉庫のみ
        $enabledWarehouseIds = WmsWarehouseAutoOrderSetting::enabled()
            ->pluck('warehouse_id')
            ->toArray();

        // 倉庫指定時はその倉庫のみに絞り込む
        if ($this->targetWarehouseId !== null) {
            $enabledWarehouseIds = array_intersect($enabledWarehouseIds, [$this->targetWarehouseId]);
        }

        $this->realWarehouseIds = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_virtual', false)
            ->whereIn('id', $enabledWarehouseIds)
            ->pluck('id')
            ->toArray();

        Log::info('自動発注有効な実倉庫をロード', ['count' => count($this->realWarehouseIds), 'target_warehouse' => $this->targetWarehouseId]);

        // JOINでis_auto_order有効な商品のスナップショットを取得（実倉庫のみ）
        // snapshotJobIdが指定されている場合、そのジョブのスナップショットのみを使用
        // （過去のスナップショットの古いincoming値が混入するのを防止）
        $snapshotQuery = DB::connection('sakemaru')
            ->table('wms_item_stock_snapshots as s')
            ->join('item_contractors as ic', function ($join) {
                $join->on('s.warehouse_id', '=', 'ic.warehouse_id')
                    ->on('s.item_id', '=', 'ic.item_id');
            })
            ->whereIn('s.warehouse_id', $this->realWarehouseIds)
            ->where('ic.is_auto_order', true)
            ->where('ic.safety_stock', '>=', 0);

        if ($this->snapshotJobId) {
            $snapshotQuery->where('s.job_control_id', $this->snapshotJobId);
        } else {
            // snapshotJobIdが未指定の場合は最新のスナップショットジョブを使用
            $latestSnapshotJobId = DB::connection('sakemaru')
                ->table('wms_auto_order_job_controls')
                ->where('process_name', JobProcessName::STOCK_SNAPSHOT->value)
                ->where('status', 'SUCCESS')
                ->orderByDesc('id')
                ->value('id');

            if ($latestSnapshotJobId) {
                $snapshotQuery->where('s.job_control_id', $latestSnapshotJobId);
            }
        }

        $snapshots = $snapshotQuery
            ->select('s.warehouse_id', 's.item_id', 's.total_effective_piece', 's.total_incoming_piece')
            ->distinct()
            ->get();

        foreach ($snapshots as $s) {
            if (! isset($this->stockSnapshots[$s->warehouse_id])) {
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

        // 仕入先別商品仕入単価をメモリにロード（item_partner_prices.unit_price を使用）
        // 条件:
        // - partner_category = 'SUPPLIER'
        // - partner_id = partners.id (partners.is_supplier = true)
        // - start_date <= systemDate の最新
        $systemDate = now()->format('Y-m-d');

        $supplierItemPrices = DB::connection('sakemaru')
            ->select('
                SELECT
                    ipp.item_id,
                    s.id AS supplier_id,
                    ipp.unit_price
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
        }

        Log::info('仕入先別商品仕入単価をロード', ['count' => count($supplierItemPrices)]);

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
            ->where('s.partner_category', 'SUPPLIER')
            ->where('p.is_supplier', true)
            ->select('s.id', 'p.code', 'p.name')
            ->get();

        foreach ($suppliers as $s) {
            $this->supplierMaster[$s->id] = [
                'code' => $s->code,
                'name' => $s->name,
            ];
        }

        Log::info('仕入先マスタをロード', ['count' => count($this->supplierMaster)]);

        // 移動配送コース設定をメモリにロード
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

        Log::info('移動配送コース設定をロード', ['count' => count($transferCourses)]);

        // 発注先リードタイムをプリロード（contractors.lead_time_id → lead_times）
        $contractorsWithLeadTime = DB::connection('sakemaru')
            ->table('contractors as c')
            ->join('lead_times as lt', 'c.lead_time_id', '=', 'lt.id')
            ->select('c.id as contractor_id', 'lt.lead_time_mon as lead_time')
            ->get();

        foreach ($contractorsWithLeadTime as $c) {
            $this->contractorLeadTimes[$c->contractor_id] = $c->lead_time;
        }

        Log::info('発注先リードタイムをロード', ['count' => count($this->contractorLeadTimes)]);

        // 納品可能曜日をプリロード（発注先×倉庫）
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

        Log::info('納品可能曜日をロード', ['count' => count($deliveryDays)]);

        // 倉庫休日をプリロード（今後30日分）
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

        Log::info('倉庫休日をロード', ['count' => count($holidays)]);

        // 発注コードをプリロード（is_used_for_ordering=trueのもの）
        $orderingCodes = DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->select('item_id', 'search_string')
            ->get();

        foreach ($orderingCodes as $oc) {
            // 13桁にゼロパディング
            $this->orderingCodes[$oc->item_id] = str_pad($oc->search_string, 13, '0', STR_PAD_LEFT);
        }

        Log::info('発注コードをロード', ['count' => count($this->orderingCodes)]);
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

        // INTERNAL発注先の商品を取得（is_auto_order有効、実倉庫のみ）
        $internalContractorIds = $this->internalContractorIds;

        // Note: 仕入先指定がある場合でも、INTERNAL発注先は常に全件処理する
        // （外部発注先のスケジュール実行時にも移動候補を生成するため）

        $itemContractors = DB::connection('sakemaru')
            ->table('item_contractors')
            ->join('items', 'item_contractors.item_id', '=', 'items.id')
            ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
            ->whereIn('item_contractors.contractor_id', $internalContractorIds)
            ->whereIn('item_contractors.warehouse_id', $this->realWarehouseIds)
            ->where('item_contractors.is_auto_order', true)
            ->where('item_contractors.safety_stock', '>=', 0)
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($q) => $q->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true)
            ->select('item_contractors.id', 'item_contractors.warehouse_id', 'item_contractors.item_id', 'item_contractors.contractor_id', 'item_contractors.supplier_id', 'item_contractors.safety_stock', 'item_contractors.purchase_unit')
            ->get();

        $insertData = [];

        foreach ($itemContractors as $ic) {
            $supplyWarehouseId = $this->internalSettings[$ic->contractor_id] ?? null;
            if (! $supplyWarehouseId) {
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

            if ($shortageQty < 0) {
                continue;
            }

            // 発注点0かつ在庫0の場合、最低1個を発注（在庫切れ補充）
            if ($shortageQty === 0 && (int) $ic->safety_stock === 0) {
                if ($effectiveStock > 0 || $incomingStock > 0) {
                    continue;
                }
                $shortageQty = 1;
            } elseif ($shortageQty === 0) {
                continue;
            }

            // 移動候補はバラ発注可能（仕入れ単位の切り上げなし）
            $orderQty = $shortageQty;

            // 配送コースIDを取得（移動元 → 移動先）
            $deliveryCourseId = $this->transferDeliveryCourses[$supplyWarehouseId][$ic->warehouse_id] ?? null;

            // 到着予定日を計算（リードタイム + 納品曜日 + 倉庫休日）
            $arrivalInfo = $this->calculateArrivalDate(
                $ic->contractor_id,
                $ic->warehouse_id,
                $now,
                isInternal: true
            );
            $arrivalDate = $arrivalInfo['arrival_date']->format('Y-m-d');
            $originalArrivalDate = $now->copy()->addDays($arrivalInfo['lead_time_days'])->format('Y-m-d');
            $leadTimeDays = $arrivalInfo['lead_time_days'];

            $insertData[] = [
                'batch_code' => $batchCode,
                'satellite_warehouse_id' => $ic->warehouse_id,
                'hub_warehouse_id' => $supplyWarehouseId,
                'item_id' => $ic->item_id,
                'contractor_id' => $ic->contractor_id,
                'delivery_course_id' => $deliveryCourseId,
                'suggested_quantity' => $orderQty,
                'transfer_quantity' => $orderQty,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'calculated_available' => $effectiveStock + $incomingStock,
                'shortage_qty' => $shortageQty,
                'safety_stock' => $ic->safety_stock,
                'purchase_unit' => max(1, (int) ($ic->purchase_unit ?? 1)),
                'quantity_type' => QuantityType::PIECE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $originalArrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'origin_type' => OriginType::AUTO->value,
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
                    '発注数量' => $orderQty,
                    '備考' => '移動候補はバラ発注可能（仕入れ単位切り上げなし）',
                    '到着日調整' => $arrivalInfo['shifted_days'],
                    '調整理由' => implode(', ', $arrivalInfo['shift_reasons']),
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
     *
     * @return array [warehouse_id][item_id] => ['incoming' => qty, 'outgoing' => qty, 'outgoing_breakdown' => [[warehouse_id, quantity], ...]]
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
            if (! isset($result[$c->satellite_warehouse_id][$c->item_id])) {
                $result[$c->satellite_warehouse_id][$c->item_id] = ['incoming' => 0, 'outgoing' => 0, 'outgoing_breakdown' => []];
            }
            $result[$c->satellite_warehouse_id][$c->item_id]['incoming'] += $c->transfer_quantity;

            // 移動元（出庫）
            if (! isset($result[$c->hub_warehouse_id][$c->item_id])) {
                $result[$c->hub_warehouse_id][$c->item_id] = ['incoming' => 0, 'outgoing' => 0, 'outgoing_breakdown' => []];
            }
            $result[$c->hub_warehouse_id][$c->item_id]['outgoing'] += $c->transfer_quantity;

            // 出庫先の内訳を記録（仮想倉庫ごとの需要）
            $result[$c->hub_warehouse_id][$c->item_id]['outgoing_breakdown'][] = [
                'warehouse_id' => $c->satellite_warehouse_id,
                'quantity' => $c->transfer_quantity,
            ];
        }

        return $result;
    }

    /**
     * EXTERNAL発注候補をバルク作成
     */
    private function createExternalOrderCandidatesBulk(string $batchCode, Carbon $now, array $transferCandidates): int
    {
        // EXTERNAL発注先の商品を取得（is_auto_order有効、実倉庫のみ、販売終了品を除外）
        $externalQuery = DB::connection('sakemaru')
            ->table('item_contractors')
            ->join('items', 'item_contractors.item_id', '=', 'items.id')
            ->join('contractors', 'item_contractors.contractor_id', '=', 'contractors.id')
            ->whereNotIn('item_contractors.contractor_id', $this->internalContractorIds ?: [0])
            ->whereIn('item_contractors.warehouse_id', $this->realWarehouseIds)
            ->where('item_contractors.is_auto_order', true)
            ->where('item_contractors.safety_stock', '>=', 0)
            ->where('items.end_of_sale_type', 'NORMAL')
            ->where('items.is_ended', false)
            ->where(fn ($q) => $q->whereNull('items.start_of_sale_date')->orWhere('items.start_of_sale_date', '<=', now()->toDateString()))
            ->where(fn ($q) => $q->whereNull('items.end_of_sale_date')->orWhere('items.end_of_sale_date', '>', now()->toDateString()))
            ->where('contractors.is_auto_change_order', true);

        // 仕入先指定がある場合、対象の仕入先のみに絞る（親+子仕入先）
        if ($this->targetContractorIds !== null) {
            $externalQuery->whereIn('contractor_id', $this->targetContractorIds);
        }

        $itemContractors = $externalQuery
            ->select('item_contractors.id', 'item_contractors.warehouse_id', 'item_contractors.item_id', 'item_contractors.contractor_id', 'item_contractors.supplier_id', 'item_contractors.safety_stock', 'item_contractors.purchase_unit')
            ->get();

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

            if ($shortageQty < 0) {
                continue;
            }

            // 発注点0かつ在庫0の場合、最低1個を発注（在庫切れ補充）
            if ($shortageQty === 0 && (int) $ic->safety_stock === 0) {
                if ($calculatedStock > 0) {
                    continue;
                }
                $shortageQty = 1;
            } elseif ($shortageQty === 0) {
                continue;
            }

            // 最小仕入単位で切り上げ
            $purchaseUnit = max(1, (int) ($ic->purchase_unit ?? 1));
            $orderQty = $this->roundUpToUnit($shortageQty, $purchaseUnit);

            // 需要内訳を構築（自倉庫分 + サテライト倉庫分）
            // 注意: shortageQtyは既に移動出庫を考慮した値なので、
            //       satellite_demand_qtyはshortageQtyを超えないようにする
            $demandBreakdown = [];
            $originWarehouseIds = [];

            // サテライト需要（移動出庫のうち、不足数に含まれる分）
            $satelliteDemandQty = min($outgoingToTransfer, $shortageQty);

            // 自倉庫の不足分（純粋な自倉庫分）
            $selfShortageOnly = max(0, $shortageQty - $satelliteDemandQty);
            if ($selfShortageOnly > 0) {
                $demandBreakdown[] = [
                    'warehouse_id' => $ic->warehouse_id,
                    'quantity' => $selfShortageOnly,
                ];
                $originWarehouseIds[] = $ic->warehouse_id;
            }

            // サテライト倉庫からの需要（移動出庫の内訳を按分）
            $outgoingBreakdown = $transfer['outgoing_breakdown'] ?? [];
            if ($satelliteDemandQty > 0 && $outgoingToTransfer > 0) {
                // 移動出庫の比率でサテライト需要を按分
                $ratio = $satelliteDemandQty / $outgoingToTransfer;
                foreach ($outgoingBreakdown as $breakdown) {
                    $adjustedQty = (int) round($breakdown['quantity'] * $ratio);
                    if ($adjustedQty > 0) {
                        $demandBreakdown[] = [
                            'warehouse_id' => $breakdown['warehouse_id'],
                            'quantity' => $adjustedQty,
                        ];
                        if (! in_array($breakdown['warehouse_id'], $originWarehouseIds)) {
                            $originWarehouseIds[] = $breakdown['warehouse_id'];
                        }
                    }
                }
            }

            // 到着予定日を計算（リードタイム + 納品曜日 + 倉庫休日）
            $arrivalInfo = $this->calculateArrivalDate(
                $ic->contractor_id,
                $ic->warehouse_id,
                $now,
                isInternal: false
            );
            $arrivalDate = $arrivalInfo['arrival_date']->format('Y-m-d');
            $originalArrivalDate = $now->copy()->addDays($arrivalInfo['lead_time_days'])->format('Y-m-d');
            $leadTimeDays = $arrivalInfo['lead_time_days'];

            // 発注コードを取得
            $orderingCode = $this->orderingCodes[$ic->item_id] ?? null;

            // 仕入単価を取得（仕入先別単価）
            $purchaseUnitPrice = $this->supplierItemPrices[$ic->item_id][$ic->supplier_id] ?? null;

            $insertData[] = [
                'batch_code' => $batchCode,
                'warehouse_id' => $ic->warehouse_id,
                'item_id' => $ic->item_id,
                'contractor_id' => $ic->contractor_id,
                'supplier_id' => $ic->supplier_id,
                'purchase_unit_price' => $purchaseUnitPrice,
                'ordering_code' => $orderingCode,
                'self_shortage_qty' => $selfShortageOnly,
                'satellite_demand_qty' => $satelliteDemandQty,
                'demand_breakdown' => ! empty($demandBreakdown) ? json_encode($demandBreakdown, JSON_UNESCAPED_UNICODE) : null,
                'origin_warehouse_ids' => ! empty($originWarehouseIds) ? implode(',', $originWarehouseIds) : null,
                'suggested_quantity' => $orderQty,
                'order_quantity' => $orderQty,
                'current_effective_stock' => $effectiveStock,
                'incoming_quantity' => $incomingStock,
                'safety_stock' => $ic->safety_stock,
                'calculated_shortage_qty' => $shortageQty,
                'purchase_unit' => $purchaseUnit,
                'quantity_type' => QuantityType::PIECE->value,
                'expected_arrival_date' => $arrivalDate,
                'original_arrival_date' => $originalArrivalDate,
                'status' => CandidateStatus::PENDING->value,
                'lot_status' => LotStatus::RAW->value,
                'origin_type' => OriginType::AUTO->value,
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
                'lead_time_days' => $leadTimeDays,
                'calculated_shortage_qty' => $shortageQty,
                'calculated_order_quantity' => $orderQty,
                'calculation_details' => json_encode([
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
                    '到着日調整' => $arrivalInfo['shifted_days'],
                    '調整理由' => implode(', ', $arrivalInfo['shift_reasons']),
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
     * 到着予定日を計算（リードタイム + 納品可能曜日 + 倉庫休日を考慮）
     *
     * @param  int  $contractorId  発注先ID
     * @param  int  $warehouseId  倉庫ID
     * @param  Carbon  $orderDate  発注日
     * @param  bool  $isInternal  内部移動かどうか
     * @return array{arrival_date: Carbon, lead_time_days: int, shifted_days: int, shift_reasons: array}
     */
    private function calculateArrivalDate(
        int $contractorId,
        int $warehouseId,
        Carbon $orderDate,
        bool $isInternal = false
    ): array {
        // Step 1: リードタイム取得（発注先単位）
        $leadTimeDays = $this->contractorLeadTimes[$contractorId]
            ?? 1;  // デフォルト値: 1日

        // Step 2: 仮到着予定日
        $arrivalDate = $orderDate->copy()->addDays($leadTimeDays);
        $shiftedDays = 0;
        $shiftReasons = [];

        // Step 3: 納品可能曜日チェック（最大14日）
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

        // Step 4: 倉庫休日チェック（最大14日）
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

    /**
     * 指定した曜日に納品可能かどうかを判定
     *
     * @param  array  $setting  納品曜日設定
     * @param  int  $dayOfWeek  曜日（0=日曜, 1=月曜, ..., 6=土曜）
     */
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

    /**
     * 数量を指定単位で切り上げ
     *
     * @param  int  $quantity  数量
     * @param  int  $unit  単位（1以上）
     * @return int 切り上げ後の数量
     */
    private function roundUpToUnit(int $quantity, int $unit): int
    {
        if ($unit <= 1) {
            return $quantity;
        }

        return (int) ceil($quantity / $unit) * $unit;
    }

    /**
     * 発注候補生成結果データを構築
     */
    private function buildResultData(string $batchCode, int $transferCount, int $orderCount): array
    {
        // 生成された候補を集計
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->with(['warehouse', 'contractor'])
            ->get();

        // サマリー
        $totalQuantity = $candidates->sum('order_quantity');

        // 倉庫別集計（コード順でソート）
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

        // 発注先別集計（コード順でソート）
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

        // 倉庫×発注先クロス集計
        $crossSummary = $candidates->groupBy(function ($c) {
            return "{$c->warehouse_id}_{$c->contractor_id}";
        })->map(function ($group) {
            $first = $group->first();

            return [
                'warehouse_code' => $first->warehouse?->code ?? '',
                'warehouse_name' => $first->warehouse?->name ?? '不明',
                'contractor_code' => $first->contractor?->code ?? '',
                'contractor_name' => $first->contractor?->name ?? '不明',
                'count' => $group->count(),
                'quantity' => $group->sum('order_quantity'),
            ];
        })->sortBy(['warehouse_code', 'contractor_code'])->values()->toArray();

        return [
            'batch_code' => $batchCode,
            'summary' => [
                'total_candidates' => $transferCount + $orderCount,
                'internal_candidates' => $transferCount,
                'external_candidates' => $orderCount,
                'total_quantity' => $totalQuantity,
                'warehouse_count' => count($byWarehouse),
                'contractor_count' => count($byContractor),
            ],
            'by_warehouse' => $byWarehouse,
            'by_contractor' => $byContractor,
            'cross_summary' => $crossSummary,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
