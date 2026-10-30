<?php

namespace App\Services\AutoReplenishment\Replay;

use Carbon\CarbonImmutable;

/**
 * Arrival calendar for the replay: either a simple rule (fixed lead, no Sunday arrivals, optional
 * stop ranges) or dated opportunities supplied by a provider (today's contractor/warehouse settings
 * applied to past dates). Pure; the provider closure owns any database access.
 */
final class ReplayCalendar
{
    /** @var array<string, string|null> order date => arrival date */
    private array $arrivals = [];

    private function __construct(private ?\Closure $provider, private int $lead, private array $stops, private array $weekdays, private int $reviewInterval = 1) {}

    /**
     * @param  array<int, array{0: string, 1: string}>  $stops  inclusive date ranges without arrivals (例：['2025-12-31', '2026-01-03'])
     * @param  array<int>  $weekdays  generation weekdays (0=Sun … 6=Sat); default every day
     */
    public static function simple(int $lead, array $stops = [], array $weekdays = [0, 1, 2, 3, 4, 5, 6]): self
    {
        throw_unless($lead >= 1 && $lead <= 14, \RuntimeException::class, 'リード日数は1〜14日で指定してください。');

        return new self(null, $lead, $stops, $weekdays);
    }

    /**
     * @param  \Closure(string $asOf): array<int, array{generation_date: string, arrival_date: string}>  $provider  opportunities from $asOf for at least 56 days
     */
    public static function scheduled(\Closure $provider, int $reviewInterval = 1): self
    {
        throw_unless($reviewInterval >= 1 && $reviewInterval <= 7, \RuntimeException::class, '実行間隔は1〜7日で指定してください。');

        return new self($provider, 1, [], range(0, 6), $reviewInterval);
    }

    /** True for a calendar built from the store's delivery schedule (scheduled), false for a simple lead-day calendar. */
    public function isScheduled(): bool
    {
        return $this->provider !== null;
    }

    /**
     * Review interval in days as the production recommendation computes it (execution times included for
     * scheduled calendars, e.g. Saturday 10:00 → Sunday 22:00 = 2 days); simple calendars derive it from the weekdays.
     */
    public function reviewInterval(): int
    {
        if ($this->provider !== null) {
            return $this->reviewInterval;
        }
        $days = $this->weekdays;
        sort($days);
        $max = $days[0] + 7 - $days[count($days) - 1];
        for ($i = 1; $i < count($days); $i++) {
            $max = max($max, $days[$i] - $days[$i - 1]);
        }

        return max(1, min(7, $max));
    }

    public function isGenerationDay(string $date): bool
    {
        if ($this->provider !== null) {
            return $this->arrivalFor($date) !== null;
        }

        return in_array(CarbonImmutable::parse($date)->dayOfWeek, $this->weekdays, true);
    }

    /** Arrival date for an order generated on $date, or null when that day generates nothing. */
    public function arrivalFor(string $date): ?string
    {
        if (array_key_exists($date, $this->arrivals)) {
            return $this->arrivals[$date];
        }
        if ($this->provider !== null) {
            $this->load($date);

            return $this->arrivals[$date] ?? null;
        }
        if (! $this->isGenerationDay($date)) {
            return $this->arrivals[$date] = null;
        }
        $arrival = CarbonImmutable::parse($date)->addDays($this->lead);
        for ($i = 0; $i < 60 && ($arrival->isSunday() || $this->stopped($arrival->toDateString())); $i++) {
            $arrival = $arrival->addDay();
        }

        return $this->arrivals[$date] = $arrival->toDateString();
    }

    /** Next generation day strictly after $date with an arrival, within 21 days. */
    public function nextArrivalAfter(string $date): ?array
    {
        $day = CarbonImmutable::parse($date);
        for ($i = 1; $i <= 21; $i++) {
            $candidate = $day->addDays($i)->toDateString();
            $arrival = $this->arrivalFor($candidate);
            if ($arrival !== null) {
                return ['generation_date' => $candidate, 'arrival_date' => $arrival];
            }
        }

        return null;
    }

    /** The recommendation's arrival schedule shape for the calculator, from $asOf over $days days. */
    public function schedule(string $asOf, int $days = 56): array
    {
        $start = CarbonImmutable::parse($asOf);
        $opportunities = [];
        $distinct = [];
        $lead = 1;
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->addDays($offset)->toDateString();
            $arrival = $this->arrivalFor($date);
            if ($arrival === null) {
                continue;
            }
            if ($offset < 7) {
                $lead = max($lead, (int) CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($arrival)));
            }
            $distinct[$arrival] = true;
            $opportunities[] = ['generation_date' => $date, 'order_base_date' => $date, 'arrival_date' => $arrival];
        }
        $dates = array_keys($distinct);
        sort($dates, SORT_STRING);
        throw_if(count($dates) < 2, \RuntimeException::class, "{$asOf} からの入荷予定を算定できません。");
        $gap = 0;
        for ($i = 1; $i < count($dates); $i++) {
            $gap = max($gap, (int) CarbonImmutable::parse($dates[$i - 1])->diffInDays(CarbonImmutable::parse($dates[$i])));
        }
        $weekdays = array_values(array_unique(array_map(fn ($o) => CarbonImmutable::parse($o['generation_date'])->dayOfWeek, $opportunities)));
        sort($weekdays);

        return ['start' => $asOf, 'end' => $start->addDays($days - 1)->toDateString(), 'generation_count' => count($opportunities),
            'distinct_arrival_count' => count($dates), 'maximum_gap_days' => max(1, $gap), 'nominal_lead_days' => $lead,
            'arrival_dates' => $dates, 'opportunities' => $opportunities, 'schedule_fingerprint' => 'replay', 'weekdays' => $weekdays];
    }

    private function stopped(string $date): bool
    {
        foreach ($this->stops as [$from, $to]) {
            if ($date >= $from && $date <= $to) {
                return true;
            }
        }

        return false;
    }

    private function load(string $date): void
    {
        $rows = ($this->provider)($date);
        $from = CarbonImmutable::parse($date);
        for ($i = 0; $i < 56; $i++) {
            $this->arrivals[$from->addDays($i)->toDateString()] = null;
        }
        foreach ($rows as $row) {
            $this->arrivals[$row['generation_date']] = $row['arrival_date'];
        }
    }
}
