<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsAutoOrderRunLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReplenishmentGuard
{
    private static ?bool $schemaInstalled = null;

    public function installed(): bool
    {
        return self::$schemaInstalled ??= Schema::connection('sakemaru')->hasTable('wms_auto_order_controls');
    }

    public function clientId(): int
    {
        $id = (int) config('app.client_id');
        throw_if($id < 1, \RuntimeException::class, '実行顧客が未設定です。');

        return $id;
    }

    public function control(int $clientId, bool $lock = true): object
    {
        $query = DB::connection('sakemaru')->table('wms_auto_order_controls')->where('client_id', $clientId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        throw_unless($row, \RuntimeException::class, '初回リリースの初期化が必要です。');

        return $row;
    }

    public function assertExecutable(int $clientId, int $warehouseId, ?int $generation = null): object
    {
        $c = $this->control($clientId);
        throw_unless(config('replenishment.enabled') && $c->enabled && $c->reset_state === 'COMPLETE', \RuntimeException::class, '自動発注は停止中です。');
        throw_if($generation !== null && (int) $c->generation !== $generation, \RuntimeException::class, '実行世代が変更されています。');
        $warehouse = DB::connection('sakemaru')->table('warehouses')->where('id', $warehouseId)
            ->where('client_id', $clientId)->where('is_active', true)->where('is_virtual', false)->first();
        throw_unless($warehouse, \RuntimeException::class, '対象倉庫が無効です。');
        $setting = DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
        throw_unless($setting?->is_auto_order_enabled, \RuntimeException::class, '倉庫の自動発注はOFFです。');

        return $setting;
    }

    public function lockScope(int $clientId, int $warehouseId, int $itemId): void
    {
        if (! $this->installed()) {
            return;
        }
        $db = DB::connection('sakemaru');
        $db->table('wms_replenishment_scopes')->insertOrIgnore([
            'client_id' => $clientId, 'warehouse_id' => $warehouseId, 'item_id' => $itemId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $db->table('wms_replenishment_scopes')->where([
            'client_id' => $clientId, 'warehouse_id' => $warehouseId, 'item_id' => $itemId,
        ])->lockForUpdate()->first();
    }

    public function assertLine(WmsAutoOrderRunLine $line): void
    {
        $run = DB::connection('sakemaru')->table('wms_auto_order_runs')->find($line->run_id);
        throw_unless($run && ! $run->is_preview, \RuntimeException::class, '試算から発注は生成できません。');
        $warehouse = $this->assertExecutable((int) $line->client_id, (int) $line->warehouse_id, (int) $run->generation);
        $policy = DB::connection('sakemaru')->table('wms_item_reorder_policies')->find($line->policy_id);
        throw_unless($policy && $policy->is_enabled && $policy->is_configured
            && (int) $policy->version === (int) $line->policy_version
            && (int) $warehouse->replenishment_version === (int) $line->warehouse_version,
            \RuntimeException::class, '設定変更のため再確認が必要です。');
        throw_if(in_array($line->phase, ['STOPPED', 'ABORTED', 'EOS_JX_READY', 'TRANSFER_CREATED']), \RuntimeException::class, 'この実行は再生成できません。');
    }

    public function assertLegacyAllowed(): void
    {
        if ($this->installed()) {
            throw new \RuntimeException('旧自動発注は停止されています。商品別発注設定を使用してください。');
        }
    }

    public function event(int $clientId, string $event, array $payload, ?int $lineId = null, ?int $actorId = null): void
    {
        DB::connection('sakemaru')->table('wms_auto_order_events')->insert([
            'client_id' => $clientId, 'line_id' => $lineId, 'actor_id' => $actorId, 'event' => $event,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }
}
