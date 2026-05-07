<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\WmsOrderCandidate;
use Tests\TestCase;

class WmsOrderConfirmationWaitingTableTest extends TestCase
{
    public function test_six_pack_order_quantity_is_rounded_up_to_multiple_of_four(): void
    {
        $method = new \ReflectionMethod(WmsOrderConfirmationWaitingTable::class, 'resolveSixPackOrderQuantity');
        $method->setAccessible(true);

        $candidate = new WmsOrderCandidate([
            'order_quantity' => 5,
            'suggested_quantity' => 30,
        ]);

        $this->assertSame(8, $method->invoke(null, $candidate));
    }

    public function test_six_pack_order_quantity_keeps_four_pack_minimum(): void
    {
        $method = new \ReflectionMethod(WmsOrderConfirmationWaitingTable::class, 'resolveSixPackOrderQuantity');
        $method->setAccessible(true);

        $candidate = new WmsOrderCandidate([
            'order_quantity' => 1,
            'suggested_quantity' => 6,
        ]);

        $this->assertSame(4, $method->invoke(null, $candidate));
    }
}
