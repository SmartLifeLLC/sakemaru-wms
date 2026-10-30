<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use App\Models\WmsItemReorderPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Learns event factors for products and categories from past instances of the standard events.
 * Reads bounded windows around each instance; writes wms_demand_event_profiles only via persist().
 */
class DemandEventLearningService
{
    public const MIN_ITEM_PIECES = 10.0;

    public function __construct(private DemandEventHistoryReader $reader, private DemandEventLearner $learner, private DemandEventCalendar $calendar) {}

    /** Past instances of the standard events: the most recent $years years ending at $through. */
    public function trainingInstances(string $through, int $years): array
    {
        $end = CarbonImmutable::parse($through);
        $earliest = $end->subYears($years)->subDays(30)->toDateString();
        $instances = [];
        for ($year = $end->year - $years - 1; $year <= $end->year; $year++) {
            foreach ($this->calendar->standardEvents($year, $this->reader->holidays($year)) as $event) {
                // Complete instances only: the post days are learned positions too, so they must also lie before $through.
                $complete = CarbonImmutable::parse($event['end'])->addDays($event['post_days'])->toDateString() <= $through;
                if ($complete && $event['start'] >= $earliest) {
                    $instances[] = $event + ['year' => $year];
                }
            }
        }
        usort($instances, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $instances;
    }

    /**
     * @param  array<int>|null  $itemIds  products that receive ITEM profiles (null = every product with enough pieces)
     * @return array{items: array<int, array<string, array>>, categories: array<int, array<string, array>>, instances: array, stats: array}
     */
    public function learn(int $client, string $through, int $years = 3, ?array $itemIds = null, ?callable $progress = null, ?array $keys = null, ?array $onlyItems = null): array
    {
        $instances = $this->trainingInstances($through, $years);
        throw_unless($instances, \RuntimeException::class, '学習できる過去のイベントがありません。');
        $namedCounts = array_count_values(array_column(array_filter($instances, fn ($i) => $i['type'] === 'LONG_WEEKEND'), 'name'));
        $allEvents = $instances;
        if ($keys !== null) {
            // Only the instances that feed the requested profile keys (an upcoming event's name or type); other events
            // still define the exclusion windows through $allEvents.
            $instances = array_values(array_filter($instances, fn ($i) => array_intersect($this->learner->profileKeys($i, $namedCounts), $keys) !== []));
            throw_unless($instances, \RuntimeException::class, '対象イベントの過去実例がありません：'.implode('・', $keys));
        }
        $keep = $itemIds === null ? null : array_flip($itemIds);
        // Same rule as the recommendation's normal level: a product's baseline skips the adjusted days of the events applied to it
        // (plus the instance being learned, always). Products without applied events exclude the instance only.
        $appliedTypes = $this->reader->itemEventTypes($client);
        $ratios = [];   // key => item => compact instance ratios (positions + pieces only)
        $pieces = [];   // item => total actual pieces seen
        $rangesRead = 0;
        // One bounded read per instance (about 100 days, every product) keeps memory flat; products too small to vote
        // in a category profile are dropped immediately unless an ITEM profile was requested for them.
        foreach ($instances as $instance) {
            $window = ['start' => CarbonImmutable::parse($instance['start'])->subDays(DemandEventLearner::PRE_WINDOW_MAX + $instance['pre_days'])->toDateString(),
                'end' => min($through, CarbonImmutable::parse($instance['end'])->addDays($instance['post_days'])->toDateString())];
            $data = $this->reader->productTotals($client, $window['start'], $window['end'], $onlyItems);
            $rangesRead++;
            $dates = [];
            for ($d = CarbonImmutable::parse($window['start']); $d->toDateString() <= $window['end']; $d = $d->addDay()) {
                $dates[] = $d->toDateString();
            }
            if ($progress) {
                $progress("{$instance['name']} {$instance['start']}〜{$instance['end']}：".count($data['items']).'商品');
            }
            $yearEvents = array_values(array_filter($allEvents, fn ($e) => $e['end'] >= $window['start'] && $e['start'] <= $instance['end']));
            $keys = $this->learner->profileKeys($instance, $namedCounts);
            $layouts = []; // one layout per distinct set of applied types (at most a handful), reused across products
            foreach ($data['items'] as $itemId => $daily) {
                $types = $appliedTypes[$itemId] ?? [];
                sort($types);
                $layoutKey = implode(',', $types);
                if (! isset($layouts[$layoutKey])) {
                    $exclusion = $this->exclusionEvents($yearEvents, $instance, $types);
                    $layouts[$layoutKey] = ['events' => $exclusion, 'layout' => $this->learner->layout($instance, $exclusion)];
                }
                $result = $this->learner->instanceRatios($this->series($daily, $data['observed'], $dates), $instance, $layouts[$layoutKey]['events'], $layouts[$layoutKey]['layout']);
                if ($result === null || ($result['pieces'] < self::MIN_ITEM_PIECES && ! isset($keep[$itemId]))) {
                    continue;
                }
                $pieces[$itemId] = ($pieces[$itemId] ?? 0) + $result['pieces'];
                $compact = ['positions' => array_map(fn ($p) => ['ratio' => $p['ratio'], 'ratio_weekday' => $p['ratio_weekday']], $result['positions']), 'pieces' => $result['pieces']];
                foreach ($keys as $key) {
                    $ratios[$key][$itemId][] = $compact;
                }
            }
            unset($data, $dates);
        }
        // Reading selected products only cannot vote category profiles (the category needs every member): keep the existing ones.
        $categories = $onlyItems === null ? $this->reader->itemCategories($client) : [];
        $itemProfiles = [];
        $categoryProfiles = [];
        foreach ($ratios as $key => $byItem) {
            $members = [];
            foreach ($byItem as $itemId => $list) {
                $profile = $this->learner->aggregate($list);
                if ($profile === null) {
                    continue;
                }
                if (isset($categories[$itemId])) {
                    $members[$categories[$itemId]][] = $profile;
                }
                if ($itemIds === null ? $profile['pieces'] >= self::MIN_ITEM_PIECES : in_array((int) $itemId, $itemIds, true)) {
                    $itemProfiles[$itemId][$key] = $profile;
                }
            }
            foreach ($members as $category => $profiles) {
                $profile = $this->learner->categoryProfile($profiles, self::MIN_ITEM_PIECES);
                if ($profile !== null) {
                    $categoryProfiles[$category][$key] = $profile;
                }
            }
        }

        return ['items' => $itemProfiles, 'categories' => $categoryProfiles, 'instances' => $instances,
            'stats' => ['ranges_read' => $rangesRead, 'products_seen' => count($pieces), 'item_profiles' => array_sum(array_map('count', $itemProfiles)),
                'category_profiles' => array_sum(array_map('count', $categoryProfiles))]];
    }

    /** The events whose adjusted days a product's baseline skips: the instance being learned plus the events applied to the product. */
    public function exclusionEvents(array $yearEvents, array $instance, array $appliedTypes): array
    {
        return array_values(array_filter($yearEvents, fn ($e) => ($e['type'] === $instance['type'] && $e['start'] === $instance['start']) || in_array($e['type'], $appliedTypes, true)));
    }

    /**
     * Save learned profiles inside a transaction: all keys, or only the given profile keys (the weekly preparation
     * of upcoming events leaves the other keys untouched). By default the rows are written as CANDIDATE, so the
     * factors in use (ACTIVE) are never replaced by a learning result that has not passed a backtest or been approved;
     * promote() moves candidates into use.
     */
    public function persist(int $client, array $learned, string $through, ?array $keys = null, string $stage = WmsDemandEventProfile::CANDIDATE): int
    {
        throw_unless(in_array($stage, [WmsDemandEventProfile::ACTIVE, WmsDemandEventProfile::CANDIDATE], true), \RuntimeException::class, '倍率の段階を確認してください。');

        return DB::connection('sakemaru')->transaction(function () use ($client, $learned, $through, $keys, $stage) {
            $rows = [];
            $now = now();
            foreach (['items' => 'ITEM', 'categories' => 'CATEGORY'] as $group => $scope) {
                foreach ($learned[$group] as $scopeId => $profiles) {
                    foreach ($profiles as $key => $profile) {
                        $rows[] = ['client_id' => $client, 'profile_key' => $key, 'scope_type' => $scope, 'scope_id' => (int) $scopeId, 'stage' => $stage,
                            'positions' => json_encode($profile['positions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'pieces' => $profile['pieces'], 'instance_count' => $profile['instance_count'], 'trained_through' => $through,
                            'model_version' => DemandEventLearner::MODEL_VERSION, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }
            if ($keys !== null) {
                $rows = array_values(array_filter($rows, fn ($r) => in_array($r['profile_key'], $keys, true)));
            }
            WmsDemandEventProfile::where(['client_id' => $client, 'stage' => $stage])->when($keys !== null, fn ($q) => $q->whereIn('profile_key', $keys))->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                WmsDemandEventProfile::insert($chunk);
            }

            return count($rows);
        }, 3);
    }

    /**
     * Put the CANDIDATE profiles of the given keys into use: the ACTIVE rows of those keys are replaced by the
     * candidates (keys without a candidate keep their ACTIVE rows). Returns the number of promoted rows.
     */
    public function promote(int $client, array $keys): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($client, $keys) {
            $withCandidates = WmsDemandEventProfile::where(['client_id' => $client, 'stage' => WmsDemandEventProfile::CANDIDATE])->whereIn('profile_key', $keys)
                ->distinct()->pluck('profile_key')->all();
            if (! $withCandidates) {
                return 0;
            }
            WmsDemandEventProfile::where(['client_id' => $client, 'stage' => WmsDemandEventProfile::ACTIVE])->whereIn('profile_key', $withCandidates)->delete();

            return WmsDemandEventProfile::where(['client_id' => $client, 'stage' => WmsDemandEventProfile::CANDIDATE])->whereIn('profile_key', $withCandidates)
                ->update(['stage' => WmsDemandEventProfile::ACTIVE, 'updated_at' => now()]);
        }, 3);
    }

    /**
     * Learn one product only (fast path for the settings screen: one bounded read per instance for that product).
     * Returns the product's profiles keyed by profile key (standard events), or [] when nothing could be learned.
     *
     * @param  callable|null  $progress  fn(string $line) per instance
     */
    public function learnProduct(int $client, int $itemId, string $through, int $years = 3, ?callable $progress = null): array
    {
        $learned = $this->learn($client, $through, $years, [$itemId], $progress, null, [$itemId]);

        return $learned['items'][$itemId] ?? [];
    }

    /** Save one product's profiles (ITEM scope only) in the given stage, replacing that product's rows of the same keys and stage. */
    public function persistItem(int $client, int $itemId, array $profiles, string $through, string $stage = WmsDemandEventProfile::CANDIDATE): int
    {
        throw_unless(in_array($stage, [WmsDemandEventProfile::ACTIVE, WmsDemandEventProfile::CANDIDATE], true), \RuntimeException::class, '倍率の段階を確認してください。');

        return DB::connection('sakemaru')->transaction(function () use ($client, $itemId, $profiles, $through, $stage) {
            $now = now();
            $rows = [];
            foreach ($profiles as $key => $profile) {
                $rows[] = ['client_id' => $client, 'profile_key' => $key, 'scope_type' => 'ITEM', 'scope_id' => $itemId, 'stage' => $stage,
                    'positions' => json_encode($profile['positions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'pieces' => $profile['pieces'], 'instance_count' => $profile['instance_count'], 'trained_through' => $through,
                    'model_version' => DemandEventLearner::MODEL_VERSION, 'created_at' => $now, 'updated_at' => $now];
            }
            WmsDemandEventProfile::where(['client_id' => $client, 'scope_type' => 'ITEM', 'scope_id' => $itemId, 'stage' => $stage])->whereIn('profile_key', array_keys($profiles) ?: [''])->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                WmsDemandEventProfile::insert($chunk);
            }

            return count($rows);
        }, 3);
    }

    /** Put one product's CANDIDATE profiles of the given keys into use (the operator accepted them on the settings screen). */
    public function promoteItem(int $client, int $itemId, array $keys): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($client, $itemId, $keys) {
            $scope = ['client_id' => $client, 'scope_type' => 'ITEM', 'scope_id' => $itemId];
            $withCandidates = WmsDemandEventProfile::where($scope + ['stage' => WmsDemandEventProfile::CANDIDATE])->whereIn('profile_key', $keys)->pluck('profile_key')->all();
            if (! $withCandidates) {
                return 0;
            }
            WmsDemandEventProfile::where($scope + ['stage' => WmsDemandEventProfile::ACTIVE])->whereIn('profile_key', $withCandidates)->delete();

            return WmsDemandEventProfile::where($scope + ['stage' => WmsDemandEventProfile::CANDIDATE])->whereIn('profile_key', $withCandidates)
                ->update(['stage' => WmsDemandEventProfile::ACTIVE, 'updated_at' => now()]);
        }, 3);
    }

    /** Profile keys currently in use by an approved event (name and type of every APPROVED row). */
    public function keysInUse(int $client): array
    {
        $keys = [];
        foreach (WmsDemandEvent::where(['client_id' => $client, 'status' => 'APPROVED'])->get(['name', 'type']) as $event) {
            $keys[] = (string) $event->name;
            $keys[] = (string) $event->type;
        }

        return array_values(array_unique($keys));
    }

    public function policyItemIds(int $client): array
    {
        return WmsItemReorderPolicy::where('client_id', $client)->distinct()->pluck('item_id')->map(fn ($id) => (int) $id)->all();
    }

    private function series(array $daily, array $observed, array $dates): array
    {
        $series = [];
        foreach ($dates as $key) {
            $series[$key] = isset($observed[$key]) ? (int) ($daily[$key] ?? 0) : null;
        }

        return $series;
    }
}
