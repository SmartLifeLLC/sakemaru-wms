<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\StrategyCalculator;
use Tests\TestCase;

class AdaptiveArrivalStrategyTest extends TestCase
{
    private function policy(array $changes = []): array
    {
        return $changes + ['strategy_key' => 'arrival_adaptive_v1', 'reorder_point_pieces' => 6,
            'min_order_pieces' => 6, 'max_order_pieces' => 12, 'order_multiple_pieces' => 1,
            'target_stock_pieces' => 10, 'calculation_basis' => ['mean_daily' => .7143, 'batch_quantity_pieces' => 6]];
    }

    private function snapshot(array $changes = []): array
    {
        return $changes + ['stock' => 0, 'incoming' => 0, 'claims' => 0, 'packing_unit' => 1,
            'business_date' => '2026-10-14', 'arrival_date' => '2026-10-14', 'next_arrival_date' => '2026-10-15'];
    }

    private function calculate(array $policy, array $snapshot): array
    {
        $result = app(StrategyCalculator::class)->calculate($policy, $snapshot);
        app(ProposalSafety::class)->validate($policy, $result, $snapshot['packing_unit']);

        return $result;
    }

    public function test_agreed_six_ten_twelve_examples_and_existing_fixed_policy_remain_distinct(): void
    {
        foreach ([4 => 6, 0 => 10, -6 => 12] as $stock => $quantity) {
            $result = $this->calculate($this->policy(), $this->snapshot(['stock' => $stock]));
            self::assertSame('READY', $result['phase']);
            self::assertSame($quantity, $result['quantity']);
            self::assertSame(6, $this->calculate($this->policy(['strategy_key' => 'arrival_batch_v1']), $this->snapshot(['stock' => $stock]))['quantity']);
        }
    }

    public function test_both_incoming_and_claims_are_counted_before_raising_quantity(): void
    {
        foreach (['incoming', 'claims'] as $key) {
            $snapshot = $this->snapshot(['stock' => -6, $key => 6]);
            self::assertSame(10, $this->calculate($this->policy(), $snapshot)['quantity']);
            $snapshot[$key] = 12;
            self::assertSame('NO_ORDER', $this->calculate($this->policy(), $snapshot)['phase']);
            $snapshot[$key] = 16;
            self::assertSame('NO_ORDER', $this->calculate($this->policy(), $snapshot)['phase']);
        }
        self::assertSame(6, $this->calculate($this->policy(), $this->snapshot(['stock' => -6, 'incoming' => 4, 'claims' => 6]))['quantity']);
    }

    public function test_small_quantity_waits_until_usual_batch_unless_waiting_risks_stockout(): void
    {
        $p = $this->policy(['min_order_pieces' => 2]);
        self::assertSame('NO_ORDER', $this->calculate($p, $this->snapshot(['stock' => 5]))['phase']);
        self::assertSame(5, $this->calculate($p, $this->snapshot(['stock' => 5, 'next_arrival_date' => '2026-10-21']))['quantity']);
        self::assertSame('NO_ORDER', $this->calculate($p, $this->snapshot(['stock' => 6]))['phase']);
        $p['min_order_pieces'] = 6;
        self::assertSame('NO_ORDER', $this->calculate($p, $this->snapshot(['stock' => 5]))['phase']);
    }

    public function test_pallets_output_packs_and_non_multiple_maxima_never_exceed_the_holding_target(): void
    {
        $p = $this->policy(['reorder_point_pieces' => 100, 'min_order_pieces' => 6, 'max_order_pieces' => 25,
            'order_multiple_pieces' => 6, 'target_stock_pieces' => 30]);
        self::assertSame(24, $this->calculate($p, $this->snapshot())['quantity']);
        self::assertSame(18, $this->calculate($p, $this->snapshot(['stock' => 11]))['quantity']);
        self::assertSame(12, $this->calculate($p, $this->snapshot(['stock' => 11, 'packing_unit' => 4]))['quantity']);
        self::assertSame('NO_ORDER', $this->calculate($p, $this->snapshot(['stock' => 25]))['phase']);
        $p['order_multiple_pieces'] = $p['min_order_pieces'] = 1728;
        $p['max_order_pieces'] = 3456;
        $p['target_stock_pieces'] = 4000;
        self::assertSame(3456, $this->calculate($p, $this->snapshot(['stock' => -6, 'packing_unit' => 24]))['quantity']);
        self::assertSame(1728, $this->calculate($p, $this->snapshot(['stock' => -6, 'incoming' => 1728]))['quantity']);
        self::assertSame('NO_ORDER', $this->calculate($p, $this->snapshot(['stock' => -6, 'incoming' => 3456]))['phase']);
    }

    public function test_arrival_forecast_preserves_real_negative_stock_without_deepening_debt(): void
    {
        $snapshot = $this->snapshot(['stock' => -6, 'arrival_date' => '2026-10-16']);
        $result = $this->calculate($this->policy(), $snapshot);
        self::assertSame(12, $result['quantity']);
        self::assertStringContainsString('在庫残 -6', implode('', $result['formula']));
        self::assertStringContainsString('残りの不足 4バラ', implode('', $result['formula']));
        self::assertSame(12, $this->calculate($this->policy(), $this->snapshot(['stock' => -2147483648]))['quantity']);
    }

    public function test_invalid_conditions_hold_and_configured_threshold_still_applies(): void
    {
        foreach ([['stock' => null], ['stock' => -2147483649], ['incoming' => -1], ['claims' => -1], ['arrival_date' => 'bad']] as $changes) {
            self::assertSame('HOLD', $this->calculate($this->policy(), $this->snapshot($changes))['phase']);
        }
        foreach ([['min_order_pieces' => 13], ['order_multiple_pieces' => 0], ['calculation_basis' => []]] as $changes) {
            self::assertSame('HOLD', $this->calculate($this->policy($changes), $this->snapshot())['phase']);
        }
    }

    public function test_small_order_exception_keeps_all_future_supply_and_same_arrival_is_not_urgent(): void
    {
        $p = $this->policy(['min_order_pieces' => 1]);
        self::assertSame(4, $this->calculate($p, $this->snapshot(['stock' => 0, 'incoming' => 3, 'claims' => 3]))['quantity']);
        self::assertSame('NO_ORDER', $this->calculate($p, $this->snapshot(['stock' => 0, 'incoming' => 10]))['phase']);
        self::assertSame('NO_ORDER', $this->calculate($p, $this->snapshot(['stock' => 0, 'incoming' => 6, 'next_arrival_date' => '2026-10-14']))['phase']);
        foreach (['bad', '2026-02-30', '2026-10-13', '2026-11-05'] as $date) {
            self::assertSame('HOLD', $this->calculate($p, $this->snapshot(['stock' => 0, 'incoming' => 6, 'next_arrival_date' => $date]))['phase']);
        }
    }

    public function test_manual_target_and_maximum_edits_bound_the_normal_batch(): void
    {
        $p = $this->policy(['min_order_pieces' => 1, 'max_order_pieces' => 4, 'target_stock_pieces' => 4]);
        self::assertSame(4, $this->calculate($p, $this->snapshot())['quantity']);
        $p['max_order_pieces'] = 12;
        self::assertSame(4, $this->calculate($p, $this->snapshot())['quantity']);
    }

    public function test_daily_demand_is_grouped_without_stockouts(): void
    {
        $p = $this->policy(['reorder_point_pieces' => 20, 'min_order_pieces' => 1, 'max_order_pieces' => 12,
            'target_stock_pieces' => 16, 'calculation_basis' => ['mean_daily' => 1, 'batch_quantity_pieces' => 6]]);
        $stock = 16;
        $pending = 0;
        $orders = [];
        for ($day = 0; $day < 30; $day++) {
            $stock += $pending;
            $pending = 0;
            $result = $this->calculate($p, $this->snapshot(['stock' => $stock]));
            if ($result['phase'] === 'READY') {
                $orders[] = $pending = $result['quantity'];
            }
            $stock--;
            self::assertGreaterThanOrEqual(0, $stock);
        }
        self::assertSame([6, 6, 6, 6], $orders);
    }
}
