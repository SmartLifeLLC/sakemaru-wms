<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\EVolumeUnit;
use App\Models\Sakemaru\ClientSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 外部発注（本部）専用の参考データ取得サービス。
 *
 * 既存の（新）外部発注（OrderRegistrationSearchService / WmsOrderRegistration）には
 * 手を入れず、本部画面だけで使う集計をここにまとめる。
 *
 * - 最終入荷予定日 / 最終入荷予定数（ケース・バラ）
 * - 最終仕入日 / 最終仕入数（ケース・バラ、確定済みの仕入データ）
 * - 店舗（敦賀・小浜）の卸実績を除いた販売実績
 * - 発注先に紐づく仕入先
 */
class HqOrderRegistrationReferenceService
{
    private const ITEM_CHUNK_SIZE = 1000;

    private int|false|null $hqWarehouseId = false;

    /** @var array<int>|null */
    private ?array $excludedDeliveryCourseIds = null;

    private ?string $salesExclusionLabel = null;

    public function __construct(private readonly OrderRegistrationSearchService $searchService) {}

    public function hqWarehouseCode(): string
    {
        return (string) config('wms_hq_order_registration.warehouse_code', '91');
    }

    public function hqWarehouseId(): ?int
    {
        if ($this->hqWarehouseId === false) {
            $id = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('code', $this->hqWarehouseCode())
                ->value('id');

            $this->hqWarehouseId = $id !== null ? (int) $id : null;
        }

        return $this->hqWarehouseId;
    }

    public function isHqWarehouse(int $warehouseId): bool
    {
        return $warehouseId > 0 && $warehouseId === $this->hqWarehouseId();
    }

    /**
     * 販売実績から除外する配送コース（出荷倉庫が敦賀・小浜のコース）。
     *
     * @return array<int>
     */
    public function excludedDeliveryCourseIds(): array
    {
        if ($this->excludedDeliveryCourseIds !== null) {
            return $this->excludedDeliveryCourseIds;
        }

        $warehouseIds = $this->excludedCourseWarehouseIds();
        if ($warehouseIds === []) {
            return $this->excludedDeliveryCourseIds = [];
        }

        return $this->excludedDeliveryCourseIds = DB::connection('sakemaru')
            ->table('delivery_courses')
            ->whereIn('warehouse_id', $warehouseIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 画面の説明文に使う除外対象の名称（例: 敦賀店・小浜店）。
     */
    public function salesExclusionLabel(): string
    {
        if ($this->salesExclusionLabel !== null) {
            return $this->salesExclusionLabel;
        }

        $codes = $this->excludedCourseWarehouseCodes();
        if ($codes === []) {
            return $this->salesExclusionLabel = '';
        }

        $names = DB::connection('sakemaru')
            ->table('warehouses')
            ->whereIn('code', $codes)
            ->orderBy('code')
            ->pluck('name')
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->all();

        return $this->salesExclusionLabel = implode('・', $names !== [] ? $names : $codes);
    }

    /**
     * @return array{basis: string, week1: array{start: string, end: string}, week2: array{start: string, end: string}, week3: array{start: string, end: string}, previous_month: array{start: string, end: string}}
     */
    public function weeklySalesDateRanges(): array
    {
        $basisDate = Carbon::parse(ClientSetting::freshSystemDateYMD('order_registration_hq:weekly_sales'));

        return [
            'basis' => $basisDate->toDateString(),
            'week1' => [
                'start' => $basisDate->copy()->subDays(6)->toDateString(),
                'end' => $basisDate->toDateString(),
            ],
            'week2' => [
                'start' => $basisDate->copy()->subDays(13)->toDateString(),
                'end' => $basisDate->copy()->subDays(7)->toDateString(),
            ],
            'week3' => [
                'start' => $basisDate->copy()->subDays(20)->toDateString(),
                'end' => $basisDate->copy()->subDays(14)->toDateString(),
            ],
            'previous_month' => [
                'start' => $basisDate->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'end' => $basisDate->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
        ];
    }

    /**
     * 1週・2週・3週・前月の販売数量（本部倉庫は店舗の卸実績を除く）。
     *
     * @param  array<int>  $itemIds
     * @return array<int, array{sales_week1_qty: int, sales_week2_qty: int, sales_week3_qty: int, previous_month_sales_qty: int}>
     */
    public function adjustedWeeklySalesQuantities(int $warehouseId, array $itemIds): array
    {
        $itemIds = $this->normalizeIds($itemIds);
        if ($warehouseId < 1 || $itemIds === []) {
            return [];
        }

        $ranges = $this->weeklySalesDateRanges();
        $targets = [
            'sales_week1_qty' => $ranges['week1'],
            'sales_week2_qty' => $ranges['week2'],
            'sales_week3_qty' => $ranges['week3'],
            'previous_month_sales_qty' => $ranges['previous_month'],
        ];

        $totals = $this->statsSalesQuantities($warehouseId, $itemIds, $targets);
        $branch = $this->branchWholesaleQuantities($warehouseId, $itemIds, $targets);

        $result = [];
        foreach ($itemIds as $itemId) {
            $row = [];
            foreach (array_keys($targets) as $key) {
                $row[$key] = self::subtractBranchQuantity(
                    (int) ($totals[$itemId][$key] ?? 0),
                    (int) ($branch[$itemId][$key] ?? 0),
                );
            }
            $result[$itemId] = $row;
        }

        return $result;
    }

    /**
     * 店舗（敦賀・小浜）の卸実績として本部倉庫に計上されている数量（バラ換算）。
     *
     * 売上の計上倉庫は本部だが、配送コースが除外対象倉庫のもの。
     * 本部倉庫以外を渡した場合は常に空（除外しない）。
     *
     * @param  array<int>  $itemIds
     * @param  array<string, array{start: string, end: string}>  $ranges
     * @return array<int, array<string, int>>
     */
    public function branchWholesaleQuantities(int $warehouseId, array $itemIds, array $ranges): array
    {
        $itemIds = $this->normalizeIds($itemIds);
        if (! $this->isHqWarehouse($warehouseId) || $itemIds === [] || $ranges === []) {
            return [];
        }

        $courseIds = $this->excludedDeliveryCourseIds();
        if ($courseIds === []) {
            return [];
        }

        $selects = ['ti.item_id'];
        $bindings = [];
        foreach (array_keys($ranges) as $index => $key) {
            $selects[] = "SUM(CASE WHEN e.delivered_date BETWEEN ? AND ? THEN COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0) ELSE 0 END) as range_{$index}";
            $bindings[] = $ranges[$key]['start'];
            $bindings[] = $ranges[$key]['end'];
        }

        $query = DB::connection('sakemaru')
            ->table('earnings as e')
            ->join('trade_items as ti', 'ti.trade_id', '=', 'e.trade_id')
            ->where('e.warehouse_id', $warehouseId)
            ->whereIn('e.delivery_course_id', $courseIds)
            ->whereBetween('e.delivered_date', [
                min(array_column($ranges, 'start')),
                max(array_column($ranges, 'end')),
            ])
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('ti.item_id')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->groupBy('ti.item_id');

        // 商品では SQL で絞らず、PHP 側で絞る。
        // 商品で絞ると売上明細（trade_items）を商品の全期間分から読む実行計画になることがあり、
        // よく売れる商品ほど遅くなる。対象伝票（店舗コースの売上）は少ないので、売上側から読ませる。
        $targetItemIds = array_flip($itemIds);
        $keys = array_keys($ranges);
        $result = [];

        foreach ($query->get() as $row) {
            $itemId = (int) $row->item_id;
            if (! isset($targetItemIds[$itemId])) {
                continue;
            }

            foreach ($keys as $index => $key) {
                $result[$itemId][$key] = (int) ($row->{"range_{$index}"} ?? 0);
            }
        }

        return $result;
    }

    /**
     * 最終入荷予定日と、その日の入荷予定数（ケース・バラ）。
     *
     * キャンセル・削除以外の入荷予定のうち、一番新しい入荷予定日を採用する。
     * 入荷確定済みの予定も対象。
     *
     * @param  array<int>  $itemIds
     * @return array<int, array{date: string, case_qty: int, piece_qty: int}>
     */
    public function lastIncomingSchedules(int $warehouseId, array $itemIds): array
    {
        $itemIds = $this->normalizeIds($itemIds);
        if ($warehouseId < 1 || $itemIds === []) {
            return [];
        }

        $excludedStatuses = [
            IncomingScheduleStatus::CANCELLED->value,
            IncomingScheduleStatus::PARTIAL_CANCELLED->value,
            IncomingScheduleStatus::DELETED->value,
        ];
        $result = [];

        foreach (array_chunk($itemIds, self::ITEM_CHUNK_SIZE) as $chunk) {
            $latest = DB::connection('sakemaru')
                ->table('wms_order_incoming_schedules')
                ->where('warehouse_id', $warehouseId)
                ->whereIn('item_id', $chunk)
                ->whereNotIn('status', $excludedStatuses)
                ->whereNotNull('expected_arrival_date')
                ->selectRaw('item_id, MAX(expected_arrival_date) as last_date')
                ->groupBy('item_id');

            $rows = DB::connection('sakemaru')
                ->table('wms_order_incoming_schedules as s')
                ->joinSub($latest, 'latest', function ($join): void {
                    $join->on('latest.item_id', '=', 's.item_id')
                        ->on('latest.last_date', '=', 's.expected_arrival_date');
                })
                ->leftJoin('items as i', 'i.id', '=', 's.item_id')
                ->where('s.warehouse_id', $warehouseId)
                ->whereNotIn('s.status', $excludedStatuses)
                ->selectRaw('
                    s.item_id,
                    s.expected_arrival_date as last_date,
                    SUM(CASE WHEN s.quantity_type = \'CASE\' THEN s.expected_quantity ELSE 0 END) as case_qty,
                    SUM(
                        CASE s.quantity_type
                            WHEN \'CASE\' THEN 0
                            WHEN \'CARTON\' THEN s.expected_quantity * GREATEST(COALESCE(i.capacity_carton, 1), 1)
                            ELSE s.expected_quantity
                        END
                    ) as piece_qty
                ')
                ->groupBy('s.item_id', 's.expected_arrival_date')
                ->get();

            foreach ($rows as $row) {
                $result[(int) $row->item_id] = [
                    'date' => Carbon::parse((string) $row->last_date)->toDateString(),
                    'case_qty' => (int) ($row->case_qty ?? 0),
                    'piece_qty' => (int) ($row->piece_qty ?? 0),
                ];
            }
        }

        return $result;
    }

    /**
     * 最終仕入日と、その日の仕入数（ケース・バラ）。
     *
     * 予定ではなく確定済みの仕入データ（purchases）から取る。返品は含めない。
     * 対象はシステム日付から直近3ヶ月（config: last_purchase_lookback_months）。
     *
     * @param  array<int>  $itemIds
     * @return array<int, array{date: string, case_qty: int, piece_qty: int}>
     */
    public function lastPurchases(int $warehouseId, array $itemIds): array
    {
        $itemIds = $this->normalizeIds($itemIds);
        if ($warehouseId < 1 || $itemIds === []) {
            return [];
        }

        $since = $this->lastPurchaseSinceDate();
        // 商品数が多いときは商品で絞らずに 1 回で取り、PHP 側で絞る。
        // （1000 件ごとに分けると、同じ期間の仕入を分けた回数だけ読み直すことになる）
        $filterInSql = count($itemIds) <= self::ITEM_CHUNK_SIZE;
        $targetItemIds = array_flip($itemIds);
        $rows = [];

        $cursor = DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('purchases as p', 'p.trade_id', '=', 'ti.trade_id')
            ->join('trades as t', 't.id', '=', 'p.trade_id')
            ->where('p.warehouse_id', $warehouseId)
            ->where('p.is_active', true)
            ->where('p.delivered_date', '>=', $since)
            ->where('t.is_active', true)
            ->where('t.trade_category', 'PURCHASE')
            ->where('t.trade_direction', 'NORMAL')
            ->where('ti.is_active', true)
            ->where('ti.quantity', '>', 0)
            ->whereNotNull('ti.item_id')
            ->when($filterInSql, fn ($query) => $query->whereIn('ti.item_id', $itemIds))
            ->selectRaw('
                ti.item_id,
                p.delivered_date as last_date,
                SUM(CASE WHEN ti.quantity_type = \'CASE\' THEN ti.quantity ELSE 0 END) as case_qty,
                SUM(
                    CASE WHEN ti.quantity_type = \'CASE\' THEN 0
                        ELSE COALESCE(CAST(ti.total_piece_quantity AS SIGNED), ti.quantity)
                    END
                ) as piece_qty
            ')
            ->groupBy('ti.item_id', 'p.delivered_date')
            ->cursor();

        foreach ($cursor as $row) {
            $itemId = (int) $row->item_id;
            if (! isset($targetItemIds[$itemId])) {
                continue;
            }

            $rows[] = [
                'item_id' => $itemId,
                'date' => Carbon::parse((string) $row->last_date)->toDateString(),
                'case_qty' => (int) ($row->case_qty ?? 0),
                'piece_qty' => (int) ($row->piece_qty ?? 0),
            ];
        }

        return self::latestRowsByItem($rows);
    }

    /**
     * 発注候補検索・外部発注候補リストの行に足す参考データ。
     *
     * @param  array<int>  $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function rowReferences(int $warehouseId, array $itemIds): array
    {
        $itemIds = $this->normalizeIds($itemIds);
        if ($warehouseId < 1 || $itemIds === []) {
            return [];
        }

        $incomingWarehouseId = $this->searchService->incomingWarehouseId($warehouseId);
        $lastIncoming = $this->lastIncomingSchedules($incomingWarehouseId, $itemIds);
        $lastPurchases = $this->lastPurchases($incomingWarehouseId, $itemIds);
        $weeklySales = $this->adjustedWeeklySalesQuantities($warehouseId, $itemIds);

        $result = [];
        foreach ($itemIds as $itemId) {
            $incoming = $lastIncoming[$itemId] ?? null;
            $purchase = $lastPurchases[$itemId] ?? null;
            $weekly = $weeklySales[$itemId] ?? [];

            $result[$itemId] = [
                'last_incoming_date' => $incoming['date'] ?? null,
                'last_incoming_case_qty' => (int) ($incoming['case_qty'] ?? 0),
                'last_incoming_piece_qty' => (int) ($incoming['piece_qty'] ?? 0),
                'last_purchase_date' => $purchase['date'] ?? null,
                'last_purchase_case_qty' => (int) ($purchase['case_qty'] ?? 0),
                'last_purchase_piece_qty' => (int) ($purchase['piece_qty'] ?? 0),
                'sales_week1_qty' => (int) ($weekly['sales_week1_qty'] ?? 0),
                'sales_week2_qty' => (int) ($weekly['sales_week2_qty'] ?? 0),
                'sales_week3_qty' => (int) ($weekly['sales_week3_qty'] ?? 0),
                'previous_month_sales_qty' => (int) ($weekly['previous_month_sales_qty'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * 登録リストの行に表示する参考データ（発注点・理論在庫を含む）。
     *
     * @param  array<int>  $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function itemReferences(int $warehouseId, array $itemIds): array
    {
        $itemIds = $this->normalizeIds($itemIds);
        if ($warehouseId < 1 || $itemIds === []) {
            return [];
        }

        $incomingWarehouseId = $this->searchService->incomingWarehouseId($warehouseId);
        $references = $this->rowReferences($warehouseId, $itemIds);
        $stocks = [];
        $safetyStocks = [];
        $volumeLabels = [];

        foreach (array_chunk($itemIds, self::ITEM_CHUNK_SIZE) as $chunk) {
            $stockRows = DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('warehouse_id', $warehouseId)
                ->whereIn('item_id', $chunk)
                ->selectRaw('item_id, SUM(available_quantity) as available_qty')
                ->groupBy('item_id')
                ->get();

            foreach ($stockRows as $row) {
                $stocks[(int) $row->item_id] = (int) ($row->available_qty ?? 0);
            }

            $safetyRows = DB::connection('sakemaru')
                ->table('item_contractors')
                ->where('warehouse_id', $incomingWarehouseId)
                ->whereIn('item_id', $chunk)
                ->get(['item_id', 'contractor_id', 'safety_stock']);

            foreach ($safetyRows as $row) {
                $safetyStocks[(int) $row->item_id][(int) $row->contractor_id] = (int) ($row->safety_stock ?? 0);
            }

            $itemRows = DB::connection('sakemaru')
                ->table('items')
                ->whereIn('id', $chunk)
                ->get(['id', 'volume', 'volume_unit']);

            foreach ($itemRows as $row) {
                $volumeLabels[(int) $row->id] = self::volumeLabel($row->volume, $row->volume_unit);
            }
        }

        $result = [];
        foreach ($itemIds as $itemId) {
            $result[$itemId] = array_merge($references[$itemId] ?? [], [
                'effective_stock' => (int) ($stocks[$itemId] ?? 0),
                'safety_stocks' => $safetyStocks[$itemId] ?? [],
                'volume_label' => $volumeLabels[$itemId] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * 発注先に紐づく仕入先（倉庫の商品発注先マスタで実際に組になっているもの）。
     *
     * @param  array<int>  $contractorIds
     * @return array{suppliers: array<int, array{id: int, code: string, name: string}>, links: array<string, array<int>>}
     */
    public function contractorSupplierLinks(int $warehouseId, array $contractorIds): array
    {
        $contractorIds = $this->normalizeIds($contractorIds);
        if ($warehouseId < 1 || $contractorIds === []) {
            return ['suppliers' => [], 'links' => []];
        }

        $incomingWarehouseId = $this->searchService->incomingWarehouseId($warehouseId);
        $targetContractorIds = array_flip($contractorIds);

        // 先に（発注先, 仕入先）の組を絞ってから名称を引く（商品発注先マスタは件数が多い）。
        $pairs = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $incomingWarehouseId)
            ->select(['contractor_id', 'supplier_id'])
            ->distinct();

        $rows = DB::connection('sakemaru')
            ->query()
            ->fromSub($pairs, 'ic')
            ->join('suppliers as s', 's.id', '=', 'ic.supplier_id')
            ->leftJoin('partners as p', 'p.id', '=', 's.partner_id')
            ->get([
                'ic.contractor_id',
                'ic.supplier_id',
                'p.code as supplier_code',
                'p.name as supplier_name',
            ]);

        $suppliers = [];
        $links = [];

        foreach ($rows as $row) {
            $contractorId = (int) $row->contractor_id;
            $supplierId = (int) $row->supplier_id;
            if ($supplierId < 1 || ! isset($targetContractorIds[$contractorId])) {
                continue;
            }

            $suppliers[$supplierId] = [
                'id' => $supplierId,
                'code' => (string) ($row->supplier_code ?? ''),
                'name' => (string) ($row->supplier_name ?? ''),
            ];
            $links[(string) $contractorId][] = $supplierId;
        }

        $suppliers = array_values($suppliers);
        usort($suppliers, fn (array $a, array $b): int => strnatcmp($a['code'], $b['code']) ?: ($a['id'] <=> $b['id']));

        foreach ($links as $contractorId => $supplierIds) {
            $links[$contractorId] = array_values(array_unique($supplierIds));
        }

        return ['suppliers' => $suppliers, 'links' => $links];
    }

    /**
     * 最終仕入日を探し始める日付（システム日付から直近 N ヶ月）。
     */
    public function lastPurchaseSinceDate(): string
    {
        $months = $this->lastPurchaseLookbackMonths();
        $systemDate = Carbon::parse(ClientSetting::freshSystemDateYMD('order_registration_hq:last_purchase'));

        return $systemDate->subMonthsNoOverflow($months)->toDateString();
    }

    public function lastPurchaseLookbackMonths(): int
    {
        return max(1, (int) config('wms_hq_order_registration.last_purchase_lookback_months', 3));
    }

    /**
     * 商品の容量表示（例: 750ml）。容量が登録されていない商品は null。
     */
    public static function volumeLabel(mixed $volume, mixed $volumeUnit): ?string
    {
        if (! is_numeric($volume) || (float) $volume <= 0) {
            return null;
        }

        $number = (float) $volume;
        $volumeText = abs($number - round($number)) < 0.0001
            ? (string) (int) round($number)
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        $unit = EVolumeUnit::tryFrom((string) $volumeUnit)?->name() ?? '';

        return $volumeText.$unit;
    }

    /**
     * 全体の実績から店舗卸分を差し引く。
     *
     * 実績集計（30分ごとのバッチ）と売上データの時点差で一時的にマイナスへ
     * 振れないよう、元の実績が0以上のときは0で止める。
     */
    public static function subtractBranchQuantity(int $total, int $branch): int
    {
        if ($branch === 0) {
            return $total;
        }

        $adjusted = $total - $branch;

        return ($total >= 0 && $adjusted < 0) ? 0 : $adjusted;
    }

    /**
     * 商品ごとに一番新しい日付の行だけを残す。
     *
     * @param  array<int, array{item_id: int, date: string, case_qty: int, piece_qty: int}>  $rows
     * @return array<int, array{date: string, case_qty: int, piece_qty: int}>
     */
    public static function latestRowsByItem(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $date = (string) $row['date'];

            if (! isset($result[$itemId]) || $date > $result[$itemId]['date']) {
                $result[$itemId] = [
                    'date' => $date,
                    'case_qty' => (int) $row['case_qty'],
                    'piece_qty' => (int) $row['piece_qty'],
                ];

                continue;
            }

            if ($date === $result[$itemId]['date']) {
                $result[$itemId]['case_qty'] += (int) $row['case_qty'];
                $result[$itemId]['piece_qty'] += (int) $row['piece_qty'];
            }
        }

        return $result;
    }

    /**
     * @param  array<int>  $itemIds
     * @param  array<string, array{start: string, end: string}>  $ranges
     * @return array<int, array<string, int>>
     */
    private function statsSalesQuantities(int $warehouseId, array $itemIds, array $ranges): array
    {
        $selects = ['item_id'];
        $bindings = [];
        foreach (array_keys($ranges) as $index => $key) {
            $selects[] = "SUM(CASE WHEN business_date BETWEEN ? AND ? THEN shipped_piece_qty ELSE 0 END) as range_{$index}";
            $bindings[] = $ranges[$key]['start'];
            $bindings[] = $ranges[$key]['end'];
        }

        $keys = array_keys($ranges);
        $result = [];

        foreach (array_chunk($itemIds, self::ITEM_CHUNK_SIZE) as $chunk) {
            $rows = DB::connection('sakemaru')
                ->table('stats_item_warehouse_daily_sales')
                ->where('warehouse_id', $warehouseId)
                ->whereIn('item_id', $chunk)
                ->whereBetween('business_date', [
                    min(array_column($ranges, 'start')),
                    max(array_column($ranges, 'end')),
                ])
                ->selectRaw(implode(', ', $selects), $bindings)
                ->groupBy('item_id')
                ->get();

            foreach ($rows as $row) {
                foreach ($keys as $index => $key) {
                    $result[(int) $row->item_id][$key] = (int) ($row->{"range_{$index}"} ?? 0);
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int>
     */
    private function excludedCourseWarehouseIds(): array
    {
        $codes = $this->excludedCourseWarehouseCodes();
        if ($codes === []) {
            return [];
        }

        $warehouseIds = DB::connection('sakemaru')
            ->table('warehouses')
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($warehouseIds === []) {
            return [];
        }

        // 敦賀店 業販課のように、除外対象倉庫を在庫倉庫とする仮想倉庫も対象にする。
        $virtualWarehouseIds = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('is_virtual', true)
            ->whereIn('stock_warehouse_id', $warehouseIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($warehouseIds, $virtualWarehouseIds)));
    }

    /**
     * @return array<int, string>
     */
    private function excludedCourseWarehouseCodes(): array
    {
        // 設定キャッシュが古い環境でも除外が効くよう、未設定時は敦賀(10)・小浜(22)を既定にする。
        $codes = config('wms_hq_order_registration.excluded_sales_course_warehouse_codes');

        return collect(is_array($codes) ? $codes : ['10', '22'])
            ->map(fn ($code): string => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string>  $ids
     * @return array<int>
     */
    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
