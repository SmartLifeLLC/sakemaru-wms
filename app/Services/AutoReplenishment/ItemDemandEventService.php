<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use App\Models\WmsItemDemandEvent;
use App\Models\WmsItemReorderPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which standard events (年末年始・お盆・GW) apply to which product.
 *
 * Set at release from the learned factors and kept by hand afterwards: only an applied event changes the
 * recommendation, the factor baseline and the replay for that product. No row = not applied.
 */
class ItemDemandEventService
{
    /** A product is suggested for an event when its own learned factors show at least this block-mean uplift ... */
    public const SUGGEST_MIN_MEAN = 1.3;

    /** ... over at least this many instances (years). */
    public const SUGGEST_MIN_INSTANCES = 2;

    /** Current applicability of every product that has an auto-order policy: item_id => [type => bool]. */
    public function matrix(int $client): array
    {
        $items = WmsItemReorderPolicy::where('client_id', $client)->distinct()->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        $matrix = array_fill_keys($items, array_fill_keys(DemandEventCalendar::STANDARD_TYPES, false));
        foreach (WmsItemDemandEvent::where('client_id', $client)->whereIn('item_id', $items ?: [-1])->get() as $row) {
            if (isset($matrix[(int) $row->item_id][$row->event_type])) {
                $matrix[(int) $row->item_id][$row->event_type] = (bool) $row->is_enabled;
            }
        }

        return $matrix;
    }

    /**
     * Suggested applicability from the product's own learned factors (category factors are shown, never applied):
     * item_id => [type => ['suggest' => bool, 'mean' => float|null, 'peak' => float|null, 'instances' => int, 'category_mean' => float|null]].
     */
    public function suggestions(int $client, array $itemIds): array
    {
        if (! $itemIds) {
            return [];
        }
        $categories = DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('id', $itemIds)->pluck('item_category3_id', 'id');
        $profiles = WmsDemandEventProfile::where('client_id', $client)->whereIn('profile_key', DemandEventCalendar::STANDARD_TYPES)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('scope_type', 'ITEM')->whereIn('scope_id', $itemIds))
                ->orWhere(fn ($q) => $q->where('scope_type', 'CATEGORY')->whereIn('scope_id', $categories->filter()->unique()->values()->all() ?: [-1])))
            ->orderByRaw("CASE WHEN stage = 'ACTIVE' THEN 0 ELSE 1 END")
            ->get();
        $byScope = [];
        foreach ($profiles as $profile) {
            // Suggestions prefer the factors in use; a candidate (not yet approved) is shown only when nothing is in use.
            $byScope[$profile->scope_type][(int) $profile->scope_id][$profile->profile_key] ??= $profile;
        }
        $result = [];
        foreach ($itemIds as $itemId) {
            foreach (DemandEventCalendar::STANDARD_TYPES as $type) {
                $own = $byScope['ITEM'][$itemId][$type] ?? null;
                $category = isset($categories[$itemId]) ? ($byScope['CATEGORY'][(int) $categories[$itemId]][$type] ?? null) : null;
                $ownSummary = $own ? $this->blockSummary(is_array($own->positions) ? $own->positions : []) : null;
                $categorySummary = $category ? $this->blockSummary(is_array($category->positions) ? $category->positions : []) : null;
                $result[$itemId][$type] = ['suggest' => $ownSummary !== null && (int) $own->instance_count >= self::SUGGEST_MIN_INSTANCES && $ownSummary['mean'] >= self::SUGGEST_MIN_MEAN,
                    'mean' => $ownSummary['mean'] ?? null, 'peak' => $ownSummary['peak'] ?? null, 'instances' => $own ? (int) $own->instance_count : 0,
                    'stage' => $own?->stage, 'category_mean' => $categorySummary['mean'] ?? null];
            }
        }

        return $result;
    }

    /**
     * Replace the applicability of the given products: $applied = item_id => [type, ...] (types enabled; others disabled).
     * Returns ['enabled' => n, 'disabled' => n, 'unchanged' => n].
     */
    public function apply(int $client, array $applied, int $actorId, ?string $note = null): array
    {
        $counts = ['enabled' => 0, 'disabled' => 0, 'unchanged' => 0];
        DB::connection('sakemaru')->transaction(function () use ($client, $applied, $actorId, $note, &$counts) {
            foreach ($applied as $itemId => $types) {
                $itemId = (int) $itemId;
                throw_unless($itemId > 0, \RuntimeException::class, '商品IDを確認してください。');
                $types = array_values(array_unique(array_map('strval', (array) $types)));
                foreach ($types as $type) {
                    throw_unless(in_array($type, DemandEventCalendar::STANDARD_TYPES, true), \RuntimeException::class, "イベント種別を確認してください（{$type}）。標準イベントのみ商品に適用できます。");
                }
                $existing = WmsItemDemandEvent::where(['client_id' => $client, 'item_id' => $itemId])->lockForUpdate()->get()->keyBy('event_type');
                foreach (DemandEventCalendar::STANDARD_TYPES as $type) {
                    $enable = in_array($type, $types, true);
                    $row = $existing[$type] ?? null;
                    if ($row === null && ! $enable) {
                        $counts['unchanged']++;

                        continue;
                    }
                    if ($row !== null && (bool) $row->is_enabled === $enable) {
                        $counts['unchanged']++;

                        continue;
                    }
                    $row ??= new WmsItemDemandEvent(['client_id' => $client, 'item_id' => $itemId, 'event_type' => $type]);
                    $row->fill(['is_enabled' => $enable, 'updated_by' => $actorId, 'note' => $note ?? $row->note]);
                    $row->save();
                    $counts[$enable ? 'enabled' : 'disabled']++;
                }
            }
        }, 3);

        return $counts;
    }

    /**
     * Everything the settings screen shows for one product: per standard type the application, the learned factors
     * (ACTIVE preferred, CANDIDATE flagged), the suggestion, and the registered events of the next 12 months.
     *
     * @return array{installed: bool, has_profiles: bool, types: array<string, array>}
     */
    public function productSetup(int $client, int $itemId, ?string $asOf = null): array
    {
        if (! Schema::connection('sakemaru')->hasTable('wms_item_demand_events') || ! Schema::connection('sakemaru')->hasTable('wms_demand_event_profiles')) {
            return ['installed' => false, 'has_profiles' => false, 'types' => []];
        }
        $asOf = $asOf ?: now('Asia/Tokyo')->toDateString();
        $applied = WmsItemDemandEvent::where(['client_id' => $client, 'item_id' => $itemId])->get()->keyBy('event_type');
        $profiles = WmsDemandEventProfile::where(['client_id' => $client, 'scope_type' => 'ITEM', 'scope_id' => $itemId, 'model_version' => DemandEventLearner::MODEL_VERSION])
            ->whereIn('profile_key', DemandEventCalendar::STANDARD_TYPES)->get()->groupBy('profile_key');
        $events = WmsDemandEvent::where('client_id', $client)->whereIn('type', DemandEventCalendar::STANDARD_TYPES)->where('status', '!=', 'DISABLED')
            ->whereBetween('start_date', [$asOf, CarbonImmutable::parse($asOf)->addYear()->toDateString()])->orderBy('start_date')->get()->groupBy('type');
        $types = [];
        $hasProfiles = false;
        foreach (DemandEventCalendar::STANDARD_TYPES as $type) {
            $active = $profiles->get($type)?->firstWhere('stage', WmsDemandEventProfile::ACTIVE);
            $candidate = $profiles->get($type)?->firstWhere('stage', WmsDemandEventProfile::CANDIDATE);
            $shown = $active ?: $candidate;
            $summary = $shown ? $this->blockSummary(is_array($shown->positions) ? $shown->positions : []) : null;
            $hasProfiles = $hasProfiles || $shown !== null;
            $next = $events->get($type)?->first();
            $types[$type] = ['label' => DemandEventCalendar::NAMES[$type], 'applied' => (bool) ($applied[$type]->is_enabled ?? false),
                'suggest' => $summary !== null && (int) $shown->instance_count >= self::SUGGEST_MIN_INSTANCES && $summary['mean'] >= self::SUGGEST_MIN_MEAN,
                'mean' => $summary['mean'] ?? null, 'peak' => $summary['peak'] ?? null, 'instances' => $shown ? (int) $shown->instance_count : 0,
                'stage' => $shown?->stage, 'has_candidate' => $candidate !== null && $active !== null, 'trained_through' => $shown?->trained_through?->toDateString(),
                'event' => $next ? ['id' => (int) $next->id, 'name' => $next->name, 'start' => $next->start_date->toDateString(), 'end' => $next->end_date->toDateString(), 'status' => $next->status] : null];
        }

        return ['installed' => true, 'has_profiles' => $hasProfiles, 'types' => $types];
    }

    /** Block-day summary of one learned profile: mean and peak of max(factor, factor_weekday) over block positions. */
    public function blockSummary(array $positions): ?array
    {
        $values = [];
        foreach ($positions as $pos => $p) {
            if (str_starts_with((string) $pos, 'pre') || str_starts_with((string) $pos, 'post')) {
                continue;
            }
            $values[] = max((float) ($p['factor'] ?? 0), (float) ($p['factor_weekday'] ?? 0));
        }
        if (! $values) {
            return null;
        }

        return ['mean' => round(array_sum($values) / count($values), 2), 'peak' => round(max($values), 2), 'positions' => count($values)];
    }
}
