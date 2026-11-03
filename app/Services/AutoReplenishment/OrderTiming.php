<?php

namespace App\Services\AutoReplenishment;

/** Automatic orders only: wait while a later scheduled run can deliver no later. */
final class OrderTiming
{
    public function selectLatest(array $opportunities): array
    {
        usort($opportunities, fn ($a, $b) => strcmp($a['generation_date'], $b['generation_date']));
        $selected = $deferred = [];
        $next = null;
        foreach (array_reverse($opportunities) as $opportunity) {
            if ($next !== null && $next['arrival_date'] <= $opportunity['arrival_date']) {
                $deferred[$opportunity['generation_date']] = $next;

                continue;
            }
            $selected[$opportunity['generation_date']] = $opportunity;
            $next = $opportunity;
        }
        ksort($selected);
        ksort($deferred);

        return ['opportunities' => array_values($selected), 'deferred' => $deferred];
    }

    /** Recheck the actual arrival (including a late run's EOS cutoff), not just the configured time. */
    public function decision(string $date, string $arrival, array $schedule, array $config): array
    {
        $next = null;
        foreach ($schedule['opportunities'] as $opportunity) {
            if ($opportunity['generation_date'] > $date) {
                $next = $opportunity;
                break;
            }
        }
        $enabled = in_array(\Carbon\CarbonImmutable::parse($date)->dayOfWeek, app(GenerationSchedule::class)->weekdays($config), true);
        $defer = ! $enabled || ($next !== null && $next['arrival_date'] <= $arrival);

        return ['eligible' => ! $defer, 'next_generation_date' => $next['generation_date'] ?? null,
            'next_arrival_date' => $next['arrival_date'] ?? null,
            'reason' => $defer ? ($next
                ? "入荷予定日 {$next['arrival_date']} に間に合う {$next['generation_date']} の実行時に在庫を再判定します。"
                : '倉庫の自動発注の実行曜日ではありません。') : null];
    }
}
