<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ShippingLotProfile;
use PHPUnit\Framework\TestCase;

class ShippingLotProfileTest extends TestCase
{
    public function test_common_unit_is_separate_from_typical_batches_and_not_a_confirmed_pallet(): void
    {
        $rows = [];
        foreach ([1728, 1728, 1728, 768, 384, 576] as $i => $quantity) {
            $rows[] = ['warehouse_id' => $i < 3 ? 1 : 2, 'pieces' => $quantity, 'date' => '2026-10-'.(10 + $i)];
        }
        $r = (new ShippingLotProfile)->summarize($rows, 1, 24);
        self::assertFalse($r['pallet_confirmed']);
        self::assertSame(3, $r['own']['count']);
        self::assertSame(1728, $r['own']['gcd_pieces']);
        self::assertSame(192, $r['source_all_warehouses']['gcd_pieces']);
        self::assertSame(['pieces' => 1728, 'count' => 3, 'cases' => 72], $r['source_all_warehouses']['top'][0]);
        self::assertSame(2, $r['source_all_warehouses']['warehouses']);
    }

    public function test_empty_and_loose_piece_history_are_not_invented_as_cases(): void
    {
        $profile = new ShippingLotProfile;
        $empty = $profile->summarize([], 1, 24);
        self::assertSame([], $empty['own']['top']);
        $r = $profile->summarize([['warehouse_id' => 2, 'pieces' => 11, 'date' => '2026-10-01']], 1, 24);
        self::assertSame(0, $r['own']['count']);
        self::assertNull($r['source_all_warehouses']['top'][0]['cases']);
        self::assertSame(11, $r['source_all_warehouses']['minimum_pieces']);
    }
}
