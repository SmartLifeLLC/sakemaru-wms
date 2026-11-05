<?php

namespace App\Services\Distribution;

use App\Models\WmsDistributionBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DistributionBatchService
{
    public static function current(): ?WmsDistributionBatch
    {
        return app()->bound('request') ? request()->attributes->get('distribution_batch') : null;
    }

    public static function rowLocked(array $data): bool
    {
        foreach (['warehouseTransferGenerated', 'warehouse_transfer_generated', 'orderCandidateGenerated', 'order_candidate_generated', 'warehouseTransferQueueIds', 'warehouse_transfer_queue_ids', 'transferSlipQueueIds', 'transfer_slip_queue_ids', 'orderCandidateIds', 'order_candidate_ids', 'distributionGenerationPending'] as $key) {
            if (is_array($data[$key] ?? null) ? $data[$key] !== [] : filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    /** Queue receipts are distinct from actual worker completion. */
    public static function status(array $rows, array $queues): string
    {
        $generated = 0;
        $processing = false;
        foreach ($rows as $row) {
            if (! self::rowLocked($row)) {
                continue;
            }
            $ids = array_values(array_unique(array_merge($row['warehouseTransferQueueIds'] ?? [], $row['transferSlipQueueIds'] ?? [])));
            if ($ids === []) {
                return '要確認';
            }
            foreach ($ids as $id) {
                $queue = $queues[(int) $id] ?? null;
                if (! $queue || ($queue->status === 'FINISHED' && (! $queue->is_success || ! $queue->stock_transfer_id))) {
                    return '要確認';
                }
                if ($queue->status !== 'FINISHED') {
                    $processing = true;
                }
            }
            $generated++;
        }
        if ($processing) {
            return '生成中';
        }
        if ($generated === 0) {
            return '未生成';
        }

        return $generated === count($rows) ? '生成済' : '一部生成済';
    }

    public function assertEditable(WmsDistributionBatch $batch): void
    {
        if ($batch->deleted_at) {
            throw ValidationException::withMessages(['分配' => '削除済みの分配です。一覧から対象の分配を選び直してください。']);
        }
        if ($batch->archived_at) {
            throw ValidationException::withMessages(['分配' => 'すでに対応済みの分配です。作業を再開するには再開するボタンを押してください。']);
        }
    }

    public function hasGeneratedRows(WmsDistributionBatch $batch): bool
    {
        return $batch->rows()->get(['row_data'])->contains(fn ($row) => self::rowLocked($row->row_data ?? []));
    }

    /** Only add the parent reference; retain every original business field and timestamp. */
    public function migrateLegacy(bool $execute = false): array
    {
        $db = DB::connection('sakemaru');
        $groups = $db->table('wms_distribution_rows')->where('mode', 'allocation')
            ->whereNull('distribution_batch_id')->select('client_id', 'warehouse_id')
            ->selectRaw('COUNT(*) AS row_count')->groupBy('client_id', 'warehouse_id')->get();
        if (! $execute) {
            return $groups->map(fn ($group) => (array) $group)->all();
        }
        foreach ($groups as $group) {
            $db->transaction(function () use ($db, $group): void {
                $key = "legacy:{$group->client_id}:{$group->warehouse_id}";
                $db->table('wms_distribution_batches')->insertOrIgnore([
                    'client_id' => $group->client_id, 'warehouse_id' => $group->warehouse_id,
                    'name' => '初回分配', 'is_legacy' => true, 'registration_key' => $key,
                    'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $batch = $db->table('wms_distribution_batches')->where('registration_key', $key)->lockForUpdate()->first();
                $count = $db->table('wms_distribution_rows')->where('mode', 'allocation')
                    ->where('client_id', $group->client_id)->where('warehouse_id', $group->warehouse_id)
                    ->whereNull('distribution_batch_id')->update(['distribution_batch_id' => $batch->id]);
                if ($count > 0) {
                    $db->table('wms_distribution_batches')->where('id', $batch->id)->increment('revision');
                }
            });
        }

        return $groups->map(fn ($group) => (array) $group)->all();
    }
}
