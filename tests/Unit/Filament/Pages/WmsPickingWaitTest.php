<?php

namespace Tests\Unit\Filament\Pages;

use App\Enums\QuantityType;
use App\Filament\Pages\WmsPickingWait;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsPickingWaitTest extends TestCase
{
    #[Test]
    public function row_shortage_pieces_uses_case_capacity_for_allocation_shortage(): void
    {
        $page = new WmsPickingWait;
        $row = (object) [
            'ordered_qty' => 1,
            'ordered_qty_type' => QuantityType::CASE->value,
            'planned_qty' => 0,
            'planned_qty_type' => QuantityType::CASE->value,
            'picked_qty' => 0,
            'picked_qty_type' => QuantityType::CASE->value,
            'capacity_case' => 12,
            'capacity_carton' => 1,
            'has_picking_shortage' => false,
        ];

        $this->assertSame(12, $page->rowShortagePieces($row));
        $this->assertSame(
            ['case' => 1, 'piece' => 0, 'total' => 12],
            $page->quantityBreakdown($page->rowShortagePieces($row), QuantityType::PIECE->value, 12, false),
        );
    }

    #[Test]
    public function row_shortage_pieces_uses_picked_quantity_for_picking_shortage(): void
    {
        $page = new WmsPickingWait;
        $row = (object) [
            'ordered_qty' => 2,
            'ordered_qty_type' => QuantityType::CASE->value,
            'planned_qty' => 2,
            'planned_qty_type' => QuantityType::CASE->value,
            'picked_qty' => 1,
            'picked_qty_type' => QuantityType::CASE->value,
            'capacity_case' => 6,
            'capacity_carton' => 1,
            'has_picking_shortage' => true,
        ];

        $this->assertSame(6, $page->rowShortagePieces($row));
        $this->assertSame(
            ['case' => 1, 'piece' => 0, 'total' => 6],
            $page->quantityBreakdown($page->rowShortagePieces($row), QuantityType::PIECE->value, 6, false),
        );
    }
}
