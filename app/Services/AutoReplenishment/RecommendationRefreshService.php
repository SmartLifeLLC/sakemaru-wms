<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Weekly re-calculation of saved recommendations with the same conditions the operator chose
 * (unit mode, review days, safety days). Approved events flow in through SettingsRecommendationService.
 * A change beyond the guard ratio is held for review instead of being saved.
 */
class RecommendationRefreshService
{
    public const GUARDED = RecommendationChangeGuard::GUARDED;

    public function __construct(private SettingsRecommendationService $recommendations, private PolicyService $policies, private ReplenishmentGuard $guard) {}

    /** Configured arrival_adaptive_v1 policies whose saved basis came from the recommendation with safety days (ordered by id, loaded lazily). */
    public function candidates(int $client, ?int $warehouse = null): Collection
    {
        $ids = [];
        WmsItemReorderPolicy::where(['client_id' => $client, 'is_configured' => true, 'strategy_key' => 'arrival_adaptive_v1'])
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse))->whereNotNull('calculation_basis')
            ->select(['id', 'calculation_basis'])->lazyById(100)->each(function ($p) use (&$ids) {
                $basis = $p->calculation_basis;
                if (is_array($basis) && isset($basis['safety_stock_days'], $basis['suggested_values']) && empty($basis['reason']) && ($basis['manually_adjusted_fields'] ?? []) === []) {
                    $ids[] = (int) $p->id;
                }
            });

        return collect($ids);
    }

    /**
     * @return array{outcome: string, policy_id: int, before: array, after: array|null, changes: array, reason: string|null}
     */
    public function refreshOne(WmsItemReorderPolicy $policy, float $maxChange, int $actorId, bool $apply): array
    {
        $basis = $policy->calculation_basis;
        $before = array_intersect_key($policy->toArray(), array_flip(self::GUARDED));
        $result = ['outcome' => null, 'policy_id' => (int) $policy->id, 'warehouse_id' => (int) $policy->warehouse_id, 'item_id' => (int) $policy->item_id,
            'before' => $before, 'after' => null, 'changes' => [], 'reason' => null];
        try {
            $form = $this->form($policy);
            $unitMode = $basis['unit_mode'] ?? 'AUTO';
            $options = ['unit_mode' => $unitMode === 'PALLET' ? 'AUTO' : $unitMode, 'review_days' => $basis['review_days'] ?? 7,
                'safety_stock_days' => $basis['safety_stock_days'], 'existing_reorder_point' => $policy->reorder_point_pieces];
            $recommendation = $this->recommendations->recommend((int) $policy->client_id, (int) $policy->warehouse_id, $form, $options);
            if (! $recommendation['available']) {
                return array_replace($result, ['outcome' => 'UNAVAILABLE', 'reason' => $recommendation['basis']['reason'] ?? '算定できません。']);
            }
            $after = array_intersect_key($recommendation['values'], array_flip(self::GUARDED));
            $result['after'] = $after;
            $guard = (new RecommendationChangeGuard)->evaluate($before, $after, $maxChange, is_array($basis['event_adjustment']['baseline'] ?? null) ? $basis['event_adjustment']['baseline'] : []);
            $changes = $guard['changes'];
            $result['changes'] = $changes;
            $forecastChanged = $this->forecastFingerprint($basis) !== $this->forecastFingerprint($recommendation['basis']);
            $result['forecast_changed'] = $forecastChanged;
            if (! $changes && ! $forecastChanged) {
                return array_replace($result, ['outcome' => 'UNCHANGED']);
            }
            if (! $changes) {
                // Same quantities, newer demand evidence (level, anchor, event forecast): save the basis so execution uses it.
                if (! $apply) {
                    return array_replace($result, ['outcome' => 'FORECAST_UPDATED', 'reason' => '（確認のみ・未保存）']);
                }
                $saved = $this->policies->saveWithState((int) $policy->client_id, (int) $policy->warehouse_id,
                    array_replace($form, $recommendation['values'], ['version' => (int) $policy->version]), (bool) $policy->is_enabled, $actorId, $recommendation['basis']);
                $result['version'] = (int) $saved->version;

                return array_replace($result, ['outcome' => 'FORECAST_UPDATED']);
            }
            if ($guard['held']) {
                return array_replace($result, ['outcome' => 'HELD', 'reason' => $guard['reason']]);
            }
            if (! $apply) {
                return array_replace($result, ['outcome' => 'UPDATED', 'reason' => '（確認のみ・未保存）']);
            }
            $saved = $this->policies->saveWithState((int) $policy->client_id, (int) $policy->warehouse_id,
                array_replace($form, $recommendation['values'], ['version' => (int) $policy->version]), (bool) $policy->is_enabled, $actorId, $recommendation['basis']);
            $result['version'] = (int) $saved->version;

            return array_replace($result, ['outcome' => 'UPDATED']);
        } catch (\Throwable $e) {
            if (! ($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException || $e instanceof \Illuminate\Validation\ValidationException)) {
                report($e);
            }

            return array_replace($result, ['outcome' => 'ERROR', 'reason' => $e instanceof \Illuminate\Validation\ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage()]);
        }
    }

    public function refresh(int $client, ?int $warehouse, float $maxChange, int $actorId, bool $apply, ?callable $progress = null): array
    {
        (new RecommendationChangeGuard)->evaluate([], [], $maxChange); // validates the ratio once
        $control = $this->guard->control($client, false);
        throw_unless($control->reset_state === 'COMPLETE', \RuntimeException::class, '初期化完了後に実行してください。');
        $summary = ['UPDATED' => 0, 'FORECAST_UPDATED' => 0, 'UNCHANGED' => 0, 'HELD' => 0, 'UNAVAILABLE' => 0, 'ERROR' => 0];
        $rows = [];
        foreach ($this->candidates($client, $warehouse) as $id) {
            $policy = WmsItemReorderPolicy::where('client_id', $client)->find($id);
            if (! $policy) {
                continue;
            }
            $row = $this->refreshOne($policy, $maxChange, $actorId, $apply);
            $summary[$row['outcome']]++;
            $rows[] = $row;
            if ($progress) {
                $progress($row);
            }
        }
        $payload = ['apply' => $apply, 'warehouse_id' => $warehouse, 'max_change_ratio' => $maxChange, 'summary' => $summary,
            'held' => array_values(array_filter($rows, fn ($r) => in_array($r['outcome'], ['HELD', 'ERROR', 'UNAVAILABLE'], true)))];
        Log::channel(config('logging.default'))->info('auto-replenishment weekly refresh', $payload);
        if ($apply) {
            $this->guard->event($client, 'POLICY_WEEKLY_REFRESH', $payload, actorId: $actorId);
        }

        return ['summary' => $summary, 'rows' => $rows];
    }

    /**
     * The last applied weekly refresh for the operator: when it ran, its summary and the products held or failed,
     * with item code/name, optionally limited to one warehouse. Null when no refresh has been applied yet.
     */
    public function lastReport(int $client, ?int $warehouse = null): ?array
    {
        $event = \Illuminate\Support\Facades\DB::connection('sakemaru')->table('wms_auto_order_events')->where(['client_id' => $client, 'event' => 'POLICY_WEEKLY_REFRESH'])
            ->orderByDesc('id')->first(['id', 'payload', 'created_at']);
        if (! $event) {
            return null;
        }
        $payload = json_decode((string) $event->payload, true) ?: [];
        $held = array_values(array_filter($payload['held'] ?? [], fn ($r) => $warehouse === null || (int) ($r['warehouse_id'] ?? 0) === $warehouse));
        $items = \Illuminate\Support\Facades\DB::connection('sakemaru')->table('items')->whereIn('id', array_unique(array_map(fn ($r) => (int) ($r['item_id'] ?? 0), $held)) ?: [-1])->pluck('name', 'id');
        $codes = \Illuminate\Support\Facades\DB::connection('sakemaru')->table('items')->whereIn('id', array_unique(array_map(fn ($r) => (int) ($r['item_id'] ?? 0), $held)) ?: [-1])->pluck('code', 'id');
        $rows = [];
        foreach ($held as $r) {
            $itemId = (int) ($r['item_id'] ?? 0);
            $rows[] = ['policy_id' => (int) ($r['policy_id'] ?? 0), 'warehouse_id' => (int) ($r['warehouse_id'] ?? 0), 'item_id' => $itemId,
                'item_code' => (string) ($codes[$itemId] ?? $itemId), 'item_name' => (string) ($items[$itemId] ?? '（商品情報なし）'),
                'outcome' => (string) ($r['outcome'] ?? ''), 'reason' => (string) ($r['reason'] ?? ''), 'changes' => $r['changes'] ?? []];
        }

        return ['at' => (string) $event->created_at, 'summary' => $payload['summary'] ?? [], 'max_change_ratio' => $payload['max_change_ratio'] ?? null,
            'warehouse_id' => $payload['warehouse_id'] ?? null, 'held' => $rows];
    }

    /** The demand evidence execution relies on: level, anchor, weekday pattern and dated event forecast. */
    private function forecastFingerprint(array $basis): string
    {
        $profile = $basis['demand_profile'] ?? null;
        if (! is_array($profile)) {
            return 'legacy:'.json_encode($basis['mean_daily'] ?? null);
        }
        $weekdays = array_map(fn ($w) => round((float) ($w['mean'] ?? 0), 4), $profile['weekdays'] ?? []);
        $events = array_map(fn ($d) => round((float) $d, 4), $profile['event_forecast']['days'] ?? []);

        return hash('sha256', json_encode([$profile['anchor_date'] ?? null, $profile['period'] ?? null, round((float) ($profile['mean'] ?? 0), 4), $weekdays, $events,
            $basis['safety_stock_pieces'] ?? null, $basis['batch_quantity_pieces'] ?? null]));
    }

    /** Editor-equivalent form for a saved policy (route + current shipping-unit binding). */
    private function form(WmsItemReorderPolicy $policy): array
    {
        $form = $policy->toArray();
        $form['is_enabled'] = (bool) $policy->is_enabled;
        $form['order_unit_mode'] = $policy->order_unit_mode ?? 'AUTO';
        $form['shipping_unit_mode'] = app(PalletUnitService::class)->binding((int) $policy->id) ? 'PALLET' : 'STANDARD';
        if ($policy->channel === 'LOGISTICS' && app(ShippingQuantityService::class)->binding((int) $policy->id)) {
            $form['shipping_unit_mode'] = 'SHIPMENT';
        }
        unset($form['calculation_basis']);

        return $form;
    }
}
