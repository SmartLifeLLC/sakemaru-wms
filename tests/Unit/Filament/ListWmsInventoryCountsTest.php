<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\WmsInventoryCount\Pages\ListWmsInventoryCounts;
use ReflectionMethod;
use Tests\TestCase;

class ListWmsInventoryCountsTest extends TestCase
{
    public function test_inventory_count_create_action_is_available_as_header_action(): void
    {
        $page = new ListWmsInventoryCounts;
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $method->setAccessible(true);

        $actions = $method->invoke($page);

        $this->assertNotEmpty($actions);
        $this->assertSame('createInventoryCount', $actions[0]->getName());
        $this->assertSame('棚卸し作成', $actions[0]->getLabel());
    }
}
