<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\InventoryProjection;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ArrivalSafetyBufferTest extends TestCase
{
    public function test_recommendation_keeps_buffer_through_sunday_with_unchanged_order_limits(): void
    {
        // Regression shape: a large Saturday demand, no Sunday receipt, usual lot 192 / minimum 144 / case 24.
        $totals = [37, 18, 113, 46, 34, 43, 198];
        $seen = array_fill(0, 7, 0);
        $daily = [];
        for ($d = CarbonImmutable::parse('2026-10-03'); $d->toDateString() <= '2026-10-30'; $d = $d->addDay()) {
            $w = $d->dayOfWeek;
            $daily[$d->toDateString()] = intdiv($totals[$w], 4) + ($seen[$w]++ < $totals[$w] % 4 ? 1 : 0);
        }
        $history = ['scope' => ['client_id' => 6, 'warehouse_id' => 2, 'channel' => 'EOS', 'packing_unit' => 24, 'capacity_case' => 24,
            'order_unit' => ['quantity_type' => 'CASE', 'reason' => 'ケース', 'packing_unit' => 24]],
            'period' => ['start' => '2026-10-03', 'end' => '2026-10-30'], 'daily' => $daily,
            'nominal_lead_days' => 3, 'review_interval_days' => 1, 'maximum_arrival_gap_days' => 2,
            'receipts' => array_map(fn ($i) => ['warehouse_id' => 2, 'date' => '2026-10-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT),
                'pieces' => $i === 0 ? 144 : 192, 'lead_days' => 1], range(0, 5))];
        $arrivals = [];
        for ($d = CarbonImmutable::parse('2026-10-31'); $d->toDateString() <= '2027-01-09'; $d = $d->addDay()) {
            $arrival = $d->addDay();
            if ($arrival->isSunday()) {
                $arrival = $arrival->addDay();
            }
            $arrivals[$d->toDateString()] = $arrival->toDateString();
        }
        $input = ['snapshot' => ['stock' => 338, 'incoming' => 0, 'claims' => 0, 'supply_events' => [], 'packing_unit' => 24,
            'capacity_case' => 24, 'quantity_type' => 'CASE', 'shipping_unit' => ['mode' => 'STANDARD'], 'business_date' => '2026-10-31'],
            'calendar' => ['arrivals' => $arrivals], 'horizon_days' => 70, 'observed_at' => 'test', 'picking_lead_days' => 0];
        $minimum = 0;
        foreach ([4, 5, 7] as $days) {
            $r = app(SettingsRecommendationCalculator::class)->calculate($history, ['unit_mode' => 'AUTO', 'safety_stock_days' => $days]);
            self::assertTrue($r['available']);
            self::assertSame([144, 192, 24], array_map(fn ($k) => $r['values'][$k], ['min_order_pieces', 'max_order_pieces', 'order_multiple_pieces']));
            $policy = $r['values'] + ['channel' => 'EOS', 'calculation_basis' => $r['basis']];
            $projection = app(InventoryProjection::class)->calculate($policy, $input);
            self::assertSame(0, $projection['shortage_days']);
            self::assertGreaterThanOrEqual($r['basis']['safety_stock_pieces'], $projection['minimum_stock']);
            self::assertGreaterThan($minimum, $projection['minimum_stock']);
            foreach ($projection['orders'] as $order) {
                self::assertFalse(CarbonImmutable::parse($order['arrival_date'])->isSunday());
                self::assertGreaterThanOrEqual(144, $order['pieces']);
                self::assertLessThanOrEqual(192, $order['pieces']);
                self::assertSame(0, $order['pieces'] % 24);
            }
            $minimum = $projection['minimum_stock'];
            if ($days === 4) {
                // Saved v1 quantities continue to run unchanged; only pressing Recommend produces v2 quantities.
                $legacy = array_replace($policy, ['reorder_point_pieces' => 167, 'target_stock_pieces' => 264]);
                $legacy['calculation_basis']['quantity_model'] = 'safety-stock-days-v1';
                $old = app(InventoryProjection::class)->calculate($legacy, $input);
                self::assertSame(35, $old['minimum_stock']);
                self::assertSame(105, $projection['minimum_stock']);
            }
        }
    }
}
