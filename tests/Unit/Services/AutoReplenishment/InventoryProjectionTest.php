<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\InventoryProjection;
use App\Services\AutoReplenishment\InventoryProjectionCalendar;
use App\Services\AutoReplenishment\StrategyCalculator;
use App\Services\AutoReplenishment\WeekdayDemand;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class InventoryProjectionTest extends TestCase
{
    public static function policy(): array
    {
        return ['channel' => 'LOGISTICS', 'strategy_key' => 'arrival_adaptive_v1', 'reorder_point_pieces' => 100,
            'target_stock_pieces' => 120, 'min_order_pieces' => 20, 'max_order_pieces' => 60, 'order_multiple_pieces' => 10,
            'is_enabled' => false, 'calculation_basis' => ['mean_daily' => 10, 'batch_quantity_pieces' => 60]];
    }

    public static function inputs(): array
    {
        $opportunities = [];
        $date = CarbonImmutable::parse('2026-10-19');
        for ($i = 0; $i < 28; $i++) {
            $opportunities[] = ['generation_date' => $date->addDays($i)->toDateString(), 'arrival_date' => $date->addDays($i + 2)->toDateString()];
        }
        $calendar = (new InventoryProjectionCalendar)->normalize($opportunities, range(1, 6), []);

        return ['snapshot' => ['stock' => 90, 'incoming' => 0, 'claims' => 0, 'supply_events' => [], 'packing_unit' => 10,
            'quantity_type' => 'CASE', 'capacity_case' => 10, 'business_date' => '2026-10-19', 'shipping_unit' => ['mode' => 'STANDARD']],
            'calendar' => $calendar + ['weekdays' => range(1, 6), 'sunday_master_enabled' => false],
            'demand' => ['weekdays' => array_fill(0, 7, ['mean' => 10.0, 'days' => 13, 'fallback' => false]),
                'period' => ['start' => '2026-07-19', 'end' => '2026-10-18'], 'observed_days' => 92, 'missing_days' => 0, 'warnings' => []],
            'picking_lead_days' => 1, 'observed_at' => '10/19 10:00'];
    }

    public function test_weekday_average_includes_observed_zero_and_excludes_missing_feeds(): void
    {
        $r = (new WeekdayDemand)->summarize(['2026-10-05' => 10, '2026-10-12' => 0, '2026-10-19' => null, '2026-10-06' => 30]);
        self::assertEqualsWithDelta(5.0, $r['weekdays'][1]['mean'], 0.00001);
        self::assertSame(2, $r['weekdays'][1]['days']);
        self::assertSame(30, $r['weekdays'][2]['mean']);
        self::assertTrue($r['weekdays'][0]['fallback']);
        self::assertEqualsWithDelta(40 / 3, $r['weekdays'][0]['mean'], 0.00001);
        self::assertSame(1, $r['missing_days']);
    }

    public function test_missing_history_is_not_assumed_zero_sales(): void
    {
        $this->expectException(\RuntimeException::class);
        (new WeekdayDemand)->summarize(['2026-10-19' => null]);
    }

    public function test_calendar_excludes_sundays_and_skips_holidays_on_allowed_weekdays(): void
    {
        $r = (new InventoryProjectionCalendar)->normalize([
            ['generation_date' => '2026-10-23', 'arrival_date' => '2026-10-25'],
            ['generation_date' => '2026-10-24', 'arrival_date' => '2026-10-26'],
        ], [0, 1, 3, 5], ['2026-10-26']);
        self::assertSame(['2026-10-23' => '2026-10-28', '2026-10-24' => '2026-10-28'], $r['arrivals']);
        self::assertTrue($r['adjusted']);
    }

    public function test_simulated_decisions_reuse_the_batch_calculator_and_consume_future_supply_once(): void
    {
        $input = self::inputs();
        $input['snapshot']['incoming'] = 30;
        $input['snapshot']['supply_events'] = [['date' => '2026-10-21', 'pieces' => 30]];
        $policy = self::policy();
        $r = (new InventoryProjection)->calculate($policy, $input);
        $events = $input['snapshot']['supply_events'];
        foreach ($r['days'] as $day) {
            $events = array_values(array_filter($events, fn ($e) => $e['date'] !== $day['date']));
            $dates = array_keys($input['calendar']['arrivals']);
            $index = array_search($day['date'], $dates, true);
            $snapshot = array_replace($input['snapshot'], ['stock' => $day['after_receipt'], 'incoming' => array_sum(array_column($events, 'pieces')),
                'business_date' => $day['date'], 'arrival_date' => $input['calendar']['arrivals'][$day['date']],
                'next_arrival_date' => $input['calendar']['arrivals'][$dates[$index + 1]]]);
            $batch = app(StrategyCalculator::class)->calculate($policy, $snapshot);
            self::assertSame($batch['phase'], $day['phase']);
            self::assertSame($batch['formula'], $day['formula']);
            self::assertSame($snapshot['incoming'], $day['pending_at_decision']);
            self::assertSame($day['after_receipt'] < $policy['reorder_point_pieces'], $day['below_point']);
            self::assertSame($batch['phase'] === 'READY' ? $batch['quantity'] : 0, $day['ordered']);
            self::assertSame($day['opening'] + $day['received'], $day['after_receipt']);
            self::assertSame(max(0, $day['after_receipt'] - $day['demand']), $day['stock']);
            if ($day['ordered']) {
                $events[] = ['date' => $day['arrival_date'], 'pieces' => $day['ordered']];
                self::assertSame(0, $day['ordered'] % 10);
                self::assertLessThanOrEqual(60, $day['ordered']);
            }
            if ($day['weekday'] === 0) {
                self::assertSame(0, $day['received']);
            }
        }
        self::assertSame(30, array_sum(array_column($r['days'], 'existing_receipt')));
        self::assertSame(14, count($r['days']));
    }

    public function test_quantity_edits_change_frequency_without_modifying_the_input(): void
    {
        $input = self::inputs();
        $hash = serialize($input);
        $normal = (new InventoryProjection)->calculate(self::policy(), $input);
        $small = (new InventoryProjection)->calculate(array_replace(self::policy(), ['max_order_pieces' => 20, 'target_stock_pieces' => 70]), $input);
        self::assertGreaterThan($normal['order_count'], $small['order_count']);
        self::assertSame($hash, serialize($input));
        self::assertNotSame($normal['end_stock'], $small['end_stock']);
    }

    public function test_distant_unknown_and_unconfirmed_supply_is_not_plotted_early_or_ordered_twice(): void
    {
        $input = self::inputs();
        $input['snapshot']['incoming'] = 800;
        $input['snapshot']['claims'] = 200;
        $input['snapshot']['supply_events'] = [['date' => '2026-12-01', 'pieces' => 500], ['date' => null, 'pieces' => 100],
            ['date' => '2026-10-18', 'pieces' => 100], ['date' => '2026-10-25', 'pieces' => 100]];
        $r = (new InventoryProjection)->calculate(self::policy(), $input);
        self::assertSame(0, $r['order_count']);
        self::assertSame(0, array_sum(array_column($r['days'], 'received')));
        self::assertSame(300, $r['undated_incoming']);
        self::assertSame(800, $r['outstanding_after_period']);
        self::assertGreaterThan(0, $r['shortage_days']);
    }

    public function test_negative_stock_is_retained_until_arrival_without_fictitious_debt(): void
    {
        $input = self::inputs();
        $input['snapshot']['stock'] = -6;
        $r = (new InventoryProjection)->calculate(self::policy(), $input);
        self::assertSame(-6, $r['days'][0]['stock']);
        self::assertSame(-6, $r['days'][1]['stock']);
        self::assertGreaterThan(0, $r['days'][0]['ordered']);
        self::assertGreaterThan(0, $r['days'][2]['received']);
        self::assertSame(10, $r['days'][0]['unmet']);
    }

    public function test_registered_shipments_and_explicit_pallets_preserve_physical_limits(): void
    {
        $input = self::inputs();
        $input['snapshot']['shipping_unit'] = ['mode' => 'SHIPMENT', 'quantities' => [20, 40, 60]];
        $policy = array_replace(self::policy(), ['order_multiple_pieces' => 20]);
        $r = (new InventoryProjection)->calculate($policy, $input);
        self::assertNotEmpty($r['orders']);
        foreach ($r['orders'] as $order) {
            self::assertContains($order['pieces'], [20, 40, 60]);
        }
        $input['snapshot']['shipping_unit'] = ['mode' => 'PALLET', 'pieces_per_pallet' => 40];
        $r = (new InventoryProjection)->calculate(array_replace($policy, ['order_multiple_pieces' => 40, 'min_order_pieces' => 40]), $input);
        foreach ($r['orders'] as $order) {
            self::assertSame(0, $order['pieces'] % 40);
        }
        $this->expectException(\RuntimeException::class);
        (new InventoryProjection)->calculate($policy, $input);
    }

    public function test_weekday_consumption_varies_and_closed_generation_days_never_order(): void
    {
        $input = self::inputs();
        $input['demand']['weekdays'][0]['mean'] = 55.5;
        $input['calendar']['arrivals'] = array_filter($input['calendar']['arrivals'], fn ($d) => CarbonImmutable::parse($d)->isMonday(), ARRAY_FILTER_USE_KEY);
        $policy = self::policy();
        $policy['calculation_basis']['mean_daily'] = 16.5;
        $policy['calculation_basis']['demand_profile'] = $input['demand'] + ['version' => 'weekday-demand-v1', 'legacy' => false, 'mean' => 16.5, 'anchor_date' => '2026-10-19'];
        $r = (new InventoryProjection)->calculate($policy, $input);
        self::assertSame(111, array_sum(array_column(array_filter($r['days'], fn ($day) => $day['weekday'] === 0), 'demand')));
        foreach ($r['days'] as $day) {
            if ($day['weekday'] === 0) {
                self::assertContains($day['demand'], [55, 56]);
            }
            if ($day['weekday'] !== 1) {
                self::assertSame('SKIP', $day['phase']);
            }
        }
    }

    public function test_invalid_or_empty_quantity_never_reuses_old_results(): void
    {
        $this->expectException(\RuntimeException::class);
        (new InventoryProjection)->calculate(array_replace(self::policy(), ['max_order_pieces' => '']), self::inputs());
    }

    public function test_no_shortage_does_not_hide_low_or_zero_stock_before_the_next_receipt(): void
    {
        $input = self::inputs();
        $input['calendar']['arrivals'] = [];
        $policy = self::policy();
        $policy['calculation_basis']['safety_stock_pieces'] = 30;
        foreach ([[146, 6, true], [140, 0, true], [400, 260, false]] as [$initial, $minimum, $risk]) {
            $input['snapshot']['stock'] = $initial;
            $r = (new InventoryProjection)->calculate($policy, $input);
            self::assertSame(0, $r['shortage_days']);
            self::assertSame($minimum, $r['minimum_stock']);
            self::assertSame('2026-11-01', $r['minimum_stock_date']);
            self::assertSame('日末', $r['minimum_stock_timing']);
            self::assertSame(10, $r['minimum_day_demand']);
            self::assertSame($risk, $r['low_stock_buffer']);
        }
        $input['snapshot']['stock'] = -6;
        $r = (new InventoryProjection)->calculate($policy, $input);
        self::assertSame(-6, $r['minimum_stock']);
        self::assertSame('2026-10-19', $r['minimum_stock_date']);
        self::assertSame('入荷前', $r['minimum_stock_timing']);
    }
}
