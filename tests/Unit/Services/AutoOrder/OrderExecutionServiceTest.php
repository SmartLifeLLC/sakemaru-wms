<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\QuantityType;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderAuditService;
use App\Services\AutoOrder\OrderExecutionService;
use Tests\TestCase;

class OrderExecutionServiceTest extends TestCase
{
    public function test_pack_order_incoming_quantity_keeps_candidate_quantity_and_type(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
        $candidate = new WmsOrderCandidate([
            'item_id' => 100,
            'ordering_code' => '4901411004754',
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
        ]);

        $method = $reflection->getMethod('resolveIncomingQuantity');
        $method->setAccessible(true);

        [$quantity, $quantityType] = $method->invoke($service, $candidate);

        $this->assertSame(4, $quantity);
        $this->assertSame(QuantityType::CASE, $quantityType);
    }

    public function test_regular_case_order_incoming_quantity_stays_case_quantity(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
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

    public function test_ordering_unit_order_incoming_quantity_keeps_candidate_quantity_and_type(): void
    {
        $service = new OrderExecutionService($this->createMock(OrderAuditService::class));

        $reflection = new \ReflectionClass($service);
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
