<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\InventoryCountController;
use App\Models\WmsInventoryCountItem;
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

    public function test_item_payload_adds_ending_system_quantity_without_changing_existing_system_quantity(): void
    {
        $item = new WmsInventoryCountItem([
            'id' => 123,
            'item_id' => 0,
            'item_code' => 'TEST001',
            'item_name' => 'API payload test item',
            'system_quantity' => 5,
            'ending_system_quantity' => 8,
        ]);

        $controller = new InventoryCountController(new InventoryCountService);
        $method = new ReflectionMethod($controller, 'itemPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, $item, false);

        $this->assertSame(5, $payload['system_quantity']);
        $this->assertSame(5, $payload['system_quantity_start']);
        $this->assertSame(8, $payload['ending_system_quantity']);
        $this->assertSame(8, $payload['system_quantity_end']);
    }

    private function packageQuantity(object $row): int
    {
        $controller = new InventoryCountController(new InventoryCountService);
        $method = new ReflectionMethod($controller, 'packageQuantity');
        $method->setAccessible(true);

        return $method->invoke($controller, $row);
    }
}
