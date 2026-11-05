<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\LogisticsPickingDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LogisticsPickingDateTest extends TestCase
{
    public static function dates(): array
    {
        return [
            'Monday after Sunday' => ['2026-10-19', 1, '2026-10-19'],
            'Tuesday' => ['2026-10-20', 1, '2026-10-19'],
            'same day' => ['2026-10-19', 0, '2026-10-19'],
            'Saturday permitted' => ['2026-10-19', 2, '2026-10-17'],
            'year boundary' => ['2027-01-01', 2, '2026-12-30'],
            'Sunday after a week' => ['2026-10-19', 8, '2026-10-19'],
        ];
    }

    #[DataProvider('dates')]
    public function test_existing_core_date_rules(string $arrival, int $lead, string $expected): void
    {
        self::assertSame($expected, (new LogisticsPickingDate)->resolve($arrival, $lead));
    }
}
