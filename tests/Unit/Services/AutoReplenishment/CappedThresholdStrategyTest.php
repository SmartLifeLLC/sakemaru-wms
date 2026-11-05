<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\CappedThresholdStrategy;
use App\Services\AutoReplenishment\ProposalSafety;
use Tests\TestCase;

class CappedThresholdStrategyTest extends TestCase
{
    public function test_superdry_can_recover_below_the_old_deadlock_boundary(): void
    {
        $policy = ['reorder_point_pieces' => 3336, 'min_order_pieces' => 576, 'order_multiple_pieces' => 192, 'max_order_pieces' => 1728];
        $r = app(CappedThresholdStrategy::class)->calculate($policy, 584, 0, 0, 1);
        self::assertSame('READY', $r['phase']);
        self::assertSame(1728, $r['quantity']);
        self::assertSame(2880, $r['required_quantity']);
        app(ProposalSafety::class)->validate($policy, $r, 1);
        $filled = app(CappedThresholdStrategy::class)->calculate($policy, 584, 3000, 0, 1);
        self::assertSame('NO_ORDER', $filled['phase']);
    }

    public function test_cap_respects_packing_and_cannot_go_below_minimum(): void
    {
        $policy = ['reorder_point_pieces' => 300, 'min_order_pieces' => 75, 'order_multiple_pieces' => 15, 'max_order_pieces' => 110];
        $r = app(CappedThresholdStrategy::class)->calculate($policy, 1, 0, 0, 12);
        self::assertSame('HOLD', $r['phase']); // LCM 60; upper bound fits only 60, below minimum 75.
        $policy['max_order_pieces'] = 140;
        $r = app(CappedThresholdStrategy::class)->calculate($policy, 1, 0, 0, 12);
        self::assertSame('READY', $r['phase']);
        self::assertSame(120, $r['quantity']);
        app(ProposalSafety::class)->validate($policy, $r, 12);
        self::assertSame(120, app(CappedThresholdStrategy::class)->calculate($policy, -1000, 0, 0, 12)['quantity']);
    }

    public function test_negative_stock_orders_whole_pallets_below_maximum_and_counts_existing_supply(): void
    {
        $p = ['reorder_point_pieces' => 6000, 'min_order_pieces' => 1728, 'order_multiple_pieces' => 1728, 'max_order_pieces' => 3000];
        $s = app(CappedThresholdStrategy::class);
        $r = $s->calculate($p, -6, 0, 0, 1);
        self::assertSame('READY', $r['phase']);
        self::assertSame(1728, $r['quantity']); // Never 3,000 or an inferred 1,536-piece partial pallet.
        app(ProposalSafety::class)->validate($p, $r, 1);
        self::assertSame('NO_ORDER', $s->calculate($p, -6, 6006, 0, 1)['phase']);
        $p['max_order_pieces'] = 1700;
        self::assertSame('HOLD', $s->calculate($p, -6, 0, 0, 1)['phase']);
    }
}
