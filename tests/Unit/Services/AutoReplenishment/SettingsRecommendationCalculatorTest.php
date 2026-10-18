<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SettingsRecommendationCalculatorTest extends TestCase
{
    private function history(): array
    {
        $daily = [];
        for ($d = CarbonImmutable::parse('2026-09-01'); $d->toDateString() <= '2026-10-17'; $d = $d->addDay()) {
            $daily[$d->toDateString()] = $d->month === 9 && $d->day < 20 ? 1000 : 300;
        }

        return ['scope' => ['warehouse_id' => 1, 'packing_unit' => 1, 'capacity_case' => 24],
            'period' => ['start' => '2026-09-01', 'end' => '2026-10-17'], 'daily' => $daily, 'nominal_lead_days' => 3,
            'receipts' => array_map(fn ($i) => ['warehouse_id' => 1, 'pieces' => $i === 0 ? 576 : 1728,
                'date' => CarbonImmutable::parse('2026-09-01')->addDays($i)->toDateString(), 'lead_days' => 1, 'source' => ['id' => $i]], range(0, 12))
                + [20 => ['warehouse_id' => 2, 'pieces' => 384, 'date' => '2026-09-01', 'lead_days' => 1, 'source' => ['id' => 20]]]];
    }

    public function test_recent_demand_and_actual_linked_lots_are_separate_inputs(): void
    {
        $r = app(SettingsRecommendationCalculator::class)->calculate($this->history());
        self::assertTrue($r['available']);
        // GCD remains 192; the batch is this warehouse's observed P50, not its minimum.
        self::assertSame(192, $r['values']['order_multiple_pieces']);
        self::assertSame(1728, $r['values']['min_order_pieces']);
        self::assertSame(1344, $r['values']['reorder_point_pieces']);
        self::assertSame(1728, $r['values']['max_order_pieces']);
        self::assertSame(2112, $r['values']['target_stock_pieces']);
        self::assertSame('arrival_batch_v1', $r['values']['strategy_key']);
        self::assertSame(28, $r['basis']['observed_days']);
        self::assertSame(4, $r['basis']['protection_days']);
    }

    public function test_missing_days_are_never_filled_with_zero(): void
    {
        $h = $this->history();
        foreach ($h['daily'] as $day => $v) {
            $h['daily'][$day] = (int) substr($day, -2) % 2 ? null : $v;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertFalse($r['available']);
        self::assertStringContainsString('連続実績が不足', $r['basis']['reason']);
    }

    public function test_insufficient_linked_receipts_does_not_invent_minimum_or_unit(): void
    {
        $h = $this->history();
        $h['receipts'] = [];
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertFalse($r['available']);
        self::assertSame([], $r['values']);
    }

    public function test_piece_preference_preserves_eos_output_pack_and_uses_typical_batch(): void
    {
        $h = $this->history();
        $h['scope']['packing_unit'] = 24;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE']);
        self::assertSame(24, $r['values']['order_multiple_pieces']);
        self::assertSame(1728, $r['values']['min_order_pieces']);
        $h['scope']['packing_unit'] = 1;
        self::assertSame(1, app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'PIECE'])['values']['order_multiple_pieces']);
    }

    public function test_sparse_recent_sales_use_longer_observed_history_with_an_explicit_warning(): void
    {
        $h = $this->history();
        foreach ($h['daily'] as $day => $value) {
            $h['daily'][$day] = in_array($day, ['2026-09-03', '2026-09-10', '2026-09-17', '2026-10-03', '2026-10-10']) ? 24 : 0;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame('2026-09-01', $r['basis']['demand_start']);
        self::assertSame(5, $r['basis']['positive_demand_days']);
        self::assertStringContainsString('出荷のある日が3日未満', implode('', $r['basis']['warnings']));
        $h['daily'] = array_map(fn () => 0, $h['daily']);
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h)['available']);
    }

    public function test_other_warehouse_is_not_used_to_invent_a_store_batch_and_long_lead_is_rejected(): void
    {
        $h = $this->history();
        $h['scope']['warehouse_id'] = 99;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertFalse($r['available']);
        self::assertSame(0, $r['basis']['own_receipt_count']);
        self::assertStringContainsString('他倉庫の数量を自動採用せず', $r['basis']['reason']);
        $h['scope']['warehouse_id'] = 1;
        $h['nominal_lead_days'] = 15;
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h)['available']);
    }

    public function test_even_sample_uses_an_observed_quantity_instead_of_an_unshipped_average(): void
    {
        $h = $this->history();
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ([576, 576, 768, 960, 1728, 1728] as $i => $qty) {
            $h['receipts'][$i]['pieces'] = $qty;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertTrue($r['available']);
        self::assertSame(768, $r['values']['min_order_pieces']);
        self::assertSame(768, $r['values']['max_order_pieces']);
        self::assertSame(768, $r['basis']['batch_quantity_pieces']);
    }

    public function test_repeated_receipts_on_one_day_do_not_establish_a_typical_store_batch(): void
    {
        $h = $this->history();
        foreach ($h['receipts'] as &$receipt) {
            $receipt['date'] = '2026-10-01';
        }
        unset($receipt);
        self::assertFalse(app(SettingsRecommendationCalculator::class)->calculate($h)['available']);
    }

    public function test_case_preference_requires_sufficient_compatible_own_history(): void
    {
        $h = $this->history();
        $h['receipts'] = array_slice($h['receipts'], 0, 6);
        foreach ([1, 2, 3, 4, 5, 24] as $i => $qty) {
            $h['receipts'][$i]['pieces'] = $qty;
        }
        $r = app(SettingsRecommendationCalculator::class)->calculate($h, ['unit_mode' => 'CASE']);
        self::assertFalse($r['available']);
        self::assertStringContainsString('単位に合う', $r['basis']['reason']);
    }

    public function test_fixed_batch_matches_history_for_small_or_large_shortages_and_counts_incoming(): void
    {
        $p = app(SettingsRecommendationCalculator::class)->calculate($this->history())['values'];
        $p['reorder_point_pieces'] = 4000;
        $p['target_stock_pieces'] = 4000;
        $strategy = app(\App\Services\AutoReplenishment\CappedThresholdStrategy::class);
        foreach ([3999, 3000, 0] as $stock) {
            $r = $strategy->calculate($p, $stock, 0, 0, 1);
            self::assertSame('READY', $r['phase']);
            self::assertSame(1728, $r['quantity']);
        }
        self::assertSame('NO_ORDER', $strategy->calculate($p, 3000, 1728, 0, 1)['phase']);
        self::assertSame('NO_ORDER', $strategy->calculate($p, 3000, 0, 1728, 1)['phase']);
        self::assertSame('HOLD', $strategy->calculate($p, -1, 0, 0, 1)['phase']);
    }

    public function test_one_large_historical_order_does_not_raise_the_typical_batch(): void
    {
        $h = $this->history();
        $h['receipts'][0]['pieces'] = 10000000;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertSame(1728, $r['values']['max_order_pieces']);
    }

    public function test_long_lead_does_not_inflate_the_arrival_holding_target(): void
    {
        $h = $this->history();
        $h['nominal_lead_days'] = 8;
        $r = app(SettingsRecommendationCalculator::class)->calculate($h);
        self::assertSame(2112, $r['values']['target_stock_pieces']);
        self::assertLessThan($r['values']['reorder_point_pieces'], $r['values']['target_stock_pieces']);
    }
}
