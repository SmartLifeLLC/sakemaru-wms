<?php

namespace App\Services\Shortage;

use App\Models\WmsShortageAllocation;
use App\Services\QuantityUpdate\AllocationSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProxyShipmentLifecycleService
{
    public function locked(int $id, callable $operation): mixed
    {
        return DB::connection('sakemaru')->transaction(function () use ($id, $operation) {
            $shortageId = WmsShortageAllocation::whereKey($id)->value('shortage_id');
            // Every writer locks siblings in the same order, including quantity synchronization.
            $siblings = WmsShortageAllocation::where('shortage_id', $shortageId)
                ->orderBy('id')->lockForUpdate()->get();

            $allocation = $siblings->firstWhere('id', $id) ?? WmsShortageAllocation::findOrFail($id);
            abort_unless((int) $allocation->shortage?->trade?->client_id === (int) config('app.client_id') && (int) config('app.client_id') > 0, 403);

            return $operation($allocation);
        }, 3);
    }

    public function cancel(int $id, int $userId, string $reason): WmsShortageAllocation
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['cancel_reason' => '取消理由を500文字以内で入力してください。']);
        }

        return $this->locked($id, function (WmsShortageAllocation $allocation) use ($userId, $reason) {
            if ($allocation->isCancelled()) {
                return $allocation;
            }
            $allocation->assertWorkable(true);
            if ($allocation->createQueue()->exists()) {
                throw ValidationException::withMessages(['status' => '倉庫移動の処理が登録済みのため、この依頼は取り消せません。']);
            }
            // Invalidation only: quantities, parent, stock, synchronization flags and queues are untouched.
            $allocation->update([
                'cancel_previous_status' => $allocation->status,
                'status' => WmsShortageAllocation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_user_id' => $userId,
                'cancel_reason' => $reason,
            ]);

            return $allocation->refresh();
        });
    }

    public function complete(int $id, ?int $userId = null, ?int $pickerId = null, ?int $pickedQty = null): array
    {
        $result = $this->locked($id, function (WmsShortageAllocation $allocation) use ($userId, $pickerId, $pickedQty) {
            if ($allocation->isCancelled()) {
                throw ValidationException::withMessages(['status' => '取消済みの横持ち出荷依頼は完了できません。']);
            }
            if ($allocation->is_finished) {
                if ($pickedQty !== null && $pickedQty !== $allocation->picked_qty) {
                    throw ValidationException::withMessages(['picked_qty' => '完了済みのピック数は変更できません。']);
                }

                return ['allocation' => $allocation, 'stock_transfer_queue_id' => $allocation->createQueue()->value('id'), 'quantity_update_queue_id' => null];
            }
            $allocation->assertWorkable();
            $quantity = $pickedQty ?? $allocation->picked_qty;
            if ($quantity < 0 || $quantity > $allocation->assign_qty) {
                throw ValidationException::withMessages(['picked_qty' => 'ピック数は0から予定数までの整数で入力してください。']);
            }
            $allocation->update([
                'picked_qty' => $quantity,
                'status' => $quantity === $allocation->assign_qty ? 'FULFILLED' : 'SHORTAGE',
                'is_finished' => true,
                'finished_at' => now(),
                'finished_user_id' => $userId,
                'finished_picker_id' => $pickerId,
            ]);
            $queueId = app(StockTransferQueueService::class)->createStockTransferQueue($allocation);
            $allocation->update(['stock_transfer_queue_id' => $queueId]);
            // Preserve device completion's parent-status update, but never derive a new parent state from a cancelled group.
            if ($pickerId !== null && ! $allocation->shortage->allocations()->where('status', 'CANCELLED')->exists()) {
                $total = $allocation->shortage->allocations()->where('is_finished', true)->sum('picked_qty');
                if ($total > 0) {
                    $allocation->shortage->update(['status' => $total >= $allocation->shortage->shortage_qty ? 'SHORTAGE' : 'PARTIAL_SHORTAGE']);
                }
            }
            return ['allocation' => $allocation->refresh(), 'stock_transfer_queue_id' => $queueId, 'quantity_update_queue_id' => null];
        });

        // Completion and transfer registration must remain committed if quantity synchronization fails.
        // Repeated completion also retries synchronization; the sync service locks siblings, checks
        // for cancellation and uses an idempotent queue key before updating the original document.
        $result['quantity_sync_failed'] = false;
        if ($result['allocation']->status === WmsShortageAllocation::STATUS_SHORTAGE) {
            try {
                $queue = app(AllocationSyncService::class)->syncAllocationIfReady($result['allocation']);
                $result['quantity_update_queue_id'] = $queue?->id;
                $result['allocation'] = $result['allocation']->refresh();
            } catch (Throwable $exception) {
                Log::error('Failed to sync proxy shipment shortage allocation', [
                    'allocation_id' => $id,
                    'shortage_id' => $result['allocation']->shortage_id,
                    'error' => $exception->getMessage(),
                ]);
                $result['quantity_sync_failed'] = true;
            }
        }

        return $result;
    }
}
