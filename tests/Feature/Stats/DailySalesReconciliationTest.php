<?php

namespace Tests\Feature\Stats;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Exercises the real source UNION and command using disposable memory tables. */
class DailySalesReconciliationTest extends TestCase
{
    private const DAILY = 'stats_item_warehouse_daily_sales';

    private const SUMMARY = 'stats_item_warehouse_sales_summaries';

    private const QUANTITIES = [
        'shipped_piece_qty', 'sales_piece_qty', 'transfer_piece_qty',
        'return_piece_qty', 'shipped_case_qty', 'shipped_bottle_qty',
    ];

    private int $nextTradeId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_keys(config('database.connections')) as $name) {
            DB::purge($name);
            config(["database.connections.{$name}" => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        }
        config(['database.default' => 'sakemaru']);
        $connection = DB::connection('sakemaru');
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame('', $connection->selectOne('PRAGMA database_list')->file);
        $connection->getPdo()->sqliteCreateFunction('greatest',
            fn (...$values) => in_array(null, $values, true) ? null : max($values));

        $this->createMemoryTables();
        Carbon::setTestNow('2026-10-05 08:00:00');
        CarbonImmutable::setTestNow('2026-10-05 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        if (isset($this->app)) {
            DB::purge('sakemaru');
        }
        parent::tearDown();
    }

    public static function cancellationCases(): iterable
    {
        foreach (['earning', 'retail', 'transfer'] as $kind) {
            foreach (['header', 'item'] as $level) {
                yield "{$kind} {$level}" => [$kind, $level];
            }
        }
        yield 'transfer trade' => ['transfer', 'trade'];
    }

    #[DataProvider('cancellationCases')]
    public function test_cancelled_old_day_is_zeroed_when_replacement_is_registered_on_another_day(string $kind, string $level): void
    {
        $this->seedDaily('2026-09-01', 1, 999);
        $oldId = $this->source($kind);
        $this->runDaily();
        $before = $this->daily('2026-09-30');
        $this->assertSame(48, $before['shipped_piece_qty']);

        $this->cancel($kind, $oldId, $level);
        $this->source($kind, ['date' => '2026-10-01']);
        $this->runDaily(); // Normal command, without the legacy purge option.

        $this->assertQuantities($this->daily('2026-09-30'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame($before['created_at'], $this->daily('2026-09-30')['created_at']);
        $this->assertQuantities($this->daily('2026-10-01'), $kind === 'transfer'
            ? [48, 0, 48, 0, 0, 48] : [48, 48, 0, 0, 0, 48]);
        $this->assertSame(48, $this->summary()['last_3d_qty']);
        $this->assertSame(48, $this->summary()['last_30d_qty']);
        $this->assertSame('2026-10-01', $this->summary()['last_shipped_at']);
    }

    public function test_all_source_rows_disappearing_preserves_warehouse_coverage_and_clears_last_shipment(): void
    {
        $id = $this->source('earning', ['date' => '2026-09-02']);
        $this->runDaily(['--from' => '2026-09-02']);
        $this->assertSame(48, $this->summary()['last_30d_qty']);
        $this->assertSame('2026-09-02', $this->summary()['last_shipped_at']);

        $this->cancel('earning', $id, 'header');
        $this->runDaily(['--from' => '2026-09-02']);
        $this->assertCount(1, $this->snapshot(self::DAILY));
        $this->assertQuantities($this->daily('2026-09-02'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame('2026-09-02', DB::table(self::DAILY)->min('business_date'));
        foreach ([3, 5, 7, 14, 30] as $days) {
            $this->assertSame(0, $this->summary()["last_{$days}d_qty"]);
        }
        $this->assertNull($this->summary()['last_shipped_at']);
    }

    public function test_another_source_in_the_same_group_remains_after_cancellation(): void
    {
        $id = $this->source('earning');
        $this->source('retail', ['pieces' => 12, 'quantity' => 12]);
        $this->source('transfer', ['pieces' => 6, 'quantity' => 6]);
        $this->runDaily();
        $this->assertQuantities($this->daily('2026-09-30'), [66, 60, 6, 0, 0, 66]);

        $this->cancel('earning', $id, 'item');
        $this->runDaily();
        $this->assertQuantities($this->daily('2026-09-30'), [18, 12, 6, 0, 0, 18]);
    }

    public function test_zero_null_and_offsetting_quantities_remain_present_with_correct_breakdowns(): void
    {
        $this->seedDaily('2026-09-01', 1, 999);
        $this->source('earning', ['pieces' => 48, 'quantity' => 2, 'quantity_type' => 'CASE']);
        $this->source('retail', ['pieces' => 12, 'quantity' => 12]);
        $this->source('transfer', ['pieces' => -60, 'quantity' => -60]);
        $this->source('earning', ['item_id' => 20, 'pieces' => 0, 'quantity' => 0]);
        $this->source('retail', ['item_id' => 30, 'pieces' => null, 'quantity' => 0]);
        $this->source('earning', ['item_id' => null]);
        $this->runDaily();

        $this->assertQuantities($this->daily('2026-09-30'), [0, 60, 0, 60, 2, -48]);
        $this->assertQuantities($this->daily('2026-09-30', 1, 20), [0, 0, 0, 0, 0, 0]);
        $this->assertQuantities($this->daily('2026-09-30', 1, 30), [0, 0, 0, 0, 0, 0]);
        $this->assertSame(3, DB::table(self::DAILY)->where('business_date', '2026-09-30')->count());
        $this->assertNull($this->summary()['last_shipped_at']);
    }

    public function test_returns_preserve_signed_case_and_piece_quantities(): void
    {
        $this->source('earning', ['pieces' => -24, 'quantity' => -1, 'quantity_type' => 'CASE']);
        $this->source('retail', ['pieces' => -3, 'quantity' => -3]);
        $this->source('transfer', ['pieces' => 12, 'quantity' => 12]);
        $this->runDaily();
        $this->assertQuantities($this->daily('2026-09-30'), [-15, 0, 12, 27, -1, 9]);
    }

    public static function movementCases(): iterable
    {
        foreach (['earning', 'retail', 'transfer'] as $kind) {
            foreach (['date', 'warehouse', 'item'] as $axis) {
                yield "{$kind} {$axis}" => [$kind, $axis];
            }
        }
    }

    #[DataProvider('movementCases')]
    public function test_moving_a_source_group_zeros_only_its_old_key(string $kind, string $axis): void
    {
        $id = $this->source($kind);
        $this->runDaily();
        if ($axis === 'item') {
            DB::table('trade_items')->where('trade_id', $id)->update(['item_id' => 20]);
        } else {
            $table = ['earning' => 'earnings', 'retail' => 'retails', 'transfer' => 'stock_transfers'][$kind];
            $column = $axis === 'date'
                ? ['earning' => 'delivered_date', 'retail' => 'shipped_date', 'transfer' => 'delivered_date'][$kind]
                : ($kind === 'transfer' ? 'from_warehouse_id' : 'warehouse_id');
            DB::table($table)->where('trade_id', $id)->update([$column => $axis === 'date' ? '2026-10-01' : 2]);
        }
        $this->runDaily();
        $this->assertQuantities($this->daily('2026-09-30'), [0, 0, 0, 0, 0, 0]);
        $row = $this->daily($axis === 'date' ? '2026-10-01' : '2026-09-30', $axis === 'warehouse' ? 2 : 1, $axis === 'item' ? 20 : 10);
        $this->assertSame(48, $row['shipped_piece_qty']);
        $this->assertSame(48, (int) DB::table(self::DAILY)->sum('shipped_piece_qty'));
        $this->assertCount(2, $this->snapshot(self::DAILY));
    }

    public function test_transfer_picking_date_takes_precedence_and_null_falls_back_to_delivery_date(): void
    {
        $id = $this->source('transfer', ['date' => '2026-10-01', 'picking_date' => '2026-09-29']);
        $this->runDaily(['--from' => '2026-09-29']);
        $this->assertSame(48, $this->daily('2026-09-29')['shipped_piece_qty']);
        DB::table('stock_transfers')->where('trade_id', $id)->update(['picking_date' => null]);
        $this->runDaily(['--from' => '2026-09-29']);
        $this->assertQuantities($this->daily('2026-09-29'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame(48, $this->daily('2026-10-01')['shipped_piece_qty']);
    }

    public function test_warehouse_and_date_scope_leave_unselected_daily_rows_unchanged(): void
    {
        foreach ([['2026-09-29', 1], ['2026-10-02', 1], ['2026-09-30', 2], ['2026-09-30', 1]] as [$date, $warehouseId]) {
            $this->seedDaily($date, $warehouseId, 10, [48, 48, 0, 0, 0, 48]);
        }
        $before = $this->snapshot(self::DAILY);
        $this->runDaily(['--warehouse-id' => 1]);
        foreach ($before as $key => $row) {
            if ($key !== '2026-09-30:1:10') {
                $this->assertSame($row, $this->snapshot(self::DAILY)[$key]);
            }
        }
        $this->assertQuantities($this->daily('2026-09-30'), [0, 0, 0, 0, 0, 0]);
    }

    public function test_dry_run_does_not_change_sources_daily_or_summary_rows(): void
    {
        $this->seedDaily('2026-09-01', 1, 999);
        $id = $this->source('earning');
        $this->runDaily();
        $this->cancel('earning', $id, 'header');
        $this->source('retail', ['date' => '2026-10-01']);
        $before = [self::DAILY => $this->snapshot(self::DAILY), self::SUMMARY => $this->snapshot(self::SUMMARY)];
        $sourceBefore = $this->sourceSnapshot();
        DB::connection()->enableQueryLog();
        $this->runDaily(['--dry-run' => true]);
        $this->assertSame([], $this->writeQueries());
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $this->snapshot($table));
        }
        $this->assertSame($sourceBefore, $this->sourceSnapshot());
    }

    public function test_missing_rows_before_live_start_are_protected_but_active_historical_sources_still_update(): void
    {
        $this->seedDaily('2026-05-05', 1, 10, [48, 48, 0, 0, 0, 48]);
        $this->seedDaily('2026-05-06', 1, 10, [48, 48, 0, 0, 0, 48]);
        $this->seedDaily('2026-05-05', 1, 20, [1, 1, 0, 0, 0, 1]);
        $this->source('earning', ['date' => '2026-05-05', 'item_id' => 20]);
        $protected = $this->daily('2026-05-05');
        $this->runDaily(['--from' => '2026-05-05', '--to' => '2026-05-06']);
        $this->assertSame($protected, $this->daily('2026-05-05'));
        $this->assertQuantities($this->daily('2026-05-06'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame(48, $this->daily('2026-05-05', 1, 20)['shipped_piece_qty']);
    }

    public function test_reexecution_skips_unchanged_daily_writes_and_preserves_daily_timestamps(): void
    {
        $this->source('earning');
        $this->seedDaily('2026-09-30', 1, 20);
        $this->runDaily();
        $before = $this->snapshot(self::DAILY);
        Carbon::setTestNow('2026-10-05 09:00:00');
        CarbonImmutable::setTestNow('2026-10-05 09:00:00');
        DB::connection()->enableQueryLog();
        $this->runDaily();
        $this->assertSame([], $this->writeQueries(self::DAILY));
        $this->assertSame($before, $this->snapshot(self::DAILY));
        $this->assertStringContainsString('日次実績: 1 件', Artisan::output());
    }

    public function test_second_chunk_failure_rolls_back_source_upserts_and_missing_zero_rows_in_the_scope(): void
    {
        foreach (range(1, 1004) as $itemId) {
            $this->source('earning', ['item_id' => $itemId, 'pieces' => 6, 'quantity' => 6]);
        }
        $this->seedDaily('2026-09-30', 1, 1, [1, 1, 0, 0, 0, 1]);
        $this->seedDaily('2026-09-30', 1, 1005, [48, 48, 0, 0, 0, 48]);
        $before = $this->snapshot(self::DAILY);
        DB::statement('CREATE TEMP TRIGGER fail_missing_zero BEFORE INSERT ON '.self::DAILY.
            " WHEN NEW.item_id = 1005 AND NEW.shipped_piece_qty = 0 BEGIN SELECT RAISE(ABORT, 'forced daily scope failure'); END");
        DB::connection()->enableQueryLog();
        try {
            $this->runDaily();
            $this->fail('The second chunk must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced daily scope failure', $exception->getMessage());
        }
        $this->assertCount(1, $this->writeQueries(self::DAILY), 'A first chunk must have succeeded before the injected failure.');
        $this->assertSame($before, $this->snapshot(self::DAILY));
        $this->assertSame([], $this->snapshot(self::SUMMARY));
        $this->assertSame([], $this->writeQueries(self::SUMMARY));
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_summary_failure_can_be_retried_without_rewriting_completed_daily_values(): void
    {
        $this->seedDaily('2026-09-01', 1, 999);
        $this->source('earning');
        // A test-local switch allows recovery without dropping any table/trigger.
        Schema::create('test_failure_switch', fn (Blueprint $table) => $table->boolean('enabled'));
        DB::table('test_failure_switch')->insert(['enabled' => true]);
        DB::statement('CREATE TEMP TRIGGER fail_summary BEFORE INSERT ON '.self::SUMMARY.
            " WHEN (SELECT enabled FROM test_failure_switch) = 1 BEGIN SELECT RAISE(ABORT, 'forced summary failure'); END");
        try {
            $this->runDaily();
            $this->fail('The summary publication must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced summary failure', $exception->getMessage());
        }
        $dailyBeforeRetry = $this->snapshot(self::DAILY);
        $this->assertSame(48, $this->daily('2026-09-30')['shipped_piece_qty']);
        $this->assertSame([], $this->snapshot(self::SUMMARY));
        DB::table('test_failure_switch')->update(['enabled' => false]);
        DB::connection()->enableQueryLog();
        $this->runDaily();
        $this->assertSame([], $this->writeQueries(self::DAILY));
        $this->assertSame($dailyBeforeRetry, $this->snapshot(self::DAILY));
        $this->assertSame(48, $this->summary()['last_30d_qty']);
    }

    public function test_history_reconciliation_covers_both_sides_of_week_boundaries_and_publishes_summary_once(): void
    {
        foreach (['2026-05-05', '2026-05-12', '2026-05-19', '2026-05-20'] as $date) {
            $this->seedDaily($date, 1, 10, [48, 48, 0, 0, 0, 48]);
        }
        $this->source('earning', ['date' => '2026-05-06', 'pieces' => 6, 'quantity' => 6]);
        $this->source('retail', ['date' => '2026-05-13', 'pieces' => 13, 'quantity' => 13]);
        $before = $this->snapshot(self::DAILY);
        DB::connection()->enableQueryLog();
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', ['--reconcile-history' => true, '--to' => '2026-05-19', '--days' => 1]));
        $queries = DB::connection()->getQueryLog();
        $this->assertSame($before['2026-05-05:1:10'], $this->daily('2026-05-05'));
        $this->assertSame($before['2026-05-20:1:10'], $this->daily('2026-05-20'));
        $this->assertQuantities($this->daily('2026-05-12'), [0, 0, 0, 0, 0, 0]);
        $this->assertQuantities($this->daily('2026-05-19'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame(6, $this->daily('2026-05-06')['shipped_piece_qty']);
        $this->assertSame(13, $this->daily('2026-05-13')['shipped_piece_qty']);
        $sourceReads = array_values(array_filter($queries, fn ($q) => str_starts_with(strtolower($q['query']), 'select') && str_contains($q['query'], 'earnings')));
        $this->assertCount(2, $sourceReads);
        foreach (['2026-05-06', '2026-05-12'] as $date) {
            $this->assertContains($date, $sourceReads[0]['bindings']);
        }
        foreach (['2026-05-13', '2026-05-19'] as $date) {
            $this->assertContains($date, $sourceReads[1]['bindings']);
        }
        $this->assertCount(1, $this->writeQueries(self::SUMMARY));
        $this->assertSame(19, $this->summary()['last_14d_qty']);
    }

    public function test_later_history_scope_failure_preserves_the_failed_scope_and_can_resume_after_completed_scopes(): void
    {
        $this->seedDaily('2026-05-06', 1, 10, [48, 48, 0, 0, 0, 48]);
        $this->seedDaily('2026-05-13', 1, 10, [48, 48, 0, 0, 0, 48]);
        $this->source('earning', ['date' => '2026-05-06', 'pieces' => 6, 'quantity' => 6]);
        $this->source('earning', ['date' => '2026-05-13', 'item_id' => 20, 'pieces' => 20, 'quantity' => 20]);
        $laterBefore = $this->daily('2026-05-13');
        $failLaterScope = true;
        DB::connection()->getPdo()->sqliteCreateFunction('test_fail_later_scope',
            function ($date, $itemId) use (&$failLaterScope): int {
                return (int) ($failLaterScope && $date === '2026-05-13' && $itemId === 20);
            });
        DB::statement('CREATE TEMP TRIGGER fail_later_history_scope BEFORE INSERT ON '.self::DAILY.
            " WHEN test_fail_later_scope(NEW.business_date, NEW.item_id) = 1 BEGIN SELECT RAISE(ABORT, 'forced later history failure'); END");
        $options = ['--reconcile-history' => true, '--to' => '2026-05-19'];
        try {
            Artisan::call('wms:sync-sales-summaries', $options);
            $this->fail('The second history scope must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced later history failure', $exception->getMessage());
        }
        $this->assertSame(6, $this->daily('2026-05-06')['shipped_piece_qty']);
        $completedBeforeRetry = $this->daily('2026-05-06');
        $this->assertSame($laterBefore, $this->daily('2026-05-13'));
        $this->assertCount(2, $this->snapshot(self::DAILY));
        $this->assertSame([], $this->snapshot(self::SUMMARY));
        $this->assertSame(0, DB::connection()->transactionLevel());

        $failLaterScope = false;
        Carbon::setTestNow('2026-10-05 09:00:00');
        CarbonImmutable::setTestNow('2026-10-05 09:00:00');
        DB::connection()->enableQueryLog();
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options));
        $dailyWrites = $this->writeQueries(self::DAILY);
        $this->assertCount(1, $dailyWrites, 'Only the previously failed scope needs a daily write.');
        $this->assertNotContains('2026-05-06', $dailyWrites[0]['bindings']);
        $this->assertSame($completedBeforeRetry, $this->daily('2026-05-06'));
        $this->assertQuantities($this->daily('2026-05-13'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame(20, $this->daily('2026-05-13', 1, 20)['shipped_piece_qty']);
        $this->assertNotSame([], $this->snapshot(self::SUMMARY));
        $this->assertCount(1, $this->writeQueries(self::SUMMARY));
    }

    public function test_history_dry_run_and_warehouse_filter_preserve_unselected_data(): void
    {
        $this->seedDaily('2026-05-06', 1, 10, [48, 48, 0, 0, 0, 48]);
        $this->seedDaily('2026-05-06', 2, 10, [24, 24, 0, 0, 0, 24]);
        $before = $this->snapshot(self::DAILY);
        DB::connection()->enableQueryLog();
        $options = ['--reconcile-history' => true, '--to' => '2026-05-08', '--warehouse-id' => 1];
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options + ['--dry-run' => true]));
        $this->assertSame([], $this->writeQueries());
        $this->assertSame($before, $this->snapshot(self::DAILY));
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options));
        $this->assertQuantities($this->daily('2026-05-06'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame($before['2026-05-06:2:10'], $this->daily('2026-05-06', 2));
    }

    public function test_history_ending_before_live_start_is_a_no_op(): void
    {
        $this->seedDaily('2026-05-05', 1, 10, [48, 48, 0, 0, 0, 48]);
        $before = $this->snapshot(self::DAILY);
        DB::connection()->enableQueryLog();
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', ['--reconcile-history' => true, '--to' => '2026-05-05']));
        $this->assertSame([], DB::connection()->getQueryLog());
        $this->assertSame($before, $this->snapshot(self::DAILY));
    }

    public static function invalidHistoryOptions(): iterable
    {
        yield 'explicit from' => [['--from' => '2026-05-07']];
        yield 'summary only' => [['--summary-only' => true]];
        yield 'legacy purge' => [['--purge-before-sync' => true]];
    }

    #[DataProvider('invalidHistoryOptions')]
    public function test_history_rejects_incompatible_options_before_writing(array $options): void
    {
        DB::connection()->enableQueryLog();
        $this->assertSame(1, Artisan::call('wms:sync-sales-summaries', $options + ['--reconcile-history' => true, '--to' => '2026-10-01']));
        $this->assertSame([], $this->writeQueries());
    }

    public static function orderWindowCases(): iterable
    {
        yield 'October start includes all September' => ['2026-10-05', '2026-09-01', 5, true];
        yield 'October end covers 61 days' => ['2026-10-31', '2026-09-01', 9, true];
        yield 'August end covers 62 days' => ['2026-08-31', '2026-07-01', 9, true];
        yield 'non-leap March needs January 31 for 30 days' => ['2027-03-01', '2027-01-31', 5, true];
        yield 'leap March starts February 1' => ['2028-03-01', '2028-02-01', 5, true];
        yield 'January crosses the calendar year' => ['2027-01-01', '2026-12-01', 5, true];
        yield 'live start clamps older dates' => ['2026-05-06', '2026-05-06', 1, false];
        yield 'before live start has no scope' => ['2026-05-05', null, 0, false];
    }

    #[DataProvider('orderWindowCases')]
    public function test_order_window_reconciles_only_the_required_calendar_and_thirty_day_range(
        string $to,
        ?string $from,
        int $scopeCount,
        bool $summaryMatured,
    ): void {
        $options = ['--reconcile-order-window' => true, '--to' => $to, '--days' => 1];
        if ($from === null) {
            $this->seedDaily($to, 1, 10, [48, 48, 0, 0, 0, 48]);
            $before = $this->snapshot(self::DAILY);
            DB::connection()->enableQueryLog();
            $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options));
            $this->assertSame([], DB::connection()->getQueryLog());
            $this->assertSame($before, $this->snapshot(self::DAILY));

            return;
        }

        $priorDate = CarbonImmutable::parse($from)->subDay()->toDateString();
        $nextDate = CarbonImmutable::parse($to)->addDay()->toDateString();
        $this->seedDaily($priorDate, 1, 10, [48, 48, 0, 0, 0, 48]);
        $this->seedDaily($nextDate, 1, 10, [60, 60, 0, 0, 0, 60]);
        $this->seedDaily($from, 1, 20, [24, 24, 0, 0, 0, 24]);
        $this->source('earning', ['date' => $from, 'item_id' => 30, 'pieces' => 7, 'quantity' => 7]);
        $this->source('retail', ['date' => $to, 'pieces' => 5, 'quantity' => 5]);
        $before = $this->snapshot(self::DAILY);
        DB::connection()->enableQueryLog();
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options));
        $queries = DB::connection()->getQueryLog();
        $this->assertSame($before["{$priorDate}:1:10"], $this->daily($priorDate));
        $this->assertSame($before["{$nextDate}:1:10"], $this->daily($nextDate));
        $this->assertQuantities($this->daily($from, 1, 20), [0, 0, 0, 0, 0, 0]);
        $this->assertSame($before["{$from}:1:20"]['created_at'], $this->daily($from, 1, 20)['created_at']);
        $this->assertSame(7, $this->daily($from, 1, 30)['shipped_piece_qty']);
        $this->assertSame(5, $this->daily($to)['shipped_piece_qty']);

        $sourceReads = array_values(array_filter($queries, fn ($q) =>
            str_starts_with(strtolower($q['query']), 'select') && str_contains($q['query'], 'earnings')));
        $this->assertCount($scopeCount, $sourceReads, '--days=1 must not shorten the reconciliation window.');
        $nextScopeFrom = $from;
        foreach ($sourceReads as $query) {
            [$scopeFrom, $scopeTo] = array_slice($query['bindings'], 0, 2);
            $this->assertSame($nextScopeFrom, $scopeFrom, 'Scopes must be contiguous and start at the expected boundary.');
            $span = (int) CarbonImmutable::parse($scopeFrom)->diffInDays(CarbonImmutable::parse($scopeTo)) + 1;
            $this->assertGreaterThanOrEqual(1, $span);
            $this->assertLessThanOrEqual(7, $span);
            $nextScopeFrom = CarbonImmutable::parse($scopeTo)->addDay()->toDateString();
        }
        $this->assertSame($nextDate, $nextScopeFrom, 'The final scope must include --to and stop there.');
        if ($summaryMatured) {
            $this->assertSame(5, $this->summary()['last_30d_qty']);
            $this->assertSame(5, $this->summary()['sales_today_qty']);
            $this->assertSame($to, $this->summary()['last_shipped_at']);
            $this->assertCount(1, $this->writeQueries(self::SUMMARY), 'Publish once after all scopes complete.');
        } else {
            $this->assertSame([], $this->snapshot(self::SUMMARY));
            $this->assertSame([], $this->writeQueries(self::SUMMARY));
        }
    }

    public function test_order_window_dry_run_and_warehouse_filter_preserve_unselected_data(): void
    {
        $this->seedDaily('2026-09-01', 1, 10, [48, 48, 0, 0, 0, 48]);
        $this->seedDaily('2026-09-01', 2, 10, [24, 24, 0, 0, 0, 24]);
        $this->source('earning', ['date' => '2026-10-05', 'pieces' => 5, 'quantity' => 5]);
        $this->source('earning', ['date' => '2026-10-05', 'warehouse_id' => 2]);
        $before = $this->snapshot(self::DAILY);
        $options = ['--reconcile-order-window' => true, '--to' => '2026-10-05', '--warehouse-id' => 1];
        DB::connection()->enableQueryLog();
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options + ['--dry-run' => true]));
        $this->assertSame([], $this->writeQueries());
        $this->assertSame($before, $this->snapshot(self::DAILY));
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options));
        $this->assertQuantities($this->daily('2026-09-01'), [0, 0, 0, 0, 0, 0]);
        $this->assertSame(5, $this->daily('2026-10-05')['shipped_piece_qty']);
        $this->assertSame($before['2026-09-01:2:10'], $this->daily('2026-09-01', 2));
        $this->assertSame(1, DB::table(self::DAILY)->where('warehouse_id', 2)->count());
    }

    public static function invalidOrderWindowOptions(): iterable
    {
        yield 'explicit from' => [['--from' => '2026-09-01']];
        yield 'summary only' => [['--summary-only' => true]];
        yield 'legacy purge' => [['--purge-before-sync' => true]];
        yield 'full history' => [['--reconcile-history' => true]];
    }

    #[DataProvider('invalidOrderWindowOptions')]
    public function test_order_window_rejects_incompatible_options_before_writing(array $options): void
    {
        DB::connection()->enableQueryLog();
        $this->assertSame(1, Artisan::call('wms:sync-sales-summaries',
            $options + ['--reconcile-order-window' => true, '--to' => '2026-10-05']));
        $this->assertSame([], $this->writeQueries());
    }

    public function test_real_schedule_runs_normal_sync_from_eight_to_twenty_two_thirty_and_order_window_at_twenty_three(): void
    {
        // These are the actual events loaded from routes/console.php. Evaluate
        // their cron expressions and filters without running any command.
        $schedule = $this->app->make(Schedule::class)->useCache('array');
        $events = collect($schedule->events())->filter(fn ($event) =>
            str_contains((string) $event->command, 'wms:sync-sales-summaries'));
        $this->assertCount(2, $events);
        $this->assertCount(1, $events->filter(fn ($event) => str_contains($event->command, '--reconcile-order-window')));
        $this->assertCount(0, $events->filter(fn ($event) => str_contains($event->command, '--reconcile-history')));
        $this->assertCount(1, $events->filter(fn ($event) => str_contains($event->command, '--days=4')));
        DB::connection()->enableQueryLog();

        $cases = [];
        for ($minutes = 0; $minutes < 24 * 60; $minutes += 30) {
            $time = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            $cases[$time] = $minutes === 23 * 60
                ? ['order_window']
                : ($minutes >= 8 * 60 && $minutes <= 22 * 60 + 30 ? ['normal'] : []);
        }
        foreach (['07:59', '08:01', '08:29', '08:31', '22:29', '22:31', '22:59', '23:01', '23:29', '23:31', '23:59'] as $time) {
            $cases[$time] = [];
        }
        foreach ($cases as $time => $expected) {
            Carbon::setTestNow(Carbon::parse("2026-10-05 {$time}:00", 'Asia/Tokyo'));
            CarbonImmutable::setTestNow(CarbonImmutable::parse("2026-10-05 {$time}:00", 'Asia/Tokyo'));
            $due = $events->filter(fn ($event) => $event->isDue($this->app) && $event->filtersPass($this->app))
                ->map(fn ($event) => str_contains($event->command, '--reconcile-order-window') ? 'order_window' : 'normal')
                ->values()->all();
            $this->assertSame($expected, $due, "Unexpected daily-sales schedule at {$time} JST");
        }
        $this->assertSame([], DB::connection()->getQueryLog(), 'Schedule inspection must not execute database work.');
    }

    private function runDaily(array $options = []): void
    {
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options + ['--from' => '2026-09-30', '--to' => '2026-10-01']));
    }

    private function source(string $kind, array $overrides = []): int
    {
        $values = array_replace(['date' => '2026-09-30', 'warehouse_id' => 1, 'item_id' => 10,
            'pieces' => 48, 'quantity' => 48, 'quantity_type' => 'PIECE', 'picking_date' => null], $overrides);
        $id = $this->nextTradeId++;
        DB::table('trades')->insert(['id' => $id, 'trade_category' => $kind === 'transfer' ? 'STOCK_TRANSFER' : strtoupper($kind), 'is_active' => true]);
        DB::table('trade_items')->insert(['id' => $id, 'trade_id' => $id, 'item_id' => $values['item_id'], 'is_active' => true,
            'total_piece_quantity' => $values['pieces'], 'quantity' => $values['quantity'], 'quantity_type' => $values['quantity_type']]);
        if ($kind === 'earning') {
            DB::table('earnings')->insert(['trade_id' => $id, 'delivered_date' => $values['date'], 'warehouse_id' => $values['warehouse_id'], 'is_active' => true]);
        } elseif ($kind === 'retail') {
            DB::table('retails')->insert(['trade_id' => $id, 'shipped_date' => $values['date'], 'warehouse_id' => $values['warehouse_id']]);
        } else {
            DB::table('stock_transfers')->insert(['trade_id' => $id, 'delivered_date' => $values['date'], 'picking_date' => $values['picking_date'],
                'from_warehouse_id' => $values['warehouse_id'], 'is_active' => true]);
        }

        return $id;
    }

    private function cancel(string $kind, int $id, string $level): void
    {
        $table = $level === 'item' ? 'trade_items' : ($level === 'trade' || $kind === 'retail'
            ? 'trades' : ($kind === 'earning' ? 'earnings' : 'stock_transfers'));
        DB::table($table)->where($table === 'trades' ? 'id' : 'trade_id', $id)->update(['is_active' => false]);
    }

    private function seedDaily(string $date, int $warehouseId, int $itemId, array $quantities = [0, 0, 0, 0, 0, 0]): void
    {
        DB::table(self::DAILY)->insert(['business_date' => $date, 'warehouse_id' => $warehouseId, 'item_id' => $itemId,
            ...array_combine(self::QUANTITIES, $quantities), 'created_at' => '2026-08-01 01:02:03', 'updated_at' => '2026-08-01 01:02:03']);
    }

    private function daily(string $date, int $warehouseId = 1, int $itemId = 10): array
    {
        $row = DB::table(self::DAILY)->where('business_date', $date)->where('warehouse_id', $warehouseId)->where('item_id', $itemId)->first();
        $this->assertNotNull($row, "Missing daily row {$date}:{$warehouseId}:{$itemId}");

        return (array) $row;
    }

    private function summary(): array
    {
        $row = DB::table(self::SUMMARY)->where('warehouse_id', 1)->where('item_id', 10)->first();
        $this->assertNotNull($row);

        return (array) $row;
    }

    private function assertQuantities(array $row, array $quantities): void
    {
        $this->assertSame(array_combine(self::QUANTITIES, $quantities), array_intersect_key($row, array_flip(self::QUANTITIES)));
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->get()->mapWithKeys(fn ($row) => [
            ($table === self::DAILY ? $row->business_date.':' : '').$row->warehouse_id.':'.$row->item_id => (array) $row,
        ])->sortKeys()->all();
    }

    private function sourceSnapshot(): array
    {
        $rows = [];
        foreach (['trades', 'trade_items', 'earnings', 'retails', 'stock_transfers'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    private function writeQueries(?string $table = null): array
    {
        return array_values(array_filter(DB::connection()->getQueryLog(), fn ($query) =>
            preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1
            && ($table === null || str_contains($query['query'], $table))));
    }

    private function createMemoryTables(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('trade_category');
            $table->boolean('is_active');
        });
        Schema::create('trade_items', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('trade_id');
            $table->bigInteger('item_id')->nullable();
            $table->boolean('is_active');
            $table->bigInteger('total_piece_quantity')->nullable();
            $table->bigInteger('quantity');
            $table->string('quantity_type');
        });
        Schema::create('earnings', function (Blueprint $table) {
            $table->bigInteger('trade_id')->primary();
            $table->date('delivered_date');
            $table->bigInteger('warehouse_id');
            $table->boolean('is_active');
        });
        Schema::create('retails', function (Blueprint $table) {
            $table->bigInteger('trade_id')->primary();
            $table->date('shipped_date');
            $table->bigInteger('warehouse_id');
        });
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->bigInteger('trade_id')->primary();
            $table->date('picking_date')->nullable();
            $table->date('delivered_date');
            $table->bigInteger('from_warehouse_id');
            $table->boolean('is_active');
        });
        Schema::create(self::DAILY, function (Blueprint $table) {
            $table->date('business_date');
            $table->bigInteger('warehouse_id');
            $table->bigInteger('item_id');
            foreach (self::QUANTITIES as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->timestamps();
            $table->primary(['business_date', 'warehouse_id', 'item_id']);
        });
        Schema::create(self::SUMMARY, function (Blueprint $table) {
            $table->bigInteger('warehouse_id');
            $table->bigInteger('item_id');
            foreach ([3, 5, 7, 14, 30] as $days) {
                $table->bigInteger("last_{$days}d_qty")->default(0);
                $table->decimal("avg_{$days}d_qty", 10, 2)->default(0);
            }
            foreach (['sales_today_qty', 'sales_yesterday_qty', 'sales_2days_ago_qty'] as $column) {
                $table->bigInteger($column)->default(0);
            }
            $table->date('last_shipped_at')->nullable();
            $table->dateTime('calculated_at')->nullable();
            $table->timestamps();
            $table->primary(['warehouse_id', 'item_id']);
        });
    }
}
