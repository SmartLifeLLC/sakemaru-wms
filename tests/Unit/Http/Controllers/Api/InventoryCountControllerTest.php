<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\InventoryCountController;
use App\Services\InventoryCount\InventoryCountService;
use ReflectionMethod;
use Tests\TestCase;

class InventoryCountControllerTest extends TestCase
{
    public function test_piece_jan_package_quantity_uses_item_capacity_case(): void
    {
        $this->assertSame(12, $this->packageQuantity((object) [
            'quantity_type' => 'PIECE',
            'package_quantity' => 1,
            'item_capacity_case' => 12,
        ]));
    }

    public function test_case_jan_package_quantity_uses_jan_quantity(): void
    {
        $this->assertSame(6, $this->packageQuantity((object) [
            'quantity_type' => 'CASE',
            'package_quantity' => 6,
            'item_capacity_case' => 12,
        ]));
    }

    public function test_piece_jan_package_quantity_falls_back_to_one(): void
    {
        $this->assertSame(1, $this->packageQuantity((object) [
            'quantity_type' => 'PIECE',
            'package_quantity' => 1,
            'item_capacity_case' => null,
        ]));
    }

    private function packageQuantity(object $row): int
    {
        $controller = new InventoryCountController(new InventoryCountService);
        $method = new ReflectionMethod($controller, 'packageQuantity');
        $method->setAccessible(true);

        return $method->invoke($controller, $row);
    }
}
