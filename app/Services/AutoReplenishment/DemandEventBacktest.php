<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsNationalHoliday;
use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayEngine;
use Carbon\CarbonImmutable;

/**
 * Backtest of one upcoming event on its past instances (same holiday name when available, else same type):
 * factors are learned only from data before each past instance (leave-one-out), then the real calculators are
 * replayed over the instance with and without event factors. Scored on the event's block days.
 * Pure given the demand arrays; learning reads through DemandEventLearningService (SELECT only).
 */
class DemandEventBacktest
{
    public const VERSION = 'event-backtest-v1';

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

    /** Demand windows the replay of each past instance needs: [start − 130, end + post + 7]. */
    public function windows(array $instances): array
    {
        return array_map(fn ($i) => ['start' => CarbonImmutable::parse($i['start'])->subDays(ReplayEngine::HISTORY_DAYS + 40)->toDateString(),
            'end' => CarbonImmutable::parse($i['end'])->addDays($i['post_days'] + 7)->toDateString()], $instances);
    }

    /**
     * @param  array  $seriesList  series arrays without demand (warehouse_id, item_id, capacity_case, labels)
     * @param  array  $demand  warehouse => item => date => pieces|null, covering windows()
     */
    public function run(int $client, array $event, array $seriesList, array $demand, string $asOf, int $years = 3, ?ReplayCalendar $calendar = null, ?callable $progress = null): array
    {
        $calendar ??= ReplayCalendar::simple(2, [['12-31', '01-03']]);
        $instances = $this->pastInstances($event, $asOf, $years);
        $itemIds = array_values(array_unique(array_map(fn ($s) => (int) $s['item_id'], $seriesList)));
        $result = ['version' => self::VERSION, 'event' => array_intersect_key($event, array_flip(['type', 'name', 'start', 'end'])), 'as_of' => $asOf,
            'instances' => [], 'pooled' => null, 'verdict' => 'NO_DATA', 'reason' => null];
        if (! $instances) {
            $result['reason'] = '過去に同じ種類のイベントがありません。';

            return $result;
        }
        $keys = $event['profile_keys'];
        $totals = ['base' => $this->empty(), 'event' => $this->empty()];
        foreach ($instances as $instance) {
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
            $start = CarbonImmutable::parse($instance['start'])->subDays(28)->toDateString();
            $end = CarbonImmutable::parse($instance['end'])->addDays($instance['post_days'] + 7)->toDateString();
            $scenario = ['name' => 'base', 'start' => $start, 'end' => $end, 'mode' => 'weekly', 'unit_mode' => 'CASE', 'safety_stock_days' => 5, 'review_days' => 7,
                'variability_buffer' => false, 'events' => [], 'factors' => null, 'segment_events' => $segmentEvents, 'warmup_days' => 0, 'max_change_ratio' => 2.0];
            $perInstance = ['base' => $this->empty(), 'event' => $this->empty()];
            $used = 0;
            foreach ($seriesList as $s) {
                $series = $s + ['demand' => $demand[$s['warehouse_id']][$s['item_id']] ?? []];
                if (! $series['demand']) {
                    continue;
                }
                $runs = ['base' => $this->engine->run($series, $scenario, $calendar),
                    'event' => $this->engine->run($series, array_replace($scenario, ['name' => 'eventA', 'events' => [$instance], 'factors' => $factors]), $calendar)];
                $used++;
                foreach ($runs as $label => $run) {
                    $this->accumulate($perInstance[$label], $run['days'], $instance['start'], $instance['end']);
                }
            }
            foreach (['base', 'event'] as $label) {
                $this->merge($totals[$label], $perInstance[$label]);
            }
            $result['instances'][] = ['start' => $instance['start'], 'end' => $instance['end'], 'name' => $instance['name'], 'learn_through' => $through, 'series' => $used,
                'base' => $this->finish($perInstance['base']), 'event' => $this->finish($perInstance['event'])];
            if ($progress) {
                $progress($instance['name'].' '.$instance['start'].'：'.$used.'系列');
            }
        }
        $pooled = ['base' => $this->finish($totals['base']), 'event' => $this->finish($totals['event'])];
        $result['pooled'] = $pooled;
        [$result['verdict'], $result['reason']] = $this->verdict($pooled);

        return $result;
    }

    private function empty(): array
    {
        return ['demand' => 0, 'abs_error' => 0.0, 'error' => 0.0, 'unmet' => 0, 'stock_sum' => 0, 'days' => 0, 'forecast_days' => 0];
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

    private function merge(array &$into, array $from): void
    {
        foreach ($from as $k => $v) {
            $into[$k] += $v;
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

    /** PASS when the event factors do not worsen the forecast and do not add shortages; FAIL otherwise. */
    private function verdict(array $pooled): array
    {
        $base = $pooled['base'];
        $event = $pooled['event'];
        if ($base['demand'] <= 0 || $event['wape'] === null || $base['wape'] === null) {
            return ['NO_DATA', '過去のイベント日に実績がありません。'];
        }
        $worseForecast = $event['wape'] > $base['wape'] + 0.02;
        $worseStockout = $event['stockout_rate'] > $base['stockout_rate'] + 0.005;
        $muchMoreStock = $base['mean_stock'] > 0 && $event['mean_stock'] > $base['mean_stock'] * 1.5 && $event['stockout_rate'] >= $base['stockout_rate'];
        if ($worseForecast || $worseStockout || $muchMoreStock) {
            return ['FAIL', sprintf('予測誤差 %.1f%%→%.1f%%、欠品率 %.2f%%→%.2f%%、平均在庫 %s→%s：補正の効果が確認できません。',
                $base['wape'] * 100, $event['wape'] * 100, $base['stockout_rate'] * 100, $event['stockout_rate'] * 100, $base['mean_stock'], $event['mean_stock'])];
        }

        return ['PASS', sprintf('予測誤差 %.1f%%→%.1f%%、欠品率 %.2f%%→%.2f%%、平均在庫 %s→%s。',
            $base['wape'] * 100, $event['wape'] * 100, $base['stockout_rate'] * 100, $event['stockout_rate'] * 100, $base['mean_stock'], $event['mean_stock'])];
    }
}
