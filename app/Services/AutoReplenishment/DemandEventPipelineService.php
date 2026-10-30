<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Services\AutoReplenishment\Replay\ReplayCalendar;
use App\Services\AutoReplenishment\Replay\ReplayDataReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Weekly preparation of the events inside the planning horizon (about nine weeks):
 *   1. propose the standard events that start within the horizon (DRAFT rows),
 *   2. learn their factors from everything observed so far (only those profile keys are replaced),
 *   3. backtest each event on its past instances with the real calculators (leave-one-out),
 *   4. approve standard events automatically when the backtest passes; send an approved event back to
 *      DRAFT when a new backtest fails; custom events always wait for a person.
 * The weekly recommendation refresh then regenerates reorder points and targets from the approved events.
 */
class DemandEventPipelineService
{
    public function __construct(private DemandEventCalendar $calendar, private DemandEventHistoryReader $history, private DemandEventLearningService $learning,
        private DemandEventBacktest $backtest, private ReplayDataReader $reader, private DemandEventService $events) {}

    /** Standard proposals and registered events whose block starts inside [asOf, asOf + horizon]. */
    public function upcoming(int $client, string $asOf, int $horizonDays): array
    {
        $end = CarbonImmutable::parse($asOf)->addDays($horizonDays)->toDateString();
        $proposals = [];
        foreach (array_unique([(int) substr($asOf, 0, 4), (int) substr($end, 0, 4)]) as $year) {
            $proposals = array_merge($proposals, $this->calendar->standardEvents($year, $this->history->holidays($year)));
        }
        $proposals = array_values(array_filter($proposals, fn ($p) => $p['start'] >= $asOf && $p['start'] <= $end));
        $registered = WmsDemandEvent::where('client_id', $client)->where('status', '!=', 'DISABLED')->whereBetween('start_date', [$asOf, $end])->orderBy('start_date')->get();

        return ['proposals' => $proposals, 'registered' => $registered, 'horizon_end' => $end];
    }

    /**
     * @return array{events: array<int, array>, summary: array}
     */
    public function run(int $client, string $asOf, int $horizonDays, int $actorId, bool $apply, ?callable $progress = null, ?ReplayCalendar $calendar = null): array
    {
        $upcoming = $this->upcoming($client, $asOf, $horizonDays);
        $autoTypes = (array) config('replenishment.demand_events.weekly_prepare.auto_approve_types', []);
        $report = [];
        // 1. Proposals missing from the master become DRAFT rows (apply only).
        $existing = $upcoming['registered']->map(fn ($e) => $e->type.'|'.$e->start_date->toDateString())->flip();
        foreach ($upcoming['proposals'] as $proposal) {
            if (! isset($existing[$proposal['type'].'|'.$proposal['start']])) {
                if ($apply) {
                    WmsDemandEvent::create(['client_id' => $client, 'year' => (int) substr($proposal['start'], 0, 4), 'type' => $proposal['type'], 'name' => $proposal['name'],
                        'start_date' => $proposal['start'], 'end_date' => $proposal['end'], 'pre_days' => $proposal['pre_days'], 'post_days' => $proposal['post_days'],
                        'status' => 'DRAFT', 'created_by' => $actorId, 'updated_by' => $actorId]);
                }
                $report[] = ['event' => $proposal, 'action' => $apply ? 'PROPOSED' : 'WOULD_PROPOSE'];
            }
        }
        $rows = $apply ? $this->upcoming($client, $asOf, $horizonDays)['registered'] : $upcoming['registered'];
        if ($rows->isEmpty() && ! $apply) {
            return ['events' => $report, 'summary' => ['upcoming' => count($upcoming['proposals']), 'prepared' => 0, 'approved' => 0, 'reverted' => 0, 'needs_review' => 0]];
        }
        // 2.–4. Learn, backtest and decide per registered upcoming event.
        $seriesList = $this->seriesList($client);
        $summary = ['upcoming' => $rows->count(), 'prepared' => 0, 'approved' => 0, 'reverted' => 0, 'needs_review' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $event = $this->calendar->normalize([$row->toCalendarEvent()])[0];
            $through = CarbonImmutable::parse($asOf)->subDay()->toDateString();
            $entry = ['event' => $event, 'id' => (int) $row->id, 'status_before' => $row->status];
            try {
                $itemIds = array_values(array_unique(array_map(fn ($s) => (int) $s['item_id'], $seriesList)));
                try {
                    $learned = $this->learning->learn($client, $through, 3, $itemIds, null, $event['profile_keys']);
                } catch (\RuntimeException $e) {
                    // No past instance for this key (typically a custom event): nothing to learn, nothing to backtest.
                    $learned = null;
                }
                $instances = $learned ? $this->backtest->pastInstances($event, $asOf, 3) : [];
                $demand = $instances ? $this->demandFor($client, $seriesList, $this->backtest->windows($instances)) : [];
                $backtest = $learned ? $this->backtest->run($client, $event, $seriesList, $demand, $asOf, 3, $calendar ?? $this->calendarResolver(), $progress)
                    : ['version' => DemandEventBacktest::VERSION, 'event' => array_intersect_key($event, array_flip(['type', 'name', 'start', 'end'])), 'as_of' => $asOf,
                        'instances' => [], 'pooled' => null, 'verdict' => 'NO_DATA', 'reason' => '過去に同じイベントの実例がないため倍率を学習できません（曜日別のみで算定されます）。'];
                $entry['backtest'] = $backtest;
                $entry['learned'] = $learned['stats'] ?? null;
                $decision = $this->decide($row, $backtest, in_array($event['type'], $autoTypes, true));
                $entry['action'] = $decision['action'];
                $entry['review_note'] = $decision['note'];
                if ($apply) {
                    DB::connection('sakemaru')->transaction(function () use ($client, $learned, $through, $event, $row, $backtest, $decision, $actorId) {
                        if ($learned) {
                            $this->learning->persist($client, $learned, $through, $event['profile_keys']);
                        }
                        $row->fill(['factor_trained_through' => $through, 'backtest' => $backtest, 'review_note' => $decision['note'], 'prepared_at' => now(), 'updated_by' => $actorId]);
                        if ($decision['action'] === 'AUTO_APPROVED') {
                            $row->fill(['status' => 'APPROVED', 'auto_approved' => true, 'approved_by' => $actorId, 'approved_at' => now()]);
                        } elseif ($decision['action'] === 'REVERTED') {
                            $row->fill(['status' => 'DRAFT', 'auto_approved' => false, 'approved_by' => null, 'approved_at' => null]);
                        }
                        $row->save();
                    }, 3);
                }
                $summary['prepared']++;
                $summary['approved'] += $decision['action'] === 'AUTO_APPROVED' ? 1 : 0;
                $summary['reverted'] += $decision['action'] === 'REVERTED' ? 1 : 0;
                $summary['needs_review'] += $decision['action'] === 'NEEDS_REVIEW' ? 1 : 0;
            } catch (\RuntimeException $e) {
                $entry['action'] = 'SKIPPED';
                $entry['review_note'] = $e->getMessage();
                $summary['skipped']++;
            }
            $report[] = $entry;
            if ($progress) {
                $progress($event['name'].' '.$event['start'].'：'.$entry['action'].($entry['review_note'] ? '（'.$entry['review_note'].'）' : ''));
            }
        }

        return ['events' => $report, 'summary' => $summary];
    }

    /** Approval decision for one event from its backtest; manual approvals are respected but annotated. */
    private function decide(WmsDemandEvent $row, array $backtest, bool $autoType): array
    {
        $pass = $backtest['verdict'] === 'PASS';
        $note = $backtest['reason'];
        if ($row->status === 'APPROVED') {
            if ($pass) {
                return ['action' => 'KEPT_APPROVED', 'note' => $note];
            }

            // A person approved it: keep, but flag. Automatic approvals lose their approval until a backtest passes again.
            return $row->auto_approved ? ['action' => 'REVERTED', 'note' => '再検証で効果を確認できず素案に戻しました。'.$note]
                : ['action' => 'NEEDS_REVIEW', 'note' => '承認済みですが最新の検証では効果を確認できません。'.$note];
        }
        if ($autoType && $pass) {
            return ['action' => 'AUTO_APPROVED', 'note' => '過去実例の検証に合格したため自動承認。'.$note];
        }

        return ['action' => 'NEEDS_REVIEW', 'note' => ($autoType ? '' : '任意イベントは担当者の承認が必要です。').$note];
    }

    private function seriesList(int $client): array
    {
        $asOf = CarbonImmutable::now('Asia/Tokyo')->toDateString();
        $list = $this->reader->seriesList($client, CarbonImmutable::parse($asOf)->subDays(365)->toDateString(), $asOf, 0);
        $items = $this->reader->items($client, array_values(array_unique(array_column($list, 'item_id'))));
        $warehouses = $this->reader->warehouses($client, array_values(array_unique(array_column($list, 'warehouse_id'))));
        $series = [];
        foreach ($list as $entry) {
            $item = $items[$entry['item_id']] ?? ['code' => '', 'name' => '', 'capacity_case' => 0, 'item_category3_id' => null];
            $series[] = ['client_id' => $client, 'warehouse_id' => $entry['warehouse_id'], 'warehouse_code' => (string) ($warehouses[$entry['warehouse_id']]['code'] ?? ''),
                'item_id' => $entry['item_id'], 'item_code' => (string) $item['code'], 'item_name' => (string) $item['name'], 'capacity_case' => (int) $item['capacity_case'],
                'item_category3_id' => $item['item_category3_id'] ?? null, 'channel' => $entry['channel'] ?? 'EOS',
                'contractor_id' => $entry['contractor_id'] ?? null, 'saved_policy' => $entry['saved_policy'] ?? null];
        }

        return $series;
    }

    /**
     * The store's own delivery calendar (today's warehouse × contractor schedule applied to the past) per series,
     * cached per warehouse × contractor; a simple 2-day calendar when the series has no contractor or the schedule cannot be built.
     */
    private function calendarResolver(): \Closure
    {
        $cache = [];
        $fallback = ReplayCalendar::simple(2, [['12-31', '01-03']]);

        return function (array $series) use (&$cache, $fallback): ReplayCalendar {
            $contractor = (int) ($series['contractor_id'] ?? 0);
            if ($contractor <= 0) {
                return $fallback;
            }
            $key = $series['warehouse_id'].'|'.$contractor.'|'.($series['channel'] ?? 'EOS');
            if (! array_key_exists($key, $cache)) {
                try {
                    $calendar = $this->reader->scheduledCalendar((int) $series['warehouse_id'], $contractor, (string) ($series['channel'] ?? 'EOS'));
                    $calendar->schedule(CarbonImmutable::now('Asia/Tokyo')->subYear()->toDateString(), 28); // probe once so a missing schedule falls back here, not per day
                    $cache[$key] = $calendar;
                } catch (\Throwable $e) {
                    report($e);
                    $cache[$key] = $fallback;
                }
            }

            return $cache[$key];
        };
    }

    /** Demand for the backtest windows only (merged); missing dates stay null. */
    private function demandFor(int $client, array $seriesList, array $windows): array
    {
        $demand = [];
        $warehouses = array_values(array_unique(array_column($seriesList, 'warehouse_id')));
        $items = array_values(array_unique(array_column($seriesList, 'item_id')));
        foreach ($windows as $window) {
            foreach ($this->reader->demand($client, $warehouses, $items, $window['start'], $window['end']) as $warehouse => $byItem) {
                foreach ($byItem as $item => $daily) {
                    $demand[$warehouse][$item] = ($demand[$warehouse][$item] ?? []) + $daily;
                }
            }
        }

        return $demand;
    }
}
