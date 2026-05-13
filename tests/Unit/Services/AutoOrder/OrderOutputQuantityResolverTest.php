<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use Tests\TestCase;

class OrderOutputQuantityResolverTest extends TestCase
{
    public function test_six_pack_ordering_code_keeps_case_quantity_and_uses_packs_per_case_for_display_capacity(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);
        $this->setPrivateProperty($resolver, 'purchaseUnitPriceCache', [
            999006 => 215.0,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 1,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => 5160,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame('4901411004754', $output['ordering_code']);
        $this->assertSame(4, $output['display_capacity']);
        $this->assertSame(1, $output['order_quantity']);
        $this->assertSame(1, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    public function test_piece_quantity_is_converted_to_six_pack_case_quantity_for_output(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);
        $this->setPrivateProperty($resolver, 'purchaseUnitPriceCache', [
            999006 => 215.0,
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 25,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => 215,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame(2, $output['order_quantity']);
        $this->assertSame(2, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    public function test_190_piece_quantity_is_converted_to_eight_cases_for_six_pack_ordering_code(): void
    {
        $resolver = new OrderOutputQuantityResolver;
        $this->setPrivateProperty($resolver, 'orderingCodeInfoCache', [
            '999006:4901411004754' => (object) ['quantity' => 6],
        ]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 999006,
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 190,
            'ordering_code' => '4901411004754',
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame(4, $output['display_capacity']);
        $this->assertSame(8, $output['order_quantity']);
        $this->assertSame(8, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
