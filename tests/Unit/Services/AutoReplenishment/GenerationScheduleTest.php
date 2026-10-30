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

    public function test_recommendation_defaults_do_not_change_scheduler_defaults(): void
    {
        $schedule = new GenerationSchedule;
        self::assertSame(range(0, 6), $schedule->weekdays([]));
        self::assertSame(range(0, 6), $schedule->weekdays(['weekdays' => null]));
        self::assertSame(2, $schedule->maxReviewIntervalDays([]));
        self::assertSame('10:00', $schedule->executionTimeForDate('2026-10-17', []));
        self::assertSame('22:00', $schedule->executionTimeForDate('2026-10-18', []));
        self::assertSame('09:00', $schedule->executionTimeForDate('2026-10-18', ['execution_time' => '09:00']));
        self::assertSame('21:30', $schedule->executionTimeForDate('2026-10-18', ['execution_time' => '09:00', 'sunday_execution_time' => '21:30']));
        self::assertSame('2026-10-19', $schedule->nextRunDate('2026-10-18', [])->toDateString());
        self::assertNull($schedule->dueSlot([], CarbonImmutable::parse('2026-10-18T22:00:00+09:00')));
    }

    #[DataProvider('reviewIntervals')]
    public function test_review_gaps_and_next_enabled_date_include_week_wrap(array $weekdays, int $gap, string $from, string $next): void
    {
        $schedule = new GenerationSchedule;
        self::assertSame($gap, $schedule->maxReviewIntervalDays(['weekdays' => $weekdays]));
        $date = $schedule->nextRunDate($from, ['weekdays' => $weekdays]);
        self::assertSame($next, $date->toDateString());
        self::assertSame('00:00:00', $date->format('H:i:s'));
        self::assertSame('Asia/Tokyo', $date->timezoneName);
    }

    public static function reviewIntervals(): array
    {
        return [
            'daily with Sunday evening' => [range(0, 6), 2, '2026-10-18', '2026-10-19'],
            'weekdays only' => [[1, 2, 3, 4, 5], 3, '2026-10-16', '2026-10-19'],
            'Monday and Thursday' => [[1, 4], 4, '2026-10-15', '2026-10-19'],
            'Sunday only is strictly next week' => [[0], 7, '2026-10-18', '2026-10-25'],
            'Saturday before Sunday' => [[0], 7, '2026-10-17', '2026-10-18'],
            'Sunday and Saturday' => [[0, 6], 6, '2026-10-18', '2026-10-24'],
            'serialized keys and duplicates' => [['4', '1', '4'], 4, '2026-10-18', '2026-10-19'],
        ];
    }

    public function test_weekdays_are_normalized_in_order(): void
    {
        self::assertSame([0, 1, 4], (new GenerationSchedule)->weekdays(['weekdays' => ['4', 1, '0', 4]]));
    }

    public function test_generation_clock_gap_is_rounded_up_to_whole_days(): void
    {
        $schedule = new GenerationSchedule;
        self::assertSame(1, $schedule->maxReviewIntervalDays(['execution_time' => '10:00', 'sunday_execution_time' => '10:00']));
        self::assertSame(2, $schedule->maxReviewIntervalDays(['execution_time' => '10:00', 'sunday_execution_time' => '22:00']));
        self::assertSame(2, $schedule->maxReviewIntervalDays(['execution_time' => '10:00', 'sunday_execution_time' => '10:01']));
        self::assertSame(7, $schedule->maxReviewIntervalDays(['weekdays' => [1], 'execution_time' => '10:00']));
        self::assertSame(7, $schedule->maxReviewIntervalDays(['weekdays' => [0, 1], 'execution_time' => '10:00', 'sunday_execution_time' => '22:00']));
    }

    public function test_fingerprint_normalizes_weekdays_and_detects_clock_changes(): void
    {
        $schedule = new GenerationSchedule;
        self::assertSame($schedule->fingerprint([]), $schedule->fingerprint([
            'weekdays' => ['6', 5, 4, 3, 2, 1, '0', 1], 'execution_time' => '10:00', 'sunday_execution_time' => '22:00',
        ]));
        self::assertNotSame($schedule->fingerprint([]), $schedule->fingerprint(['weekdays' => [1, 4]]));
        self::assertNotSame($schedule->fingerprint([]), $schedule->fingerprint(['execution_time' => '11:00', 'sunday_execution_time' => '22:00']));
        self::assertNotSame($schedule->fingerprint([]), $schedule->fingerprint(['sunday_execution_time' => '10:00']));
    }

    #[DataProvider('invalidWeekdays')]
    public function test_explicit_invalid_or_empty_weekdays_are_not_assumed_daily(mixed $weekdays): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('倉庫の実行曜日');
        (new GenerationSchedule)->weekdays(['weekdays' => $weekdays]);
    }

    public static function invalidWeekdays(): array
    {
        return [[[]], [['Monday']], [[7]], [[-1]], [[true]], [[1.0]], ['1,2,3'], [['01']], [[null]]];
    }

    public function test_recommendation_rejects_an_invalid_generation_time(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('倉庫の生成時刻が不正です');
        (new GenerationSchedule)->executionTimeForDate('2026-10-18', ['sunday_execution_time' => '24:00']);
    }
}
