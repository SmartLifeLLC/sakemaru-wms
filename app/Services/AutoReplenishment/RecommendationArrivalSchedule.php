<?php

namespace App\Services\AutoReplenishment;

use Carbon\Carbon;

/** Read-only delivery opportunities for recommendation quantities, never order creation. */
class RecommendationArrivalSchedule
{
    public const LOOKAHEAD_DAYS = 56;

    /** @param  int  $lookahead  calendar days to scan; the recommendation default is 56, the 10-week chart passes a longer span */
    public function calculate(int $warehouseId, int $contractorId, string $channel, string $asOf, array $config, int $lookahead = self::LOOKAHEAD_DAYS): array
    {
        throw_unless(in_array($channel, ['EOS', 'LOGISTICS', 'FAX'], true), \RuntimeException::class, '発注方式を選択してください。');
        throw_unless($lookahead >= self::LOOKAHEAD_DAYS && $lookahead <= 120, \RuntimeException::class, '入荷予定の算定日数を確認してください。');
        $schedule = app(GenerationSchedule::class);
        $weekdays = $schedule->weekdays($config);
        $start = Carbon::parse($asOf, 'Asia/Tokyo')->startOfDay();
        $orderDates = [];
        $generationDates = [];
        // Include the tail so the final display day is not mistaken for the last chance to order.
        for ($offset = 0; $offset < $lookahead + self::LOOKAHEAD_DAYS; $offset++) {
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
        $candidates = [];
        foreach ($generationDates as $key => $day) {
            $arrival = $arrivals[$key] ?? null;
            throw_unless($arrival instanceof Carbon && $arrival->gt($orderDates[$key]), \RuntimeException::class,
                '既存の配送設定から入荷可能日を算定できません。発注先の納品曜日・休日設定を確認してください。');
            $candidates[] = ['generation_date' => $key, 'order_base_date' => $orderDates[$key]->toDateString(), 'arrival_date' => $arrival->toDateString()];
        }
        $selection = app(OrderTiming::class)->selectLatest($candidates);
        $end = $start->copy()->addDays($lookahead - 1)->toDateString();
        $opportunities = array_values(array_filter($selection['opportunities'], fn ($o) => $o['generation_date'] <= $end));
        $distinct = [];
        $lead = 1;
        $reviewInterval = 1;
        $previous = null;
        foreach ($opportunities as $opportunity) {
            $key = $opportunity['generation_date'];
            $day = $generationDates[$key];
            $arrival = $arrivals[$key];
            throw_if($arrival->gte($start->copy()->addDays($lookahead + self::LOOKAHEAD_DAYS)), \RuntimeException::class,
                '発注日の逆算範囲を超えています。リードタイムと納品曜日を確認してください。');
            $execution = $day->copy()->setTimeFromTimeString($schedule->executionTimeForDate($key, $config));
            if ($previous !== null) {
                $reviewInterval = max($reviewInterval, (int) ceil($previous->diffInMinutes($execution) / 1440));
            }
            $previous = $execution;
            // Preserve the original seven-calendar-day nominal lead calculation.
            // Extending the target's horizon must not change the reorder point.
            if ($day->lt($start->copy()->addDays(7))) {
                $lead = max($lead, (int) $day->diffInDays($arrival));
            }
            $arrivalDate = $arrival->toDateString();
            $distinct[$arrivalDate] = true;
        }
        $dates = array_keys($distinct);
        sort($dates, SORT_STRING);
        throw_if(count($dates) < 2, \RuntimeException::class,
            "今後{$lookahead}日の設定から入荷間隔を算定できません。実行曜日と発注先の納品曜日・休日設定を確認してください。");
        $maximumGap = 0;
        for ($i = 1; $i < count($dates); $i++) {
            $maximumGap = max($maximumGap, (int) Carbon::parse($dates[$i - 1])->diffInDays(Carbon::parse($dates[$i])));
        }
        throw_unless($maximumGap >= 1 && $maximumGap <= self::LOOKAHEAD_DAYS, \RuntimeException::class,
            '入荷間隔が'.self::LOOKAHEAD_DAYS.'日を超えるため、おすすめ設定を算定できません。発注先の納品曜日・休日設定を確認してください。');

        return [
            'start' => $start->toDateString(),
            'end' => $start->copy()->addDays($lookahead - 1)->toDateString(),
            'generation_count' => count($opportunities),
            'distinct_arrival_count' => count($dates),
            'maximum_gap_days' => $maximumGap,
            'nominal_lead_days' => $lead,
            'review_interval_days' => $reviewInterval,
            'review_weekdays' => array_values(array_unique(array_map(fn ($o) => Carbon::parse($o['generation_date'])->dayOfWeek, $opportunities))),
            'arrival_dates' => $dates,
            'opportunities' => $opportunities,
            'deferred' => array_filter($selection['deferred'], fn ($key) => $key <= $end, ARRAY_FILTER_USE_KEY),
            'schedule_fingerprint' => $schedule->fingerprint($config),
        ];
    }
}
