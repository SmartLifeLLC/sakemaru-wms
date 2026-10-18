<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonInterface;

class GenerationSchedule
{
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
