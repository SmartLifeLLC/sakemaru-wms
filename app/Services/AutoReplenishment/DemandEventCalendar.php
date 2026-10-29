<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;

/**
 * Pure calendar of approved demand events (年末年始・お盆・GW・連休・任意期間).
 *
 * Calendar-aligned events (年末年始・お盆) are identified by month-day; block-aligned events
 * (GW・連休・任意) are identified by their position inside the block and keep a few
 * pre/post days whose weekday type still matters. No database access.
 */
final class DemandEventCalendar
{
    public const TYPES = ['YEAR_END', 'OBON', 'GW', 'LONG_WEEKEND', 'CUSTOM'];

    public const CALENDAR_TYPES = ['YEAR_END', 'OBON'];

    public const NAMES = ['YEAR_END' => '年末年始', 'OBON' => 'お盆', 'GW' => 'ゴールデンウィーク', 'LONG_WEEKEND' => '連休', 'CUSTOM' => '任意イベント'];

    private const DEFAULT_PRE_DAYS = ['GW' => 3, 'LONG_WEEKEND' => 2, 'CUSTOM' => 2];

    /** Validate and normalize event rows (database rows, form input or fixtures). */
    public function normalize(array $events): array
    {
        $result = [];
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            throw_unless(in_array($type, self::TYPES, true), \RuntimeException::class, 'イベント種別を確認してください。');
            $start = $this->date((string) ($event['start'] ?? ''));
            $end = $this->date((string) ($event['end'] ?? ''));
            throw_if($start->gt($end) || $start->diffInDays($end) > 60, \RuntimeException::class, 'イベント期間は開始日以降・60日以内で設定してください。');
            $align = in_array($type, self::CALENDAR_TYPES, true) ? 'CALENDAR' : 'BLOCK';
            $pre = $align === 'CALENDAR' ? 0 : (int) ($event['pre_days'] ?? self::DEFAULT_PRE_DAYS[$type]);
            $post = $align === 'CALENDAR' ? 0 : (int) ($event['post_days'] ?? 1);
            throw_unless($pre >= 0 && $pre <= 7 && $post >= 0 && $post <= 7, \RuntimeException::class, 'イベント前後の日数は0〜7日で設定してください。');
            $name = trim((string) ($event['name'] ?? '')) ?: self::NAMES[$type];
            $result[] = ['id' => isset($event['id']) ? (int) $event['id'] : null, 'type' => $type, 'name' => $name,
                'start' => $start->toDateString(), 'end' => $end->toDateString(), 'align' => $align,
                'pre_days' => $pre, 'post_days' => $post, 'profile_keys' => array_values(array_unique([$name, $type]))];
        }
        usort($result, fn ($a, $b) => [$a['align'] !== 'CALENDAR', $a['start']] <=> [$b['align'] !== 'CALENDAR', $b['start']]);

        return $result;
    }

    /**
     * Per-date position inside the events, for [start, start + days).
     * Block days take precedence over the pre/post days of another event.
     *
     * @return array<string, array{event: array, position: string, in_block: bool}>
     */
    public function positions(array $events, string $start, int $days): array
    {
        throw_unless($days >= 0 && $days <= 400, \RuntimeException::class, 'イベント暦の計算日数を確認してください。');
        $events = $this->normalize($events);
        $first = $this->date($start);
        $last = $first->addDays(max(0, $days - 1));
        $positions = [];
        foreach ($events as $event) {
            $blockStart = CarbonImmutable::parse($event['start']);
            $blockEnd = CarbonImmutable::parse($event['end']);
            $from = $blockStart->subDays($event['pre_days']);
            $to = $blockEnd->addDays($event['post_days']);
            if ($to->lt($first) || $from->gt($last)) {
                continue;
            }
            $until = $to->min($last);
            for ($date = $from->max($first); $date->lte($until); $date = $date->addDay()) {
                $key = $date->toDateString();
                $inBlock = $date->between($blockStart, $blockEnd);
                if (isset($positions[$key])) {
                    $existing = $positions[$key];
                    // Block days win; between pre/post days of two events the upcoming event's run-up wins over the previous event's tail.
                    $upcomingWins = ! $existing['in_block'] && ! $inBlock && str_starts_with($existing['position'], 'post') && $date->lt($blockStart);
                    if ($existing['in_block'] || (! $inBlock && ! $upcomingWins)) {
                        continue;
                    }
                }
                $position = match (true) {
                    $event['align'] === 'CALENDAR' => $date->format('m-d'),
                    $date->lt($blockStart) => 'pre'.$date->diffInDays($blockStart),
                    $date->gt($blockEnd) => 'post'.$blockEnd->diffInDays($date),
                    $date->eq($blockStart) => 'd1',
                    $date->eq($blockEnd) => 'last',
                    default => 'mid',
                };
                $positions[$key] = ['event' => $event, 'position' => $position, 'in_block' => $inBlock];
            }
        }
        ksort($positions, SORT_STRING);

        return $positions;
    }

    /** Dates inside an event block (pre/post days excluded) between two dates inclusive. */
    public function eventDates(array $events, string $start, string $end): array
    {
        $days = (int) $this->date($start)->diffInDays($this->date($end), false) + 1;
        if ($days <= 0) {
            return [];
        }

        return array_keys(array_filter($this->positions($events, $start, $days), fn ($p) => $p['in_block']));
    }

    /** Events whose block overlaps [start, end], ordered by start date. */
    public function within(array $events, string $start, string $end): array
    {
        $rows = array_values(array_filter($this->normalize($events), fn ($e) => $e['end'] >= $start && $e['start'] <= $end));
        usort($rows, fn ($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        return $rows;
    }

    /**
     * Default event proposals for one year from the national holiday calendar.
     * 年末年始 12/20〜1/5, お盆 8/10〜8/17, GW = the non-working block containing 5/4,
     * and every other block of three or more consecutive non-working days (土日祝).
     *
     * @param  array<string, string>  $holidays  date => name
     */
    public function proposals(int $year, array $holidays): array
    {
        throw_unless($year >= 2000 && $year <= 2100, \RuntimeException::class, '対象年を確認してください。');
        $holidays = $this->expandHolidays($holidays);
        $proposals = [
            ['type' => 'YEAR_END', 'name' => '年末年始', 'start' => "{$year}-12-20", 'end' => ($year + 1).'-01-05'],
            ['type' => 'OBON', 'name' => 'お盆', 'start' => "{$year}-08-10", 'end' => "{$year}-08-17"],
        ];
        $off = fn (CarbonImmutable $d) => $d->isWeekend() || isset($holidays[$d->toDateString()]);
        $date = CarbonImmutable::create($year, 1, 1);
        $yearEnd = CarbonImmutable::create($year, 12, 31);
        while ($date->lte($yearEnd)) {
            if (! $off($date)) {
                $date = $date->addDay();

                continue;
            }
            $blockStart = $date;
            while ($off($date->addDay())) {
                $date = $date->addDay();
            }
            $blockEnd = $date;
            $date = $date->addDay();
            $length = (int) $blockStart->diffInDays($blockEnd) + 1;
            $containsGw = $blockStart->lte(CarbonImmutable::create($year, 5, 4)) && $blockEnd->gte(CarbonImmutable::create($year, 5, 4));
            if ($containsGw) {
                $proposals[] = ['type' => 'GW', 'name' => 'ゴールデンウィーク', 'start' => $blockStart->toDateString(), 'end' => $blockEnd->toDateString()];

                continue;
            }
            if ($length < 3 || $blockStart->month === 8 && $blockStart->day >= 8 && $blockStart->day <= 17
                || $blockEnd->month === 12 && $blockEnd->day >= 20 || $blockEnd->year > $year || $blockStart->month === 1 && $blockStart->day <= 5) {
                continue;
            }
            $names = [];
            for ($d = $blockStart; $d->lte($blockEnd); $d = $d->addDay()) {
                if (isset($holidays[$d->toDateString()])) {
                    $names[] = $holidays[$d->toDateString()];
                }
            }
            $proposals[] = ['type' => 'LONG_WEEKEND', 'name' => ($names ? implode('・', array_unique($names)) : $blockStart->format('n月')).'連休',
                'start' => $blockStart->toDateString(), 'end' => $blockEnd->toDateString()];
        }

        return $this->normalize($proposals);
    }

    /**
     * Add substitute holidays (振替休日: a holiday on Sunday moves to the next working day) and
     * citizens' holidays (国民の休日: a weekday between two holidays) to a date => name map.
     */
    public function expandHolidays(array $holidays): array
    {
        $result = [];
        foreach ($holidays as $date => $name) {
            $result[$this->date((string) $date)->toDateString()] = (string) $name;
        }
        ksort($result, SORT_STRING);
        foreach (array_keys($result) as $date) {
            $day = CarbonImmutable::parse($date);
            if (! $day->isSunday()) {
                continue;
            }
            $substitute = $day->addDay();
            while (isset($result[$substitute->toDateString()])) {
                $substitute = $substitute->addDay();
            }
            $result[$substitute->toDateString()] = '振替休日';
        }
        foreach (array_keys($result) as $date) {
            $day = CarbonImmutable::parse($date);
            $between = $day->addDay();
            if (! isset($result[$between->toDateString()]) && isset($result[$between->addDay()->toDateString()]) && ! $between->isSunday()) {
                $result[$between->toDateString()] = '国民の休日';
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function date(string $date): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            throw_unless($parsed && $parsed->toDateString() === $date, \RuntimeException::class);

            return $parsed;
        } catch (\Throwable) {
            throw new \RuntimeException('イベントの日付を確認してください。');
        }
    }
}
