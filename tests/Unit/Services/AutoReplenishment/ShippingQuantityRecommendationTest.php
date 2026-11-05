<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\SettingsRecommendationCalculator;
use Tests\TestCase;

class ShippingQuantityRecommendationTest extends TestCase
{
    private function history(int $warehouse, int $mean = 100): array
    {
        $daily = [];
        for ($i = 1; $i <= 28; $i++) {
            $daily[sprintf('2026-09-%02d', $i)] = $mean;
        }
        $receipts = [];
        foreach ([384, 576, 576, 576, 576, 576, 576, 768] as $i => $q) {
            $receipts[] = ['warehouse_id' => 1, 'pieces' => $q, 'date' => sprintf('2026-09-%02d', $i + 1), 'lead_days' => 1];
            $receipts[] = ['warehouse_id' => 2, 'pieces' => 1728, 'date' => sprintf('2026-09-%02d', $i + 1), 'lead_days' => 1];
        }

        return ['scope' => ['channel' => 'LOGISTICS', 'warehouse_id' => $warehouse, 'packing_unit' => 1, 'capacity_case' => 24],
            'period' => ['start' => '2026-09-01', 'end' => '2026-09-28'], 'daily' => $daily, 'nominal_lead_days' => 1, 'receipts' => $receipts];
    }

    public function test_shared_large_quantities_do_not_raise_a_small_stores_usual_batch_or_target(): void
    {
        $calc = new SettingsRecommendationCalculator;
        $options = ['shipping_quantities' => [384, 576, 768, 1728, 3456]];
        $small = $calc->calculate($this->history(1), $options);
        $large = $calc->calculate($this->history(2), $options);
        self::assertTrue($small['available']);
        self::assertSame(576, $small['basis']['batch_quantity_pieces']);
        self::assertSame(768, $small['values']['max_order_pieces']);
        self::assertLessThan(1728, $small['values']['target_stock_pieces']);
        self::assertSame(1728, $large['basis']['batch_quantity_pieces']);
        self::assertSame(384, $large['values']['min_order_pieces']);
    }

    public function test_no_store_receipts_uses_sales_but_does_not_round_up_to_a_full_pallet(): void
    {
        $r = (new SettingsRecommendationCalculator)->calculate($this->history(3), ['shipping_quantities' => [384, 576, 1728]]);
        self::assertTrue($r['available']);
        self::assertSame('sales', $r['basis']['basis_type']);
        self::assertSame(576, $r['basis']['batch_quantity_pieces']);
        self::assertSame(576, $r['values']['max_order_pieces']);
        self::assertSame(384, $r['values']['min_order_pieces']);
    }

    public function test_small_sales_never_raise_the_holding_target_to_fit_the_first_pallet_candidate(): void
    {
        $r = (new SettingsRecommendationCalculator)->calculate($this->history(3, 10), ['shipping_quantities' => [1728]]);
        self::assertFalse($r['available']);
        self::assertSame([], $r['values']);
        self::assertStringContainsString('補充目標を増やさず', $r['basis']['reason']);
    }

    public function test_shortage_and_unregistered_receipts_do_not_change_the_normal_quantity(): void
    {
        $h = $this->history(1);
        for ($i = 0; $i < 20; $i++) {
            $h['receipts'][] = ['warehouse_id' => 1, 'pieces' => 1536, 'date' => '2026-09-25', 'lead_days' => 1];
            $h['receipts'][] = ['warehouse_id' => 1, 'pieces' => 1728, 'date' => '2026-09-26', 'lead_days' => 1, 'has_shortage' => true];
        }
        $r = (new SettingsRecommendationCalculator)->calculate($h, ['shipping_quantities' => [384, 576, 768, 1728]]);
        self::assertSame(576, $r['basis']['batch_quantity_pieces']);
        self::assertSame(768, $r['values']['max_order_pieces']);
    }
}
