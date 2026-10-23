<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PolicyService
{
    public function __construct(private readonly ReplenishmentGuard $guard) {}

    public function save(int $clientId, int $warehouseId, array $data, int $actorId, ?array $calculationBasis = null): WmsItemReorderPolicy
    {
        $values = $this->validate($data);
        // Arrival dates are resolved from the existing contractor/warehouse settings.
        $values['arrival_rule'] = null;
        if ($values['channel'] === 'EOS') {
            $values['source_warehouse_id'] = $values['delivery_course_id'] = null;
        }

        if ($calculationBasis) {
            $scope = $calculationBasis['scope'] ?? [];
            foreach (['client_id' => $clientId, 'warehouse_id' => $warehouseId] + array_intersect_key($values, array_flip(['item_id', 'channel', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id'])) as $key => $value) {
                if (($scope[$key] ?? null) != $value) {
                    $calculationBasis = null;
                    break;
                }
            }
        }
        throw_if($values['strategy_key'] === 'arrival_adaptive_v1' && $calculationBasis
            && ! in_array($calculationBasis['batch_rule'] ?? '', ['observed-mode-with-historical-maximum', 'mean-demand-times-review-days', 'preserved-store-normal-with-shipping-quantities'], true),
            \RuntimeException::class, '通常量をまとめて補充する方法へ変更する場合は、「おすすめ設定」で実績を再計算してください。');
        $values['calculation_basis'] = $calculationBasis;

        return $this->persist($clientId, $warehouseId, $values, $actorId, $data);
    }

    public function validate(array $data): array
    {
        return Validator::make($data, [
            'item_id' => 'required|integer|min:1', 'version' => 'nullable|integer|min:1',
            'reorder_point_pieces' => 'required|integer|min:1|max:2147483647',
            'max_order_pieces' => 'required|integer|min:1|max:2147483647',
            'min_order_pieces' => 'required|integer|min:1|lte:max_order_pieces',
            'order_multiple_pieces' => 'required|integer|min:1|max:2147483647',
            'target_stock_pieces' => in_array($data['strategy_key'] ?? '', ['arrival_batch_v1', 'arrival_adaptive_v1'], true)
                ? 'nullable|integer|min:1|max:2147483647' : 'nullable|integer|gte:reorder_point_pieces|max:2147483647',
            'channel' => 'required|in:EOS,LOGISTICS', 'contractor_id' => 'required|integer|min:1',
            'supplier_id' => 'nullable|integer|min:1', 'source_warehouse_id' => 'nullable|integer|min:1',
            'delivery_course_id' => 'nullable|integer|min:1',
            'order_unit_mode' => 'sometimes|required|in:AUTO,PIECE,CASE',
            'strategy_key' => 'required|in:'.implode(',', array_keys(config('replenishment.strategies'))),
        ], trans('auto-replenishment.messages', [], 'ja'), trans('auto-replenishment.attributes', [], 'ja'))->validate();
    }

    public function saveWithState(int $clientId, int $warehouseId, array $data, bool $enabled, int $actorId, ?array $calculationBasis = null): WmsItemReorderPolicy
    {
        return DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $data, $enabled, $actorId, $calculationBasis) {
            $policy = $this->save($clientId, $warehouseId, $data, $actorId, $calculationBasis);
            if ($enabled) {
                $this->setEnabled($clientId, (int) $policy->id, (int) $policy->version, true, $actorId);
            }

            return $policy->refresh();
        }, 3);
    }

    private function persist(int $clientId, int $warehouseId, array $values, int $actorId, array $selection): WmsItemReorderPolicy
    {
        return DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $values, $actorId, $selection) {
            $c = $this->guard->control($clientId);
            throw_unless($c->reset_state === 'COMPLETE', \RuntimeException::class, '初期化完了後に設定してください。');
            $db = DB::connection('sakemaru');
            throw_unless($db->table('warehouses')->where(['id' => $warehouseId, 'client_id' => $clientId, 'is_active' => 1, 'is_virtual' => 0])->exists(), \RuntimeException::class, '倉庫が無効です。');
            throw_unless($db->table('items')->where(['id' => $values['item_id'], 'client_id' => $clientId])->exists(), \RuntimeException::class, '商品が無効です。');
            app(OrderRouteEligibility::class)->assertEligible($values['channel'], (int) $values['contractor_id']);
            $this->guard->lockScope($clientId, $warehouseId, (int) $values['item_id']);
            $policy = WmsItemReorderPolicy::firstOrNew(['client_id' => $clientId, 'warehouse_id' => $warehouseId, 'item_id' => $values['item_id']]);
            throw_if($policy->exists && (int) ($values['version'] ?? 0) !== (int) $policy->version, \RuntimeException::class, '他の操作で設定が変更されています。再読込してください。');
            $before = $policy->toArray();
            $mode = $values['order_unit_mode'] ?? $policy->order_unit_mode ?? 'AUTO';
            $units = app(OrderUnitService::class);
            $units->assertRecommendationMode($values['calculation_basis'], $mode);
            if ($mode !== 'AUTO' || ($values['channel'] === 'LOGISTICS' && array_key_exists('order_unit_mode', $values))) {
                $item = $db->table('items')->where(['id' => $values['item_id'], 'client_id' => $clientId])->first();
                $unit = $units->forEditor($clientId, $item, $values['channel'], $mode, (int) ($values['source_warehouse_id'] ?? 0));
                $multiple = app(ShippingQuantitySet::class)->gcd([$values['order_multiple_pieces'], $unit['packing_unit']]);
                $step = intdiv($values['order_multiple_pieces'], $multiple) * $unit['packing_unit'];
                throw_if((int) (ceil($values['min_order_pieces'] / $step) * $step) > $values['max_order_pieces'], \RuntimeException::class,
                    '選択した発注単位で最小発注数・最大発注数を満たせません。数量条件を見直してください。');
            }
            $pallets = app(PalletUnitService::class);
            $palletBefore = $pallets->binding($policy->exists ? (int) $policy->id : null);
            $pallet = $pallets->prepare($clientId, $values, $selection, $policy->exists ? (int) $policy->id : null);
            $shipping = app(ShippingQuantityService::class);
            $shippingBefore = $shipping->binding($policy->exists ? (int) $policy->id : null);
            $profile = $shipping->prepare($clientId, $values, $selection, $policy->exists ? (int) $policy->id : null);
            if ($values['calculation_basis']) {
                $suggested = $values['calculation_basis']['suggested_values'] ?? [];
                $values['calculation_basis']['applied_values'] = array_intersect_key($values, $suggested);
                $values['calculation_basis']['manually_adjusted_fields'] = array_keys(array_filter($suggested, fn ($v, $k) => ($values[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));
            }
            unset($values['version']);
            $policy->fill($values + ['is_configured' => true, 'is_enabled' => false, 'updated_by' => $actorId]);
            $policy->version = $policy->exists ? $policy->version + 1 : 1;
            $policy->save();
            $pallets->persist((int) $policy->id, $pallet);
            $shipping->persist((int) $policy->id, $profile);
            $this->guard->event($clientId, 'POLICY_SAVED', ['before' => $before, 'after' => $policy->toArray(),
                'shipping_before' => $shippingBefore, 'shipping_after' => $shipping->binding((int) $policy->id),
                'pallet_before' => $palletBefore, 'pallet_after' => $pallets->binding((int) $policy->id)], actorId: $actorId);

            return $policy;
        }, 3);
    }

    public function setEnabled(int $clientId, int $id, int $version, bool $enabled, int $actorId): void
    {
        DB::connection('sakemaru')->transaction(function () use ($clientId, $id, $version, $enabled, $actorId) {
            $c = $this->guard->control($clientId);
            throw_unless($c->reset_state === 'COMPLETE', \RuntimeException::class, '初期化が未完了です。');
            $p = WmsItemReorderPolicy::where('client_id', $clientId)->lockForUpdate()->findOrFail($id);
            throw_if((int) $p->version !== $version || ($enabled && ! $p->is_configured), \RuntimeException::class, '再設定が必要です。');
            if ($enabled) {
                $snapshot = app(SnapshotReader::class)->read($p);
                if (in_array($p->strategy_key, ['arrival_batch_v1', 'arrival_adaptive_v1'], true)) {
                    $result = app(StrategyCalculator::class)->calculate($p->toArray(), $snapshot);
                    throw_if($result['phase'] === 'HOLD', \RuntimeException::class, $result['reason']);
                }
            }
            $p->update(['is_enabled' => $enabled, 'version' => $version + 1, 'updated_by' => $actorId]);
            $this->guard->event($clientId, 'POLICY_ENABLED_CHANGED', ['policy_id' => $id, 'enabled' => $enabled], actorId: $actorId);
        }, 3);
    }

    public function setSelectedEnabled(int $clientId, int $warehouseId, array $versions, bool $enabled, int $actorId): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $versions, $enabled, $actorId) {
            $control = $this->guard->control($clientId);
            throw_unless($control->reset_state === 'COMPLETE', \RuntimeException::class, '初期化が未完了です。');
            $policies = WmsItemReorderPolicy::where(['client_id' => $clientId, 'warehouse_id' => $warehouseId])
                ->whereIn('id', array_keys($versions))->orderBy('id')->lockForUpdate()->get();
            $plan = app(PolicyStateChangePlan::class)->build($versions, $policies->toArray(), $enabled);
            $codes = DB::connection('sakemaru')->table('items')->where('client_id', $clientId)
                ->whereIn('id', $policies->pluck('item_id'))->pluck('code', 'id');
            foreach ($policies as $policy) {
                if ((bool) $policy->is_enabled === $enabled) {
                    continue;
                }
                try {
                    $this->setEnabled($clientId, (int) $policy->id, (int) $policy->version, $enabled, $actorId);
                } catch (\RuntimeException $exception) {
                    throw new \RuntimeException('商品CD '.($codes[$policy->item_id] ?? '不明').'：'.$exception->getMessage(), 0, $exception);
                }
            }

            return $plan['change_count'];
        }, 3);
    }

    public function saveWarehouse(int $clientId, int $warehouseId, array $config, bool $enabled, int $actorId): void
    {
        $config = Validator::make($config, [
            'version' => 'required|integer|min:0', 'generation' => 'required|integer|min:1',
            'execution_time' => 'required|date_format:H:i', 'weekdays' => 'required|array|min:1', 'weekdays.*' => 'integer|between:0,6',
            'sunday_execution_time' => 'required|date_format:H:i',
        ], trans('auto-replenishment.messages', [], 'ja'), trans('auto-replenishment.attributes', [], 'ja'))->validate();
        DB::connection('sakemaru')->transaction(function () use ($clientId, $warehouseId, $config, $enabled, $actorId) {
            $c = $this->guard->control($clientId);
            throw_unless($c->reset_state === 'COMPLETE', \RuntimeException::class, '初期化が未完了です。');
            $db = DB::connection('sakemaru');
            throw_unless($db->table('warehouses')->where(['id' => $warehouseId, 'client_id' => $clientId, 'is_active' => 1, 'is_virtual' => 0])->exists(), \RuntimeException::class, '倉庫が無効です。');
            $existing = $db->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
            throw_if((int) ($existing->replenishment_version ?? 0) !== (int) $config['version'] || (int) $c->generation !== (int) $config['generation'], \RuntimeException::class, '倉庫または全体設定が変更されています。再読込してください。');
            $db->table('wms_auto_order_controls')->where('client_id', $clientId)->update(['generation' => $c->generation + 1, 'updated_at' => now()]);
            $db->table('wms_warehouse_auto_order_settings')->updateOrInsert(['warehouse_id' => $warehouseId], [
                'is_auto_order_enabled' => $enabled, 'replenishment_config' => json_encode($config, JSON_THROW_ON_ERROR),
                'replenishment_version' => ($existing->replenishment_version ?? 0) + 1, 'updated_at' => now(),
            ]);
            $this->guard->event($clientId, 'WAREHOUSE_SETTINGS_CHANGED', ['warehouse_id' => $warehouseId, 'enabled' => $enabled, 'config' => $config], actorId: $actorId);
        }, 3);
    }
}
