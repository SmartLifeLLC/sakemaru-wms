<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\DemandEventHistoryReader;
use App\Services\AutoReplenishment\DemandObservationExclusions;
use App\Services\AutoReplenishment\Replay\ReplayDataReader;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemandObservationExclusionsTest extends TestCase
{
    public function test_closed_days_are_unobserved_only_for_the_confirmed_store_client_and_dates(): void
    {
        $service = new DemandObservationExclusions;
        $daily = ['2026-08-31' => 5, '2026-09-01' => 0, '2026-09-30' => 9, '2026-10-01' => 0];
        self::assertSame(['2026-08-31' => 5, '2026-09-01' => null, '2026-09-30' => null, '2026-10-01' => 0], $service->mask(6, 3, $daily));
        self::assertSame($daily, $service->mask(6, 2, $daily));
        self::assertSame($daily, $service->mask(7, 3, $daily));
        self::assertSame([2], $service->learningStores(6, [2, 3], '2026-08-01', '2026-09-01'));
        self::assertSame([2, 3], $service->learningStores(6, [2, 3], '2026-08-01', '2026-08-31'));
        self::assertSame([2, 3], $service->learningStores(6, [2, 3], '2026-10-01', '2026-10-31'));
    }

    public function test_recommendation_excludes_closure_from_mean_and_receipt_lots_without_mutating_history(): void
    {
        $history = SafetyStockSettingsTest::history();
        $history['scope']['client_id'] = 6;
        $history['scope']['warehouse_id'] = 3;
        $history['period'] = ['start' => '2026-08-01', 'end' => '2026-10-15'];
        $history['daily'] = [];
        for ($d = CarbonImmutable::parse('2026-08-01'); $d->toDateString() <= '2026-10-15'; $d = $d->addDay()) {
            $history['daily'][$d->toDateString()] = $d->month === 9 ? 0 : 10;
        }
        $history['receipts'] = [['warehouse_id' => 3, 'date' => '2026-09-10', 'pieces' => 999, 'lead_days' => 2]];
        $r = app(SettingsRecommendationCalculator::class)->calculate($history, ['safety_stock_days' => 5]);
        self::assertTrue($r['available']);
        self::assertEquals(10, $r['basis']['mean_daily']);
        self::assertSame(0, $r['basis']['own_receipt_days']);
        self::assertStringContainsString('災害・休業による除外期間 2026-09-01〜2026-09-30（30日）', implode('', $r['basis']['warnings']));
        self::assertSame(0, $history['daily']['2026-09-10']);
        $history['scope']['client_id'] = 7;
        $other = app(SettingsRecommendationCalculator::class)->calculate($history, ['safety_stock_days' => 5]);
        self::assertLessThan(10, $other['basis']['mean_daily']);
        self::assertSame(1, $other['basis']['own_receipt_days']);
    }

    public function test_learning_uses_a_fixed_store_cohort_and_replay_marks_only_closed_days_missing(): void
    {
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE warehouses (id INTEGER, client_id INTEGER, code INTEGER, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE stats_item_warehouse_daily_sales (warehouse_id INTEGER, item_id INTEGER, business_date TEXT, sales_piece_qty INTEGER)');
        foreach ([2, 3] as $wh) {
            $db->table('warehouses')->insert(['id' => $wh, 'client_id' => 6, 'code' => $wh, 'is_active' => 1, 'is_virtual' => 0]);
            foreach (['2026-08-31', '2026-09-01', '2026-09-30', '2026-10-01'] as $date) {
                $db->table('stats_item_warehouse_daily_sales')->insert(['warehouse_id' => $wh, 'item_id' => 1, 'business_date' => $date, 'sales_piece_qty' => $wh === 3 ? 100 : 5]);
            }
        }
        $reader = new DemandEventHistoryReader;
        $learning = $reader->productTotals(6, '2026-08-31', '2026-10-01', [1]);
        self::assertSame(5, $learning['items'][1]['2026-08-31']);
        self::assertSame(5, $learning['items'][1]['2026-09-01']);
        self::assertSame(5, $learning['items'][1]['2026-10-01']);
        self::assertSame(105, $reader->productTotals(6, '2026-10-01', '2026-10-01', [1])['items'][1]['2026-10-01']);
        $replay = (new ReplayDataReader($reader))->demand(6, [2, 3], [1], '2026-08-31', '2026-10-01');
        self::assertSame(100, $replay[3][1]['2026-08-31']);
        self::assertNull($replay[3][1]['2026-09-01']);
        self::assertNull($replay[3][1]['2026-09-30']);
        self::assertSame(100, $replay[3][1]['2026-10-01']);
        self::assertSame(5, $replay[2][1]['2026-09-01']);
        self::assertSame(8, $db->table('stats_item_warehouse_daily_sales')->count());
        DB::disconnect('sakemaru');
    }
}
