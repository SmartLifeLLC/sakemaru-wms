<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Versioned logistics profiles. Registration does not enable or reconfigure stores. */
class ShippingQuantityService
{
    private ?bool $installed = null;

    public function installed(): bool
    {
        return $this->installed ??= Schema::connection('sakemaru')->hasTable('wms_shipping_quantity_profiles')
            && Schema::connection('sakemaru')->hasTable('wms_policy_shipping_quantities');
    }

    public function find(int $client, int $source, int $item, bool $lock = false): ?object
    {
        return $this->installed() ? DB::connection('sakemaru')->table('wms_shipping_quantity_profiles')
            ->where(['client_id' => $client, 'source_warehouse_id' => $source, 'item_id' => $item])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
    }

    public function save(int $client, int $source, int $item, mixed $quantities, int $version, int $actor, ?array $basis = null): object
    {
        throw_unless($this->installed(), \RuntimeException::class, '出荷数量候補の追加migrationを実行してください。');
        try {
            $quantities = app(ShippingQuantitySet::class)->normalize($quantities);
        } catch (\JsonException) {
            throw new \RuntimeException('出荷数量候補は [384,576,768] の形式で入力してください。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($client, $source, $item, $quantities, $version, $actor, $basis) {
            $guard = app(ReplenishmentGuard::class);
            $guard->control($client);
            $db = DB::connection('sakemaru');
            throw_unless($db->table('warehouses')->where(['id' => $source, 'client_id' => $client, 'is_active' => 1, 'is_virtual' => 0])->exists(), \RuntimeException::class, '移動元倉庫が無効です。');
            $product = $db->table('items')->where(['id' => $item, 'client_id' => $client, 'is_active' => 1, 'is_ended' => 0])->first(['capacity_case']);
            throw_unless($product && $product->capacity_case > 0, \RuntimeException::class, '商品のケース入数を確認してください。');
            $before = $this->find($client, $source, $item, true);
            throw_unless((int) ($before?->version ?? 0) === $version, \RuntimeException::class, '出荷数量候補が他の操作で変更されました。商品設定を開き直してください。');
            $previousBasis = $before ? json_decode($before->basis ?? '{}', true) : [];
            throw_if($basis && isset($basis['period']['end'], $previousBasis['period']['end'])
                && $basis['period']['end'] < $previousBasis['period']['end'], \RuntimeException::class,
                '登録済み候補より古い期間の実績です。業務日と算定期間を確認してください。');
            try {
                $previousQuantities = $before ? $this->quantities($before) : [];
            } catch (\RuntimeException) {
                $previousQuantities = []; // An administrator can repair a corrupt stored array.
            }
            if ($before && $previousQuantities === $quantities && (int) $before->capacity_case === (int) $product->capacity_case) {
                if ($basis && $previousBasis !== $basis) {
                    $db->table('wms_shipping_quantity_profiles')->where('id', $before->id)->update([
                        'basis' => json_encode($basis, JSON_THROW_ON_ERROR), 'updated_by' => $actor, 'updated_at' => now(),
                    ]);
                    $guard->event($client, 'SHIPPING_QUANTITY_EVIDENCE_REFRESHED', ['profile_id' => $before->id, 'basis' => $basis], actorId: $actor);
                }

                return $this->find($client, $source, $item); // Same candidates never invalidate existing store bindings.
            }
            $values = ['quantities' => json_encode($quantities, JSON_THROW_ON_ERROR), 'capacity_case' => (int) $product->capacity_case,
                'basis' => $basis ? json_encode($basis, JSON_THROW_ON_ERROR) : null,
                'version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()];
            if ($before) {
                $db->table('wms_shipping_quantity_profiles')->where('id', $before->id)->update($values);
            } else {
                $db->table('wms_shipping_quantity_profiles')->insert($values + ['client_id' => $client, 'source_warehouse_id' => $source, 'item_id' => $item, 'created_at' => now()]);
            }
            $after = $this->find($client, $source, $item);
            $guard->event($client, 'SHIPPING_QUANTITIES_SAVED', ['before' => $before, 'after' => $after], actorId: $actor);

            return $after;
        }, 3);
    }

    public function quantities(object $profile): array
    {
        try {
            return app(ShippingQuantitySet::class)->normalize($profile->quantities);
        } catch (\Throwable) {
            throw new \RuntimeException('登録済みの出荷数量候補を読み込めません。商品設定で登録し直してください。');
        }
    }

    public function binding(?int $policy): ?object
    {
        return $policy && $this->installed() ? DB::connection('sakemaru')->table('wms_policy_shipping_quantities')->where('policy_id', $policy)->first() : null;
    }

    public function prepare(int $client, array &$values, array $selection, ?int $policy): ?object
    {
        $binding = $this->binding($policy);
        if (($selection['shipping_unit_mode'] ?? ($binding ? 'SHIPMENT' : 'STANDARD')) !== 'SHIPMENT') {
            return null;
        }
        throw_unless($values['channel'] === 'LOGISTICS', \RuntimeException::class, '出荷数量候補は物流発注（店間）で選択してください。');
        throw_unless($values['strategy_key'] === 'arrival_adaptive_v1', \RuntimeException::class, '出荷数量候補を使う場合は「おすすめ設定」で通常量と補充目標を計算してください。');
        $profile = $this->find($client, (int) $values['source_warehouse_id'], (int) $values['item_id'], true);
        throw_unless($profile, \RuntimeException::class, '移動元倉庫・商品の出荷数量候補を登録してください。');
        throw_if(! array_key_exists('shipping_unit_mode', $selection) && $binding && (int) $binding->profile_id !== (int) $profile->id,
            \RuntimeException::class, '移動元倉庫が変わるため、出荷数量候補を確認して商品設定を保存してください。');
        throw_unless((int) ($selection['shipping_profile_version'] ?? $binding?->profile_version) === (int) $profile->version,
            \RuntimeException::class, '出荷数量候補が更新されています。商品設定を開き直して保存してください。');
        $capacity = (int) DB::connection('sakemaru')->table('items')->where(['client_id' => $client, 'id' => $values['item_id']])->value('capacity_case');
        $this->assertCapacity($profile, $capacity);
        $quantities = $this->quantities($profile);
        $set = app(ShippingQuantitySet::class);
        $values['order_multiple_pieces'] = $set->gcd($quantities);
        throw_unless($set->floor($quantities, min((int) $values['max_order_pieces'], (int) ($values['target_stock_pieces'] ?? $values['reorder_point_pieces'])), (int) $values['min_order_pieces']) > 0,
            \RuntimeException::class, '最小発注数・最大発注数・補充目標に収まる出荷数量候補がありません。数量条件を見直してください。');

        return $profile;
    }

    public function persist(int $policy, ?object $profile): void
    {
        if (! $this->installed()) {
            return;
        }
        $q = DB::connection('sakemaru')->table('wms_policy_shipping_quantities');
        if (! $profile) {
            $q->where('policy_id', $policy)->delete();

            return;
        }
        $q->upsert([['policy_id' => $policy, 'profile_id' => $profile->id, 'profile_version' => $profile->version, 'created_at' => now(), 'updated_at' => now()]],
            ['policy_id'], ['profile_id', 'profile_version', 'updated_at']);
    }

    /** One indexed batch read; never scan shipping history while ordering. */
    public function bindings(int $client, int $warehouse, array $items, bool $lock): Collection
    {
        return $this->installed() ? DB::connection('sakemaru')->table('wms_policy_shipping_quantities as b')
            ->join('wms_item_reorder_policies as p', 'p.id', '=', 'b.policy_id')->leftJoin('wms_shipping_quantity_profiles as m', 'm.id', '=', 'b.profile_id')
            ->where('p.client_id', $client)->where('p.warehouse_id', $warehouse)->whereIn('p.item_id', $items)
            ->when($lock, fn ($q) => $q->lockForUpdate())->get(['b.policy_id', 'b.profile_version', 'm.*'])->keyBy('policy_id') : collect();
    }

    public function assertBinding(object $row, array $policy, int $capacity): array
    {
        throw_unless($row->id && $policy['channel'] === 'LOGISTICS' && $policy['strategy_key'] === 'arrival_adaptive_v1'
            && (int) $row->client_id === (int) $policy['client_id'] && (int) $row->source_warehouse_id === (int) $policy['source_warehouse_id']
            && (int) $row->item_id === (int) $policy['item_id'] && (int) $row->profile_version === (int) $row->version,
            \RuntimeException::class, '出荷数量候補が変更・削除されています。商品設定を開き直して保存してください。');
        $this->assertCapacity($row, $capacity);
        $quantities = $this->quantities($row);
        $this->assertPolicyMatches($policy, $quantities);

        return ['mode' => 'SHIPMENT', 'id' => (int) $row->id, 'version' => (int) $row->version, 'quantities' => $quantities];
    }

    /** The saved order multiple must be the common unit of the registered candidates (same rule for the daily run and the past replay). */
    public function assertPolicyMatches(array $policy, array $quantities): void
    {
        throw_unless($quantities !== [] && (int) ($policy['order_multiple_pieces'] ?? 0) === app(ShippingQuantitySet::class)->gcd($quantities),
            \RuntimeException::class, '発注単位と出荷数量候補が一致しません。商品設定を保存し直してください。');
    }

    public function assertCapacity(object $profile, int $capacity): void
    {
        throw_unless($capacity > 0 && (int) $profile->capacity_case === $capacity, \RuntimeException::class, 'ケース入数が変更されています。出荷数量候補を確認して登録し直してください。');
    }
}
