<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only projection: a transfer replaces its schedules/candidate, never adds to them. */
class TransferSupplyReader
{
    public function read(int $clientId, int $warehouseId, array $itemIds, array $allocationIds, bool $lock = false, bool $includeDates = false, ?string $availabilityDate = null): array
    {
        $db = DB::connection('sakemaru');
        $get = fn ($query) => ($lock ? $query->lockForUpdate() : $query)->get();
        $effects = $get($db->table('wms_replenishment_effects')->where([
            'client_id' => $clientId, 'warehouse_id' => $warehouseId, 'source_type' => 'transfer',
        ])->whereIn('item_id', $itemIds)->whereIn('state', ['OPEN', 'UNKNOWN', 'RECEIPT_PENDING']));
        $candidates = $get($db->table('wms_stock_transfer_candidates')->where('satellite_warehouse_id', $warehouseId)
            ->whereIn('item_id', $itemIds)->whereIn('status', ['PENDING', 'APPROVED']))->keyBy('id');
        $schedules = $get($db->table('wms_order_incoming_schedules as s')->where('s.warehouse_id', $warehouseId)->whereIn('s.item_id', $itemIds)
            ->where(fn ($q) => $q->where('s.order_source', 'TRANSFER')->orWhereNotNull('s.transfer_candidate_id')->orWhereNotNull('s.stock_transfer_id'))
            ->where(fn ($q) => $q->whereIn('s.status', ['PENDING', 'PARTIAL'])
                ->orWhereIn('s.transfer_candidate_id', $candidates->keys())
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('wms_replenishment_effect_sources as m')
                    ->whereColumn('m.incoming_schedule_id', 's.id')->whereIn('m.effect_id', $effects->pluck('id')))))->keyBy('id');
        $maps = $get($db->table('wms_replenishment_effect_sources')->whereIn('effect_id', $effects->pluck('id')))->keyBy('incoming_schedule_id');
        // Split schedules normally retain candidate/transfer IDs; inherit them from the parent when needed.
        $parents = $schedules;
        for ($depth = 0; $depth < 20; $depth++) {
            $missing = $parents->pluck('source_incoming_schedule_id')->filter()->diff($parents->keys());
            if ($missing->isEmpty()) {
                break;
            }
            $found = $get($db->table('wms_order_incoming_schedules')->whereIn('id', $missing));
            if ($found->isEmpty()) {
                break; // Project each item separately so one broken link does not hold the whole batch.
            }
            $parents = $parents->union($found->keyBy('id'));
        }
        $effectsByCandidate = $effects->keyBy('source_id');
        $candidateIds = $schedules->pluck('transfer_candidate_id')->merge($parents->pluck('transfer_candidate_id'))
            ->merge($effects->pluck('source_id'))->merge($candidates->keys())->filter()->unique();
        $relatedCandidates = $get($db->table('wms_stock_transfer_candidates')->whereIn('id', $candidateIds))->keyBy('id');
        $transferIds = $schedules->pluck('stock_transfer_id')->merge($parents->pluck('stock_transfer_id'))
            ->merge($effects->pluck('stock_transfer_id'))->filter()->unique();
        $warehouseCode = $db->table('warehouses')->where(['id' => $warehouseId, 'client_id' => $clientId])->value('code');
        $queues = $get($db->table('stock_transfer_queue')->where(['client_id' => $clientId, 'action_type' => 'CREATE', 'to_warehouse_code' => $warehouseCode])
            ->where(fn ($q) => $q->whereIn('id', $effects->pluck('queue_id')->filter())
                ->orWhereIn('stock_transfer_id', $transferIds)
                ->orWhereIn('request_id', $candidateIds->map(fn ($id) => 'transfer-create-'.$id))));
        $queueByCandidate = $this->queueMap($queues, $candidateIds);
        // Old grouped requests can have a hashed request_id. Inspect the stored candidate notes, not quantity/date similarity.
        $unresolved = $candidateIds->reject(fn ($id) => isset($queueByCandidate[$id]) || $effectsByCandidate->get($id)?->stock_transfer_id
            || $parents->contains(fn ($s) => (int) $s->transfer_candidate_id === (int) $id && $s->stock_transfer_id));
        if ($unresolved->isNotEmpty()) {
            $groups = $get($db->table('stock_transfer_queue')->where(['client_id' => $clientId, 'action_type' => 'CREATE', 'to_warehouse_code' => $warehouseCode])
                ->where('request_id', 'like', 'transfer-create-group-%')
                ->when($relatedCandidates->min('created_at'), fn ($q, $date) => $q->where('created_at', '>=', $date))
                ->limit(10001));
            throw_if($groups->count() > 10000, \RuntimeException::class, '移動発注の生成履歴が多すぎるため照合できません。管理者に確認してください。');
            $queueByCandidate = $this->queueMap($queues->merge($groups), $candidateIds);
        }
        $transferIds = $transferIds->merge(collect($queueByCandidate)->pluck('stock_transfer_id'))->filter()->unique();
        $transferQuery = $db->table('stock_transfers as st')->join('trades as t', 't.id', '=', 'st.trade_id')
            ->where(['st.client_id' => $clientId, 'st.to_warehouse_id' => $warehouseId])
            ->whereColumn('st.from_warehouse_id', '!=', 'st.to_warehouse_id')
            ->select(['st.*', 't.is_active as trade_active', 't.is_latest', 't.trade_category', 't.trade_direction', 't.is_returned', 't.client_id as trade_client_id']);
        // Separate pending ranges from explicit IDs so MySQL can use every column of the inbound index.
        $transfers = $get((clone $transferQuery)->where('st.is_active', true)->where('st.is_delivered', false)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('trade_items as ti')->whereColumn('ti.trade_id', 'st.trade_id')->whereIn('ti.item_id', $itemIds)))->keyBy('id');
        if ($transferIds->isNotEmpty()) {
            $transfers = $transfers->union($get((clone $transferQuery)->whereIn('st.id', $transferIds))->keyBy('id'));
        }
        $lines = $get($db->table('trade_items')->whereIn('trade_id', $transfers->pluck('trade_id'))->whereIn('item_id', $itemIds)
            ->where(['client_id' => $clientId, 'is_active' => true])->whereIn('stock_allocation_id', $allocationIds)
            ->select(['id', 'trade_id', 'item_id', 'quantity', 'quantity_type', 'capacity_case', 'capacity_carton', 'total_piece_quantity']))->groupBy('trade_id');
        $receipts = $get($db->table('stock_transfer_lot_allocations')->whereIn('stock_transfer_id', $transfers->keys())
            ->whereNotNull('to_real_stock_lot_id')->where('is_set_component', false)
            ->select(['stock_transfer_id', 'trade_item_id', 'quantity']))->groupBy('trade_item_id');
        $capacities = $db->table('items')->where('client_id', $clientId)->whereIn('id', $itemIds)
            ->get(['id', 'code', 'capacity_case', 'capacity_carton'])->keyBy('id');
        $result = [];
        foreach ($itemIds as $itemId) {
            try {
                $result[$itemId] = $this->project((int) $itemId, $warehouseId, $clientId, $transfers, $lines, $receipts,
                    $schedules->where('item_id', $itemId), $parents, $relatedCandidates->where('item_id', $itemId),
                    $effects->where('item_id', $itemId), $maps, $queueByCandidate, $capacities->get($itemId), $includeDates, $availabilityDate);
            } catch (\RuntimeException $e) {
                $result[$itemId] = ['error' => $e->getMessage()];
            }
        }

        return $result;
    }

    private function queueMap(Collection $queues, Collection $candidateIds): array
    {
        $result = [];
        $wanted = array_fill_keys($candidateIds->all(), true);
        foreach ($queues->unique('id') as $queue) {
            $items = json_decode($queue->items, true, 512, JSON_THROW_ON_ERROR);
            foreach ($items as $item) {
                if (preg_match('/^移動候補ID:\s*(\d+)$/u', $item['note'] ?? '', $m)) {
                    $id = (int) $m[1];
                    if (! isset($wanted[$id])) {
                        continue;
                    }
                    throw_if(isset($result[$id]), \RuntimeException::class, '同じ移動候補に複数の生成要求・明細があります。管理者に確認してください。');
                    $result[$id] = clone $queue;
                    $result[$id]->candidate_payload = $item;
                }
            }
        }

        return $result;
    }

    private function project(int $itemId, int $warehouseId, int $clientId, Collection $transfers, Collection $lines, Collection $receipts,
        Collection $schedules, Collection $parents, Collection $candidates, Collection $effects, Collection $maps, array $queues, ?object $capacity, bool $includeDates, ?string $availabilityDate): array
    {
        $incoming = $claims = 0;
        $incomingToday = 0;
        $availability = app(IncomingAvailability::class);
        $covered = $scheduleGroups = $actual = $pending = [];
        $events = [];
        foreach ($transfers as $transfer) {
            $itemLines = $lines->get($transfer->trade_id, collect())->where('item_id', $itemId);
            $valid = $transfer->is_active && $transfer->trade_active && $transfer->is_latest && ! $transfer->is_returned
                && $transfer->trade_category === 'STOCK_TRANSFER' && $transfer->trade_direction === 'NORMAL' && (int) $transfer->trade_client_id === $clientId;
            $total = $applied = 0;
            if ($valid && ! $transfer->is_delivered) {
                foreach ($itemLines as $line) {
                    $pieces = $this->pieces((int) $line->quantity, $line->quantity_type, $line);
                    throw_if($line->total_piece_quantity === null || $pieces !== (int) $line->total_piece_quantity, \RuntimeException::class, '移動伝票の数量と総バラ数が一致しません。管理者に伝票の数量・入数を確認してください。');
                    $received = (int) $receipts->get($line->id, collect())->sum('quantity');
                    throw_if($receipts->get($line->id, collect())->contains(fn ($r) => $r->quantity < 0) || $received > $pieces,
                        \RuntimeException::class, '移動伝票の受入数量が伝票数量と一致しません。管理者に確認してください。');
                    $total += $pieces;
                    $applied += $received;
                }
            }
            $actual[$transfer->id] = ['transfer' => $transfer, 'lines' => $itemLines, 'valid' => $valid, 'total' => $total, 'applied' => $applied];
            $incoming += $total - $applied;
            $arrivalDate = $availability->transferDate($transfer->delivered_date ?? null, $availabilityDate);
            if ($total > $applied && $availability->isToday($arrivalDate, $availabilityDate)) {
                $incomingToday += $total - $applied;
            }
            if ($includeDates && $total > $applied) {
                $events[] = ['source' => 'transfer', 'date' => $arrivalDate, 'pieces' => $total - $applied];
            }
        }
        $effectsByCandidate = $effects->keyBy('source_id');
        foreach ($schedules as $schedule) {
            $origin = $schedule;
            $seen = [];
            while (! $origin->transfer_candidate_id && ! $origin->stock_transfer_id && $origin->source_incoming_schedule_id) {
                throw_if(isset($seen[$origin->id]), \RuntimeException::class, '移動入荷予定の分割元が循環しています。管理者に確認してください。');
                $seen[$origin->id] = true;
                $origin = $parents->get($origin->source_incoming_schedule_id);
                throw_unless($origin && (int) $origin->warehouse_id === $warehouseId && (int) $origin->item_id === $itemId, \RuntimeException::class, '移動入荷予定の分割元の倉庫・商品が一致しません。');
            }
            $candidateId = (int) ($schedule->transfer_candidate_id ?: $origin->transfer_candidate_id);
            $candidate = $candidates->get($candidateId);
            $effect = $effectsByCandidate->get($candidateId);
            $queue = $queues[$candidateId] ?? null;
            $ids = array_unique(array_filter([(int) $schedule->stock_transfer_id, (int) $origin->stock_transfer_id,
                (int) ($effect?->stock_transfer_id ?? 0), (int) ($queue?->stock_transfer_id ?? 0)]));
            throw_if(count($ids) > 1, \RuntimeException::class, '移動入荷予定と生成履歴の移動伝票が一致しません。管理者に確認してください。');
            if ($candidateId) {
                throw_unless($candidate && (int) $candidate->satellite_warehouse_id === $warehouseId, \RuntimeException::class, '移動入荷予定と候補の倉庫・商品が一致しません。');
                $covered[$candidateId] = true;
            }
            $transferId = (int) (reset($ids) ?: 0);
            if ($transferId) {
                $a = $actual[$transferId] ?? null;
                throw_unless($a, \RuntimeException::class, '移動入荷予定に対応する移動伝票が見つかりません。管理者に紐付けを確認してください。');
                throw_if($candidate && (int) $candidate->hub_warehouse_id !== (int) $a['transfer']->from_warehouse_id, \RuntimeException::class, '移動候補と伝票の移動元倉庫が一致しません。');
                $scheduleGroups[$transferId][] = $schedule;

                continue;
            }
            throw_unless($candidate && $queue && in_array($queue->status, ['BEFORE', 'PROCESSING'], true), \RuntimeException::class, '移動入荷予定に対応する伝票・生成待ちの要求が見つかりません。管理者に確認してください。');
            throw_if(! in_array($schedule->status, ['PENDING', 'PARTIAL'], true) || (int) $schedule->received_quantity !== 0, \RuntimeException::class, '移動伝票の生成前に受入が記録されています。管理者に在庫反映を確認してください。');
            throw_unless((string) ($queue->candidate_payload['item_code'] ?? '') === (string) $capacity?->code,
                \RuntimeException::class, '移動の生成要求と商品の紐付けが一致しません。管理者に確認してください。');
            // The CREATE payload will become the actual transfer. Split or edited schedules do not create another supply.
            $pending[$candidateId] = $this->pieces((int) ($queue->candidate_payload['quantity'] ?? -1),
                (string) ($queue->candidate_payload['quantity_type'] ?? ''), $effect ?: $capacity);
        }
        $incoming += array_sum($pending);
        if ($includeDates || $availabilityDate !== null) {
            $schedulesByCandidate = $schedules->groupBy('transfer_candidate_id');
            foreach ($pending as $candidateId => $pieces) {
                // A split schedule is not another receipt. Only an unambiguous date is plotted.
                $dates = $schedulesByCandidate->get($candidateId, collect())->pluck('expected_arrival_date')->unique()->values();
                $date = $dates->count() === 1 ? $dates->first() : null;
                if ($availability->isToday($date, $availabilityDate)) {
                    $incomingToday += $pieces;
                }
                if ($includeDates) {
                    $events[] = ['source' => 'transfer', 'date' => $date, 'pieces' => $pieces];
                }
            }
        }
        foreach ($scheduleGroups as $id => $group) {
            $a = $actual[$id];
            // Core confirms current stock and is_delivered together; stale/deleted WMS schedules must not add it again.
            if (! $a['valid'] || $a['transfer']->is_delivered) {
                continue;
            }
            $received = 0;
            foreach ($group as $schedule) {
                if ((int) $schedule->received_quantity > 0) {
                    $effect = $effects->firstWhere('id', $maps->get($schedule->id)?->effect_id);
                    $unit = $effect ?: $this->capacityOf($a['lines']);
                    $received += $this->pieces((int) $schedule->received_quantity, $schedule->quantity_type, $unit);
                }
            }
            throw_if($received > $a['applied'], \RuntimeException::class, '移動の受入数量が実在庫へ反映されていません。反映完了後に再計算してください。');
        }
        foreach ($candidates as $candidate) {
            $effect = $effectsByCandidate->get($candidate->id);
            $queue = $queues[$candidate->id] ?? null;
            throw_if($effect?->stock_transfer_id && $queue?->stock_transfer_id && (int) $effect->stock_transfer_id !== (int) $queue->stock_transfer_id,
                \RuntimeException::class, '移動候補と生成履歴の移動伝票が一致しません。管理者に確認してください。');
            $id = (int) ($effect?->stock_transfer_id ?: $queue?->stock_transfer_id);
            if ($id) {
                throw_unless(isset($actual[$id]), \RuntimeException::class, '移動候補の生成先伝票が見つかりません。管理者に確認してください。');
                throw_if((int) $candidate->hub_warehouse_id !== (int) $actual[$id]['transfer']->from_warehouse_id,
                    \RuntimeException::class, '移動候補と伝票の移動元倉庫が一致しません。管理者に確認してください。');
                $covered[$candidate->id] = true;
            }
            if (isset($covered[$candidate->id])) {
                continue;
            }
            throw_if($effect || $queue, \RuntimeException::class, '移動の生成要求と入荷予定の照合が必要です。管理者に確認してください。');
            if (in_array($candidate->status, ['PENDING', 'APPROVED'], true)) {
                $claims += $this->pieces((int) $candidate->transfer_quantity, $candidate->quantity_type, $capacity);
            }
        }
        foreach ($effects as $effect) {
            throw_unless(isset($covered[$effect->source_id]), \RuntimeException::class, '移動発注と移動伝票・入荷予定の照合が必要です。管理者に確認してください。');
        }

        return ['incoming' => $incoming, 'incoming_today' => $incomingToday, 'claims' => $claims, 'covered_candidate_ids' => array_keys($covered)]
            + ($includeDates ? ['supply_events' => $events] : []);
    }

    private function capacityOf(Collection $lines): ?object
    {
        $capacities = $lines->map(fn ($l) => ['capacity_case' => $l->capacity_case, 'capacity_carton' => $l->capacity_carton])->unique();

        return $capacities->count() === 1 ? (object) $capacities->first() : null;
    }

    private function pieces(int $quantity, string $type, ?object $capacity): int
    {
        $factor = match ($type) {
            'PIECE' => 1, 'CASE' => (int) ($capacity?->capacity_case ?? 0), 'CARTON' => (int) ($capacity?->capacity_carton ?? 0), default => 0,
        };
        throw_if($quantity < 0 || $factor < 1 || $quantity > intdiv(PHP_INT_MAX, max(1, $factor)), \RuntimeException::class, '移動の数量または発注時の入数が不明です。管理者に数量単位・入数を確認してください。');

        return $quantity * $factor;
    }
}
