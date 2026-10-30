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
    public const GUARDED = ['reorder_point_pieces', 'target_stock_pieces', 'max_order_pieces', 'min_order_pieces', 'order_multiple_pieces'];

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
            $changes = [];
            foreach (self::GUARDED as $key) {
                if ((int) $before[$key] !== (int) $after[$key]) {
                    $changes[$key] = ['from' => (int) $before[$key], 'to' => (int) $after[$key]];
                }
            }
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
            foreach ($changes as $key => $change) {
                $ratio = $change['from'] > 0 ? $change['to'] / $change['from'] : INF;
                if ($ratio > $maxChange || $ratio < 1 / $maxChange) {
                    return array_replace($result, ['outcome' => 'HELD', 'reason' => "{$key} が {$change['from']} → {$change['to']}（×".round($ratio, 2)."）で変化幅の上限 ×{$maxChange} を超えるため保留。画面で確認してください。"]);
                }
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
        throw_unless($maxChange >= 1.1 && $maxChange <= 10, \RuntimeException::class, '変化幅の上限は1.1〜10で指定してください。');
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
