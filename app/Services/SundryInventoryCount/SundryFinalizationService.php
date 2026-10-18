<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Support\SundryCalculationProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SundryFinalizationService
{
    public const TABLE = 'wms_sundry_finalizations';
    public const SOURCE = 'WMS_SUNDRY_INVENTORY_COUNT';

    public function __construct(private readonly SundryFinalizationCalculator $calculator = new SundryFinalizationCalculator) {}

    public static function record(int $countId): ?object
    {
        return Schema::connection('sakemaru')->hasTable(self::TABLE)
            ? DB::connection('sakemaru')->table(self::TABLE)->where('sundry_inventory_count_id', $countId)->where('status', '!=', 'cancelled')->first()
            : null;
    }

    public function preview(WmsSundryInventoryCount $count, string $date): array
    {
        $db = DB::connection('sakemaru');
        $calculate = function () use ($count, $date): array {
            $this->calculator->assertPeriodOpen($count, $date);
            [$snapshot, $proof] = (new SundryCalculationProof(DB::connection('sakemaru')))
                ->capture(fn () => $this->calculator->calculate($count->fresh(), $date));
            $snapshot['proof'] = $proof;
            return ['snapshot' => $snapshot, 'token' => $this->token($snapshot)];
        };
        if ($db->transactionLevel() > 0 || $db->getDriverName() === 'sqlite') {
            return $calculate();
        }
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        return $db->transaction($calculate);
    }

    public function finalize(WmsSundryInventoryCount $count, string $date, string $token, ?int $userId, bool $approveUnknown = false): int
    {
        if ($existing = self::record($count->id)) {
            if ($existing->process_date === $date && hash_equals($existing->token, $token)) {
                return $existing->id;
            }
            $this->fail('既に最終確定されています。保存済みの確定差異を確認してください。');
        }
        // Long ledger reads run before acquiring the short finalization lock.
        $preview = $this->preview($count, $date);
        if (! hash_equals($preview['token'], $token)) {
            $this->fail('確認後に入力・受払・原価が変わりました。差異を再確認してください。');
        }
        if (collect($preview['snapshot']['lines'])->contains('kind', 'book') && ! $approveUnknown) {
            $this->fail('部門の不明差異金額を確認し、「不明差異を含めて確定する」にチェックしてください。');
        }
        return DB::connection('sakemaru')->transaction(function () use ($count, $date, $userId, $preview, $approveUnknown): int {
            $db = DB::connection('sakemaru');
            $db->table('clients')->where('id', $count->client_id)->lockForUpdate()->firstOrFail();
            $locked = WmsSundryInventoryCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $existing = self::record($count->id);
            if ($existing) {
                if (hash_equals($existing->token, $preview['token'])) {
                    return $existing->id;
                }
                $this->fail('別の操作で最終確定されました。画面を再読込してください。');
            }
            $inputs = $preview['snapshot']['inputs'];
            if ($locked->getAttributes() !== $inputs['header']
                || $locked->items()->orderBy('id')->get()->map->getAttributes()->all() !== $inputs['items']
                || $locked->amounts()->orderBy('id')->get()->map->getAttributes()->all() !== $inputs['amounts']) {
                $this->fail('確認後に入力が変更されました。画面を再読込してください。');
            }
            $this->calculator->assertPeriodOpen($locked, $date);
            (new SundryCalculationProof($db))->verify($preview['snapshot']['proof']);
            if (Schema::connection('sakemaru')->hasTable('stock_report_generation_runs') && $db->table('stock_report_generation_runs')
                ->where('client_id', $count->client_id)->whereIn('status', ['queued', 'running'])->exists()) {
                $this->fail('在庫金額報告書を生成中です。完了後に再実行してください。');
            }
            if ($db->table(self::TABLE)->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)
                ->whereNotIn('status', ['applied', 'cancelled'])->exists()) {
                $this->fail('同じ倉庫に未適用の雑貨棚卸し伝票があります。先行伝票を確認してください。');
            }
            $snapshot = $preview['snapshot'];
            $snapshot['unknown_approval'] = ['accepted' => $approveUnknown, 'user_id' => $userId, 'at' => now()->toIso8601String()];
            $id = $db->table(self::TABLE)->insertGetId([
                'client_id' => $count->client_id, 'warehouse_id' => $count->warehouse_id, 'sundry_inventory_count_id' => $count->id, 'active_count_id' => $count->id,
                'count_date' => $count->count_date->toDateString(), 'process_date' => $date, 'status' => 'queued',
                'token' => $preview['token'], 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $queueId = $db->table('inventory_adjustment_queue')->insertGetId([
                'client_id' => $count->client_id, 'warehouse_code' => $count->warehouse_code,
                'process_date' => $date, 'adjustment_date' => $date, 'source_type' => self::SOURCE, 'source_id' => $id,
                'wms_inventory_count_id' => null, 'request_id' => 'sundry-record-'.$id,
                'note' => '雑貨棚卸し '.$count->count_no.' 実施日 '.$count->count_date->toDateString().'（伝票記録のみ・未適用）',
                'items' => json_encode(['operation' => 'record_v3', 'finalization_id' => $id], JSON_THROW_ON_ERROR),
                'status' => 'BEFORE', 'retry_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $db->table(self::TABLE)->where('id', $id)->update(['record_queue_id' => $queueId]);
            return $id;
        });
    }

    public function requestApplication(WmsSundryInventoryCount $count, ?int $userId): void
    {
        DB::connection('sakemaru')->transaction(function () use ($count, $userId): void {
            $db = DB::connection('sakemaru');
            $db->table('clients')->where('id', $count->client_id)->lockForUpdate()->firstOrFail();
            $record = $db->table(self::TABLE)->where('client_id', $count->client_id)
                ->where('sundry_inventory_count_id', $count->id)->where('status', '!=', 'cancelled')->lockForUpdate()->first();
            if (! $record || ! in_array($record->status, ['recorded', 'apply_failed', 'applied', 'applying'], true)) {
                $this->fail('実棚変更伝票の作成完了後に適用してください。');
            }
            if (in_array($record->status, ['applied', 'applying'], true)) {
                return;
            }
            $snapshot = json_decode($record->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $this->calculator->assertPeriodOpen($count, $record->process_date);
            (new SundryCalculationProof($db))->verify($snapshot['proof']);
            foreach ($snapshot['lines'] as $line) {
                if ($line['kind'] === 'managed' && ($line['quantity_before'] != round($line['quantity_before'])
                    || $line['quantity_after'] != round($line['quantity_after']))) {
                    $this->fail('小数数量の伝票は正確な値で保存されていますが、現在庫・LOTが整数型のため適用できません。単位・在庫精度の対応が必要です。');
                }
            }
            $id = $db->table('inventory_adjustment_queue')->insertGetId([
                'client_id' => $count->client_id, 'warehouse_code' => $count->warehouse_code,
                'process_date' => $record->process_date, 'adjustment_date' => $record->process_date,
                'source_type' => self::SOURCE, 'source_id' => $record->id, 'wms_inventory_count_id' => null,
                'request_id' => 'sundry-apply-'.$record->id.'-'.bin2hex(random_bytes(8)),
                'note' => '雑貨棚卸し '.$count->count_no.' 適用依頼',
                'items' => json_encode(['operation' => match ($snapshot['version'] ?? 1) { 3 => 'apply_v3', 2 => 'apply_v2', default => 'apply' },
                    'finalization_id' => $record->id, 'requested_by' => $userId], JSON_THROW_ON_ERROR),
                'status' => 'BEFORE', 'retry_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $db->table(self::TABLE)->where('id', $record->id)->update(['status' => 'applying', 'apply_queue_id' => $id, 'error_message' => null, 'updated_at' => now()]);
        });
    }

    public function cancelUnapplied(WmsSundryInventoryCount $count, ?int $userId): void
    {
        DB::connection('sakemaru')->transaction(function () use ($count, $userId): void {
            $db = DB::connection('sakemaru');
            $db->table('clients')->where('id', $count->client_id)->lockForUpdate()->firstOrFail();
            $record = $db->table(self::TABLE)->where('client_id', $count->client_id)
                ->where('active_count_id', $count->id)->lockForUpdate()->first();
            if (! $record) {
                return;
            }
            if (! in_array($record->status, ['queued', 'record_failed', 'recorded', 'apply_failed'], true)) {
                $this->fail('適用待ち・適用済みの最終確定は取り消せません。処理結果を確認してください。');
            }
            $db->table('inventory_adjustment_queue')->where('client_id', $count->client_id)
                ->where('source_type', self::SOURCE)->where('source_id', $record->id)
                ->whereIn('status', ['BEFORE', 'PROCESSING'])->update([
                    'status' => 'FINISHED', 'is_success' => false, 'error_message' => '未適用の最終確定が取り消されました。', 'updated_at' => now(),
                ]);
            $db->table(self::TABLE)->where('id', $record->id)->update([
                'active_count_id' => null, 'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId, 'updated_at' => now(),
            ]);
        });
    }

    private function token(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['finalization' => $message]);
    }
}
