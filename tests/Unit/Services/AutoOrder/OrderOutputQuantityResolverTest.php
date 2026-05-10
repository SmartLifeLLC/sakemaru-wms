<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use Tests\TestCase;

class OrderOutputQuantityResolverTest extends TestCase
{
    public function test_case_quantity_is_converted_to_six_pack_quantity_for_output(): void
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
        $this->assertSame(6, $output['display_capacity']);
        $this->assertSame(4, $output['order_quantity']);
        $this->assertSame(4, $output['case_quantity']);
        $this->assertSame(0, $output['piece_quantity']);
        $this->assertSame('ケース', $output['unit_label']);
    }

    public function test_already_converted_six_pack_quantity_is_not_converted_again(): void
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
            'order_quantity' => 4,
            'ordering_code' => '4901411004754',
            'purchase_unit_price' => 1290,
        ]);
        $candidate->setRelation('item', (object) [
            'id' => 999006,
            'capacity_case' => 24,
        ]);

        $output = $resolver->resolve($candidate);

        $this->assertSame(4, $output['order_quantity']);
        $this->assertSame(4, $output['case_quantity']);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
