<?php

namespace App\Services\QuantityUpdate;

use App\Models\QuantityUpdateQueue;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 横持ち出荷欠品のai-core同期サービス
 * 欠品単位でquantity_update_queueに最終数量を作成
 */
class AllocationSyncService
{
    /**
     * 指定倉庫の未同期欠品allocationを欠品単位で同期
     *
     * @return array{synced_count: int, queue_created: int, skipped: int, errors: array}
     */
    public function syncByWarehouse(int $warehouseId): array
    {
        $allocations = WmsShortageAllocation::needingSync()
            ->where('target_warehouse_id', $warehouseId)
            ->with(['shortage.trade', 'shortage.wave', 'shortage.allocations'])
            ->get();

        if ($allocations->isEmpty()) {
            return ['synced_count' => 0, 'queue_created' => 0, 'skipped' => 0, 'errors' => []];
        }

        $grouped = $allocations->groupBy('shortage_id');

        $syncedCount = 0;
        $queueCreated = 0;
        $skipped = 0;
        $errors = [];

        DB::connection('sakemaru')->transaction(function () use ($grouped, &$syncedCount, &$queueCreated, &$skipped, &$errors) {
            $queueService = app(QuantityUpdateQueueService::class);

            foreach ($grouped as $shortageId => $groupAllocations) {
                try {
                    $result = $this->syncShortageGroup($groupAllocations, $queueService);
                    if ($result) {
                        $queueCreated++;
                        $syncedCount += $groupAllocations->count();
                    } else {
                        $skipped += $groupAllocations->count();
                    }
                } catch (\Exception $e) {
                    $errors[] = "欠品ID {$shortageId}: {$e->getMessage()}";
                    Log::error('AllocationSyncService: group sync failed', [
                        'shortage_id' => $shortageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('AllocationSyncService: sync completed', [
            'warehouse_id' => $warehouseId,
            'synced_count' => $syncedCount,
            'queue_created' => $queueCreated,
            'skipped' => $skipped,
        ]);

        return compact('syncedCount', 'queueCreated', 'skipped', 'errors');
    }

    /**
     * 欠品単位でqueue作成 & allocation同期済み更新
     */
    protected function syncShortageGroup($allocations, QuantityUpdateQueueService $queueService): ?QuantityUpdateQueue
    {
        $first = $allocations->first();
        $shortage = $first->shortage;

        if (! $shortage) {
            Log::warning('AllocationSyncService: shortage not found', ['allocation_id' => $first->id]);

            return null;
        }

        $allocationIds = $allocations->pluck('id')->sort()->values()->all();
        $requestHash = substr(sha1(implode(',', $allocationIds)), 0, 12);
        $requestId = "proxy-shortage-final-{$shortage->id}-{$requestHash}";

        $queue = $queueService->createQueueForAllocationSync($shortage, $requestId);
        if (! $queue) {
            return null;
        }

        // allocation を同期済みに更新
        $this->markAllocationsAsSynced($allocations);

        Log::info('AllocationSyncService: queue created for shortage group', [
            'queue_id' => $queue->id,
            'request_id' => $requestId,
            'shortage_id' => $shortage->id,
            'update_qty' => $queue->update_qty,
            'allocation_count' => $allocations->count(),
            'allocation_ids' => $allocationIds,
        ]);

        return $queue;
    }

    protected function markAllocationsAsSynced($allocations): void
    {
        $ids = $allocations->pluck('id')->toArray();
        WmsShortageAllocation::whereIn('id', $ids)->update([
            'is_synced' => true,
            'is_synced_at' => now(),
        ]);
    }

    /**
     * 指定倉庫の未同期件数を取得
     */
    public function getUnsyncedCount(int $warehouseId): int
    {
        return WmsShortageAllocation::needingSync()
            ->where('target_warehouse_id', $warehouseId)
            ->count();
    }
}
