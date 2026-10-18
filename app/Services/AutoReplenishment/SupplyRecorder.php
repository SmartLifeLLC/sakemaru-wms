<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Metadata only: existing candidates, schedules and Core remain the business source. */
class SupplyRecorder
{
    public function record(string $type, object $candidate, Collection $schedules, ?int $queueId = null): void
    {
        if (! app(ReplenishmentGuard::class)->installed()) {
            return;
        }
        $db = DB::connection('sakemaru');
        $item = $candidate->item;
        $warehouseId = $type === 'order' ? $candidate->warehouse_id : $candidate->satellite_warehouse_id;
        $quantity = $type === 'order' ? $candidate->order_quantity : $candidate->transfer_quantity;
        $pieces = app(SnapshotReader::class)->pieces((int) $quantity, $candidate->quantity_type->value, $item);
        $db->table('wms_replenishment_effects')->insertOrIgnore([
            'client_id' => $item->client_id, 'warehouse_id' => $warehouseId, 'item_id' => $item->id,
            'source_type' => $type, 'source_id' => $candidate->id, 'ordered_pieces' => $pieces,
            'source_warehouse_id' => $type === 'transfer' ? $candidate->hub_warehouse_id : null,
            'capacity_case' => max(1, (int) $item->capacity_case), 'capacity_carton' => max(1, (int) $item->capacity_carton),
            'queue_id' => $queueId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $effect = $db->table('wms_replenishment_effects')->where(['source_type' => $type, 'source_id' => $candidate->id])->lockForUpdate()->first();
        if ($queueId !== null) {
            $db->table('wms_replenishment_effects')->where('id', $effect->id)->update(['queue_id' => $queueId]);
        }
        // Reconfirmation is legitimate for manual candidates. Replace only live
        // associations and retain a hold when the previous schedule disappeared.
        $old = $db->table('wms_replenishment_effect_sources')->where('effect_id', $effect->id)->pluck('incoming_schedule_id');
        if ($old->isNotEmpty() && $db->table('wms_order_incoming_schedules')->whereIn('id', $old)->count() !== $old->count()) {
            $db->table('wms_replenishment_effects')->where('id', $effect->id)->update(['reconciliation_required' => true]);
        }
        foreach ($schedules as $schedule) {
            $db->table('wms_replenishment_effect_sources')->insertOrIgnore(['effect_id' => $effect->id, 'incoming_schedule_id' => $schedule->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($schedules->isNotEmpty()) {
            $this->refreshReceipt((int) $schedules->first()->id);
        }
    }

    public function attachSchedule(\App\Models\WmsOrderIncomingSchedule $schedule): void
    {
        if (! app(ReplenishmentGuard::class)->installed()) {
            return;
        }
        $db = DB::connection('sakemaru');
        $type = $schedule->order_candidate_id ? 'order' : 'transfer';
        $sourceId = $schedule->order_candidate_id ?: $schedule->transfer_candidate_id;
        $effect = $sourceId ? $db->table('wms_replenishment_effects')->where(['source_type' => $type, 'source_id' => $sourceId])->first() : null;
        if (! $effect && $schedule->source_incoming_schedule_id) {
            $effectId = $db->table('wms_replenishment_effect_sources')->where('incoming_schedule_id', $schedule->source_incoming_schedule_id)->value('effect_id');
            $effect = $effectId ? $db->table('wms_replenishment_effects')->find($effectId) : null;
        }
        if (! $effect) {
            return;
        }
        throw_unless((int) $effect->warehouse_id === (int) $schedule->warehouse_id && (int) $effect->item_id === (int) $schedule->item_id, \RuntimeException::class, '分割した供給の倉庫・商品が一致しません。');
        $db->table('wms_replenishment_effect_sources')->insertOrIgnore(['effect_id' => $effect->id, 'incoming_schedule_id' => $schedule->id, 'created_at' => now(), 'updated_at' => now()]);
        $line = \App\Models\WmsAutoOrderRunLine::where($effect->source_type === 'order' ? 'order_candidate_id' : 'transfer_candidate_id', $effect->source_id)->first();
        if ($line) {
            app(ArtifactRecorder::class)->link($line, 'incoming', (int) $schedule->id, $schedule->toArray());
        }
        $this->refreshReceipt((int) $schedule->id);
    }

    public function reconcileReceipts(int $clientId, int $limit = 200): void
    {
        $db = DB::connection('sakemaru');
        foreach ($db->table('wms_replenishment_effects')->where('client_id', $clientId)->where('state', 'RECEIPT_PENDING')->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $db->transaction(function () use ($db, $id) {
                $scheduleId = $db->table('wms_replenishment_effect_sources')->where('effect_id', $id)->value('incoming_schedule_id');
                if ($scheduleId) {
                    $this->refreshReceipt((int) $scheduleId);
                }
            }, 3);
        }
    }

    public function refreshScheduleIds(array $ids): void
    {
        if (! app(ReplenishmentGuard::class)->installed()) {
            return;
        }
        $db = DB::connection('sakemaru');
        $db->transaction(function () use ($db, $ids) {
            foreach ($db->table('wms_replenishment_effect_sources')->whereIn('incoming_schedule_id', $ids)->pluck('incoming_schedule_id') as $id) {
                $this->refreshReceipt((int) $id);
            }
        }, 3);
    }

    public function refreshReceipt(int $scheduleId): void
    {
        if (! app(ReplenishmentGuard::class)->installed()) {
            return;
        }
        $db = DB::connection('sakemaru');
        $map = $db->table('wms_replenishment_effect_sources')->where('incoming_schedule_id', $scheduleId)->first();
        if (! $map) {
            return;
        }
        $e = $db->table('wms_replenishment_effects')->where('id', $map->effect_id)->lockForUpdate()->first();
        $maps = $db->table('wms_replenishment_effect_sources')->where('effect_id', $e->id)->get()->keyBy('incoming_schedule_id');
        $ids = $maps->keys();
        $rows = $db->table('wms_order_incoming_schedules')->whereIn('id', $ids)->get();
        $received = 0;
        $expected = 0;
        $applied = 0;
        $invalid = $rows->count() !== $ids->count();
        foreach ($rows as $r) {
            $unit = match ($r->quantity_type) {
                'CASE' => $e->capacity_case, 'CARTON' => $e->capacity_carton, 'PIECE' => 1, default => 0
            };
            $pieces = (int) $r->received_quantity * $unit;
            $received += $pieces;
            $expected += (int) $r->expected_quantity * $unit;
            $map = $maps[$r->id];
            $stockApplied = (int) $map->stock_applied_pieces;
            if ($pieces > $stockApplied && $this->stockApplicationSucceeded($r, $map, $pieces)) {
                $stockApplied = $pieces;
                $db->table('wms_replenishment_effect_sources')->where('id', $map->id)->update(['stock_applied_pieces' => $pieces, 'stock_applied_at' => now(), 'updated_at' => now()]);
            }
            $applied += $stockApplied;
            $invalid = $invalid || $stockApplied > $pieces || $r->received_quantity < 0 || $r->expected_quantity < 0;
            $invalid = $invalid || $unit < 1 || in_array($r->status, ['CANCELLED', 'PARTIAL_CANCELLED', 'DELETED']);
        }
        $invalid = $invalid || $received > $e->ordered_pieces || $expected !== (int) $e->ordered_pieces;
        $db->table('wms_replenishment_effects')->where('id', $e->id)->update([
            'state' => $invalid ? 'UNKNOWN' : ($applied < $received ? 'RECEIPT_PENDING' : ($received >= $e->ordered_pieces ? 'COMPLETED' : 'OPEN')),
            'received_pieces' => $received, 'reconciliation_required' => $invalid,
            'received_completed_at' => ! $invalid && $applied >= $e->ordered_pieces ? now() : null, 'updated_at' => now(),
        ]);
    }

    private function stockApplicationSucceeded(object $schedule, object $map, int $pieces): bool
    {
        $db = DB::connection('sakemaru');
        if ($schedule->purchase_queue_id) {
            return $db->table('purchase_create_queue')->where('id', $schedule->purchase_queue_id)->where('status', 'FINISHED')->where('is_success', true)->whereNotNull('slip_number')->exists();
        }
        if ($map->receipt_queue_id) {
            $queue = $db->table('stock_transfer_queue')->where('id', $map->receipt_queue_id)->where('action_type', 'DELIVER')->where('status', 'FINISHED')->where('is_success', true)->first();
            $payload = $queue ? json_decode($queue->items, true, 512, JSON_THROW_ON_ERROR) : [];

            return $queue && (int) ($payload['schedule_id'] ?? 0) === (int) $schedule->id
                && (int) ($payload['piece_quantity'] ?? -1) === $pieces;
        }

        return false;
    }
}
