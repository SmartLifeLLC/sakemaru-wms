<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\StrategyCalculator;
use Tests\TestCase;

class ShippingQuantityStrategyTest extends TestCase
{
    private function policy(array $extra = []): array
    {
        return $extra + ['channel' => 'LOGISTICS', 'strategy_key' => 'arrival_adaptive_v1', 'reorder_point_pieces' => 2000,
            'min_order_pieces' => 384, 'max_order_pieces' => 1728, 'order_multiple_pieces' => 192,
            'target_stock_pieces' => 2000, 'calculation_basis' => ['mean_daily' => 100, 'batch_quantity_pieces' => 768]];
    }

    private function snapshot(array $extra = []): array
    {
        return $extra + ['stock' => 584, 'incoming' => 0, 'claims' => 0, 'packing_unit' => 1,
            'business_date' => '2026-10-20', 'arrival_date' => '2026-10-21', 'next_arrival_date' => '2026-10-22',
            'shipping_unit' => ['mode' => 'SHIPMENT', 'quantities' => [384, 576, 768, 960, 1152, 1728]]];
    }

    public function test_it_selects_an_exact_candidate_and_never_fills_a_gap_with_an_inferred_multiple(): void
    {
        $r = app(StrategyCalculator::class)->calculate($this->policy(), $this->snapshot());
        self::assertSame('READY', $r['phase']);
        self::assertSame(1152, $r['quantity']); // Arrival deficit 1516, not 1536/1344.
        self::assertStringContainsString('候補', implode(' ', $r['formula']));
    }

    public function test_future_receipts_and_pending_orders_block_excess_replenishment(): void
    {
        foreach ([['incoming' => 2000], ['claims' => 2000], ['incoming' => 1200, 'claims' => 800]] as $supply) {
            $r = app(StrategyCalculator::class)->calculate($this->policy(), $this->snapshot($supply));
            self::assertSame('NO_ORDER', $r['phase']);
            self::assertSame(0, $r['quantity']);
        }
    }

    public function test_negative_stock_does_not_get_clamped_or_forecast_into_larger_debt(): void
    {
        $r = app(StrategyCalculator::class)->calculate($this->policy(), $this->snapshot(['stock' => -6]));
        self::assertSame(1728, $r['quantity']);
        self::assertStringContainsString('在庫残 -6', implode(' ', $r['formula']));
    }

    public function test_small_orders_wait_for_usual_quantity_unless_next_arrival_has_stockout_risk(): void
    {
        $p = $this->policy(['target_stock_pieces' => 1000]);
        $safe = app(StrategyCalculator::class)->calculate($p, $this->snapshot(['stock' => 500]));
        self::assertSame('NO_ORDER', $safe['phase']); // 576 fits but usual 768 should wait.
        $risk = app(StrategyCalculator::class)->calculate($p, $this->snapshot(['stock' => 500, 'next_arrival_date' => '2026-10-26']));
        self::assertSame('READY', $risk['phase']);
        self::assertSame(576, $risk['quantity']);
    }

    public function test_empty_or_malformed_profiles_and_wrong_strategy_fail_closed(): void
    {
        foreach ([[], [0], ['768']] as $q) {
            $r = app(StrategyCalculator::class)->calculate($this->policy(), $this->snapshot(['shipping_unit' => ['mode' => 'SHIPMENT', 'quantities' => $q]]));
            self::assertSame('HOLD', $r['phase']);
        }
    }

    public function test_all_ready_quantities_respect_candidates_caps_and_arrival_target_at_boundaries(): void
    {
        $strategy = app(StrategyCalculator::class);
        foreach ([-6, 0, 1, 383, 384, 500, 767, 768, 1000, 1727, 1728, 2000] as $stock) {
            foreach ([0, 384, 1728] as $incoming) {
                foreach ([576, 768, 1727, 1728] as $maximum) {
                    $p = $this->policy(['max_order_pieces' => $maximum]);
                    $s = $this->snapshot(['stock' => $stock, 'incoming' => $incoming, 'next_arrival_date' => '2026-10-27']);
                    $r = $strategy->calculate($p, $s);
                    if ($r['phase'] === 'READY') {
                        self::assertContains($r['quantity'], $s['shipping_unit']['quantities']);
                        self::assertLessThanOrEqual($maximum, $r['quantity']);
                        self::assertLessThanOrEqual(2000, ($stock < 0 ? $stock : max(0, $stock - 100)) + $incoming + $r['quantity']);
                        app(ProposalSafety::class)->validate($p, $r, 1, $s['shipping_unit']);
                    } else {
                        self::assertSame(0, $r['quantity']);
                    }
                }
            }
        }
    }

    public function test_final_safety_rejects_unregistered_quantity_even_when_it_is_a_gcd_multiple(): void
    {
        $this->expectExceptionMessage('出荷数量候補にありません');
        app(ProposalSafety::class)->validate($this->policy(), ['phase' => 'READY', 'reason' => 'test', 'quantity' => 1536], 1, $this->snapshot()['shipping_unit']);
    }
}
