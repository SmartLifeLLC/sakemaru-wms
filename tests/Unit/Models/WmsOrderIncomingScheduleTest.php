<?php

namespace Tests\Unit\Models;

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
}
