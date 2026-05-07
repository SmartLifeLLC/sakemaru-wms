<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderAuditService;
use App\Services\AutoOrder\OrderExecutionService;
use Tests\TestCase;

class OrderExecutionServiceTest extends TestCase
{
    public function test_pack_order_incoming_quantity_is_saved_as_piece_quantity(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
        $cache = $reflection->getProperty('orderingUnitQtyCache');
        $cache->setAccessible(true);
        $cache->setValue($service, ['100:4901411004754' => 6]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 100,
            'ordering_code' => '4901411004754',
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
        ]);

        $method = $reflection->getMethod('resolveIncomingQuantity');
        $method->setAccessible(true);

        [$quantity, $quantityType] = $method->invoke($service, $candidate);

        $this->assertSame(24, $quantity);
        $this->assertSame(QuantityType::PIECE, $quantityType);
    }

    public function test_regular_case_order_incoming_quantity_stays_case_quantity(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
        $cache = $reflection->getProperty('orderingUnitQtyCache');
        $cache->setAccessible(true);
        $cache->setValue($service, ['100:4900000000000' => null]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 100,
            'ordering_code' => '4900000000000',
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
        ]);

        $method = $reflection->getMethod('resolveIncomingQuantity');
        $method->setAccessible(true);

        [$quantity, $quantityType] = $method->invoke($service, $candidate);

        $this->assertSame(4, $quantity);
        $this->assertSame(QuantityType::CASE, $quantityType);
    }

    public function test_non_six_pack_order_incoming_quantity_stays_case_quantity(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
        $cache = $reflection->getProperty('orderingUnitQtyCache');
        $cache->setAccessible(true);
        $cache->setValue($service, ['100:4900000000012' => 12]);

        $candidate = new WmsOrderCandidate([
            'item_id' => 100,
            'ordering_code' => '4900000000012',
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
        ]);

        $method = $reflection->getMethod('resolveIncomingQuantity');
        $method->setAccessible(true);

        [$quantity, $quantityType] = $method->invoke($service, $candidate);

        $this->assertSame(4, $quantity);
        $this->assertSame(QuantityType::CASE, $quantityType);
    }
}
