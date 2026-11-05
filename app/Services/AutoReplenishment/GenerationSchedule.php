<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class GenerationSchedule
{
    /** Recommendation defaults match the warehouse editor; dueSlot retains its existing strict behavior. */
    public function weekdays(array $config): array
    {
        $days = $config['weekdays'] ?? range(0, 6);
        throw_unless(is_array($days) && $days !== [], \RuntimeException::class, '倉庫の実行曜日を1つ以上選択してください。');
        foreach ($days as $day) {
            throw_unless((is_int($day) && $day >= 0 && $day <= 6) || (is_string($day) && preg_match('/^[0-6]$/D', $day)),
                \RuntimeException::class, '倉庫の実行曜日が不正です。倉庫の自動発注設定を保存し直してください。');
        }
        $days = array_values(array_unique(array_map('intval', $days)));
        sort($days, SORT_NUMERIC);

        return $days;
    }

    public function maxReviewIntervalDays(array $config): int
    {
        $days = $this->weekdays($config);
        $minutes = [];
        foreach ($days as $day) {
            $date = CarbonImmutable::parse('2026-10-18', 'Asia/Tokyo')->addDays($day);
            [$hour, $minute] = array_map('intval', explode(':', $this->executionTimeForDate($date->toDateString(), $config)));
            $minutes[] = $day * 1440 + $hour * 60 + $minute;
        }
        $max = $minutes[0] + 7 * 1440 - $minutes[count($minutes) - 1];
        for ($i = 1; $i < count($minutes); $i++) {
            $max = max($max, $minutes[$i] - $minutes[$i - 1]);
        }

        // Daily generation can still leave 36 hours between Saturday 10:00 and Sunday 22:00.
        // Round up so the recommendation covers the whole interval to the next scheduled run.

        return (int) ceil($max / 1440);
    }

    public function nextRunDate(string $businessDate, array $config): CarbonImmutable
    {
        $days = $this->weekdays($config);
        $date = CarbonImmutable::parse($businessDate, 'Asia/Tokyo')->startOfDay()->addDay();
        while (! in_array($date->dayOfWeek, $days, true)) {
            $date = $date->addDay();
        }

        return $date;
    }

    public function executionTimeForDate(string $businessDate, array $config): string
    {
        $time = CarbonImmutable::parse($businessDate, 'Asia/Tokyo')->isSunday()
            ? ($config['sunday_execution_time'] ?? $config['execution_time'] ?? '22:00')
            : ($config['execution_time'] ?? '10:00');
        throw_unless(is_string($time) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time),
            \RuntimeException::class, '倉庫の生成時刻が不正です。「10:00」のように24時間表記で設定してください。');

        return $time;
    }

    public function fingerprint(array $config): string
    {
        return hash('sha256', json_encode([
            'weekdays' => $this->weekdays($config),
            'execution_time' => $this->executionTimeForDate('2026-10-19', $config),
            'sunday_execution_time' => $this->executionTimeForDate('2026-10-18', $config),
        ], JSON_THROW_ON_ERROR));
    }

    public function dueSlot(array $config, CarbonInterface $now): ?string
    {
        $now = $now->copy()->timezone(config('app.timezone'));
        if (! in_array($now->dayOfWeek, array_map('intval', $config['weekdays'] ?? []), true)) {
            return null;
        }
        // Existing single-time settings retain their time until explicitly saved.
        $time = $now->isSunday()
            ? ($config['sunday_execution_time'] ?? $config['execution_time'] ?? '')
            : ($config['execution_time'] ?? '');
        if (! is_string($time) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            return null;
        }
        $scheduled = $now->copy()->startOfDay()->setTimeFromTimeString($time);
        if ($now->lt($scheduled) || $scheduled->diffInSeconds($now) > 900) {
            return null;
        }

        return $now->format('Y-m-d').':'.$time;
    }
}
