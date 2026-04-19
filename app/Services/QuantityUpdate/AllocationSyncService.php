<?php

namespace App\Services\QuantityUpdate;

use App\Models\QuantityUpdateQueue;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 横持ち出荷欠品のai-core同期サービス
 * 配送コース単位でquantity_update_queueにレコードを作成
 */
class AllocationSyncService
{
    /**
     * 指定倉庫の未同期欠品allocationを配送コース単位で同期
     *
     * @return array{synced_count: int, queue_created: int, skipped: int, errors: array}
     */
    public function syncByWarehouse(int $warehouseId): array
    {
        $allocations = WmsShortageAllocation::needingSync()
            ->where('target_warehouse_id', $warehouseId)
            ->with(['shortage.trade', 'shortage.wave', 'deliveryCourse'])
            ->get();

        if ($allocations->isEmpty()) {
            return ['synced_count' => 0, 'queue_created' => 0, 'skipped' => 0, 'errors' => []];
        }

        // 配送コース + 出荷日でグループ化
        $grouped = $allocations->groupBy(function ($allocation) {
            return $allocation->delivery_course_id . '-' . $allocation->shipment_date?->format('Y-m-d');
        });

        $syncedCount = 0;
        $queueCreated = 0;
        $skipped = 0;
        $errors = [];

        DB::connection('sakemaru')->transaction(function () use ($grouped, &$syncedCount, &$queueCreated, &$skipped, &$errors) {
            foreach ($grouped as $groupKey => $groupAllocations) {
                try {
                    $result = $this->syncCourseGroup($groupAllocations);
                    if ($result) {
                        $queueCreated++;
                        $syncedCount += $groupAllocations->count();
                    } else {
                        $skipped += $groupAllocations->count();
                    }
                } catch (\Exception $e) {
                    $errors[] = "コースグループ {$groupKey}: {$e->getMessage()}";
                    Log::error('AllocationSyncService: group sync failed', [
                        'group_key' => $groupKey,
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
     * 配送コースグループ単位でqueue作成 & allocation同期済み更新
     */
    protected function syncCourseGroup($allocations): ?QuantityUpdateQueue
    {
        $first = $allocations->first();
        $shortage = $first->shortage;

        if (! $shortage) {
            Log::warning('AllocationSyncService: shortage not found', ['allocation_id' => $first->id]);

            return null;
        }

        $trade = $shortage->trade;
        if (! $trade) {
            Log::warning('AllocationSyncService: trade not found', ['shortage_id' => $shortage->id]);

            return null;
        }

        $deliveryCourseId = $first->delivery_course_id;
        $shipmentDate = $first->shipment_date?->format('Y-m-d');
        $requestId = "proxy-shortage-course-{$deliveryCourseId}-{$shipmentDate}";

        // べき等性: 同一request_idが存在する場合はallocationだけ同期済みにしてスキップ
        $existing = QuantityUpdateQueue::where('request_id', $requestId)->first();
        if ($existing) {
            $this->markAllocationsAsSynced($allocations);
            Log::info('AllocationSyncService: queue already exists, marking allocations synced', [
                'request_id' => $requestId,
                'queue_id' => $existing->id,
            ]);

            return $existing;
        }

        // 欠品数量の合計（assign_qty - picked_qty の総和）
        $totalShortageQty = $allocations->sum(fn ($a) => max(0, $a->assign_qty - $a->picked_qty));

        if ($totalShortageQty <= 0) {
            $this->markAllocationsAsSynced($allocations);

            return null;
        }

        $queue = QuantityUpdateQueue::create([
            'client_id' => $trade->client_id,
            'trade_category' => QuantityUpdateQueue::TRADE_CATEGORY_EARNING,
            'trade_id' => $shortage->trade_id,
            'trade_item_id' => $shortage->trade_item_id,
            'update_qty' => $totalShortageQty,
            'quantity_type' => $first->assign_qty_type,
            'shipment_date' => $shipmentDate,
            'request_id' => $requestId,
            'status' => QuantityUpdateQueue::STATUS_BEFORE,
        ]);

        // allocation を同期済みに更新
        $this->markAllocationsAsSynced($allocations);

        Log::info('AllocationSyncService: queue created for course group', [
            'queue_id' => $queue->id,
            'request_id' => $requestId,
            'delivery_course_id' => $deliveryCourseId,
            'shipment_date' => $shipmentDate,
            'total_shortage_qty' => $totalShortageQty,
            'allocation_count' => $allocations->count(),
            'allocation_ids' => $allocations->pluck('id')->toArray(),
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
