<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsNationalHoliday;
use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayEngine;
use Carbon\CarbonImmutable;

/**
 * Backtest of one upcoming event on its past instances (same holiday name when available, else same type):
 * factors are learned only from data before each past instance (leave-one-out), then the real calculators are
 * replayed over the instance with and without event factors, under the product's saved order constraints and
 * the store's delivery calendar when available.
 *
 * Scored on three windows — 事前確保 (the two weeks before the block), 本体 (block days) and 終了後 (two weeks
 * after the adjusted period) — pooled and per series, so an event that helps on average but hurts some
 * products or leaves stock after the event does not pass. Pure given the demand arrays; learning reads through
 * DemandEventLearningService (SELECT only). The evaluated year never feeds the level or the factors of its own replay.
 */
class DemandEventBacktest
{
    public const VERSION = 'event-backtest-v2';

    public const PRE_WINDOW_DAYS = 14;

    public const POST_WINDOW_DAYS = 14;

    public const WINDOWS = ['pre' => '事前確保', 'block' => '本体', 'post' => '終了後'];

    public function __construct(private DemandEventLearningService $learning, private ReplayEngine $engine, private DemandEventCalendar $calendar) {}

    /** Past instances to compare against: the most recent $years with the same name (≥2) or the same type. */
    public function pastInstances(array $event, string $asOf, int $years = 3): array
    {
        $candidates = array_values(array_filter($this->learning->trainingInstances($asOf, $years + 1), fn ($i) => $i['type'] === $event['type'] && $i['start'] < $event['start']));
        $sameName = array_values(array_filter($candidates, fn ($i) => $i['name'] === $event['name']));
        $pool = count($sameName) >= 2 ? $sameName : $candidates;
        usort($pool, fn ($a, $b) => $b['start'] <=> $a['start']);

        return array_reverse(array_slice($pool, 0, $years));
    }

    /** Demand windows the replay of each past instance needs: [start − pre − 130, end + post + 14]. */
    public function windows(array $instances): array
    {
        return array_map(fn ($i) => ['start' => CarbonImmutable::parse($i['start'])->subDays($i['pre_days'] + ReplayEngine::HISTORY_DAYS + 40)->toDateString(),
            'end' => CarbonImmutable::parse($i['end'])->addDays($i['post_days'] + self::POST_WINDOW_DAYS)->toDateString()], $instances);
    }

    /**
     * @param  array  $seriesList  series arrays without demand (warehouse_id, item_id, capacity_case, labels, optional saved_policy / contractor_id)
     * @param  array  $demand  warehouse => item => date => pieces|null, covering windows()
     * @param  ReplayCalendar|\Closure|null  $calendar  one calendar for every series, or fn(array $series): ReplayCalendar (the store's delivery calendar); null = simple 2-day lead
     */
    public function run(int $client, array $event, array $seriesList, array $demand, string $asOf, int $years = 3, ReplayCalendar|\Closure|null $calendar = null, ?callable $progress = null): array
    {
        $calendar ??= ReplayCalendar::simple(2, [['12-31', '01-03']]);
        $resolve = $calendar instanceof \Closure ? $calendar : fn () => $calendar;
        $instances = $this->pastInstances($event, $asOf, $years);
        $itemIds = array_values(array_unique(array_map(fn ($s) => (int) $s['item_id'], $seriesList)));
        $result = ['version' => self::VERSION, 'event' => array_intersect_key($event, array_flip(['type', 'name', 'start', 'end'])), 'as_of' => $asOf,
            'instances' => [], 'pooled' => null, 'series' => null, 'verdict' => 'NO_DATA', 'reason' => null];
        if (! $instances) {
            $result['reason'] = '過去に同じ種類のイベントがありません。';

            return $result;
        }
        $keys = $event['profile_keys'];
        $totals = ['base' => $this->emptyWindows(), 'event' => $this->emptyWindows()];
        $perSeries = [];
        $calendars = ['scheduled' => 0, 'simple' => 0];
        $constrained = 0;
        foreach ($instances as $instance) {
            // Leave-one-out: the factors come from data before this instance's adjusted period; the replay's level comes from the 90 days before each recalculation.
            $through = CarbonImmutable::parse($instance['start'])->subDays($instance['pre_days'] + 1)->toDateString();
            try {
                $learned = $this->learning->learn($client, $through, 3, $itemIds, null, $keys);
            } catch (\RuntimeException $e) {
                $result['instances'][] = ['start' => $instance['start'], 'end' => $instance['end'], 'skipped' => $e->getMessage()];

                continue;
            }
            $factors = function (int $item) use ($learned, $seriesList) {
                $category = null;
                foreach ($seriesList as $s) {
                    if ((int) $s['item_id'] === $item) {
                        $category = $s['item_category3_id'] ?? null;
                        break;
                    }
                }

                return ['item' => $learned['items'][$item] ?? [], 'category' => $category ? ($learned['categories'][$category] ?? []) : []];
            };
            $year = (int) substr($instance['start'], 0, 4);
            $holidays = fn (int $y) => array_column(WmsNationalHoliday::generateJapaneseHolidays($y), 'holiday_name', 'holiday_date');
            $segmentEvents = array_merge($this->calendar->proposals($year - 1, $holidays($year - 1)), $this->calendar->proposals($year, $holidays($year)), $this->calendar->proposals($year + 1, $holidays($year + 1)));
            $windows = $this->scoringWindows($instance);
            $start = CarbonImmutable::parse($windows['pre']['start'])->subDays(14)->toDateString(); // two weekly recalculations before the pre window
            $end = $windows['post']['end'];
            $scenario = ['name' => 'base', 'start' => $start, 'end' => $end, 'mode' => 'weekly', 'unit_mode' => 'CASE', 'safety_stock_days' => 5, 'review_days' => 7,
                'variability_buffer' => false, 'events' => [], 'factors' => null, 'segment_events' => $segmentEvents, 'warmup_days' => 0, 'max_change_ratio' => 2.0, 'saved_constraints' => true];
            $perInstance = ['base' => $this->emptyWindows(), 'event' => $this->emptyWindows()];
            $used = 0;
            foreach ($seriesList as $s) {
                $series = $s + ['demand' => $demand[$s['warehouse_id']][$s['item_id']] ?? []];
                if (! $series['demand']) {
                    continue;
                }
                $seriesCalendar = $resolve($series);
                $runs = ['base' => $this->engine->run($series, $scenario, $seriesCalendar),
                    'event' => $this->engine->run($series, array_replace($scenario, ['name' => 'eventA', 'events' => [$instance], 'factors' => $factors]), $seriesCalendar)];
                $used++;
                $calendars[$seriesCalendar->isScheduled() ? 'scheduled' : 'simple']++;
                $constrained += is_array($series['saved_policy'] ?? null) ? 1 : 0;
                $key = $series['warehouse_id'].'|'.$series['item_id'];
                $perSeries[$key] ??= ['warehouse_id' => (int) $series['warehouse_id'], 'warehouse_code' => (string) ($series['warehouse_code'] ?? ''), 'item_id' => (int) $series['item_id'],
                    'item_code' => (string) ($series['item_code'] ?? ''), 'item_name' => (string) ($series['item_name'] ?? ''), 'base' => $this->emptyWindows(), 'event' => $this->emptyWindows()];
                foreach ($runs as $label => $run) {
                    $this->accumulateWindows($perInstance[$label], $run['days'], $windows);
                    $this->accumulateWindows($perSeries[$key][$label], $run['days'], $windows);
                }
            }
            foreach (['base', 'event'] as $label) {
                $this->mergeWindows($totals[$label], $perInstance[$label]);
            }
            $result['instances'][] = ['start' => $instance['start'], 'end' => $instance['end'], 'name' => $instance['name'], 'learn_through' => $through, 'series' => $used,
                'windows' => array_map(fn ($w) => ['start' => $w['start'], 'end' => $w['end']], $windows),
                'base' => $this->finishWindows($perInstance['base']), 'event' => $this->finishWindows($perInstance['event'])];
            if ($progress) {
                $progress($instance['name'].' '.$instance['start'].'：'.$used.'系列');
            }
        }
        $pooled = ['base' => $this->finishWindows($totals['base']), 'event' => $this->finishWindows($totals['event'])];
        $result['pooled'] = $pooled;
        $result['series'] = $this->compareSeries($perSeries);
        $result['conditions'] = ['calendars' => $calendars, 'constrained_series' => $constrained, 'pre_window_days' => self::PRE_WINDOW_DAYS, 'post_window_days' => self::POST_WINDOW_DAYS];
        [$result['verdict'], $result['reason']] = $this->verdict($pooled, $result['series']);

        return $result;
    }

    /** The three scoring windows of one past instance. */
    public function scoringWindows(array $instance): array
    {
        $start = CarbonImmutable::parse($instance['start']);
        $adjustedEnd = CarbonImmutable::parse($instance['end'])->addDays($instance['post_days']);

        return ['pre' => ['start' => $start->subDays($instance['pre_days'] + self::PRE_WINDOW_DAYS)->toDateString(), 'end' => $start->subDay()->toDateString()],
            'block' => ['start' => $instance['start'], 'end' => $instance['end']],
            'post' => ['start' => $adjustedEnd->addDay()->toDateString(), 'end' => $adjustedEnd->addDays(self::POST_WINDOW_DAYS)->toDateString()]];
    }

    private function empty(): array
    {
        return ['demand' => 0, 'abs_error' => 0.0, 'error' => 0.0, 'unmet' => 0, 'stock_sum' => 0, 'days' => 0, 'forecast_days' => 0];
    }

    private function emptyWindows(): array
    {
        return array_fill_keys(array_keys(self::WINDOWS), $this->empty());
    }

    private function accumulateWindows(array &$acc, array $rows, array $windows): void
    {
        foreach ($windows as $name => $window) {
            $this->accumulate($acc[$name], $rows, $window['start'], $window['end']);
        }
    }

    private function accumulate(array &$acc, array $rows, string $start, string $end): void
    {
        foreach ($rows as $row) {
            if ($row['date'] < $start || $row['date'] > $end || $row['demand'] === null) {
                continue;
            }
            $acc['demand'] += $row['demand'];
            $acc['unmet'] += $row['unmet'];
            $acc['stock_sum'] += $row['stock'];
            $acc['days']++;
            if ($row['forecast'] !== null) {
                $acc['abs_error'] += abs($row['forecast'] - $row['demand']);
                $acc['error'] += $row['forecast'] - $row['demand'];
                $acc['forecast_days']++;
            }
        }
    }

    private function mergeWindows(array &$into, array $from): void
    {
        foreach ($from as $name => $acc) {
            foreach ($acc as $k => $v) {
                $into[$name][$k] += $v;
            }
        }
    }

    private function finish(array $acc): array
    {
        return ['demand' => $acc['demand'], 'days' => $acc['days'],
            'wape' => $acc['demand'] > 0 ? round($acc['abs_error'] / $acc['demand'], 4) : null,
            'bias' => $acc['demand'] > 0 ? round($acc['error'] / $acc['demand'], 4) : null,
            'stockout_rate' => $acc['demand'] > 0 ? round($acc['unmet'] / $acc['demand'], 4) : null,
            'unmet' => $acc['unmet'], 'mean_stock' => $acc['days'] ? round($acc['stock_sum'] / $acc['days'], 1) : null];
    }

    /** Block metrics at the top level (as before) plus every window under 'windows'. */
    private function finishWindows(array $acc): array
    {
        $windows = array_map(fn ($a) => $this->finish($a), $acc);

        return $windows['block'] + ['windows' => $windows];
    }

    /**
     * Per-series comparison: a series worsened when the event factors add shortages in any window, leave clearly more
     * stock after the event without fewer shortages, or raise the block forecast error without fewer shortages.
     */
    private function compareSeries(array $perSeries): array
    {
        $worsened = [];
        $improved = 0;
        $scored = 0;
        foreach ($perSeries as $row) {
            $base = $this->finishWindows($row['base']);
            $event = $this->finishWindows($row['event']);
            if ($base['demand'] <= 0 || $base['wape'] === null || $event['wape'] === null) {
                continue;
            }
            $scored++;
            $reasons = [];
            foreach (self::WINDOWS as $name => $label) {
                if (($event['windows'][$name]['unmet'] ?? 0) > ($base['windows'][$name]['unmet'] ?? 0) && ($event['windows'][$name]['stockout_rate'] ?? 0) > ($base['windows'][$name]['stockout_rate'] ?? 0) + 0.01) {
                    $reasons[] = $label.'の欠品増';
                }
            }
            $noFewerShortages = $event['unmet'] >= $base['unmet'];
            $postBase = $base['windows']['post']['mean_stock'];
            $postEvent = $event['windows']['post']['mean_stock'];
            if ($postBase !== null && $postEvent !== null && $postBase > 0 && $postEvent > $postBase * 1.5 && $noFewerShortages) {
                $reasons[] = '終了後の在庫増';
            }
            if ($event['wape'] > $base['wape'] + 0.05 && $noFewerShortages) {
                $reasons[] = '本体の予測誤差増';
            }
            if ($reasons) {
                $worsened[] = ['warehouse_id' => $row['warehouse_id'], 'warehouse_code' => $row['warehouse_code'], 'item_id' => $row['item_id'], 'item_code' => $row['item_code'], 'item_name' => $row['item_name'],
                    'reasons' => $reasons, 'base' => ['wape' => $base['wape'], 'stockout_rate' => $base['stockout_rate'], 'post_mean_stock' => $postBase],
                    'event' => ['wape' => $event['wape'], 'stockout_rate' => $event['stockout_rate'], 'post_mean_stock' => $postEvent]];
            } elseif ($event['unmet'] < $base['unmet'] || $event['wape'] < $base['wape'] - 0.02) {
                $improved++;
            }
        }
        usort($worsened, fn ($a, $b) => count($b['reasons']) <=> count($a['reasons']) ?: $b['event']['stockout_rate'] <=> $a['event']['stockout_rate']);

        return ['scored' => $scored, 'improved' => $improved, 'worsened' => count($worsened), 'worsened_share' => $scored ? round(count($worsened) / $scored, 4) : null,
            'worsened_top' => array_slice($worsened, 0, 20)];
    }

    /**
     * PASS when the event factors do not worsen the block forecast, do not add shortages before or during the event,
     * do not leave clearly more stock after it, and do not worsen a large share of the products; FAIL otherwise.
     */
    private function verdict(array $pooled, array $series): array
    {
        $base = $pooled['base'];
        $event = $pooled['event'];
        if ($base['demand'] <= 0 || $event['wape'] === null || $base['wape'] === null) {
            return ['NO_DATA', '過去のイベント日に実績がありません。'];
        }
        $summary = sprintf('本体：予測誤差 %.1f%%→%.1f%%、欠品率 %.2f%%→%.2f%%、平均在庫 %s→%s', $base['wape'] * 100, $event['wape'] * 100, $base['stockout_rate'] * 100, $event['stockout_rate'] * 100, $base['mean_stock'], $event['mean_stock']);
        $pre = ['base' => $base['windows']['pre'], 'event' => $event['windows']['pre']];
        $post = ['base' => $base['windows']['post'], 'event' => $event['windows']['post']];
        if ($pre['base']['days'] > 0) {
            $summary .= sprintf('／事前確保：欠品率 %.2f%%→%.2f%%', ($pre['base']['stockout_rate'] ?? 0) * 100, ($pre['event']['stockout_rate'] ?? 0) * 100);
        }
        if ($post['base']['days'] > 0) {
            $summary .= sprintf('／終了後：平均在庫 %s→%s、欠品率 %.2f%%→%.2f%%', $post['base']['mean_stock'], $post['event']['mean_stock'], ($post['base']['stockout_rate'] ?? 0) * 100, ($post['event']['stockout_rate'] ?? 0) * 100);
        }
        if ($series['scored'] > 0) {
            $summary .= sprintf('／商品×店舗：悪化 %d／%d（改善 %d）', $series['worsened'], $series['scored'], $series['improved']);
        }
        $problems = [];
        if ($event['wape'] > $base['wape'] + 0.02) {
            $problems[] = '本体の予測誤差が増える';
        }
        if ($event['stockout_rate'] > $base['stockout_rate'] + 0.005) {
            $problems[] = '本体の欠品が増える';
        }
        if ($base['mean_stock'] > 0 && $event['mean_stock'] > $base['mean_stock'] * 1.5 && $event['stockout_rate'] >= $base['stockout_rate']) {
            $problems[] = '本体の在庫が欠品改善なしに大きく増える';
        }
        if ($pre['base']['days'] > 0 && ($pre['event']['stockout_rate'] ?? 0) > ($pre['base']['stockout_rate'] ?? 0) + 0.005) {
            $problems[] = '事前確保期間の欠品が増える';
        }
        if ($post['base']['days'] > 0 && ($post['base']['mean_stock'] ?? 0) > 0 && ($post['event']['mean_stock'] ?? 0) > $post['base']['mean_stock'] * 1.3
            && ($post['event']['stockout_rate'] ?? 0) >= ($post['base']['stockout_rate'] ?? 0)) {
            $problems[] = '終了後に在庫が残る';
        }
        if ($series['scored'] >= 3 && $series['worsened_share'] >= 0.3 && $series['worsened'] > $series['improved']) {
            $problems[] = '商品×店舗の3割以上で悪化';
        }
        if ($problems) {
            return ['FAIL', $summary.'：'.implode('・', $problems).'ため補正の効果が確認できません。'];
        }

        return ['PASS', $summary.'。'];
    }
}
