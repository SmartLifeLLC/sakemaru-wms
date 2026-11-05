<?php

namespace Tests\Unit\Support;

use App\Support\OrderArrivalDate;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderArrivalDateTest extends TestCase
{
    #[DataProvider('validDates')]
    public function test_short_input_matches_core_date_convention(string $input, string $expected): void
    {
        $this->assertSame($expected, OrderArrivalDate::normalize($input, Carbon::parse('2026-10-24')));
    }

    public static function validDates(): array
    {
        return [['25', '2026-10-25'], ['１', '2026-10-01'], ['1105', '2026-11-05'], ['20261105', '2026-11-05'], ['2026/11/5', '2026-11-05'], ['2028-02-29', '2028-02-29']];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_are_not_rolled_over(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderArrivalDate::normalize($input, Carbon::parse('2026-10-24'));
    }

    public static function invalidDates(): array
    {
        return [[''], ['20260229'], ['2026/04/31'], ['00'], ['202610/20/日'], ['2026'], ['12345'], ['10000101x']];
    }

    #[DataProvider('allowedOrderDates')]
    public function test_order_dates_allow_today_through_the_day_before_six_months(string $basis, string $input, string $expected): void
    {
        $this->assertSame($expected, OrderArrivalDate::normalizeForOrder($input, Carbon::parse($basis)));
    }

    public static function allowedOrderDates(): array
    {
        return [
            ['2026-10-24', '24', '2026-10-24'],
            ['2026-10-24', '25', '2026-10-25'],
            ['2026-10-24', '20270423', '2027-04-23'],
            ['2026-08-31', '20270227', '2027-02-27'],
            ['2027-08-31', '20280228', '2028-02-28'],
        ];
    }

    #[DataProvider('rejectedOrderDates')]
    public function test_order_dates_reject_past_and_six_month_boundary(string $basis, string $input, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        OrderArrivalDate::normalizeForOrder($input, Carbon::parse($basis));
    }

    public static function rejectedOrderDates(): array
    {
        return [
            ['2026-10-24', '20261023', '過去日'],
            ['2026-10-24', '20270424', '6か月以上'],
            ['2026-10-24', '20270425', '6か月以上'],
            ['2026-08-31', '20270228', '6か月以上'],
            ['2027-08-31', '20280229', '6か月以上'],
        ];
    }

    public function test_calendar_month_limit_does_not_mutate_the_basis(): void
    {
        $basis = Carbon::parse('2026-08-31 12:34:56');
        $this->assertSame('2027-02-27', OrderArrivalDate::maximum($basis));
        $this->assertSame('2026-08-31 12:34:56', $basis->toDateTimeString());
    }
}
