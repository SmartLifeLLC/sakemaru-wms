<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Explicit physical pallets. Historical GCDs never create or enable these settings. */
class PalletUnitService
{
    private ?bool $installed = null;

    public function installed(): bool
    {
        return $this->installed ??= Schema::connection('sakemaru')->hasTable('wms_pallet_units')
            && Schema::connection('sakemaru')->hasTable('wms_policy_pallet_units');
    }

    public function find(int $client, int $source, int $item, bool $lock = false): ?object
    {
        if (! $this->installed()) {
            return null;
        }

        return DB::connection('sakemaru')->table('wms_pallet_units')
            ->where(['client_id' => $client, 'source_warehouse_id' => $source, 'item_id' => $item])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function save(int $client, int $source, int $item, mixed $pieces, int $version, int $actor): object
    {
        throw_unless($this->installed(), \RuntimeException::class, 'パレット設定の追加migrationを実行してください。');
        $data = Validator::make(['pieces' => $pieces], ['pieces' => 'required|integer|min:1|max:2147483647'], [
            'pieces.required' => '1パレットの総バラ数を入力してください。', 'pieces.integer' => '1パレットの総バラ数は整数で入力してください。',
            'pieces.min' => '1パレットの総バラ数は1以上で入力してください。', 'pieces.max' => '1パレットの総バラ数が大きすぎます。',
        ])->validate();

        return DB::connection('sakemaru')->transaction(function () use ($client, $source, $item, $data, $version, $actor) {
            $guard = app(ReplenishmentGuard::class);
            $guard->control($client); // Same lock order as policy edits and final confirmation.
            $db = DB::connection('sakemaru');
            throw_unless($db->table('warehouses')->where(['id' => $source, 'client_id' => $client, 'is_active' => 1, 'is_virtual' => 0])->exists(), \RuntimeException::class, '有効な移動元倉庫を選択してください。');
            $product = $db->table('items')->where(['id' => $item, 'client_id' => $client, 'is_active' => 1, 'is_ended' => 0])->first(['capacity_case']);
            $capacity = (int) ($product?->capacity_case ?? 0);
            throw_unless($capacity > 0 && (int) $data['pieces'] % $capacity === 0, \RuntimeException::class, '1パレットの総バラ数は、商品のケース入数の倍数で入力してください。');
            $before = $this->find($client, $source, $item, true);
            throw_unless((int) ($before?->version ?? 0) === $version, \RuntimeException::class, 'パレット入数が他の操作で変更されました。商品設定を開き直してください。');
            if ($before && (int) $before->pieces_per_pallet === (int) $data['pieces'] && (int) $before->capacity_case === $capacity) {
                return $before;
            }
            $values = ['pieces_per_pallet' => (int) $data['pieces'], 'capacity_case' => $capacity,
                'version' => $version + 1, 'updated_by' => $actor, 'updated_at' => now()];
            if ($before) {
                $db->table('wms_pallet_units')->where('id', $before->id)->update($values);
            } else {
                $db->table('wms_pallet_units')->insert($values + ['client_id' => $client, 'source_warehouse_id' => $source, 'item_id' => $item, 'created_at' => now()]);
            }
            $after = $this->find($client, $source, $item);
            $guard->event($client, 'PALLET_UNIT_SAVED', ['before' => $before, 'after' => $after], actorId: $actor);

            return $after;
        }, 3);
    }

    public function binding(?int $policyId): ?object
    {
        return $policyId && $this->installed() ? DB::connection('sakemaru')->table('wms_policy_pallet_units')->where('policy_id', $policyId)->first() : null;
    }

    public function forRoute(int $client, array $route, bool $lock = false): object
    {
        throw_unless(($route['channel'] ?? '') === 'LOGISTICS', \RuntimeException::class, 'パレット単位は物流発注（店間）で選択してください。');
        $master = $this->find($client, (int) ($route['source_warehouse_id'] ?? 0), (int) ($route['item_id'] ?? 0), $lock);
        throw_unless($master, \RuntimeException::class, 'この移動元倉庫・商品のパレット入数を先に登録してください。');

        return $master;
    }

    /** Caller already owns the client control + policy locks. Missing CSV fields preserve opt-in. */
    public function prepare(int $client, array &$values, array $selection, ?int $policyId): ?object
    {
        $binding = $this->binding($policyId);
        $mode = $selection['shipping_unit_mode'] ?? ($binding ? 'PALLET' : 'STANDARD');
        throw_unless(in_array($mode, ['STANDARD', 'PALLET', 'SHIPMENT'], true), \RuntimeException::class, '発注単位の設定を選び直してください。');
        if ($mode !== 'PALLET') {
            return null;
        }
        $master = $this->forRoute($client, $values, true);
        throw_if(! array_key_exists('shipping_unit_mode', $selection) && $binding && (int) $binding->pallet_unit_id !== (int) $master->id,
            \RuntimeException::class, '移動元倉庫が変わるため、商品設定画面でパレット入数を確認して保存してください。');
        $expected = $selection['pallet_unit_version'] ?? $binding?->pallet_version;
        throw_unless((int) $expected === (int) $master->version, \RuntimeException::class, 'パレット入数が更新されています。おすすめ設定を再計算し、商品設定を保存してください。');
        $capacity = (int) DB::connection('sakemaru')->table('items')->where(['id' => $values['item_id'], 'client_id' => $client])->value('capacity_case');
        $this->assertCapacity($master, $capacity);
        $pieces = (int) $master->pieces_per_pallet;
        throw_unless((int) $values['min_order_pieces'] % $pieces === 0 && (int) $values['max_order_pieces'] >= $pieces,
            \RuntimeException::class, "最小発注数は1パレット（{$pieces}バラ）の倍数、最大発注数は1パレット以上にしてください。");
        throw_if(in_array($values['strategy_key'] ?? '', ['arrival_batch_v1', 'arrival_adaptive_v1'], true) && (int) ($values['target_stock_pieces'] ?? $values['reorder_point_pieces']) < (int) $values['min_order_pieces'],
            \RuntimeException::class, '補充目標は1回のパレット発注量以上にしてください。');
        $values['order_multiple_pieces'] = $pieces;

        return $master;
    }

    public function persist(int $policyId, ?object $master): void
    {
        if (! $this->installed()) {
            return;
        }
        $query = DB::connection('sakemaru')->table('wms_policy_pallet_units');
        if (! $master) {
            $query->where('policy_id', $policyId)->delete();

            return;
        }
        $query->upsert([['policy_id' => $policyId, 'pallet_unit_id' => $master->id, 'pallet_version' => $master->version,
            'pieces_per_pallet' => $master->pieces_per_pallet, 'created_at' => now(), 'updated_at' => now()]], ['policy_id'],
            ['pallet_unit_id', 'pallet_version', 'pieces_per_pallet', 'updated_at']);
    }

    /** At most one indexed read per proposal batch, including current master versions. */
    public function bindings(int $client, int $warehouse, array $items, bool $lock): Collection
    {
        if (! $this->installed()) {
            return collect();
        }

        return DB::connection('sakemaru')->table('wms_policy_pallet_units as b')
            ->join('wms_item_reorder_policies as p', 'p.id', '=', 'b.policy_id')->leftJoin('wms_pallet_units as m', 'm.id', '=', 'b.pallet_unit_id')
            ->where('p.client_id', $client)->where('p.warehouse_id', $warehouse)->whereIn('p.item_id', $items)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get(['b.policy_id', 'b.pallet_version', 'b.pieces_per_pallet as bound_pieces', 'm.id', 'm.client_id', 'm.source_warehouse_id', 'm.item_id', 'm.pieces_per_pallet', 'm.capacity_case', 'm.version'])->keyBy('policy_id');
    }

    public function assertBinding(object $row, array $policy, int $capacity): array
    {
        throw_unless(($policy['channel'] ?? '') === 'LOGISTICS' && $row->id && (int) $row->client_id === (int) $policy['client_id']
            && (int) $row->source_warehouse_id === (int) $policy['source_warehouse_id'] && (int) $row->item_id === (int) $policy['item_id']
            && (int) $row->version === (int) $row->pallet_version && (int) $row->pieces_per_pallet === (int) $row->bound_pieces,
            \RuntimeException::class, 'パレット入数が変更・削除されています。商品設定を開き直して再保存してください。');
        $this->assertCapacity($row, $capacity);
        $this->assertPolicyMatches($policy, (int) $row->pieces_per_pallet);

        return ['mode' => 'PALLET', 'id' => (int) $row->id, 'version' => (int) $row->version, 'pieces_per_pallet' => (int) $row->pieces_per_pallet];
    }

    /** The saved quantity conditions must be expressed in whole pallets (same rule for the daily run and the past replay). */
    public function assertPolicyMatches(array $policy, int $piecesPerPallet): void
    {
        throw_unless($piecesPerPallet > 0 && (int) ($policy['order_multiple_pieces'] ?? 0) === $piecesPerPallet
            && (int) ($policy['min_order_pieces'] ?? 0) % $piecesPerPallet === 0,
            \RuntimeException::class, '商品の数量条件がパレット入数と一致しません。おすすめ設定を再計算してください。');
    }

    public function assertCapacity(object $master, int $capacity): void
    {
        throw_unless($capacity > 0 && (int) $master->capacity_case === $capacity && (int) $master->pieces_per_pallet > 0
            && (int) $master->pieces_per_pallet <= 2147483647 && (int) $master->pieces_per_pallet % $capacity === 0,
            \RuntimeException::class, '商品のケース入数が変わっています。パレット入数を確認して保存し直してください。');
    }
}
