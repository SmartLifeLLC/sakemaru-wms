<?php

namespace Tests\Feature\Stats;

use App\Services\Stats\SalesSummarySynchronizer;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\LegacySalesSummaryCalculator as Legacy;
use Tests\TestCase;

class SyncSalesSummariesCommandTest extends TestCase
{
    private const TO = '2026-10-04';

    protected function setUp(): void
    {
        parent::setUp();

        // No configured business database is opened. All connections, including
        // the explicitly named sakemaru connection, become isolated memory DBs.
        foreach (array_keys(config('database.connections')) as $name) {
            DB::purge($name);
            config(["database.connections.{$name}" => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        }
        config(['database.default' => 'sakemaru']);
        $this->assertSame('sqlite', DB::connection('sakemaru')->getDriverName());
        $this->assertSame(':memory:', DB::connection('sakemaru')->getDatabaseName());
        $this->assertSame('', DB::connection('sakemaru')->selectOne('PRAGMA database_list')->file);

        Schema::connection('sakemaru')->create('stats_item_warehouse_daily_sales', function (Blueprint $table) {
            $table->date('business_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->bigInteger('shipped_piece_qty');
            $table->primary(['business_date', 'warehouse_id', 'item_id']);
        });
        Schema::connection('sakemaru')->create('stats_item_warehouse_sales_summaries', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            foreach ([3, 5, 7, 14, 30] as $days) {
                $table->bigInteger("last_{$days}d_qty")->default(0);
                $table->decimal("avg_{$days}d_qty", 10, 2)->default(0);
            }
            $table->bigInteger('sales_today_qty')->default(0);
            $table->bigInteger('sales_yesterday_qty')->default(0);
            $table->bigInteger('sales_2days_ago_qty')->default(0);
            $table->date('last_shipped_at')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();
            $table->primary(['warehouse_id', 'item_id']);
        });
        Carbon::setTestNow('2026-10-04 22:00:00');
        CarbonImmutable::setTestNow('2026-10-04 22:00:00');
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

    public function test_signed_sales_zero_presence_missing_summaries_and_last_shipped_date_match_legacy(): void
    {
        $daily = [
            $this->daily(1, 900, 40, 1), // Warehouse coverage, outside all windows.
            $this->daily(1, 1, 0, 12), $this->daily(1, 1, 1, -5),
            $this->daily(1, 2, 0, -8), // Return-only overwrites old shipment with NULL.
            $this->daily(1, 3, 0, -7), $this->daily(1, 3, 1, 7), // Net zero with a positive day.
            $this->daily(1, 4, 0, 0), // Present zero clears last_shipped_at.
            $this->daily(1, 5, 20, 9), // Missing in short windows, present in 30 days.
            $this->daily(1, 6, 0, -2), $this->daily(1, 6, 10, 4), // Wider window replaces NULL.
            $this->daily(1, 7, -1, 500), // Future rows must not contribute.
            $this->daily(2, 1, 40, 1), $this->daily(2, 1, 0, 999),
        ];
        $summaries = [];
        foreach (range(1, 8) as $itemId) {
            $summaries[] = $this->summary(1, $itemId);
        }
        $summaries[] = $this->summary(3, 1); // No warehouse coverage: unchanged.
        $this->seedMemory($daily, $summaries);
        $this->assertCommandMatchesLegacy($daily, $summaries);

        $rows = $this->statistics();
        $this->assertSame(0, $rows['1:3']['last_3d_qty']);
        $this->assertSame('2026-10-03', $rows['1:3']['last_shipped_at']);
        $this->assertNull($rows['1:4']['last_shipped_at']);
        $this->assertNull($rows['1:2']['last_shipped_at']);
        $this->assertSame('2026-09-24', $rows['1:6']['last_shipped_at']);
        $this->assertSame('2026-08-01', $rows['1:8']['last_shipped_at']);
        $this->assertSame(0, $rows['1:8']['sales_today_qty']);
        $this->assertSame(77, $rows['3:1']['last_30d_qty']);
    }

    public static function maturityCases(): iterable
    {
        foreach ([3, 5, 7, 14, 30] as $days) {
            yield "{$days} days, first row exactly at start" => [$days, $days - 1, true];
            yield "{$days} days, first row one day too recent" => [$days, $days - 2, false];
        }
    }

    #[DataProvider('maturityCases')]
    public function test_each_window_requires_sufficient_warehouse_history(int $days, int $age, bool $mature): void
    {
        $daily = [$this->daily(1, 1, $age, 4), $this->daily(1, 2, 0, 6)];
        $summaries = [$this->summary(1, 2)];
        $this->seedMemory($daily, $summaries);
        $this->assertCommandMatchesLegacy($daily, $summaries);
        $this->assertSame($mature ? 6 : 77, $this->statistics()['1:2']["last_{$days}d_qty"]);
    }

    public function test_new_warehouse_updates_three_and_five_days_but_preserves_longer_windows(): void
    {
        $daily = [$this->daily(1, 1, 4, 2), $this->daily(1, 2, 0, 3)];
        $summaries = [$this->summary(1, 2)];
        $this->seedMemory($daily, $summaries);
        $this->assertCommandMatchesLegacy($daily, $summaries);
        $row = $this->statistics()['1:2'];
        $this->assertSame(3, $row['last_3d_qty']);
        $this->assertSame(3, $row['last_5d_qty']);
        $this->assertSame(77, $row['last_7d_qty']);
        $this->assertSame(77, $row['last_30d_qty']);
    }

    public function test_every_window_includes_both_boundaries_and_excludes_the_previous_day(): void
    {
        $daily = [$this->daily(1, 900, 60, 1)];
        foreach ([3, 5, 7, 14, 30] as $days) {
            $daily[] = $this->daily(1, $days, 0, 2);
            $daily[] = $this->daily(1, $days, $days - 1, 3);
            $daily[] = $this->daily(1, $days, $days, 100);
            $daily[] = $this->daily(1, $days, -1, 1000);
        }
        $this->seedMemory($daily, []);
        $this->assertCommandMatchesLegacy($daily, []);
        foreach ([3, 5, 7, 14, 30] as $days) {
            $this->assertSame(5, $this->statistics()["1:{$days}"]["last_{$days}d_qty"]);
        }
    }

    public function test_warehouse_filter_keeps_other_warehouse_rows_and_timestamps_untouched(): void
    {
        $daily = [$this->daily(1, 1, 40, 1), $this->daily(1, 1, 0, 7), $this->daily(2, 1, 40, 1), $this->daily(2, 1, 0, 8)];
        $summaries = [$this->summary(1, 1), $this->summary(2, 1)];
        $this->seedMemory($daily, $summaries);
        $before = $this->allRows()['2:1'];
        $this->assertCommandMatchesLegacy($daily, $summaries, 1);
        $this->assertSame($before, $this->allRows()['2:1']);
    }

    public function test_dry_run_retains_legacy_counts_and_never_writes(): void
    {
        $daily = [$this->daily(1, 900, 40, 1), $this->daily(1, 1, 0, 1), $this->daily(1, 2, 20, 2)];
        $summaries = [$this->summary(1, 3)];
        $this->seedMemory($daily, $summaries);
        $before = $this->allRows();
        DB::connection('sakemaru')->enableQueryLog();
        $this->assertCommandMatchesLegacy($daily, $summaries, null, true);
        $this->assertSame($before, $this->allRows());
        $this->assertSame([], $this->writeQueries());
    }

    public function test_no_daily_rows_preserves_existing_summaries_including_null_dates(): void
    {
        $summaries = [$this->summary(1, 1, ['last_shipped_at' => null, 'calculated_at' => null])];
        $this->seedMemory([], $summaries);
        $before = $this->allRows();
        $this->assertCommandMatchesLegacy([], $summaries);
        $this->assertSame($before, $this->allRows());
    }

    public function test_empty_database_is_a_successful_no_op(): void
    {
        $this->assertCommandMatchesLegacy([], []);
        $this->assertSame([], $this->allRows());
    }

    public function test_calculation_time_refreshes_without_changing_statistics_or_created_at(): void
    {
        $daily = [$this->daily(1, 900, 40, 1), $this->daily(1, 1, 0, 3)];
        $summaries = [$this->summary(1, 1, ['created_at' => null]), $this->summary(1, 2)];
        $this->seedMemory($daily, $summaries);
        $this->assertCommandMatchesLegacy($daily, $summaries);
        $statistics = $this->statistics();
        $before = $this->allRows();
        Carbon::setTestNow('2026-10-04 23:00:00');
        CarbonImmutable::setTestNow('2026-10-04 23:00:00');
        $this->assertCommandMatchesLegacy($daily, array_values($before));
        $this->assertSame($statistics, $this->statistics());
        foreach ($this->allRows() as $key => $row) {
            $this->assertSame('2026-10-04 23:00:00', $row['calculated_at']);
            $this->assertSame('2026-10-04 23:00:00', $row['updated_at']);
            $this->assertSame($before[$key]['created_at'], $row['created_at']);
        }
    }

    public function test_deterministic_mixed_history_matches_every_legacy_statistic(): void
    {
        $daily = [];
        $summaries = [];
        foreach ([1 => 45, 2 => 29, 3 => 13, 4 => 6, 5 => 4, 6 => 2, 7 => 1] as $warehouseId => $history) {
            $daily[] = $this->daily($warehouseId, 9000, $history, 0);
            foreach (range(1, 35) as $itemId) {
                foreach (range(-1, $history) as $age) {
                    if (($warehouseId * 19 + $itemId * 7 + $age * 3) % 11 < 3) {
                        $daily[] = $this->daily($warehouseId, $itemId, $age, (($itemId * 17 + $age * 5) % 31) - 15);
                    }
                }
                if ($itemId % 3 !== 0) {
                    $summaries[] = $this->summary($warehouseId, $itemId, ['last_shipped_at' => $itemId % 2 ? null : '2026-08-01']);
                }
            }
            $summaries[] = $this->summary($warehouseId, 9999); // No raw rows for this item.
        }
        $this->seedMemory($daily, $summaries);
        $this->assertCommandMatchesLegacy($daily, $summaries);
    }

    public function test_more_than_one_thousand_summary_rows_and_repeated_execution_match_legacy(): void
    {
        $daily = [$this->daily(1, 9000, 40, 1)];
        $summaries = [];
        foreach (range(1, 1105) as $itemId) {
            $daily[] = $this->daily(1, $itemId, $itemId % 30, ($itemId % 17) - 8);
            if ($itemId % 2 === 0) {
                $summaries[] = $this->summary(1, $itemId);
            }
        }
        $summaries[] = $this->summary(1, 9900);
        $this->seedMemory($daily, $summaries);
        $this->assertCommandMatchesLegacy($daily, $summaries);
        $first = $this->statistics();
        $this->assertCount(1106, $first);
        $this->assertCommandMatchesLegacy($daily, array_values($this->allRows()));
        $this->assertSame($first, $this->statistics());
    }

    public function test_uncovered_warehouse_only_reads_coverage_and_does_not_publish(): void
    {
        $this->seedMemory([], [$this->summary(1, 1)]);
        $connection = DB::connection('sakemaru');
        $connection->enableQueryLog();
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', ['--summary-only' => true, '--to' => self::TO]));
        $queries = $connection->getQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('stats_item_warehouse_daily_sales', $queries[0]['query']);
        $this->assertStringContainsString('MIN(business_date)', $queries[0]['query']);
        $this->assertSame([], $this->writeQueries());
    }

    public function test_all_windows_use_one_bounded_aggregate_without_missing_row_subqueries(): void
    {
        $daily = [$this->daily(1, 9000, 40, 1)];
        foreach (range(1, 1105) as $itemId) {
            $daily[] = $this->daily(1, $itemId, $itemId % 30, ($itemId % 17) - 8);
        }
        $this->seedMemory($daily, [$this->summary(1, 9900)]);
        $connection = DB::connection('sakemaru');
        $connection->enableQueryLog();
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', ['--summary-only' => true, '--to' => self::TO]));
        $queries = $connection->getQueryLog();
        $reads = array_values(array_filter($queries, fn ($q) => preg_match('/^select\b/i', $q['query'])));
        $this->assertCount(3, $reads, 'Coverage, one bounded aggregation, and one existing-summary read.');
        $this->assertStringContainsString('stats_item_warehouse_daily_sales', $reads[1]['query']);
        $this->assertStringContainsString('between ? and ?', $reads[1]['query']);
        $this->assertContains('2026-09-05', $reads[1]['bindings']);
        $this->assertContains(self::TO, $reads[1]['bindings']);
        $this->assertStringContainsString('stats_item_warehouse_sales_summaries', $reads[2]['query']);
        $this->assertCount(2, $this->writeQueries(), '1106 rows must publish in two bounded chunks.');
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/not\s+exists/i', $query['query']);
        }
    }

    public function test_second_publication_chunk_failure_rolls_back_the_first_chunk_too(): void
    {
        $daily = [$this->daily(1, 9000, 40, 1)];
        foreach (range(1, 1005) as $itemId) {
            $daily[] = $this->daily(1, $itemId, 0, 5);
        }
        $this->seedMemory($daily, [$this->summary(1, 1), $this->summary(1, 1001)]);
        $before = $this->allRows();
        $connection = DB::connection('sakemaru');
        // This trigger exists only on the verified in-memory connection. The
        // second chunk fails inside SQLite after the first chunk has succeeded.
        $connection->statement("CREATE TEMP TRIGGER fail_second_summary_chunk BEFORE INSERT ON stats_item_warehouse_sales_summaries
            WHEN NEW.item_id = 1001 BEGIN SELECT RAISE(ABORT, 'forced second chunk failure'); END");
        $connection->enableQueryLog();
        try {
            Artisan::call('wms:sync-sales-summaries', ['--summary-only' => true, '--to' => self::TO]);
            $this->fail('The second publication chunk must fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced second chunk failure', $exception->getMessage());
        }

        $this->assertCount(1, $this->writeQueries(), 'The first chunk must have executed successfully before the failure.');
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($before, $this->allRows(), 'Both updated existing rows and newly inserted rows must roll back.');
    }

    public function test_changed_lock_connection_prevents_any_summary_publication(): void
    {
        $daily = [$this->daily(1, 900, 40, 1), $this->daily(1, 1, 0, 5)];
        $this->seedMemory($daily, [$this->summary(1, 1)]);
        $before = $this->allRows();
        $connection = DB::connection('sakemaru');
        $connection->enableQueryLog();
        try {
            app(SalesSummarySynchronizer::class)->sync(
                CarbonImmutable::parse(self::TO),
                null,
                collect([1 => CarbonImmutable::parse('2026-08-25')]),
                false,
                new PDO('sqlite::memory:'),
            );
            $this->fail('Publishing on a different connection must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DB接続が再確立', $exception->getMessage());
        }
        $this->assertSame([], $this->writeQueries());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($before, $this->allRows());
    }

    private function assertCommandMatchesLegacy(array $daily, array $summaries, ?int $warehouseId = null, bool $dryRun = false): void
    {
        $expected = Legacy::calculate($daily, $summaries, self::TO, $warehouseId, $dryRun, now()->format('Y-m-d H:i:s'));
        $options = ['--summary-only' => true, '--to' => self::TO];
        if ($warehouseId !== null) {
            $options['--warehouse-id'] = $warehouseId;
        }
        if ($dryRun) {
            $options['--dry-run'] = true;
        }
        $this->assertSame(0, Artisan::call('wms:sync-sales-summaries', $options));
        $output = Artisan::output();
        foreach ($expected['counts'] as $days => $count) {
            $this->assertStringContainsString("{$days}日サマリ: {$count} 件", $output);
        }
        $this->assertStringContainsString("日別内訳: {$expected['daily_count']} 件", $output);
        $this->assertSame($this->normalize($expected['rows']), $this->statistics());
        $this->assertSame($this->timestamps($expected['rows']), $this->timestamps($this->allRows()));
    }

    private function daily(int $warehouseId, int $itemId, int $daysAgo, int $quantity): array
    {
        return ['warehouse_id' => $warehouseId, 'item_id' => $itemId,
            'business_date' => CarbonImmutable::parse(self::TO)->subDays($daysAgo)->toDateString(),
            'shipped_piece_qty' => $quantity];
    }

    private function summary(int $warehouseId, int $itemId, array $overrides = []): array
    {
        $row = Legacy::emptySummary($warehouseId, $itemId);
        foreach ([3, 5, 7, 14, 30] as $days) {
            $row["last_{$days}d_qty"] = 77;
            $row["avg_{$days}d_qty"] = 7.7;
        }

        return array_replace($row, [
            'sales_today_qty' => 71, 'sales_yesterday_qty' => 72, 'sales_2days_ago_qty' => 73,
            'last_shipped_at' => '2026-08-01', 'calculated_at' => '2026-08-01 01:02:03',
            'created_at' => '2026-08-01 01:02:03', 'updated_at' => '2026-08-01 01:02:03',
        ], $overrides);
    }

    private function seedMemory(array $daily, array $summaries): void
    {
        foreach (array_chunk($daily, 100) as $chunk) {
            DB::connection('sakemaru')->table('stats_item_warehouse_daily_sales')->insert($chunk);
        }
        foreach (array_chunk($summaries, 100) as $chunk) {
            DB::connection('sakemaru')->table('stats_item_warehouse_sales_summaries')->insert($chunk);
        }
    }

    private function allRows(): array
    {
        return DB::connection('sakemaru')->table('stats_item_warehouse_sales_summaries')->get()
            ->mapWithKeys(fn ($row) => [$row->warehouse_id.':'.$row->item_id => (array) $row])->sortKeys()->all();
    }

    private function statistics(): array
    {
        return $this->normalize($this->allRows());
    }

    private function normalize(array $rows): array
    {
        foreach ($rows as &$row) {
            $row = array_intersect_key($row, Legacy::emptySummary((int) $row['warehouse_id'], (int) $row['item_id']));
            foreach ($row as $column => &$value) {
                if (str_starts_with($column, 'avg_')) {
                    $value = (float) $value;
                } elseif ($column !== 'last_shipped_at') {
                    $value = (int) $value;
                }
            }
            unset($value);
            ksort($row);
        }
        unset($row);
        ksort($rows);

        return $rows;
    }

    private function writeQueries(): array
    {
        return array_values(array_filter(DB::connection('sakemaru')->getQueryLog(),
            fn ($query) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1));
    }

    private function timestamps(array $rows): array
    {
        $result = [];
        foreach ($rows as $key => $row) {
            $result[$key] = [
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'calculated_at' => $row['calculated_at'] ?? null,
            ];
        }
        ksort($result);

        return $result;
    }
}
