<?php

namespace Tests\Unit\Filament;

use App\Enums\QuantityType;
use App\Filament\Resources\WmsOrderConfirmationWaiting\Tables\WmsOrderConfirmationWaitingTable;
use App\Models\WmsOrderCandidate;
use Tests\TestCase;

class WmsOrderConfirmationWaitingTableTest extends TestCase
{
    public function test_six_pack_case_order_quantity_uses_case_capacity_and_rounds_to_four_pack_lot(): void
    {
        $method = new \ReflectionMethod(WmsOrderConfirmationWaitingTable::class, 'resolveSixPackOrderQuantity');
        $method->setAccessible(true);

        $candidate = new WmsOrderCandidate([
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 1,
        ]);
        $candidate->setRelation('item', (object) [
            'capacity_case' => 24,
        ]);

        $this->assertSame(4, $method->invoke(null, $candidate));
    }

    public function test_six_pack_case_order_quantity_scales_by_case_count(): void
    {
        $method = new \ReflectionMethod(WmsOrderConfirmationWaitingTable::class, 'resolveSixPackOrderQuantity');
        $method->setAccessible(true);

        $candidate = new WmsOrderCandidate([
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 2,
        ]);
        $candidate->setRelation('item', (object) [
            'capacity_case' => 24,
        ]);

        $this->assertSame(8, $method->invoke(null, $candidate));
    }

    public function test_six_pack_piece_order_quantity_rounds_total_pieces_to_four_pack_lot(): void
    {
        $method = new \ReflectionMethod(WmsOrderConfirmationWaitingTable::class, 'resolveSixPackOrderQuantity');
        $method->setAccessible(true);

        $candidate = new WmsOrderCandidate([
            'quantity_type' => QuantityType::PIECE,
            'order_quantity' => 23,
        ]);

        $this->assertSame(4, $method->invoke(null, $candidate));
    }

    public function test_six_pack_conversion_is_idempotent_after_unit_price_is_converted(): void
    {
        $method = new \ReflectionMethod(WmsOrderConfirmationWaitingTable::class, 'resolveSixPackOrderQuantity');
        $method->setAccessible(true);

        $candidate = new WmsOrderCandidate([
            'quantity_type' => QuantityType::CASE,
            'order_quantity' => 4,
            'purchase_unit_price' => 1290,
        ]);
        $candidate->setRelation('item', (object) [
            'capacity_case' => 24,
            'current_price' => (object) [
                'purchase_unit_price' => 215,
            ],
        ]);

        $this->assertSame(4, $method->invoke(null, $candidate));
    }
}
