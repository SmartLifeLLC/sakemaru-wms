<?php

namespace App\Services\SundryInventoryCount;

use App\Services\InventoryCount\InventoryCountLedgerBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 棚卸し（雑貨）の理論値計算。読み取り専用。
 *
 * - 在庫管理あり: 既存棚卸しと同じ受払残（InventoryCountLedgerBalanceService）の数量 × 原価
 * - 在庫管理なし: 基幹の月次在庫金額評価（MonthlyStockAmountValuation）の非管理品と同じ流量式
 *     売上原価 = (EARNING売価 + POS売価) × 分類原価率(小→中→大)   ※売価還元法
 *     仕入 / 移入 / 移出 = 実取引金額(trade_items.amount)
 *     理論金額 = 前残 + 仕入 + 移入 − 移出 − 売上原価 + 調整プラグ
 *   旧Access（まとめ.mdb）と同じ中分類単位で、日付を指定して集計する（大分類単位でも集計できる）。
 * - 在庫管理なしの前残:
 *     中分類別 … 旧システム Ｔ３在庫（在庫管理区分=0）の最終残高を取り込んだCSV
 *                 （旧システムは 2026-05-05 まで。新システムの受払は 2026-05-06 から）
 *     大分類別 … 在庫金額報告書の月末金額 − 在庫管理あり商品の月末評価額（突合用）
 */
class SundryInventoryTheoryCalculator
{
    /** 分類の階層: 1 = 大分類, 2 = 中分類 */
    private const CATEGORY_COLUMNS = [1 => 'i.item_category1_id', 2 => 'i.item_category2_id'];

    private ?bool $hasCostRateColumn = null;

    /** @var array<string, array<string, array<string, float>>>|null 基準日 => 倉庫コード => 中分類コード => 金額 */
    private ?array $legacyOpenings = null;

    /**
     * 倉庫・商品別の受払最終残（在庫管理ありの商品のみ）。
     *
     * @return array<int, float> item_id => 数量
     */
    public function managedBalances(int $clientId, int $warehouseId, string $endDate): array
    {
        $endDate = CarbonImmutable::parse($endDate)->toDateString();
        $ledgerService = new InventoryCountLedgerBalanceService;
        $connection = DB::connection('sakemaru');

        if ($connection->transactionLevel() > 0) {
            return $ledgerService->balancesByItem($clientId, $warehouseId, $endDate);
        }

        // 既存棚卸しと同じく、書込ロックを取らない一貫読み取りで計算する。
        $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $connection->beginTransaction();

        try {
            $balances = $ledgerService->balancesByItem($clientId, $warehouseId, $endDate);
            $connection->commit();

            return $balances;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * 基準日時点で有効な原価（item_prices.cost_unit_price。start_date <= 基準日 の最新行）。
     * 基幹の月末原価スナップショット（item_cost_snapshots）と同じ採用ルール。
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, float> item_id => 原価
     */
    public function costPrices(int $clientId, array $itemIds, string $date): array
    {
        $itemIds = $this->intList($itemIds);
        if ($itemIds === []) {
            return [];
        }

        $date = CarbonImmutable::parse($date)->toDateString();
        $costPrices = [];

        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $ranked = DB::connection('sakemaru')
                ->table('item_prices as ip')
                ->select([
                    'ip.item_id',
                    'ip.cost_unit_price',
                    DB::raw('ROW_NUMBER() OVER (PARTITION BY ip.item_id ORDER BY ip.start_date DESC, ip.id DESC) as price_rank'),
                ])
                ->whereIn('ip.item_id', $chunk)
                ->where('ip.client_id', $clientId)
                ->where('ip.start_date', '<=', $date);

            DB::connection('sakemaru')
                ->query()
                ->fromSub($ranked, 'ranked_prices')
                ->where('ranked_prices.price_rank', 1)
                ->get(['ranked_prices.item_id', 'ranked_prices.cost_unit_price'])
                ->each(function ($price) use (&$costPrices): void {
                    $costPrices[(int) $price->item_id] = (float) ($price->cost_unit_price ?? 0);
                });

            // 基準日以前の価格行が無い商品は、有効な価格行で補う。
            $missing = array_values(array_diff($chunk, array_keys($costPrices)));
            if ($missing !== []) {
                DB::connection('sakemaru')
                    ->table('item_prices')
                    ->whereIn('item_id', $missing)
                    ->where('client_id', $clientId)
                    ->where('is_active', true)
                    ->orderBy('start_date')
                    ->orderBy('id')
                    ->get(['item_id', 'cost_unit_price'])
                    ->each(function ($price) use (&$costPrices): void {
                        $costPrices[(int) $price->item_id] ??= (float) ($price->cost_unit_price ?? 0);
                    });
            }
        }

        return $costPrices;
    }

    /**
     * 期間売上数量（バラ数量。量り売りは 100ml 単位）。
     *
     * 旧Access（Q_03_売上数量）の「前回棚卸日の翌日〜今回棚卸日の売上数量」にあたる。
     *  - 新システム稼働日（2026-05-06）以降: 理論数と同じ元データ（小売POSの在庫引当 + 売上伝票）を集計する。
     *  - それより前: 日別売上の統計（stats_item_warehouse_daily_sales。旧システムの売上を取り込み済み）を使う。
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, float> item_id => 売上数量（売上が無い商品は 0）
     */
    public function periodSalesQuantities(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): array
    {
        $itemIds = $this->intList($itemIds);
        $fromDate = CarbonImmutable::parse($fromDate)->toDateString();
        $endDate = CarbonImmutable::parse($endDate)->toDateString();
        $sales = array_fill_keys($itemIds, 0.0);

        if ($itemIds === [] || $fromDate > $endDate) {
            return $sales;
        }

        $add = function (iterable $rows) use (&$sales): void {
            foreach ($rows as $row) {
                $itemId = (int) $row->item_id;
                $sales[$itemId] = ($sales[$itemId] ?? 0.0) + (float) ($row->quantity ?? 0);
            }
        };

        $goLiveDate = InventoryCountLedgerBalanceService::OPENING_DATE;
        $connection = DB::connection('sakemaru');
        $schema = Schema::connection('sakemaru');

        // 旧システム期間（〜稼働日の前日）
        if ($fromDate < $goLiveDate && $schema->hasTable('stats_item_warehouse_daily_sales')) {
            $legacyEndDate = min($endDate, CarbonImmutable::parse($goLiveDate)->subDay()->toDateString());

            $add($connection->table('stats_item_warehouse_daily_sales')
                ->where('warehouse_id', $warehouseId)
                ->whereIn('item_id', $itemIds)
                ->whereBetween('business_date', [$fromDate, $legacyEndDate])
                ->groupBy('item_id')
                ->selectRaw('item_id, SUM(sales_piece_qty) as quantity')
                ->get());
        }

        // 新システム期間（稼働日〜）
        if ($endDate >= $goLiveDate) {
            $newFromDate = max($fromDate, $goLiveDate);

            if ($schema->hasTable('ret_pos_stock_applications')) {
                $add($connection->table('ret_pos_stock_applications')
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('item_id', $itemIds)
                    ->whereBetween('business_date', [$newFromDate, $endDate])
                    ->groupBy('item_id')
                    ->selectRaw('item_id, SUM(quantity) as quantity')
                    ->get());
            }

            $pieceQuantity = 'COALESCE(NULLIF(ti.total_piece_quantity, 0),'
                .' CASE ti.quantity_type'
                ." WHEN 'CASE' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_case, 0), 1)"
                ." WHEN 'CARTON' THEN ti.quantity * COALESCE(NULLIF(ti.capacity_carton, 0), 1)"
                .' ELSE ti.quantity END)';
            $isReturn = "COALESCE(t.is_returned, 0) = 1 OR COALESCE(t.trade_direction, 'NORMAL') = 'RETURN' OR ({$pieceQuantity}) < 0";

            $add($connection->table('trade_items as ti')
                ->join('trades as t', 't.id', '=', 'ti.trade_id')
                ->join('earnings as e', 'e.trade_id', '=', 't.id')
                ->where('t.client_id', $clientId)
                ->where('e.warehouse_id', $warehouseId)
                ->where('t.trade_category', 'EARNING')
                ->where('t.is_active', true)
                ->where('t.is_latest', true)
                ->where('e.is_active', true)
                ->where('ti.is_active', true)
                ->whereIn('ti.item_id', $itemIds)
                ->whereRaw('COALESCE(e.delivered_date, t.process_date) BETWEEN ? AND ?', [$newFromDate, $endDate])
                ->groupBy('ti.item_id')
                ->selectRaw("ti.item_id, SUM(CASE WHEN {$isReturn} THEN -ABS({$pieceQuantity}) ELSE ABS({$pieceQuantity}) END) as quantity")
                ->get());
        }

        return array_map(fn (float $quantity): float => round($quantity, 3), $sales);
    }

    /**
     * 在庫管理なし商品の金額受払。fromDate〜endDate（両端含む）を分類別に集計する。
     *
     * @param  array<int, int>  $categoryIds  item_categories.id（depth の階層）
     * @param  int  $depth  1 = 大分類別, 2 = 中分類別
     * @return array<int, array{purchase: float, transfer_in: float, transfer_out: float, sales_cost: float, adjustment: float}>
     */
    public function unmanagedFlows(int $clientId, int $warehouseId, array $categoryIds, string $fromDate, string $endDate, int $depth = 2): array
    {
        $categoryIds = $this->intList($categoryIds);
        $column = self::CATEGORY_COLUMNS[$depth];
        $flows = [];
        foreach ($categoryIds as $categoryId) {
            $flows[$categoryId] = ['purchase' => 0.0, 'transfer_in' => 0.0, 'transfer_out' => 0.0, 'sales_cost' => 0.0, 'adjustment' => 0.0];
        }

        $fromDate = CarbonImmutable::parse($fromDate)->toDateString();
        $endDate = CarbonImmutable::parse($endDate)->toDateString();
        if ($categoryIds === [] || $fromDate > $endDate) {
            return $flows;
        }

        // 売上原価（EARNING）
        $earning = $this->unmanagedItemQuery('ti.item_id', $categoryIds, $depth, true, DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', function ($join): void {
                $join->on('t.id', '=', 'ti.trade_id')
                    ->where('t.trade_category', 'EARNING')
                    ->where('t.is_active', true)
                    ->where('t.is_latest', true);
            })
            ->join('earnings as e', 'e.trade_id', '=', 't.id'))
            ->where('t.client_id', $clientId)
            ->where('ti.is_active', true)
            ->where('e.is_active', true)
            ->where('e.warehouse_id', $warehouseId)
            ->whereRaw('COALESCE(e.delivered_date, t.process_date) BETWEEN ? AND ?', [$fromDate, $endDate])
            ->groupBy($column)
            ->select([
                "{$column} as category_id",
                DB::raw("SUM(ti.amount * {$this->costRateExpression()} / 100) as amount"),
                DB::raw("SUM(CASE WHEN {$this->plugCondition()} THEN ti.amount * {$this->costRateExpression()} / 100 ELSE 0 END) as plug"),
            ])
            ->get();

        foreach ($earning as $row) {
            $flows[(int) $row->category_id]['sales_cost'] += (float) $row->amount;
            $flows[(int) $row->category_id]['adjustment'] += (float) $row->plug;
        }

        // 売上原価（小売POS）
        if (Schema::connection('sakemaru')->hasTable('ret_pos_item_sale_histories')) {
            $pos = $this->unmanagedItemQuery('h.item_id', $categoryIds, $depth, true, DB::connection('sakemaru')
                ->table('ret_pos_item_sale_histories as h'))
                ->where('h.warehouse_id', $warehouseId)
                ->whereBetween('h.business_date', [$fromDate, $endDate])
                ->groupBy($column)
                ->select([
                    "{$column} as category_id",
                    DB::raw("SUM(h.sales_amount * {$this->costRateExpression()} / 100) as amount"),
                    DB::raw("SUM(CASE WHEN {$this->plugCondition()} THEN h.sales_amount * {$this->costRateExpression()} / 100 ELSE 0 END) as plug"),
                ])
                ->get();

            foreach ($pos as $row) {
                $flows[(int) $row->category_id]['sales_cost'] += (float) $row->amount;
                $flows[(int) $row->category_id]['adjustment'] += (float) $row->plug;
            }
        }

        // 仕入（原価 = trade_items.amount）
        $purchase = $this->unmanagedPurchaseQuery($clientId, $warehouseId, $categoryIds, $depth, $fromDate, $endDate)
            ->groupBy($column)
            ->select(["{$column} as category_id", DB::raw('SUM(ti.amount) as amount')])
            ->get();

        foreach ($purchase as $row) {
            $flows[(int) $row->category_id]['purchase'] += (float) $row->amount;
        }

        // 移入 / 移出（原価 = trade_items.amount）
        foreach (['transfer_in' => 'to_warehouse_id', 'transfer_out' => 'from_warehouse_id'] as $key => $warehouseColumn) {
            $transfer = $this->unmanagedItemQuery('ti.item_id', $categoryIds, $depth, false, DB::connection('sakemaru')
                ->table('trade_items as ti')
                ->join('trades as t', function ($join): void {
                    $join->on('t.id', '=', 'ti.trade_id')
                        ->where('t.trade_category', 'STOCK_TRANSFER')
                        ->where('t.is_active', true)
                        ->where('t.is_latest', true);
                })
                ->join('stock_transfers as st', function ($join) use ($warehouseColumn, $warehouseId): void {
                    $join->on('st.trade_id', '=', 't.id')
                        ->where("st.{$warehouseColumn}", $warehouseId)
                        ->where('st.is_active', true);
                }))
                ->where('t.client_id', $clientId)
                ->where('ti.is_active', true)
                ->whereRaw('COALESCE(st.picking_date, t.process_date) BETWEEN ? AND ?', [$fromDate, $endDate])
                ->groupBy($column)
                ->select(["{$column} as category_id", DB::raw('SUM(ti.amount) as amount')])
                ->get();

            foreach ($transfer as $row) {
                $flows[(int) $row->category_id][$key] += (float) $row->amount;
            }
        }

        foreach ($flows as $categoryId => $flow) {
            $flows[$categoryId] = array_map(fn (float $amount): float => round($amount, 2), $flow);
        }

        return $flows;
    }

    /**
     * 在庫管理なし商品の仕入明細（旧Access「雑貨代表コード仕入先」に相当）。
     *
     * @param  array<int, int>  $categoryIds  item_categories.id（depth の階層）
     * @return Collection<int, object>
     */
    public function unmanagedPurchaseLines(int $clientId, int $warehouseId, array $categoryIds, string $fromDate, string $endDate, int $depth = 2): Collection
    {
        $categoryIds = $this->intList($categoryIds);
        $fromDate = CarbonImmutable::parse($fromDate)->toDateString();
        $endDate = CarbonImmutable::parse($endDate)->toDateString();
        if ($categoryIds === [] || $fromDate > $endDate) {
            return collect();
        }

        return $this->unmanagedPurchaseQuery($clientId, $warehouseId, $categoryIds, $depth, $fromDate, $endDate)
            ->leftJoin('item_categories as c2', 'c2.id', '=', 'i.item_category2_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->leftJoin('partners as pa', 'pa.id', '=', 's.partner_id')
            ->orderBy('t.process_date')
            ->orderBy('t.id')
            ->orderBy('ti.order_of_items_in_slip')
            ->orderBy('ti.id')
            ->get([
                't.process_date',
                't.slip_number',
                'pa.code as supplier_code',
                'pa.name as supplier_name',
                'c2.code as category2_code',
                'i.code as item_code',
                DB::raw('COALESCE(ti.item_name, i.name) as item_name'),
                'ti.quantity',
                'ti.quantity_type',
                'ti.total_piece_quantity',
                'ti.amount',
                DB::raw("COALESCE(NULLIF(ti.note, ''), t.note) as note"),
            ]);
    }

    /**
     * 在庫管理なしの商品を持つ中分類（金額明細を作る対象）。
     * 大分類ID、または中分類IDで絞り込む。
     *
     * @param  array<int, int>  $category1Ids  item_categories.id（大分類）
     * @param  array<int, int>  $category2Ids  item_categories.id（中分類）
     * @return Collection<int, object> category1_id / category1_code / category1_name / category2_id / category2_code / category2_name
     */
    public function unmanagedCategories(int $clientId, array $category1Ids, array $category2Ids = []): Collection
    {
        $category1Ids = $this->intList($category1Ids);
        $category2Ids = $this->intList($category2Ids);
        if ($category1Ids === [] && $category2Ids === []) {
            return collect();
        }

        $query = DB::connection('sakemaru')
            ->table('items as i')
            ->join('item_categories as c2', 'c2.id', '=', 'i.item_category2_id')
            ->leftJoin('item_categories as c1', 'c1.id', '=', 'i.item_category1_id')
            ->where('i.client_id', $clientId)
            ->where('i.is_managed_stock', false)
            ->where(function ($query) use ($category1Ids, $category2Ids): void {
                $query->whereIn('i.item_category1_id', $category1Ids)
                    ->orWhereIn('i.item_category2_id', $category2Ids);
            });
        $this->excludeOwnedSetParents($query);

        return $query
            ->groupBy('c2.id', 'c2.code', 'c2.name')
            ->orderBy('c2.code')
            ->get([
                DB::raw('MIN(c1.id) as category1_id'),
                DB::raw('MIN(c1.code) as category1_code'),
                DB::raw('MIN(c1.name) as category1_name'),
                'c2.id as category2_id',
                'c2.code as category2_code',
                'c2.name as category2_name',
            ]);
    }

    /**
     * 在庫管理なし商品の中分類別の前残金額（旧システム Ｔ３在庫 の残高。基準日 = 旧システム最終日 2026-05-05）。
     *
     * database/data/sundry_inventory/nonmanaged_opening/{基準日}.csv（warehouse_code,category2_code,amount）から、
     * onOrBeforeDate 以前で最新の基準日のファイルを使う。その倉庫の行が1つも無ければ null。
     * 倉庫の行はあるがその中分類が無い場合は残高0。
     *
     * @return array{date: string, amount: float}|null
     */
    public function unmanagedLegacyOpening(string $warehouseCode, string $category2Code, string $onOrBeforeDate): ?array
    {
        $onOrBeforeDate = CarbonImmutable::parse($onOrBeforeDate)->toDateString();
        $warehouseCode = $this->normalizeCode($warehouseCode);
        $category2Code = $this->normalizeCode($category2Code);

        foreach ($this->legacyOpenings() as $date => $byWarehouse) {
            if ($date > $onOrBeforeDate) {
                continue;
            }

            if (! isset($byWarehouse[$warehouseCode])) {
                return null;
            }

            return [
                'date' => $date,
                'amount' => round((float) ($byWarehouse[$warehouseCode][$category2Code] ?? 0), 2),
            ];
        }

        return null;
    }

    /**
     * 在庫管理なし商品の大分類別の残高を、在庫金額報告書の月末金額から求める（中分類合計との突合用）。
     *
     * 報告書は大分類単位で在庫管理あり・なしの合算のため、
     *   残高 = 報告書の月末金額 − 在庫管理あり商品の月末評価額（月末数量 × 月次原価スナップショット）
     * とする。onOrBeforeDate 以前に月末を迎えた最新の報告書を使う。
     *
     * @return array{month: string, date: string, report_amount: float, managed_amount: float, amount: float}|null 報告書や月次データが無い場合は null
     */
    public function unmanagedOpeningFromStockReport(int $clientId, int $warehouseId, int $category1Id, string $onOrBeforeDate): ?array
    {
        $schema = Schema::connection('sakemaru');
        foreach (['stats_monthly_stock_amount_reports', 'stats_item_stock_movement_monthly_summaries', 'item_cost_snapshots'] as $table) {
            if (! $schema->hasTable($table)) {
                return null;
            }
        }

        $categoryCode = DB::connection('sakemaru')
            ->table('item_categories')
            ->where('id', $category1Id)
            ->where('depth', 1)
            ->value('code');
        if ($categoryCode === null) {
            return null;
        }

        // 報告書の大分類コードは「大分類コード − 1000」（例: 1004 雑貨 → 4）。
        $reportCategoryCode = (string) ((int) $categoryCode - 1000);
        $date = CarbonImmutable::parse($onOrBeforeDate);
        $latestMonth = $date->isSameDay($date->endOfMonth())
            ? $date->format('Y-m')
            : $date->subMonthNoOverflow()->format('Y-m');

        $report = DB::connection('sakemaru')
            ->table('stats_monthly_stock_amount_reports')
            ->where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('category_code', $reportCategoryCode)
            ->where('target_year_month', '<=', $latestMonth)
            ->orderByDesc('target_year_month')
            ->first(['target_year_month', 'closing_amount']);
        if (! $report) {
            return null;
        }

        $month = (string) $report->target_year_month;

        // 管理品の評価に必要な月次数量・月次原価が無い月（移行前の月など）は、管理品を分離できないので使わない。
        $hasMonthlyQuantities = DB::connection('sakemaru')
            ->table('stats_item_stock_movement_monthly_summaries')
            ->where('client_id', $clientId)
            ->where('warehouse_id', $warehouseId)
            ->where('closing_month', $month)
            ->exists();
        $hasCostSnapshots = DB::connection('sakemaru')
            ->table('item_cost_snapshots')
            ->where('client_id', $clientId)
            ->where('target_year_month', $month)
            ->exists();
        if (! $hasMonthlyQuantities || ! $hasCostSnapshots) {
            return null;
        }

        $managedQuery = DB::connection('sakemaru')
            ->table('stats_item_stock_movement_monthly_summaries as m')
            ->join('items as i', 'i.id', '=', 'm.item_id')
            ->leftJoin('item_cost_snapshots as ics', function ($join) use ($clientId, $month): void {
                $join->on('ics.item_id', '=', 'm.item_id')
                    ->where('ics.client_id', $clientId)
                    ->where('ics.target_year_month', $month);
            })
            ->where('m.client_id', $clientId)
            ->where('m.closing_month', $month)
            ->where('m.warehouse_id', $warehouseId)
            ->where('i.is_managed_stock', true)
            ->where('i.item_category1_id', $category1Id);
        $this->excludeOwnedSetParents($managedQuery);

        $managedAmount = round((float) $managedQuery->sum(
            DB::raw('COALESCE(m.real_stock_quantity_at_close, m.closing_quantity) * COALESCE(ics.cost_unit_price, 0)')
        ), 2);
        $reportAmount = round((float) $report->closing_amount, 2);

        return [
            'month' => $month,
            'date' => CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01')->endOfMonth()->toDateString(),
            'report_amount' => $reportAmount,
            'managed_amount' => $managedAmount,
            'amount' => round($reportAmount - $managedAmount, 2),
        ];
    }

    /**
     * 旧システム残高CSVを読み込む（基準日の新しい順）。
     *
     * @return array<string, array<string, array<string, float>>> 基準日 => 倉庫コード => 中分類コード => 金額
     */
    private function legacyOpenings(): array
    {
        if ($this->legacyOpenings !== null) {
            return $this->legacyOpenings;
        }

        $openings = [];
        foreach (glob(database_path('data/sundry_inventory/nonmanaged_opening/*.csv')) ?: [] as $path) {
            $date = basename($path, '.csv');
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
                continue;
            }

            $handle = fopen($path, 'r');
            if ($handle === false) {
                continue;
            }

            fgetcsv($handle, null, ',', '"', ''); // header
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (! isset($row[0], $row[1], $row[2])) {
                    continue;
                }

                $warehouseCode = $this->normalizeCode($row[0]);
                $category2Code = $this->normalizeCode($row[1]);
                $openings[$date][$warehouseCode][$category2Code] = ($openings[$date][$warehouseCode][$category2Code] ?? 0) + (float) $row[2];
            }
            fclose($handle);
        }

        krsort($openings);

        return $this->legacyOpenings = $openings;
    }

    private function unmanagedPurchaseQuery(int $clientId, int $warehouseId, array $categoryIds, int $depth, string $fromDate, string $endDate): Builder
    {
        return $this->unmanagedItemQuery('ti.item_id', $categoryIds, $depth, false, DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('trades as t', function ($join): void {
                $join->on('t.id', '=', 'ti.trade_id')
                    ->where('t.trade_category', 'PURCHASE')
                    ->where('t.is_active', true)
                    ->where('t.is_latest', true);
            })
            ->join('purchases as p', function ($join) use ($warehouseId): void {
                $join->on('p.trade_id', '=', 't.id')
                    ->where('p.is_active', true)
                    ->where('p.warehouse_id', $warehouseId);
            }))
            ->where('t.client_id', $clientId)
            ->where('ti.is_active', true)
            ->whereBetween('t.process_date', [$fromDate, $endDate]);
    }

    /**
     * 在庫管理なし・対象分類・自社セット親以外の商品に絞り込む。
     *
     * @param  array<int, int>  $categoryIds
     */
    private function unmanagedItemQuery(string $itemIdColumn, array $categoryIds, int $depth, bool $withCostRateCategories, Builder $query): Builder
    {
        $query
            ->join('items as i', function ($join) use ($itemIdColumn): void {
                $join->on('i.id', '=', $itemIdColumn)
                    ->where('i.is_managed_stock', false);
            })
            ->whereIn(self::CATEGORY_COLUMNS[$depth], $categoryIds);

        if ($withCostRateCategories) {
            $query
                ->leftJoin('item_categories as rate_c1', 'rate_c1.id', '=', 'i.item_category1_id')
                ->leftJoin('item_categories as rate_c2', 'rate_c2.id', '=', 'i.item_category2_id')
                ->leftJoin('item_categories as rate_c3', 'rate_c3.id', '=', 'i.item_category3_id');
        }

        $this->excludeOwnedSetParents($query);

        return $query;
    }

    private function excludeOwnedSetParents(Builder $query): void
    {
        if (! Schema::connection('sakemaru')->hasTable('item_sets')) {
            return;
        }

        $query->whereNotExists(function ($sub): void {
            $sub->select(DB::raw(1))
                ->from('item_sets as owned_sets')
                ->whereColumn('owned_sets.id', 'i.item_set_id')
                ->where('owned_sets.set_type', 'OWNED')
                ->where('owned_sets.is_active', true);
        });
    }

    private function costRateExpression(): string
    {
        // 分類原価率（item_categories.cost_rate）が未導入の環境では売上原価を計上しない。
        $this->hasCostRateColumn ??= Schema::connection('sakemaru')->hasColumn('item_categories', 'cost_rate');

        return $this->hasCostRateColumn
            ? 'COALESCE(rate_c3.cost_rate, rate_c2.cost_rate, rate_c1.cost_rate, 0)'
            : '0';
    }

    private function plugCondition(): string
    {
        $codes = SundryInventorySettings::plugSubcategoryCodes();

        return $codes === []
            ? '1 = 0'
            : 'rate_c3.code IN ('.implode(',', $codes).')';
    }

    private function normalizeCode(mixed $code): string
    {
        $code = trim((string) $code);

        return preg_match('/\A\d+\z/', $code) === 1 ? (string) (int) $code : $code;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function intList(array $values): array
    {
        return array_values(array_unique(array_map('intval', array_filter($values))));
    }
}
