<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ReorderPointGap;
use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Tests\TestCase;

class ReorderPointGapTest extends TestCase
{
    public function test_store_minimum_wins_over_two_days_and_sixteen_case_candidate(): void
    {
        $r = (new ReorderPointGap)->calculate(2883, 3456, 270.5357, 576, 24, 24, 0, [384, 576, 768, 1728], 1728);
        self::assertSame(2880, $r['point']);
        self::assertSame(576, $r['basis']['reorder_gap_required_pieces']);
        self::assertSame(3, $r['basis']['reorder_point_gap_reduction']);
        self::assertSame(576, $r['basis']['reorder_gap_minimum_pieces']);
    }

    public function test_two_days_and_pallet_minimum_can_each_determine_the_gap(): void
    {
        $demand = (new ReorderPointGap)->calculate(3000, 3456, 270.5357, 384, 24, 24);
        self::assertSame(552, $demand['basis']['reorder_gap_required_pieces']);
        self::assertSame(2904, $demand['point']);
        $pallet = (new ReorderPointGap)->calculate(3000, 3456, 270.5357, 384, 768, 24, 768);
        self::assertSame(768, $pallet['basis']['reorder_gap_required_pieces']);
        self::assertSame(2688, $pallet['point']);
    }

    public function test_registered_candidate_must_really_meet_minimum_maximum_and_unit(): void
    {
        $r = (new ReorderPointGap)->calculate(3000, 3456, 1, 600, 24, 24, 0, [384, 576, 700, 768, 1728], 1728);
        self::assertSame(768, $r['basis']['reorder_gap_minimum_pieces']);
        self::assertSame(2688, $r['point']);
        $this->expectException(\RuntimeException::class);
        (new ReorderPointGap)->calculate(3000, 3456, 1, 600, 24, 24, 0, [384, 576, 700, 768], 700);
    }

    public function test_sufficient_gap_does_not_raise_or_lower_point(): void
    {
        $r = (new ReorderPointGap)->calculate(100, 200, 10, 24, 24, 24);
        self::assertSame(100, $r['point']);
        self::assertSame(0, $r['basis']['reorder_point_gap_reduction']);
    }

    public function test_impossible_gap_does_not_expand_target_or_return_nonpositive_point(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ReorderPointGap)->calculate(100, 100, 60, 24, 24, 24);
    }

    public function test_long_lead_recommendation_caps_point_but_preserves_target_and_order_quantities(): void
    {
        $h = SafetyStockSettingsTest::history();
        $normal = SafetyStockSettingsTest::recommendation([], $h);
        $h['nominal_lead_days'] = 10;
        $r = SafetyStockSettingsTest::recommendation([], $h);
        self::assertTrue($r['available']);
        foreach (['target_stock_pieces', 'min_order_pieces', 'max_order_pieces', 'order_multiple_pieces'] as $key) {
            self::assertSame($normal['values'][$key], $r['values'][$key]);
        }
        self::assertSame(4806, $r['basis']['reorder_point_before_gap']);
        self::assertSame(3336, $r['values']['reorder_point_pieces']);
        self::assertSame(552, $r['basis']['reorder_gap_required_pieces']);
        self::assertFalse($r['basis']['reorder_point_preserved']);
        self::assertStringContainsString('最低ギャップ', implode('', $r['basis']['steps']));
    }

    public function test_standard_pallet_and_shipping_recommendations_all_keep_the_gap(): void
    {
        foreach ([['unit_mode' => 'CASE'], ['unit_mode' => 'PALLET', 'pallet_pieces' => 576],
            ['unit_mode' => 'AUTO', 'shipping_quantities' => [384, 576, 768, 1728]]] as $unit) {
            $r = app(SettingsRecommendationCalculator::class)->calculate(SafetyStockSettingsTest::history(), $unit + ['safety_stock_days' => 2]);
            self::assertTrue($r['available']);
            self::assertGreaterThanOrEqual($r['basis']['reorder_gap_required_pieces'], $r['values']['target_stock_pieces'] - $r['values']['reorder_point_pieces']);
        }
    }
}
