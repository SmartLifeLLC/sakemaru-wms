<?php

namespace App\Services\AutoReplenishment;

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
            foreach ($this->calendar->proposals($year, $this->reader->holidays($year)) as $event) {
                if ($event['end'] <= $through && $event['start'] >= $earliest) {
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
    public function learn(int $client, string $through, int $years = 3, ?array $itemIds = null, ?callable $progress = null): array
    {
        $instances = $this->trainingInstances($through, $years);
        throw_unless($instances, \RuntimeException::class, '学習できる過去のイベントがありません。');
        $namedCounts = array_count_values(array_column(array_filter($instances, fn ($i) => $i['type'] === 'LONG_WEEKEND'), 'name'));
        $allEvents = $instances;
        $keep = $itemIds === null ? null : array_flip($itemIds);
        $ratios = [];   // key => item => compact instance ratios (positions + pieces only)
        $pieces = [];   // item => total actual pieces seen
        $rangesRead = 0;
        // One bounded read per instance (about 100 days, every product) keeps memory flat; products too small to vote
        // in a category profile are dropped immediately unless an ITEM profile was requested for them.
        foreach ($instances as $instance) {
            $window = ['start' => CarbonImmutable::parse($instance['start'])->subDays(DemandEventLearner::PRE_WINDOW_MAX + $instance['pre_days'])->toDateString(),
                'end' => CarbonImmutable::parse($instance['end'])->addDays($instance['post_days'])->toDateString()];
            $data = $this->reader->productTotals($client, $window['start'], $window['end']);
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
            $layout = $this->learner->layout($instance, $yearEvents);
            foreach ($data['items'] as $itemId => $daily) {
                $result = $this->learner->instanceRatios($this->series($daily, $data['observed'], $dates), $instance, $yearEvents, $layout);
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
        $categories = $this->reader->itemCategories($client);
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

    /** Replace the client's learned profiles (one model version) inside a transaction. */
    public function persist(int $client, array $learned, string $through): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($client, $learned, $through) {
            $rows = [];
            $now = now();
            foreach (['items' => 'ITEM', 'categories' => 'CATEGORY'] as $group => $scope) {
                foreach ($learned[$group] as $scopeId => $profiles) {
                    foreach ($profiles as $key => $profile) {
                        $rows[] = ['client_id' => $client, 'profile_key' => $key, 'scope_type' => $scope, 'scope_id' => (int) $scopeId,
                            'positions' => json_encode($profile['positions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'pieces' => $profile['pieces'], 'instance_count' => $profile['instance_count'], 'trained_through' => $through,
                            'model_version' => DemandEventLearner::MODEL_VERSION, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
            }
            WmsDemandEventProfile::where('client_id', $client)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                WmsDemandEventProfile::insert($chunk);
            }

            return count($rows);
        }, 3);
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
