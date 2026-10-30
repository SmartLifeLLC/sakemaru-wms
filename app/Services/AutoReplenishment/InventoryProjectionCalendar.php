<?php

namespace App\Services\AutoReplenishment;

use Carbon\Carbon;

/** Chart adapter for the same arrival calendar used by recommendations and execution. */
class InventoryProjectionCalendar
{
    public function read(int $warehouse, int $contractor, string $channel, string $date, array $config, int $lookahead = RecommendationArrivalSchedule::LOOKAHEAD_DAYS): array
    {
        $schedule = app(RecommendationArrivalSchedule::class)->calculate($warehouse, $contractor, $channel, $date, $config, $lookahead);

        $delivery = app(ArrivalCalendar::class)->deliveryDays($contractor, $warehouse);
        $delivery['reference_assumed'] = $channel === 'FAX' && $delivery['weekdays'] === [];
        if ($delivery['reference_assumed']) {
            $delivery['weekdays'] = range(1, 6);
        }

        return ['arrivals' => array_column($schedule['opportunities'], 'arrival_date', 'generation_date'), 'adjusted' => false]
            + $delivery;
    }

    public function normalize(array $opportunities, array $allowed, array $holidays): array
    {
        $result = [];
        $adjusted = false;
        foreach ($opportunities as $entry) {
            $arrival = app(ArrivalCalendar::class)->nextAllowed(Carbon::parse($entry['arrival_date']), $allowed, $holidays);
            throw_unless($arrival, \RuntimeException::class, '28日以内に入荷可能日がありません。納品曜日・休日設定を確認してください。');
            $adjusted = $adjusted || $arrival->toDateString() !== $entry['arrival_date'];
            $result[$entry['generation_date']] = $arrival->toDateString();
        }
        ksort($result);

        return ['arrivals' => $result, 'adjusted' => $adjusted];
    }
}
