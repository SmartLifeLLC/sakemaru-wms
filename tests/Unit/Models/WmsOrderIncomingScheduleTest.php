<?php

namespace Tests\Unit\Models;

use App\Enums\AutoOrder\OrderSource;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsOrderIncomingSchedule;
use RuntimeException;
use Tests\TestCase;

class WmsOrderIncomingScheduleTest extends TestCase
{
    public function test_format_slip_number_uses_eleven_digit_yymmdd_sequence(): void
    {
        $slipNumber = WmsOrderIncomingSchedule::formatSlipNumber('2026-05-06', 1000);

        $this->assertSame('26050601000', $slipNumber);
        $this->assertSame(11, strlen($slipNumber));
    }

    public function test_format_slip_number_rejects_sequences_that_do_not_fit_eleven_digits(): void
    {
        $this->expectException(RuntimeException::class);

        WmsOrderIncomingSchedule::formatSlipNumber('2026-05-06', 100000);
    }

    public function test_purchase_transmission_scope_excludes_transfer_and_internal_contractors(): void
    {
        $query = WmsOrderIncomingSchedule::query()->forPurchaseTransmission();
        $sql = $query->toSql();

        $this->assertSame([
            OrderSource::AUTO->value,
            OrderSource::MANUAL->value,
            OrderSource::RECEIVED->value,
            TransmissionType::INTERNAL->value,
        ], $query->getBindings());
        $this->assertStringContainsString('transfer_candidate_id', $sql);
        $this->assertStringContainsString('source_warehouse_id', $sql);
        $this->assertStringContainsString('stock_transfer_id', $sql);
        $this->assertStringContainsString('wms_contractor_settings', $sql);
        $this->assertStringContainsString('transmission_type', $sql);
    }
}
