<?php

namespace App\Services\AutoReplenishment;

use Carbon\Carbon;

/** Read-only delivery opportunities for recommendation quantities, never order creation. */
class RecommendationArrivalSchedule
{
    public const LOOKAHEAD_DAYS = 56;

    public function calculate(int $warehouseId, int $contractorId, string $channel, string $asOf, array $config): array
    {
        throw_unless(in_array($channel, ['EOS', 'LOGISTICS', 'FAX'], true), \RuntimeException::class, '発注方式を選択してください。');
        $schedule = app(GenerationSchedule::class);
        $weekdays = $schedule->weekdays($config);
        $start = Carbon::parse($asOf, 'Asia/Tokyo')->startOfDay();
        $orderDates = [];
        $generationDates = [];
        for ($offset = 0; $offset < self::LOOKAHEAD_DAYS; $offset++) {
            $day = $start->copy()->addDays($offset);
            if (! in_array($day->dayOfWeek, $weekdays, true)) {
                continue;
            }
            $key = $day->toDateString();
            $generationDates[$key] = $day;
            $time = $schedule->executionTimeForDate($key, $config);
            $orderDates[$key] = $channel === 'EOS' && ! $day->isSunday() && $time >= '13:00'
                ? $day->copy()->addDay() : $day->copy();
        }
        $calendar = app(ArrivalCalendar::class);
        $arrivals = $channel === 'FAX' ? $calendar->referenceArrivalDates($contractorId, $warehouseId, $orderDates)
            : $calendar->earliestArrivalDates($contractorId, $warehouseId, $orderDates);
        $opportunities = [];
        $distinct = [];
        $lead = 1;
        foreach ($generationDates as $key => $day) {
            $arrival = $arrivals[$key] ?? null;
            throw_unless($arrival instanceof Carbon && $arrival->gt($orderDates[$key]), \RuntimeException::class,
                '既存の配送設定から入荷可能日を算定できません。発注先の納品曜日・休日設定を確認してください。');
            // Preserve the original seven-calendar-day nominal lead calculation.
            // Extending the target's horizon must not change the reorder point.
            if ($day->lt($start->copy()->addDays(7))) {
                $lead = max($lead, (int) $day->diffInDays($arrival));
            }
            $arrivalDate = $arrival->toDateString();
            $distinct[$arrivalDate] = true;
            $opportunities[] = ['generation_date' => $key, 'order_base_date' => $orderDates[$key]->toDateString(), 'arrival_date' => $arrivalDate];
        }
        $dates = array_keys($distinct);
        sort($dates, SORT_STRING);
        throw_if(count($dates) < 2, \RuntimeException::class,
            '今後56日の設定から入荷間隔を算定できません。実行曜日と発注先の納品曜日・休日設定を確認してください。');
        $maximumGap = 0;
        for ($i = 1; $i < count($dates); $i++) {
            $maximumGap = max($maximumGap, (int) Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i])));
        }
        throw_unless($maximumGap >= 1 && $maximumGap <= self::LOOKAHEAD_DAYS, \RuntimeException::class,
            '入荷間隔が56日を超えるため、おすすめ設定を算定できません。発注先の納品曜日・休日設定を確認してください。');

        return [
            'start' => $start->toDateString(),
            'end' => $start->copy()->addDays(self::LOOKAHEAD_DAYS - 1)->toDateString(),
            'generation_count' => count($opportunities),
            'distinct_arrival_count' => count($dates),
            'maximum_gap_days' => $maximumGap,
            'nominal_lead_days' => $lead,
            'arrival_dates' => $dates,
            'opportunities' => $opportunities,
            'schedule_fingerprint' => $schedule->fingerprint($config),
        ];
    }
}
