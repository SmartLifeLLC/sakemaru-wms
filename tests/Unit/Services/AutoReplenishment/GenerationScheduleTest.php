<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\GenerationSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenerationScheduleTest extends TestCase
{
    #[DataProvider('scheduleCases')]
    public function test_generation_window_uses_the_correct_day_and_time(string $now, ?string $expected): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $actual = (new GenerationSchedule)->dueSlot([
            'execution_time' => '10:00', 'sunday_execution_time' => '22:00',
            'weekdays' => [0, 1, 2, 3, 4, 5, 6],
        ], CarbonImmutable::parse($now));
        self::assertSame($expected, $actual);
    }

    public static function scheduleCases(): array
    {
        return [
            'Monday' => ['2026-10-12T10:00:00+09:00', '2026-10-12:10:00'],
            'Tuesday' => ['2026-10-13T10:00:00+09:00', '2026-10-13:10:00'],
            'Wednesday' => ['2026-10-14T10:00:00+09:00', '2026-10-14:10:00'],
            'Thursday' => ['2026-10-15T10:00:00+09:00', '2026-10-15:10:00'],
            'Friday' => ['2026-10-16T10:00:00+09:00', '2026-10-16:10:00'],
            'Saturday' => ['2026-10-17T10:00:00+09:00', '2026-10-17:10:00'],
            'before weekday window' => ['2026-10-17T09:59:59+09:00', null],
            'catch-up boundary' => ['2026-10-17T10:15:00+09:00', '2026-10-17:10:00'],
            'after catch-up' => ['2026-10-17T10:15:01+09:00', null],
            'Sunday morning' => ['2026-10-18T10:00:00+09:00', null],
            'before Sunday window' => ['2026-10-18T21:59:59+09:00', null],
            'Sunday night' => ['2026-10-18T22:00:00+09:00', '2026-10-18:22:00'],
            'Sunday catch-up' => ['2026-10-18T22:15:00+09:00', '2026-10-18:22:00'],
            'after Sunday window' => ['2026-10-18T22:15:01+09:00', null],
            'UTC clock uses Japan Sunday' => ['2026-10-18T13:00:00Z', '2026-10-18:22:00'],
            'Sunday send does not regenerate' => ['2026-10-18T23:40:00+09:00', null],
        ];
    }

    public function test_legacy_settings_and_disabled_days_remain_compatible(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $now = CarbonImmutable::parse('2026-10-18T09:00:00+09:00');
        $config = ['execution_time' => '09:00', 'weekdays' => ['0']];
        self::assertSame('2026-10-18:09:00', (new GenerationSchedule)->dueSlot($config, $now));
        $config['weekdays'] = [1, 2, 3, 4, 5, 6];
        self::assertNull((new GenerationSchedule)->dueSlot($config, $now));
    }

    public function test_invalid_times_never_generate_orders(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        foreach (['24:00', '22:60', '9:00', '', null, ['22:00']] as $time) {
            self::assertNull((new GenerationSchedule)->dueSlot([
                'execution_time' => $time, 'sunday_execution_time' => $time, 'weekdays' => [0],
            ], CarbonImmutable::parse('2026-10-18T22:00:00+09:00')));
        }
    }
}
