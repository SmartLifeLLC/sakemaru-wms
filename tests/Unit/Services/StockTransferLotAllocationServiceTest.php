<?php

namespace Tests\Unit\Services;

use App\Services\StockTransferLotAllocationService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class StockTransferLotAllocationServiceTest extends TestCase
{
    #[Test]
    public function piece_allocations_use_piece_count_as_wms_quantity(): void
    {
        $service = $this->service();

        $this->assertSame(192, $service->reservationQuantity(192, 1, 'PIECE'));
    }

    #[Test]
    public function case_allocations_convert_piece_count_to_case_quantity(): void
    {
        $service = $this->service();

        $unitSize = $service->unitSize('CASE', (object) ['capacity_case' => 24], null);

        $this->assertSame(24, $unitSize);
        $this->assertSame(8, $service->reservationQuantity(192, $unitSize, 'CASE'));
    }

    #[Test]
    public function case_allocations_reject_piece_count_that_cannot_be_represented_as_cases(): void
    {
        $service = $this->service();

        $this->expectException(RuntimeException::class);

        $service->reservationQuantity(191, 24, 'CASE');
    }

    #[Test]
    public function expected_pieces_prefers_trade_item_total_piece_quantity_snapshot(): void
    {
        $service = $this->service();

        $this->assertSame(
            192,
            $service->expectedPiecesFor((object) ['total_piece_quantity' => 192], 8, 24)
        );
    }

    #[Test]
    public function negative_source_lot_is_treated_as_stock_transfer_shortage(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isShortageSourceLot((object) ['source_lot_current_quantity' => -2]));
        $this->assertFalse($service->isShortageSourceLot((object) ['source_lot_current_quantity' => 0]));
        $this->assertFalse($service->isShortageSourceLot((object) ['source_lot_current_quantity' => 48]));
    }

    private function service(): object
    {
        return new class extends StockTransferLotAllocationService
        {
            public function reservationQuantity(int $pieces, int $unitSize, string $quantityType): int
            {
                return $this->reservationQuantityFromPieces($pieces, $unitSize, $quantityType, 1, 1);
            }

            public function unitSize(string $quantityType, object $tradeItem, ?object $item): int
            {
                return $this->unitSizeFor($quantityType, $tradeItem, $item);
            }

            public function expectedPiecesFor(object $tradeItem, int $needQty, int $unitSize): int
            {
                return $this->expectedPieces($tradeItem, $needQty, $unitSize);
            }

            public function isShortageSourceLot(object $allocation): bool
            {
                return $this->sourceLotRepresentsShortage($allocation);
            }
        };
    }
}
