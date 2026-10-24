<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ShippingQuantitySet;
use Tests\TestCase;

class ShippingQuantitySetTest extends TestCase
{
    public function test_json_is_sorted_deduplicated_and_never_expands_to_unobserved_multiples(): void
    {
        $set = new ShippingQuantitySet;
        $q = $set->normalize('[1728,576,384,768,576]');
        self::assertSame([384, 576, 768, 1728], $q);
        self::assertSame(192, $set->gcd($q));
        self::assertSame(768, $set->floor($q, 1536)); // 1536 is not an approved actual quantity.
        self::assertSame(0, $set->floor($q, 383));
        self::assertSame(576, $set->floor($q, 800, 1, 288));
    }

    public function test_zero_negative_float_string_boolean_object_empty_and_overflow_are_rejected(): void
    {
        foreach ([[], [0], [-1], [1.5], ['384'], [true], '{"0":384}', [2147483648], ['a' => 384]] as $q) {
            try {
                (new ShippingQuantitySet)->normalize($q);
                self::fail('Invalid quantity accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('出荷数量候補', $e->getMessage());
            }
        }
    }

    public function test_shortage_adjustments_do_not_create_a_new_shipping_pattern(): void
    {
        $r = (new ShippingQuantitySet)->fromReceipts([
            ['pieces' => 384, 'source' => ['id' => 1]],
            ['pieces' => 576, 'source' => ['id' => 2]],
            ['pieces' => 576, 'source' => ['id' => 3]],
            ['pieces' => 11, 'has_shortage' => true, 'source' => ['id' => 4]],
        ], ['start' => '2026-08-01', 'end' => '2026-10-22']);
        self::assertSame([384, 576], $r['quantities']);
        self::assertSame([384 => 1, 576 => 2], $r['basis']['counts']);
        self::assertSame(1, $r['basis']['excluded_shortages']);
        self::assertSame([1, 2, 3], $r['basis']['sample_transfer_ids']);
    }
}
