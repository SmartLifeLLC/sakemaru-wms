<?php

namespace Tests\Unit\Services\AutoOrder;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Models\WmsIncomingReceivedDetail;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingPriceCheckSourceRecorder;
use App\Services\AutoOrder\IncomingReceiveService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class IncomingPriceCheckSourceRecorderTest extends TestCase
{
    public function test_p_box_price_resolves_received_amount_difference(): void
    {
        $payload = $this->comparisonPayload(
            new WmsOrderIncomingSchedule([
                'unit_price' => 90.5,
                'case_price' => 2172,
                'price_type' => 'PIECE',
            ]),
            new Item([
                'capacity_case' => 24,
                'p_box_price' => 200,
            ]),
            new WmsIncomingReceivedDetail([
                'd_pack_quantity' => 24,
                'd_case_quantity' => 0,
                'd_piece_quantity' => 24,
                'total_quantity' => 24,
            ]),
            98.83,
            2372.0,
        );

        $this->assertEqualsWithDelta(8.33, $payload['price_diff'], 0.0001);
        $this->assertEqualsWithDelta(98.8333, $payload['quantity_adjusted_unit_price'], 0.0001);
        $this->assertEqualsWithDelta(8.3333, $payload['quantity_adjusted_price_diff'], 0.0001);
        $this->assertSame(200.0, $payload['purchase_adjustment_amount']);
        $this->assertSame(1.0, $payload['purchase_adjustment_case_equivalent_quantity']);
        $this->assertSame(90.5, $payload['adjustment_adjusted_unit_price']);
        $this->assertSame(0.0, $payload['adjustment_adjusted_price_diff']);
        $this->assertFalse($payload['has_mismatch']);
    }

    public function test_quantity_adjustment_includes_case_and_piece_quantities(): void
    {
        $payload = $this->comparisonPayload(
            new WmsOrderIncomingSchedule([
                'unit_price' => 90.5,
                'case_price' => 2172,
                'price_type' => 'PIECE',
            ]),
            new Item([
                'capacity_case' => 24,
                'p_box_price' => 0,
            ]),
            new WmsIncomingReceivedDetail([
                'd_pack_quantity' => 24,
                'd_case_quantity' => 1,
                'd_piece_quantity' => 2,
                'total_quantity' => 26,
            ]),
            2353.0,
            2353.0,
        );

        $this->assertSame(26.0, $payload['quantity_adjusted_piece_quantity']);
        $this->assertSame(90.5, $payload['quantity_adjusted_unit_price']);
        $this->assertSame(0.0, $payload['quantity_adjusted_price_diff']);
        $this->assertFalse($payload['has_mismatch']);
    }

    public function test_mixed_case_and_piece_receipt_uses_piece_price_when_received_price_matches_unit_price(): void
    {
        $schedule = new WmsOrderIncomingSchedule([
            'unit_price' => 1473,
            'case_price' => 8838,
            'price_type' => 'CASE',
            'partner_case_price' => 1473,
            'quantity_type' => QuantityType::PIECE,
        ]);
        $detail = new WmsIncomingReceivedDetail([
            'd_pack_quantity' => 6,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 2,
            'total_quantity' => 8,
        ]);
        $priceType = new ReflectionMethod(IncomingReceiveService::class, 'receivedPriceType');
        $this->assertSame('PIECE', $priceType->invoke(new IncomingReceiveService, $schedule, $detail, 1473.0));

        $caseSchedule = new WmsOrderIncomingSchedule([
            'unit_price' => 1473,
            'case_price' => 8838,
            'quantity_type' => QuantityType::CASE,
        ]);
        $this->assertSame('CASE', $priceType->invoke(new IncomingReceiveService, $caseSchedule, $detail, 1473.0));

        $amount = new ReflectionMethod(IncomingPriceCheckSourceRecorder::class, 'receivedAmount');
        $receivedAmount = $amount->invoke(new IncomingPriceCheckSourceRecorder, $detail, null, 1473.0, 'PIECE');
        $this->assertSame(11784.0, $receivedAmount);

        $payload = $this->comparisonPayload(
            $schedule,
            new Item(['capacity_case' => 6, 'p_box_price' => 0]),
            $detail,
            1473.0,
            $receivedAmount,
        );
        $this->assertSame('PIECE', $payload['price_type']);
        $this->assertSame(0.0, $payload['price_diff']);
        $this->assertSame(0.0, $payload['quantity_adjusted_price_diff']);
        $this->assertFalse($payload['has_mismatch']);
    }

    public function test_mixed_case_and_piece_receipt_retains_real_piece_price_difference(): void
    {
        $schedule = new WmsOrderIncomingSchedule([
            'unit_price' => 1277,
            'case_price' => 7662,
            'price_type' => 'CASE',
            'partner_case_price' => 1451,
            'quantity_type' => QuantityType::PIECE,
        ]);
        $detail = new WmsIncomingReceivedDetail([
            'd_pack_quantity' => 6,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 1,
            'total_quantity' => 7,
        ]);
        $amount = new ReflectionMethod(IncomingPriceCheckSourceRecorder::class, 'receivedAmount');
        $receivedAmount = $amount->invoke(new IncomingPriceCheckSourceRecorder, $detail, null, 1451.0, 'PIECE');
        $this->assertSame(10157.0, $receivedAmount);

        $payload = $this->comparisonPayload(
            $schedule,
            new Item(['capacity_case' => 6, 'p_box_price' => 0]),
            $detail,
            1451.0,
            $receivedAmount,
        );
        $this->assertSame('PIECE', $payload['price_type']);
        $this->assertSame(174.0, $payload['price_diff']);
        $this->assertSame(174.0, $payload['quantity_adjusted_price_diff']);
        $this->assertTrue($payload['has_mismatch']);
    }

    public function test_mixed_case_and_piece_receipt_uses_case_equivalent_for_case_price(): void
    {
        $detail = new WmsIncomingReceivedDetail([
            'd_pack_quantity' => 6,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 2,
        ]);
        $amount = new ReflectionMethod(IncomingPriceCheckSourceRecorder::class, 'receivedAmount');

        $this->assertSame(11784.0, $amount->invoke(new IncomingPriceCheckSourceRecorder, $detail, null, 8838.0, 'CASE'));
    }

    public function test_case_only_receipt_can_still_use_piece_price(): void
    {
        $schedule = new WmsOrderIncomingSchedule([
            'unit_price' => 1473,
            'case_price' => 8838,
            'price_type' => 'CASE',
            'quantity_type' => QuantityType::PIECE,
        ]);
        $detail = new WmsIncomingReceivedDetail([
            'd_pack_quantity' => 6,
            'd_case_quantity' => 1,
            'd_piece_quantity' => 0,
            'total_quantity' => 6,
        ]);
        $priceType = new ReflectionMethod(IncomingReceiveService::class, 'receivedPriceType');
        $this->assertSame('PIECE', $priceType->invoke(new IncomingReceiveService, $schedule, $detail, 1473.0));

        $amount = new ReflectionMethod(IncomingPriceCheckSourceRecorder::class, 'receivedAmount');
        $receivedAmount = $amount->invoke(new IncomingPriceCheckSourceRecorder, $detail, null, 1473.0, 'PIECE');
        $this->assertSame(8838.0, $receivedAmount);

        $payload = $this->comparisonPayload(
            $schedule,
            new Item(['capacity_case' => 6, 'p_box_price' => 0]),
            $detail,
            1473.0,
            $receivedAmount,
        );
        $this->assertSame('PIECE', $payload['price_type']);
        $this->assertSame(0.0, $payload['price_diff']);
        $this->assertFalse($payload['has_mismatch']);
    }

    private function comparisonPayload(
        WmsOrderIncomingSchedule $schedule,
        Item $item,
        WmsIncomingReceivedDetail $detail,
        ?float $receivedPrice,
        ?float $receivedAmount,
    ): array {
        $schedule->setRelation('item', $item);

        $method = new ReflectionMethod(IncomingPriceCheckSourceRecorder::class, 'comparisonPayload');
        $method->setAccessible(true);

        return $method->invoke(
            new IncomingPriceCheckSourceRecorder,
            $schedule,
            $receivedPrice,
            $receivedAmount,
            $detail,
            null,
        );
    }
}
