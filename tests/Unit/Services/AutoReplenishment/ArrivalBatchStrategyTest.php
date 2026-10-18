<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ArrivalBatchStrategy;
use App\Services\AutoReplenishment\StrategyCalculator;
use Tests\TestCase;

class ArrivalBatchStrategyTest extends TestCase
{
    private function policy(array $values = []): array
    {
        return $values + ['reorder_point_pieces' => 2688, 'target_stock_pieces' => 2688,
            'min_order_pieces' => 1728, 'max_order_pieces' => 1728, 'order_multiple_pieces' => 192,
            'strategy_key' => 'arrival_batch_v1', 'calculation_basis' => ['mean_daily' => 461.3929]];
    }

    private function snapshot(array $values = []): array
    {
        return $values + ['stock' => 2387, 'incoming' => 120, 'claims' => 0, 'packing_unit' => 1,
            'business_date' => '2026-10-14', 'arrival_date' => '2026-10-15'];
    }

    public function test_tsuruga_case_waits_instead_of_adding_1728_to_an_already_sufficient_position(): void
    {
        $r = app(StrategyCalculator::class)->calculate($this->policy(), $this->snapshot());
        self::assertSame('NO_ORDER', $r['phase']);
        self::assertSame(0, $r['quantity']);
        self::assertStringContainsString('3774 ＞ 補充目標 2688', implode("\n", $r['formula']));
    }

    public function test_target_boundary_is_exact_and_does_not_round_away_excess_stock(): void
    {
        foreach ([1300 => 'READY', 1301 => 'READY', 1302 => 'NO_ORDER'] as $stock => $phase) {
            $r = app(ArrivalBatchStrategy::class)->calculate($this->policy(), $this->snapshot(['stock' => $stock]));
            self::assertSame($phase, $r['phase']);
            self::assertSame($phase === 'READY' ? 1728 : 0, $r['quantity']);
        }
    }

    public function test_existing_calendar_arrival_date_changes_the_order_timing(): void
    {
        $strategy = app(ArrivalBatchStrategy::class);
        self::assertSame('NO_ORDER', $strategy->calculate($this->policy(), $this->snapshot())['phase']);
        self::assertSame('READY', $strategy->calculate($this->policy(), $this->snapshot(['arrival_date' => '2026-10-18']))['phase']);
    }

    public function test_future_supply_is_not_spent_by_the_consumption_forecast(): void
    {
        foreach ([['incoming' => 1728], ['claims' => 1728]] as $supply) {
            $r = app(ArrivalBatchStrategy::class)->calculate($this->policy(), $this->snapshot($supply + ['stock' => 0, 'incoming' => 0, 'claims' => 0]));
            self::assertSame('NO_ORDER', $r['phase']);
            self::assertSame(0, $r['quantity']);
        }
    }

    public function test_empty_stock_can_order_one_batch_without_manufacturing_negative_inventory(): void
    {
        $r = app(ArrivalBatchStrategy::class)->calculate($this->policy(), $this->snapshot(['stock' => 0, 'incoming' => 0]));
        self::assertSame('READY', $r['phase']);
        self::assertSame(1728, $r['quantity']);
        self::assertStringContainsString('在庫残 0', implode("\n", $r['formula']));
    }

    public function test_invalid_or_missing_forecast_holds_without_unguarded_fallback(): void
    {
        foreach ([null, 0, -1, INF, NAN, 'invalid', 2147483648] as $mean) {
            $r = app(ArrivalBatchStrategy::class)->calculate($this->policy(['calculation_basis' => ['mean_daily' => $mean]]), $this->snapshot());
            self::assertSame('HOLD', $r['phase']);
            self::assertSame(0, $r['quantity']);
        }
    }

    public function test_bad_or_extreme_arrival_dates_hold(): void
    {
        foreach (['2026-10-13', '2026-10-29', '2026-02-31', null, 'bad'] as $date) {
            $r = app(ArrivalBatchStrategy::class)->calculate($this->policy(), $this->snapshot(['arrival_date' => $date]));
            self::assertSame('HOLD', $r['phase']);
        }
    }

    public function test_units_caps_negative_stock_and_current_threshold_are_respected(): void
    {
        $s = app(ArrivalBatchStrategy::class);
        self::assertSame('HOLD', $s->calculate($this->policy(['max_order_pieces' => 100]), $this->snapshot())['phase']);
        self::assertSame('HOLD', $s->calculate($this->policy(), $this->snapshot(['stock' => -1]))['phase']);
        self::assertSame('HOLD', $s->calculate($this->policy(), $this->snapshot(['packing_unit' => 1000]))['phase']);
        self::assertSame('NO_ORDER', $s->calculate($this->policy(), $this->snapshot(['stock' => 2688]))['phase']);
        self::assertSame('READY', $s->calculate($this->policy(), $this->snapshot(['stock' => 0, 'incoming' => 0, 'packing_unit' => 24]))['phase']);
    }

    public function test_arrival_target_is_independent_of_a_long_lead_reorder_threshold(): void
    {
        $r = app(ArrivalBatchStrategy::class)->calculate($this->policy(['reorder_point_pieces' => 5760]), $this->snapshot());
        self::assertSame('NO_ORDER', $r['phase']);
        self::assertSame(0, $r['quantity']);
    }
}
