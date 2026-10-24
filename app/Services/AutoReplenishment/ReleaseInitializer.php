<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Support\Facades\DB;

class ReleaseInitializer
{
    private const OFF = ['is_auto_order' => 0, 'use_safety_stock_auto_update' => 0];

    private const QUANTITIES = ['safety_stock', 'max_stock', 'min_stock', 'purchase_unit', 'auto_order_quantity'];

    private const ZERO = ['safety_stock' => 0, 'max_stock' => 0, 'min_stock' => 0, 'purchase_unit' => 0,
        'auto_order_quantity' => 0, 'is_auto_order' => 0, 'use_safety_stock_auto_update' => 0];

    public function initialize(int $clientId): array
    {
        $db = DB::connection('sakemaru');
        throw_unless($clientId > 0 && $db->table('clients')->where('id', $clientId)->exists(), \InvalidArgumentException::class, '顧客が無効です。');
        $release = config('replenishment.release_key');
        $db->table('wms_auto_order_controls')->insertOrIgnore(['client_id' => $clientId, 'created_at' => now(), 'updated_at' => now()]);
        $start = $db->transaction(function () use ($db, $clientId, $release) {
            $control = $db->table('wms_auto_order_controls')->where('client_id', $clientId)->lockForUpdate()->first();
            if ($control->reset_state === 'COMPLETE') {
                throw_if($control->release_key !== $release, \RuntimeException::class, '別リリースの初期化済みです。自動再初期化しません。');

                return false;
            }
            if ($control->reset_state !== 'IN_PROGRESS') {
                $preservation = config('replenishment.initial_reset_preserve_warehouse_codes');
                throw_unless(is_array($preservation), \RuntimeException::class, '保持対象の初期化設定がありません。設定キャッシュを更新してから再実行してください。');
                $codes = $preservation[config('wms.client')] ?? [];
                $preservedWarehouseIds = $db->table('warehouses')->where('client_id', $clientId)->whereIn('code', $codes)->pluck('id')->map(fn ($id) => (int) $id)->all();
                app(ReplenishmentGuard::class)->event($clientId, 'RESET_SCOPE_FIXED', [
                    'release_key' => $release, 'profile' => config('wms.client'), 'preserved_warehouse_ids' => $preservedWarehouseIds,
                ]);
                $db->table('wms_auto_order_controls')->where('id', $control->id)->update([
                    'enabled' => false, 'generation' => $control->generation + 1, 'reset_state' => 'IN_PROGRESS',
                    'release_key' => $release, 'reset_target_max' => (int) $db->table('item_contractors')->where('client_id', $clientId)->max('id'), 'updated_at' => now(),
                ]);
                $warehouseIds = $db->table('warehouses')->where('client_id', $clientId)->pluck('id');
                foreach ($db->table('wms_warehouse_auto_order_settings')->whereIn('warehouse_id', $warehouseIds)->get() as $w) {
                    $this->backup($clientId, $release, 'warehouse', (int) $w->id, (array) $w);
                }
                $db->table('wms_warehouse_auto_order_settings')->whereIn('warehouse_id', $warehouseIds)->update(['is_auto_order_enabled' => false, 'updated_at' => now()]);
                $db->table('wms_warehouse_auto_order_settings')->whereIn('warehouse_id', $warehouseIds)->whereNotIn('warehouse_id', $preservedWarehouseIds)->update(['replenishment_config' => null]);
                $db->table('wms_auto_order_settings')->update(['is_auto_execution_enabled' => false]);
            }
            $scope = $db->table('wms_auto_order_events')->where('client_id', $clientId)->where('event', 'RESET_SCOPE_FIXED')
                ->where('payload->release_key', $release)->orderByDesc('id')->first();
            throw_unless($scope, \RuntimeException::class, '初期化対象の固定情報がありません。OFFのまま照合してください。');

            return json_decode($scope->payload, true, 512, JSON_THROW_ON_ERROR)['preserved_warehouse_ids'];
        }, 3);
        if ($start === false) {
            return ['status' => 'ALREADY_COMPLETE', 'changed' => 0];
        }
        $preservedWarehouseIds = $start;
        do {
            $count = $db->transaction(function () use ($db, $clientId, $release, $preservedWarehouseIds) {
                $c = $db->table('wms_auto_order_controls')->where('client_id', $clientId)->lockForUpdate()->first();
                throw_unless($c->reset_state === 'IN_PROGRESS', \RuntimeException::class, '初期化世代が変更されています。');
                $rows = $db->table('item_contractors')->where('client_id', $clientId)->where('id', '>', $c->reset_cursor)
                    ->where('id', '<=', $c->reset_target_max)->orderBy('id')->limit(500)->lockForUpdate()->get();
                if ($rows->isNotEmpty()) {
                    $db->table('wms_auto_order_reset_rows')->insertOrIgnore($rows->map(fn ($row) => [
                        'client_id' => $clientId, 'release_key' => $release, 'source_type' => 'item_contractor', 'source_id' => $row->id,
                        'snapshot' => json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(),
                    ])->all());
                    $affected = $db->table('item_contractors')->where('client_id', $clientId)->whereIn('id', $rows->pluck('id'))
                        ->whereNotIn('warehouse_id', $preservedWarehouseIds)->update(self::ZERO);
                    $affected += $db->table('item_contractors')->where('client_id', $clientId)->whereIn('id', $rows->pluck('id'))
                        ->whereIn('warehouse_id', $preservedWarehouseIds)->update(self::OFF);
                    throw_if($affected > $rows->count(), \RuntimeException::class, '初期化対象件数が不一致です。');
                    $db->table('wms_auto_order_controls')->where('id', $c->id)->update(['reset_cursor' => $rows->last()->id, 'reset_count' => $c->reset_count + $rows->count()]);
                }

                return $rows->count();
            }, 3);
        } while ($count > 0);

        return $db->transaction(function () use ($db, $clientId, $release, $preservedWarehouseIds) {
            $c = $db->table('wms_auto_order_controls')->where('client_id', $clientId)->lockForUpdate()->first();
            $invalid = $db->table('item_contractors')->where('client_id', $clientId)->whereNotIn('warehouse_id', $preservedWarehouseIds)->where(function ($q) {
                foreach (self::ZERO as $column => $zero) {
                    $q->orWhere($column, '!=', $zero);
                }
            })->exists();
            $flagsEnabled = $db->table('item_contractors')->where('client_id', $clientId)
                ->where(fn ($q) => $q->where('is_auto_order', '!=', 0)->orWhere('use_safety_stock_auto_update', '!=', 0))->exists();
            $preservedChanged = $db->table('item_contractors as ic')->join('wms_auto_order_reset_rows as r', 'r.source_id', '=', 'ic.id')
                ->where('ic.client_id', $clientId)->where('r.client_id', $clientId)->where('r.release_key', $release)->where('r.source_type', 'item_contractor')
                ->whereIn('ic.warehouse_id', $preservedWarehouseIds)->where(function ($q) {
                    foreach (self::QUANTITIES as $column) {
                        $q->orWhereRaw("NOT (ic.{$column} <=> CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.{$column}')), 'null') AS SIGNED))");
                    }
                })->exists();
            $actualCount = $db->table('item_contractors')->where('client_id', $clientId)->count();
            $actualMax = (int) $db->table('item_contractors')->where('client_id', $clientId)->max('id');
            throw_if($actualCount !== (int) $c->reset_count || $actualMax > (int) $c->reset_target_max, \RuntimeException::class, '初期化中に対象集合が変わりました。OFFのまま棚卸しを再確認してください。');
            throw_if($invalid || $flagsEnabled || $preservedChanged, \RuntimeException::class, '初期化中に対象データまたは保持する数量が変更されました。OFFのまま再確認してください。');
            foreach ($db->table('wms_item_reorder_policies')->where('client_id', $clientId)->orderBy('id')->cursor() as $p) {
                $this->backup($clientId, $release, 'policy', (int) $p->id, (array) $p);
                if (in_array((int) $p->warehouse_id, $preservedWarehouseIds, true)) {
                    $db->table('wms_item_reorder_policies')->where('id', $p->id)->update(['is_enabled' => false, 'version' => $p->version + 1, 'updated_at' => now()]);

                    continue;
                }
                $db->table('wms_item_reorder_policies')->where('id', $p->id)->update([
                    'is_enabled' => false, 'is_configured' => false, 'reorder_point_pieces' => null,
                    'max_order_pieces' => null, 'min_order_pieces' => null, 'order_multiple_pieces' => null,
                    'target_stock_pieces' => null, 'channel' => null, 'contractor_id' => null, 'supplier_id' => null,
                    'source_warehouse_id' => null, 'delivery_course_id' => null, 'arrival_rule' => null,
                    'version' => $p->version + 1, 'updated_at' => now(),
                ]);
            }
            $db->table('wms_auto_order_controls')->where('id', $c->id)->update(['reset_state' => 'COMPLETE', 'reset_completed_at' => now(), 'updated_at' => now()]);

            return ['status' => 'COMPLETE', 'changed' => $c->reset_count, 'preserved_warehouse_ids' => $preservedWarehouseIds,
                'quantity_preserved_count' => $db->table('item_contractors')->where('client_id', $clientId)->whereIn('warehouse_id', $preservedWarehouseIds)->count()];
        }, 3);
    }

    private function backup(int $clientId, string $release, string $type, int $id, array $data): void
    {
        DB::connection('sakemaru')->table('wms_auto_order_reset_rows')->insertOrIgnore([
            'client_id' => $clientId, 'release_key' => $release, 'source_type' => $type, 'source_id' => $id,
            'snapshot' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }
}
