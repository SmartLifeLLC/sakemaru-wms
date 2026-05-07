<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Services\AutoOrder\OrderQuantityAdjustmentService;
use Tests\TestCase;

class OrderQuantityAdjustmentServiceTest extends TestCase
{
    public function test_auto_order_quantity_is_used_as_order_quantity_source(): void
    {
        $result = app(OrderQuantityAdjustmentService::class)->calculate(
            shortageQty: 23,
            purchaseUnit: 1,
            autoOrderQuantity: 384,
            maxStock: 0,
            calculatedStock: 0,
        );

        $this->assertSame('自動発注数', $result['source_label']);
        $this->assertSame(384, $result['order_quantity']);
    }

    public function test_max_stock_caps_order_quantity_without_exceeding_limit(): void
    {
        $result = app(OrderQuantityAdjustmentService::class)->calculate(
            shortageQty: 80,
            purchaseUnit: 12,
            autoOrderQuantity: 0,
            maxStock: 150,
            calculatedStock: 100,
        );

        $this->assertSame(50, $result['max_order_quantity']);
        $this->assertSame(48, $result['order_quantity']);
        $this->assertTrue($result['max_stock_adjusted']);
    }

    public function test_six_pack_keeps_four_pack_lot_when_rounding_up(): void
    {
        $result = app(OrderQuantityAdjustmentService::class)->calculate(
            shortageQty: 23,
            purchaseUnit: 1,
            autoOrderQuantity: 0,
            maxStock: 0,
            calculatedStock: 0,
            orderingUnitQty: 6,
        );

        $this->assertSame(24, $result['valid_order_unit']);
        $this->assertSame(24, $result['order_quantity']);
    }

    public function test_six_pack_max_stock_cap_keeps_four_pack_lot(): void
    {
        $result = app(OrderQuantityAdjustmentService::class)->calculate(
            shortageQty: 80,
            purchaseUnit: 1,
            autoOrderQuantity: 0,
            maxStock: 150,
            calculatedStock: 100,
            orderingUnitQty: 6,
        );

        $this->assertSame(50, $result['max_order_quantity']);
        $this->assertSame(48, $result['order_quantity']);
        $this->assertSame(0, $result['order_quantity'] % 24);
    }

    public function test_six_pack_returns_zero_when_max_stock_cannot_fit_one_four_pack_lot(): void
    {
        $result = app(OrderQuantityAdjustmentService::class)->calculate(
            shortageQty: 23,
            purchaseUnit: 1,
            autoOrderQuantity: 0,
            maxStock: 120,
            calculatedStock: 100,
            orderingUnitQty: 6,
        );

        $this->assertSame(20, $result['max_order_quantity']);
        $this->assertSame(0, $result['order_quantity']);
        $this->assertTrue($result['skipped_by_max_stock']);
    }
}
