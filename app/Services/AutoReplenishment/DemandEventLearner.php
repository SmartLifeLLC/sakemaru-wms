<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/**
 * Learns event factors from past daily sales (product totals across stores).
 *
 * For each past event instance: ratio = actual ÷ pre-event mean (5〜1 weeks before the start,
 * other event days excluded, extended backwards to keep at least 14 observed days), per position.
 * Across training instances the median is kept. Pure; no database access.
 */
final class DemandEventLearner
{
    public const MODEL_VERSION = 'event-factors-v2'; // v2: block days are learned per day (d1, d2, …, last), not as one shared 'mid'

    public const PRE_WINDOW_FROM = 35;

    public const PRE_WINDOW_TO = 7;

    public const PRE_WINDOW_MAX = 63;

    public const MIN_PRE_DAYS = 14;

    public const MIN_PRE_PIECES = 1.0;

    /**
     * @param  array<string, int|float|null>  $daily  date => pieces (null = unobserved)
     * @param  array  $instance  one normalized event (DemandEventCalendar::normalize row)
     * @param  array  $yearEvents  all normalized events around that instance (their block days are excluded from the baseline)
     * @return array{positions: array<string, array{ratio: float, ratio_weekday: float, actual: float}>, pre_mean: float, pre_days: int, pieces: float}|null
     */
    public function instanceRatios(array $daily, array $instance, array $yearEvents, ?array $layout = null): ?array
    {
        $layout ??= $this->layout($instance, $yearEvents);
        $pre = [];
        $from = self::PRE_WINDOW_FROM;
        do {
            $pre = [];
            foreach ($layout['pre'] as $key => $meta) {
                if ($meta['offset'] > $from || ! isset($daily[$key]) || $daily[$key] === null) {
                    continue;
                }
                $pre[$key] = (float) $daily[$key];
            }
            $from += 7;
        } while (count($pre) < self::MIN_PRE_DAYS && $from <= self::PRE_WINDOW_MAX);
        if (count($pre) < self::MIN_PRE_DAYS) {
            return null;
        }
        $preMean = array_sum($pre) / count($pre);
        if ($preMean < self::MIN_PRE_PIECES / 7) {
            return null;
        }
        $byWeekday = array_fill(0, 7, []);
        foreach ($pre as $key => $value) {
            $byWeekday[$layout['pre'][$key]['weekday']][] = $value;
        }
        $positions = [];
        $pieces = 0.0;
        foreach ($layout['positions'] as $date => $position) {
            $actual = $daily[$date] ?? null;
            if ($actual === null) {
                continue;
            }
            $weekday = $byWeekday[$position['weekday']];
            $weekdayMean = count($weekday) >= 2 ? array_sum($weekday) / count($weekday) : $preMean;
            $positions[$position['position']] = ['ratio' => (float) $actual / $preMean,
                'ratio_weekday' => $weekdayMean > 0 ? (float) $actual / $weekdayMean : (float) $actual / $preMean, 'actual' => (float) $actual];
            if ($position['in_block']) {
                $pieces += (float) $actual;
            }
        }
        if (! $positions) {
            return null;
        }

        return ['positions' => $positions, 'pre_mean' => $preMean, 'pre_days' => count($pre), 'pieces' => $pieces];
    }

    /** Date layout of one instance (excluded baseline dates, positions with weekday), reusable across products. */
    public function layout(array $instance, array $yearEvents): array
    {
        $calendar = app(DemandEventCalendar::class);
        $start = CarbonImmutable::parse($instance['start']);
        // The baseline skips every date another event adjusts (block and pre/post days), same definition as the recommendation.
        $excluded = array_flip($calendar->adjustedDates($yearEvents, $start->subDays(self::PRE_WINDOW_MAX)->toDateString(), $instance['end']));
        $span = (int) $start->subDays($instance['pre_days'])->diffInDays(CarbonImmutable::parse($instance['end'])->addDays($instance['post_days'])) + 1;
        $positions = [];
        foreach ($calendar->positions([$instance], $start->subDays($instance['pre_days'])->toDateString(), $span) as $date => $position) {
            $positions[$date] = ['position' => $position['position'], 'in_block' => $position['in_block'], 'weekday' => CarbonImmutable::parse($date)->dayOfWeek];
        }
        // Candidate baseline dates: 8〜63 days before the start, other event days excluded.
        $pre = [];
        for ($offset = self::PRE_WINDOW_TO + 1; $offset <= self::PRE_WINDOW_MAX; $offset++) {
            $day = $start->subDays($offset);
            if (! isset($excluded[$day->toDateString()])) {
                $pre[$day->toDateString()] = ['offset' => $offset, 'weekday' => $day->dayOfWeek];
            }
        }

        return ['excluded' => $excluded, 'positions' => $positions, 'pre' => $pre];
    }

    /**
     * Median per position across training instances of one profile key.
     *
     * @param  array<int, array>  $instances  non-null instanceRatios() results
     * @return array{positions: array<string, array{factor: float, factor_weekday: float, years: int}>, pieces: float, instance_count: int}|null
     */
    public function aggregate(array $instances): ?array
    {
        $instances = array_values(array_filter($instances));
        if (! $instances) {
            return null;
        }
        $ratios = [];
        $pieces = 0.0;
        foreach ($instances as $instance) {
            $pieces += $instance['pieces'];
            foreach ($instance['positions'] as $position => $values) {
                $ratios[$position]['ratio'][] = $values['ratio'];
                $ratios[$position]['ratio_weekday'][] = $values['ratio_weekday'];
            }
        }
        $positions = [];
        foreach ($ratios as $position => $values) {
            $positions[$position] = ['factor' => round(min(DemandEventForecast::MAX_FACTOR, $this->median($values['ratio'])), 4),
                'factor_weekday' => round(min(DemandEventForecast::MAX_FACTOR, $this->median($values['ratio_weekday'])), 4), 'years' => count($values['ratio'])];
        }

        return ['positions' => $positions, 'pieces' => round($pieces, 2), 'instance_count' => count($instances)];
    }

    /**
     * Category profile = median of the member products' factors per position.
     *
     * @param  array<int, array>  $itemProfiles  aggregate() results of products in the category
     */
    public function categoryProfile(array $itemProfiles, float $minimumPieces = 10.0): ?array
    {
        $members = array_values(array_filter($itemProfiles, fn ($p) => $p !== null && $p['pieces'] >= $minimumPieces));
        if (! $members) {
            return null;
        }
        $values = [];
        $pieces = 0.0;
        $instances = 0;
        foreach ($members as $profile) {
            $pieces += $profile['pieces'];
            $instances = max($instances, $profile['instance_count']);
            foreach ($profile['positions'] as $position => $factor) {
                $values[$position]['factor'][] = $factor['factor'];
                $values[$position]['factor_weekday'][] = $factor['factor_weekday'];
                $values[$position]['years'][] = $factor['years'];
            }
        }
        $positions = [];
        foreach ($values as $position => $lists) {
            $positions[$position] = ['factor' => round($this->median($lists['factor']), 4), 'factor_weekday' => round($this->median($lists['factor_weekday']), 4),
                'years' => (int) max($lists['years']), 'items' => count($lists['factor'])];
        }

        return ['positions' => $positions, 'pieces' => round($pieces, 2), 'instance_count' => $instances, 'item_count' => count($members)];
    }

    /** Profile key for an instance: a named 連休 profile when the same name occurs in enough training instances, else the type. */
    public function profileKeys(array $instance, array $namedCounts, int $minimumNamed = 3): array
    {
        if ($instance['type'] !== 'LONG_WEEKEND') {
            return [$instance['type']];
        }
        $keys = ['LONG_WEEKEND'];
        if (($namedCounts[$instance['name']] ?? 0) >= $minimumNamed) {
            array_unshift($keys, $instance['name']);
        }

        return $keys;
    }

    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $n = count($values);

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
