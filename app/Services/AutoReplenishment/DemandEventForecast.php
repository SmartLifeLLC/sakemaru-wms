<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/**
 * Dated demand forecast = weekday level × learned event factors, attached to a demand profile.
 *
 * Factors come from the product's own history first and fall back to its category
 * (shrinkage: w = pieces / (pieces + 10), geometric blend). Calendar-aligned events use
 * the weekday-neutral factor; block days use the larger of the neutral and the weekday
 * path; pre/post days keep their weekday type. Pure; no database access.
 */
final class DemandEventForecast
{
    public const VERSION = 'event-forecast-v1';

    public const HORIZON_DAYS = 91;

    public const SHRINK_PIECES = 10.0;

    public const MAX_FACTOR = 20.0;

    /**
     * @param  array  $events  approved events (DemandEventCalendar rows)
     * @param  array  $factors  ['item' => [key => ['pieces' => float, 'positions' => [pos => ['factor' => f, 'factor_weekday' => fw]]]], 'category' => [...]]
     */
    public function attach(array $profile, array $events, array $factors, int $horizon = self::HORIZON_DAYS): array
    {
        throw_unless($horizon >= 1 && $horizon <= 120, \RuntimeException::class, '予測日数を確認してください。');
        unset($profile['event_forecast']);
        $calendar = app(DemandEventCalendar::class);
        $positions = $calendar->positions($events, $profile['anchor_date'], $horizon);
        if (! $positions) {
            return $profile;
        }
        $anchor = CarbonImmutable::parse($profile['anchor_date']);
        $mean = (float) $profile['mean'];
        $days = [];
        $uplift = [];
        $sources = [];
        $applied = false;
        for ($i = 0; $i < $horizon; $i++) {
            $date = $anchor->addDays($i);
            $key = $date->toDateString();
            $base = (float) $profile['weekdays'][$date->dayOfWeek]['mean'];
            $value = $base;
            if (isset($positions[$key])) {
                $event = $positions[$key]['event'];
                $factor = $this->factor($factors, $event['profile_keys'], $positions[$key]['position']);
                $name = $event['name'].'|'.$event['start'];
                $sources[$name] = $this->betterSource($sources[$name] ?? 'none', $factor['source']);
                if ($factor['source'] === 'none') {
                    $value = $base; // no learned factor for this position: keep the weekday pattern
                } elseif ($event['align'] === 'CALENDAR') {
                    $applied = true;
                    $value = $mean * $factor['factor'];
                } elseif ($positions[$key]['in_block']) {
                    $applied = true;
                    $value = max($mean * $factor['factor'], $base * $factor['factor_weekday']);
                } else {
                    $applied = true;
                    $value = $base * $factor['factor_weekday'];
                }
                $uplift[$name] = ($uplift[$name] ?? 0.0) + ($value - $base);
            }
            // Unrounded: non-event days must carry exactly the weekday mean so the cumulative carry matches the weekday path.
            $days[$key] = $value;
        }
        if (! $applied) {
            return $profile; // approved events without any learned factor: weekday-only, as before
        }
        $summary = [];
        foreach ($calendar->within($events, $profile['anchor_date'], $anchor->addDays($horizon - 1)->toDateString()) as $event) {
            $name = $event['name'].'|'.$event['start'];
            $summary[] = ['type' => $event['type'], 'name' => $event['name'], 'start' => $event['start'], 'end' => $event['end'],
                'pre_days' => $event['pre_days'], 'post_days' => $event['post_days'], 'uplift_pieces' => (int) round($uplift[$name] ?? 0), 'factor_source' => $sources[$name] ?? 'none',
                'factors' => $this->factorIdentity($factors, $event['profile_keys'])];
        }
        $profile['event_forecast'] = ['version' => self::VERSION, 'model_version' => DemandEventLearner::MODEL_VERSION, 'start' => $profile['anchor_date'], 'days' => $days, 'events' => $summary];

        return $profile;
    }

    /** Events for which the product has at least one learned factor (own or category); only these adjust the level. */
    public function eventsWithFactors(array $events, array $factors): array
    {
        $keys = array_merge(array_keys($factors['item'] ?? []), array_keys($factors['category'] ?? []));

        return array_values(array_filter(app(DemandEventCalendar::class)->normalize($events), fn ($e) => array_intersect($e['profile_keys'], $keys) !== []));
    }

    /** Blended factor for one position: own value shrunk toward the category value. */
    public function factor(array $factors, array $keys, string $position): array
    {
        $own = $this->lookup($factors['item'] ?? [], $keys, $position);
        $category = $this->lookup($factors['category'] ?? [], $keys, $position);
        if ($own === null && $category === null) {
            return ['factor' => 1.0, 'factor_weekday' => 1.0, 'source' => 'none'];
        }
        if ($own === null) {
            return $category + ['source' => 'category'];
        }
        if ($category === null) {
            return $own + ['source' => 'item'];
        }
        $weight = $own['pieces'] / ($own['pieces'] + self::SHRINK_PIECES);
        $blend = fn (float $a, float $b) => min(self::MAX_FACTOR, exp($weight * log(max(1e-6, $a)) + (1 - $weight) * log(max(1e-6, $b))));

        return ['factor' => $blend($own['factor'], $category['factor']), 'factor_weekday' => $blend($own['factor_weekday'], $category['factor_weekday']),
            'source' => $weight >= 0.5 ? 'item' : 'category'];
    }

    /**
     * Which learned factors (item / category profile per key) this event was forecast with: training end, instances and a digest of
     * the positions, so a saved recommendation can be compared with a later one (a 2× → 3× factor is a different condition).
     */
    private function factorIdentity(array $factors, array $keys): array
    {
        $identity = [];
        foreach (['item', 'category'] as $scope) {
            foreach ($keys as $key) {
                $profile = $factors[$scope][$key] ?? null;
                if ($profile === null) {
                    continue;
                }
                // Canonical form (recursive key order, fixed float precision) so the same factors give the same digest whatever the
                // storage key order; pieces take part because they weight the blend with category factors.
                $positions = self::canonical(is_array($profile['positions'] ?? null) ? $profile['positions'] : []);
                $identity[$scope][$key] = ['trained_through' => $profile['trained_through'] ?? null, 'instance_count' => (int) ($profile['instance_count'] ?? 0),
                    'pieces' => round((float) ($profile['pieces'] ?? 0), 4), 'positions_digest' => hash('sha256', json_encode($positions))];
            }
        }

        return $identity;
    }

    /** Recursively key-sorted copy with floats rounded to 6 places: a comparable, storage-order-independent representation. */
    public static function canonical(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = is_array($v) ? self::canonical($v) : (is_float($v) ? round($v, 6) : $v);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    private function lookup(array $profiles, array $keys, string $position): ?array
    {
        foreach ($keys as $key) {
            $entry = $profiles[$key]['positions'][$position] ?? null;
            if ($entry === null) {
                continue;
            }
            $factor = $entry['factor'] ?? null;
            $weekday = $entry['factor_weekday'] ?? $factor;
            if (! is_numeric($factor) || ! is_finite((float) $factor) || $factor < 0 || ! is_numeric($weekday) || ! is_finite((float) $weekday) || $weekday < 0) {
                continue;
            }

            return ['factor' => min(self::MAX_FACTOR, (float) $factor), 'factor_weekday' => min(self::MAX_FACTOR, (float) $weekday),
                'pieces' => max(0.0, (float) ($profiles[$key]['pieces'] ?? 0))];
        }

        return null;
    }

    private function betterSource(string $current, string $candidate): string
    {
        $rank = ['none' => 0, 'category' => 1, 'item' => 2];

        return $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }
}
