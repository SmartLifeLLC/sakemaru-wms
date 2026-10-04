<?php

namespace App\Console\Commands\Stats;

use App\Models\Sakemaru\ClientSetting;
use App\Services\Stats\SalesSummarySynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

class SyncSalesSummariesCommand extends Command
{
    private ?PDO $lockedPdo = null;

    private const LIVE_DATA_START_DATE = '2026-05-06';

    private const HISTORY_CHUNK_DAYS = 7;

    private const DAILY_QUANTITY_COLUMNS = [
        'shipped_piece_qty', 'sales_piece_qty', 'transfer_piece_qty',
        'return_piece_qty', 'shipped_case_qty', 'shipped_bottle_qty',
    ];

    protected $signature = 'wms:sync-sales-summaries
        {--warehouse-id= : 特定倉庫のみ集計}
        {--from= : 売上・小売・倉庫移動から日次実績を再集計する開始日(Y-m-d)}
        {--to= : 売上・小売・倉庫移動から日次実績を再集計する終了日(Y-m-d)。未指定ならシステム日付}
        {--days=3 : 通常実行で--from未指定時に再集計する日数（期間照合時は不使用）}
        {--reconcile-order-window : 前月全体と直近30日を含む期間の日次実績を7日ずつ照合}
        {--reconcile-history : 2026-05-06から--toまでの日次実績を7日ずつ照合}
        {--summary-only : 日次実績更新をスキップ}
        {--purge-before-sync : 日次実績更新前に、集計元に存在しない2026-05-06以降の日次行を削除}
        {--dry-run : 実際の書き込みなしに集計結果を表示}';

    protected $description = '売上・小売・倉庫移動を基に倉庫別商品別の出荷実績日次・サマリを更新';

    public function handle(): int
    {
        $connection = DB::connection('sakemaru');
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $this->executeSync();
        }

        // All-warehouse and warehouse-specific/manual runs share one lock.
        // Unlike a cache lease, it cannot expire while a backfill is running.
        $lockKey = 'wms:sales-summaries:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 40);
        $pdo = $connection->getPdo();
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lockKey]);
        if ((int) $statement->fetchColumn() !== 1) {
            $this->error('別の出荷実績集計が実行中のため、開始できませんでした。');

            return self::FAILURE;
        }

        $this->lockedPdo = $pdo;
        try {
            return $this->executeSync();
        } finally {
            try {
                // Release on the original connection, even after a reconnect.
                $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$lockKey]);
            } catch (Throwable) {
                $this->warn('集計ロックの解放を確認できませんでした。接続終了時に解放されます。');
            }
            $this->lockedPdo = null;
        }
    }

    private function executeSync(): int
    {
        $warehouseId = $this->option('warehouse-id') ? (int) $this->option('warehouse-id') : null;
        $dryRun = (bool) $this->option('dry-run');
        $summaryOnly = (bool) $this->option('summary-only');
        $purgeBeforeSync = (bool) $this->option('purge-before-sync');
        $reconcileHistory = (bool) $this->option('reconcile-history');
        $reconcileOrderWindow = (bool) $this->option('reconcile-order-window');
        $reconcile = $reconcileHistory || $reconcileOrderWindow;

        if ($reconcileHistory && $reconcileOrderWindow) {
            $this->error('--reconcile-history と --reconcile-order-window は同時に指定できません。');

            return self::FAILURE;
        }

        if ($reconcile && ($this->option('from') || $summaryOnly || $purgeBeforeSync)) {
            $option = $reconcileHistory ? '--reconcile-history' : '--reconcile-order-window';
            $this->error("{$option} は --from、--summary-only、--purge-before-sync と同時に指定できません。");

            return self::FAILURE;
        }

        [$from, $to] = $this->resolveDateRange();
        if ($reconcile && $from->greaterThan($to)) {
            $this->info('照合の対象期間がありません。');

            return self::SUCCESS;
        }
        if ($from->greaterThan($to)) {
            $this->error('--from は --to 以前の日付を指定してください。');

            return self::FAILURE;
        }

        if ($summaryOnly && $purgeBeforeSync) {
            $this->error('--summary-only と --purge-before-sync は同時に指定できません。');

            return self::FAILURE;
        }

        $this->info('倉庫別商品別 出荷実績集計を開始します...');
        $this->line("対象日: {$from->toDateString()} - {$to->toDateString()}");
        if ($warehouseId) {
            $this->line("対象倉庫: {$warehouseId}");
        }

        $dailyCount = 0;
        if (! $summaryOnly) {
            if ($purgeBeforeSync) {
                $purgedCount = $this->purgeStaleDailySales($from, $to, $warehouseId, $dryRun);
                $this->info("stale日次実績削除候補: {$purgedCount} 件");
            }

            if ($reconcile) {
                $label = $reconcileHistory ? '履歴照合' : '発注実績照合';
                for ($scopeFrom = $from; $scopeFrom->lessThanOrEqualTo($to); $scopeFrom = $scopeTo->addDay()) {
                    $scopeTo = $scopeFrom->addDays(self::HISTORY_CHUNK_DAYS - 1)->min($to);
                    $dailyCount += $this->syncDailySales($scopeFrom, $scopeTo, $warehouseId, $dryRun);
                    $this->line("{$label}: {$scopeFrom->toDateString()} - {$scopeTo->toDateString()}");
                }
            } else {
                $dailyCount = $this->syncDailySales($from, $to, $warehouseId, $dryRun);
            }
            $this->info("日次実績: {$dailyCount} 件（倉庫×商品×日）");
        }

        $coverage = $this->getWarehouseCoverage($warehouseId);
        $summaryResult = app(SalesSummarySynchronizer::class)->sync($to, $warehouseId, $coverage, $dryRun, $this->lockedPdo);
        $summaryCounts = $summaryResult['summary_counts'];
        $dailyBreakdownCount = $summaryResult['daily_breakdown_count'];

        foreach ($summaryCounts as $days => $count) {
            $this->info("{$days}日サマリ: {$count} 件");
        }
        $this->info("日別内訳: {$dailyBreakdownCount} 件");

        if ($dryRun) {
            $this->info('[DRY RUN] 書き込みはスキップしました。');
        } else {
            $this->info('完了しました。');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveDateRange(): array
    {
        $to = CarbonImmutable::parse($this->option('to') ?: ClientSetting::systemDateYMD())->startOfDay();

        if ($this->option('reconcile-history')) {
            return [CarbonImmutable::parse(self::LIVE_DATA_START_DATE)->startOfDay(), $to];
        }

        if ($this->option('reconcile-order-window')) {
            // The order screen's previous-month column uses a calendar month.
            // Keep 30 days too: on March 1 a 28-day February is not sufficient.
            $from = $to->subDays(29)->min($to->subMonthNoOverflow()->startOfMonth());

            return [$from->max(CarbonImmutable::parse(self::LIVE_DATA_START_DATE)->startOfDay()), $to];
        }

        if ($this->option('from')) {
            return [CarbonImmutable::parse($this->option('from'))->startOfDay(), $to];
        }

        $days = max(1, (int) $this->option('days'));

        return [$to->subDays($days - 1), $to];
    }

    private function syncDailySales(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $warehouseId,
        bool $dryRun
    ): int {
        $now = now();
        $results = $this->dailySalesQuery($from, $to, $warehouseId)->get();
        $key = static fn (object $row): string => "{$row->business_date}:{$row->warehouse_id}:{$row->item_id}";
        $existing = DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->useWritePdo()
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->get(['business_date', 'warehouse_id', 'item_id', ...self::DAILY_QUANTITY_COLUMNS, 'created_at'])
            ->keyBy($key);

        $rows = [];
        foreach ($results as $result) {
            $current = $existing->pull($key($result));
            $row = [
                'business_date' => $result->business_date,
                'warehouse_id' => (int) $result->warehouse_id,
                'item_id' => (int) $result->item_id,
                'created_at' => $current?->created_at ?? $now,
                'updated_at' => $now,
            ];
            foreach (self::DAILY_QUANTITY_COLUMNS as $column) {
                $row[$column] = (int) $result->{$column};
            }
            if ($this->dailyQuantitiesChanged($row, $current)) {
                $rows[] = $row;
            }
        }
        $changedSourceCount = count($rows);
        $zeroedCount = 0;

        // A removed source group must not leave its previous quantity behind.
        // Retain zero rows so the warehouse's historical coverage stays intact.
        foreach ($existing as $current) {
            if ($current->business_date < self::LIVE_DATA_START_DATE) {
                continue;
            }
            $row = [
                'business_date' => $current->business_date,
                'warehouse_id' => (int) $current->warehouse_id,
                'item_id' => (int) $current->item_id,
                ...array_fill_keys(self::DAILY_QUANTITY_COLUMNS, 0),
                'created_at' => $current->created_at,
                'updated_at' => $now,
            ];
            if ($this->dailyQuantitiesChanged($row, $current)) {
                $rows[] = $row;
                $zeroedCount++;
            }
        }

        if (! $dryRun && $rows !== []) {
            usort($rows, static fn (array $a, array $b): int => [$a['business_date'], $a['warehouse_id'], $a['item_id']] <=> [$b['business_date'], $b['warehouse_id'], $b['item_id']]);

            // Publish updated groups and disappeared groups atomically for this scope.
            $this->writeTransaction(function () use ($rows): void {
                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::connection('sakemaru')->table('stats_item_warehouse_daily_sales')->upsert(
                        $chunk,
                        ['business_date', 'warehouse_id', 'item_id'],
                        [...self::DAILY_QUANTITY_COLUMNS, 'updated_at']
                    );
                }
            });
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->line("{$prefix}日次照合: {$from->toDateString()} - {$to->toDateString()}（追加・変更: {$changedSourceCount} 件、消失ゼロ補正: {$zeroedCount} 件）");

        return $results->count();
    }

    private function dailyQuantitiesChanged(array $row, ?object $current): bool
    {
        if ($current === null) {
            return true;
        }

        foreach (self::DAILY_QUANTITY_COLUMNS as $column) {
            if ((int) $current->{$column} !== $row[$column]) {
                return true;
            }
        }

        return false;
    }

    private function purgeStaleDailySales(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $warehouseId,
        bool $dryRun
    ): int {
        $liveStart = CarbonImmutable::parse(self::LIVE_DATA_START_DATE)->startOfDay();
        $purgeFrom = $from->lessThan($liveStart) ? $liveStart : $from;

        if ($purgeFrom->greaterThan($to)) {
            return 0;
        }

        $source = $this->dailySalesQuery($purgeFrom, $to, $warehouseId);
        $query = DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales as d')
            ->useWritePdo()
            ->leftJoinSub($source, 'src', function ($join): void {
                $join
                    ->on('src.business_date', '=', 'd.business_date')
                    ->on('src.warehouse_id', '=', 'd.warehouse_id')
                    ->on('src.item_id', '=', 'd.item_id');
            })
            ->whereBetween('d.business_date', [$purgeFrom->toDateString(), $to->toDateString()])
            ->whereNull('src.item_id');

        if ($warehouseId) {
            $query->where('d.warehouse_id', $warehouseId);
        }

        $count = (clone $query)->count();
        if ($dryRun || $count === 0) {
            return $count;
        }

        $this->writeTransaction(fn () => $query->delete());

        return $count;
    }

    private function dailySalesQuery(CarbonImmutable $from, CarbonImmutable $to, ?int $warehouseId)
    {
        $earningQuery = DB::connection('sakemaru')
            ->table('earnings as e')
            ->join('trade_items as ti', 'e.trade_id', '=', 'ti.trade_id')
            ->whereBetween('e.delivered_date', [$from->toDateString(), $to->toDateString()])
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('ti.item_id')
            ->selectRaw('
                e.delivered_date as business_date,
                e.warehouse_id,
                ti.item_id,
                SUM(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0)) as shipped_piece_qty,
                SUM(GREATEST(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0), 0)) as sales_piece_qty,
                0 as transfer_piece_qty,
                SUM(GREATEST(-COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0), 0)) as return_piece_qty,
                SUM(CASE WHEN ti.quantity_type = "CASE" THEN ti.quantity ELSE 0 END) as shipped_case_qty,
                SUM(CASE WHEN ti.quantity_type = "PIECE" THEN ti.quantity ELSE 0 END) as shipped_bottle_qty
            ')
            ->groupBy('e.delivered_date', 'e.warehouse_id', 'ti.item_id');

        $retailQuery = DB::connection('sakemaru')
            ->table('retails as r')
            ->join('trades as t', 'r.trade_id', '=', 't.id')
            ->join('trade_items as ti', 'r.trade_id', '=', 'ti.trade_id')
            ->whereBetween('r.shipped_date', [$from->toDateString(), $to->toDateString()])
            ->where('t.trade_category', 'RETAIL')
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('ti.item_id')
            ->selectRaw('
                r.shipped_date as business_date,
                r.warehouse_id,
                ti.item_id,
                SUM(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0)) as shipped_piece_qty,
                SUM(GREATEST(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0), 0)) as sales_piece_qty,
                0 as transfer_piece_qty,
                SUM(GREATEST(-COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0), 0)) as return_piece_qty,
                SUM(CASE WHEN ti.quantity_type = "CASE" THEN ti.quantity ELSE 0 END) as shipped_case_qty,
                SUM(CASE WHEN ti.quantity_type = "PIECE" THEN ti.quantity ELSE 0 END) as shipped_bottle_qty
            ')
            ->groupBy('r.shipped_date', 'r.warehouse_id', 'ti.item_id');

        $stockTransferQuery = DB::connection('sakemaru')
            ->table('stock_transfers as st')
            ->join('trades as t', 'st.trade_id', '=', 't.id')
            ->join('trade_items as ti', 'st.trade_id', '=', 'ti.trade_id')
            ->whereRaw('COALESCE(st.picking_date, st.delivered_date) BETWEEN ? AND ?', [
                $from->toDateString(),
                $to->toDateString(),
            ])
            ->where('st.is_active', true)
            ->where('t.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('ti.item_id')
            ->selectRaw('
                COALESCE(st.picking_date, st.delivered_date) as business_date,
                st.from_warehouse_id as warehouse_id,
                ti.item_id,
                SUM(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0)) as shipped_piece_qty,
                0 as sales_piece_qty,
                SUM(GREATEST(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0), 0)) as transfer_piece_qty,
                SUM(GREATEST(-COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0), 0)) as return_piece_qty,
                SUM(CASE WHEN ti.quantity_type = "CASE" THEN ti.quantity ELSE 0 END) as shipped_case_qty,
                SUM(CASE WHEN ti.quantity_type = "PIECE" THEN ti.quantity ELSE 0 END) as shipped_bottle_qty
            ')
            ->groupBy(DB::raw('COALESCE(st.picking_date, st.delivered_date)'), 'st.from_warehouse_id', 'ti.item_id');

        if ($warehouseId) {
            $earningQuery->where('e.warehouse_id', $warehouseId);
            $retailQuery->where('r.warehouse_id', $warehouseId);
            $stockTransferQuery->where('st.from_warehouse_id', $warehouseId);
        }

        return DB::connection('sakemaru')
            ->query()
            ->useWritePdo()
            ->fromSub($earningQuery->unionAll($retailQuery)->unionAll($stockTransferQuery), 'sales')
            ->selectRaw('
                business_date,
                warehouse_id,
                item_id,
                SUM(shipped_piece_qty) as shipped_piece_qty,
                SUM(sales_piece_qty) as sales_piece_qty,
                SUM(transfer_piece_qty) as transfer_piece_qty,
                SUM(return_piece_qty) as return_piece_qty,
                SUM(shipped_case_qty) as shipped_case_qty,
                SUM(shipped_bottle_qty) as shipped_bottle_qty
            ')
            ->groupBy('business_date', 'warehouse_id', 'item_id')
            ->orderBy('business_date')
            ->orderBy('warehouse_id')
            ->orderBy('item_id');
    }

    private function getWarehouseCoverage(?int $warehouseId): Collection
    {
        $query = DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->useWritePdo()
            ->selectRaw('warehouse_id, MIN(business_date) as first_business_date')
            ->groupBy('warehouse_id');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query
            ->pluck('first_business_date', 'warehouse_id')
            ->map(fn ($date) => CarbonImmutable::parse($date)->startOfDay());
    }

    private function writeTransaction(callable $callback): mixed
    {
        $connection = DB::connection('sakemaru');

        return $connection->transaction(function () use ($connection, $callback) {
            if ($this->lockedPdo !== null && $connection->getPdo() !== $this->lockedPdo) {
                throw new RuntimeException('集計中にDB接続が再確立されたため、保存を中止しました。再実行してください。');
            }

            return $callback();
        });
    }
}
