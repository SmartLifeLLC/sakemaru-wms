<?php

namespace App\Services\AutoReplenishment;

use App\Enums\QuantityType;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Item;
use App\Models\WmsItemReorderPolicy;
use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SnapshotReader
{
    private array $proposalItemIds = [];

    private array $proposalCache = [];

    private array $observation = [];

    public function lastObservation(): array
    {
        return $this->observation;
    }

    /** Cache bounded proposal reads only. Final confirmation always clears this context. */
    public function prime(\Illuminate\Support\Collection $policies): void
    {
        $this->clear();
        $this->proposalItemIds = $policies->pluck('item_id')->map(fn ($id) => (int) $id)->all();
    }

    public function clear(): void
    {
        $this->proposalItemIds = $this->proposalCache = [];
    }

    private function once(string $key, callable $read): mixed
    {
        if (! $this->proposalItemIds) {
            return $read();
        }
        if (! array_key_exists($key, $this->proposalCache)) {
            $this->proposalCache[$key] = $read();
        }

        return $this->proposalCache[$key];
    }

    private function forItem(string $key, $query, string $column, int $itemId): \Illuminate\Support\Collection
    {
        if (! $this->proposalItemIds) {
            return $query->where($column, $itemId)->get();
        }
        $groups = $this->once($key, fn () => $query->whereIn($column, $this->proposalItemIds)->get()->groupBy(last(explode('.', $column))));

        return $groups->get($itemId, collect());
    }

    /** Simulation must not refresh a database-backed cache or acquire stock locks. */
    public function readForSimulation(WmsItemReorderPolicy $policy): array
    {
        $this->clear();

        // A receipt commits destination stock and the transfer state together.
        // Keep all simulation reads at one snapshot without locking or writing.
        return DB::connection('sakemaru')->transaction(fn () => $this->read($policy, readOnly: true));
    }

    public function read(WmsItemReorderPolicy $policy, ?int $excludeLineId = null, bool $readOnly = false): array
    {
        $this->observation = ['policy' => $policy->toArray()];
        if ($excludeLineId !== null) {
            $this->clear();
        }
        $db = DB::connection('sakemaru');
        $clientId = (int) $policy->client_id;
        $warehouse = $this->once('warehouse:'.$policy->warehouse_id, fn () => $db->table('warehouses')->where(['id' => $policy->warehouse_id, 'client_id' => $clientId, 'is_active' => 1, 'is_virtual' => 0])->first());
        $item = $this->proposalItemIds
            ? $this->once('items', fn () => Item::with('current_price')->where('client_id', $clientId)->where('is_active', true)->whereIn('id', $this->proposalItemIds)->get()->keyBy('id'))->get($policy->item_id)
            : Item::where('client_id', $clientId)->where('is_active', true)->find($policy->item_id);
        $this->observation += ['item_id' => (int) $policy->item_id, 'item_code' => $item?->code, 'item_name' => $item?->name,
            'warehouse_code' => $warehouse?->code, 'warehouse_name' => $warehouse?->name];
        throw_unless($warehouse && $item && ! $item->is_ended && $item->end_of_sale_type === 'NORMAL', \RuntimeException::class, '商品または倉庫が発注対象外です。');
        if ($item->relationLoaded('current_price') && $item->current_price) {
            $item->current_price->setRelation('item', $item);
        }
        $businessDate = $this->once('business_date', function () use ($readOnly) {
            if (! $readOnly) {
                return ClientSetting::freshSystemDateYMD('auto_replenishment:snapshot');
            }
            // Same source as freshSystemDateYMD, without its Cache::put side effect.
            $date = ClientSetting::query()->first()?->system_date;
            throw_unless($date, \RuntimeException::class, '業務日が未設定です。管理者に業務日の設定を確認してください。');

            return Carbon::parse($date)->toDateString();
        });
        throw_if(($item->start_of_sale_date && Carbon::parse($item->start_of_sale_date)->gt(Carbon::parse($businessDate))) || ($item->end_of_sale_date && Carbon::parse($item->end_of_sale_date)->lt(Carbon::parse($businessDate))), \RuntimeException::class, '商品が販売期間外です。');
        $setting = $this->once('setting:'.$policy->warehouse_id, fn () => $db->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $policy->warehouse_id)->first());
        $config = json_decode($setting?->replenishment_config ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $allocationIds = $this->once('normal_allocation_ids:'.$clientId, fn () => $db->table('stock_allocations')
            ->where(['client_id' => $clientId, 'is_active' => true, 'code' => 1])->pluck('id')->map(fn ($id) => (int) $id)->all());
        throw_unless(count($allocationIds) === 1, \RuntimeException::class, '在庫区分「正常品」を特定できません。管理者に在庫区分の設定を確認してください。');
        $relations = $this->forItem('relations', $db->table('item_contractors')->where([
            'client_id' => $clientId, 'warehouse_id' => $policy->warehouse_id,
        ]), 'item_id', (int) $policy->item_id)->where('contractor_id', $policy->contractor_id)
            ->when($policy->supplier_id, fn ($rows) => $rows->where('supplier_id', $policy->supplier_id));
        throw_unless($relations->count() === 1, \RuntimeException::class, '商品・倉庫・発注先の関係が未登録または重複しています。');
        $relation = $relations->first();
        $contractor = $this->once('contractor:'.$policy->contractor_id, fn () => $db->table('contractors')->where(['id' => $policy->contractor_id, 'client_id' => $clientId, 'is_active' => 1])->first());
        $supplier = $this->once('supplier:'.$relation->supplier_id, fn () => $db->table('suppliers')->where(['id' => $relation->supplier_id, 'client_id' => $clientId])->first());
        throw_unless($contractor && $supplier, \RuntimeException::class, '発注先または仕入先が無効です。');
        app(OrderRouteEligibility::class)->assertEligible($policy->channel, (int) $policy->contractor_id,
            $this->once('route_targets:'.$policy->channel, fn () => app(OrderRouteEligibility::class)->contractorIds($policy->channel)));
        $stocks = $this->forItem('stocks', $db->table('real_stocks')->where(['client_id' => $clientId, 'warehouse_id' => $policy->warehouse_id])
            ->whereIn('stock_allocation_id', $allocationIds)->when(! $readOnly && $db->transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->select(['id', 'item_id', 'current_quantity', 'reserved_quantity']), 'item_id', (int) $policy->item_id);
        // No row can also mean an unimported stock feed. Never infer zero here.
        throw_if($stocks->isEmpty(), \RuntimeException::class, '在庫データが未連携です。');
        $stock = (int) $stocks->sum('current_quantity');
        $this->observation['stock'] = $stock;
        throw_if($stocks->contains(fn ($s) => $s->current_quantity < 0), \RuntimeException::class, '負在庫があるため保留します。');

        $transfer = $this->once('transfer_supply:'.$policy->warehouse_id, fn () => app(TransferSupplyReader::class)->read(
            $clientId, (int) $policy->warehouse_id, $this->proposalItemIds ?: [(int) $policy->item_id], $allocationIds,
            ! $readOnly && $db->transactionLevel() > 0,
        ))[$policy->item_id];
        throw_if(isset($transfer['error']), \RuntimeException::class, $transfer['error'] ?? '移動入荷予定を照合できません。');
        $effects = $this->forItem('effects', $db->table('wms_replenishment_effects')->where(['client_id' => $clientId, 'warehouse_id' => $policy->warehouse_id])->whereIn('state', ['OPEN', 'UNKNOWN', 'RECEIPT_PENDING']), 'item_id', (int) $policy->item_id);
        foreach ($effects as $effect) {
            if ($effect->source_type === 'transfer') {
                continue; // The actual transfer/receipt replaces this projection, including HQ quantity changes.
            }
            $sourceMaps = $db->table('wms_replenishment_effect_sources')->where('effect_id', $effect->id)->get()->keyBy('incoming_schedule_id');
            $sourceIds = $sourceMaps->keys();
            $liveSources = $db->table('wms_order_incoming_schedules')->whereIn('id', $sourceIds)
                ->when(! $readOnly && $db->transactionLevel() > 0, fn ($q) => $q->lockForUpdate())
                ->get(['id', 'status', 'received_quantity', 'quantity_type', 'purchase_queue_id']);
            throw_if($effect->reconciliation_required || $sourceIds->isEmpty() || $liveSources->count() !== $sourceIds->count()
                || $liveSources->contains(fn ($row) => ! in_array($row->status, ['PENDING', 'PARTIAL', 'CONFIRMED', 'TRANSMITTED'], true)), \RuntimeException::class, '既発注供給の削除・分割・取消の照合が必要です。');
            foreach ($liveSources as $row) {
                throw_if($this->pieces((int) $row->received_quantity, $row->quantity_type, $effect) > (int) $sourceMaps[$row->id]->stock_applied_pieces && ! $this->purchaseApplied($row, $readOnly),
                    \RuntimeException::class, '受入数量の実在庫反映が未照合です。自動発注を保留します。');
            }
        }
        $incoming = $transfer['incoming'];
        $schedules = $this->forItem('schedules', $db->table('wms_order_incoming_schedules')->where('warehouse_id', $policy->warehouse_id)
            ->where('order_source', '!=', 'TRANSFER')->whereNull('stock_transfer_id')->whereNull('transfer_candidate_id')
            ->whereIn('status', ['PENDING', 'PARTIAL'])->when(! $readOnly && $db->transactionLevel() > 0, fn ($q) => $q->lockForUpdate()), 'item_id', (int) $policy->item_id);
        $invalidLinks = $this->forItem('external_invalid_links', $db->table('wms_order_incoming_schedules as s')->where('s.warehouse_id', $policy->warehouse_id)
            ->where('s.order_source', '!=', 'TRANSFER')->whereNull('s.stock_transfer_id')->whereNull('s.transfer_candidate_id')
            ->whereIn('s.status', ['PENDING', 'PARTIAL'])->whereNotNull('s.order_candidate_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wms_order_candidates as c')->whereColumn('c.id', 's.order_candidate_id')
                ->whereColumn('c.warehouse_id', 's.warehouse_id')->whereColumn('c.item_id', 's.item_id'))->select(['s.item_id']), 's.item_id', (int) $policy->item_id);
        throw_if($invalidLinks->isNotEmpty(), \RuntimeException::class, '外部発注の入荷予定と発注候補の倉庫・商品が一致しません。管理者に紐付けを確認してください。');
        foreach ($schedules as $s) {
            throw_if($s->received_quantity < 0 || $s->expected_quantity < 0 || $s->received_quantity > $s->expected_quantity,
                \RuntimeException::class, '入荷予定の数量が不正です。入荷数量は0以上、予定数量以下にしてください。');
            $source = $db->table('wms_replenishment_effect_sources as m')->join('wms_replenishment_effects as e', 'e.id', '=', 'm.effect_id')->where('m.incoming_schedule_id', $s->id)->first(['e.capacity_case', 'e.capacity_carton', 'e.state', 'm.stock_applied_pieces']);
            throw_if(! $source && $s->quantity_type !== 'PIECE', \RuntimeException::class, '既存入荷予定の発注時入数を確認できません。照合が必要です。');
            throw_if($source && ! in_array($source->state, ['OPEN', 'COMPLETED', 'RECEIPT_PENDING']), \RuntimeException::class, '取消・不明状態の供給が残っています。');
            $received = $this->pieces((int) $s->received_quantity, (string) $s->quantity_type, $source ?? $item);
            throw_if($received > (int) ($source?->stock_applied_pieces ?? 0) && ! $this->purchaseApplied($s, $readOnly),
                \RuntimeException::class, '外部発注の入荷数量が実在庫へ反映されていません。仕入連携の完了後に再計算してください。');
            $incoming += $this->pieces((int) $s->expected_quantity - (int) $s->received_quantity, (string) $s->quantity_type, $source ?? $item);
        }
        $orderSchedules = $this->forItem('external_order_schedules', $db->table('wms_order_candidates as c')
            ->join('wms_order_incoming_schedules as s', 's.order_candidate_id', '=', 'c.id')
            ->leftJoin('wms_replenishment_effect_sources as m', 'm.incoming_schedule_id', '=', 's.id')
            ->leftJoin('wms_replenishment_effects as e', 'e.id', '=', 'm.effect_id')
            ->where('c.warehouse_id', $policy->warehouse_id)->whereColumn('s.warehouse_id', 'c.warehouse_id')->whereColumn('s.item_id', 'c.item_id')
            ->whereIn('s.status', ['PENDING', 'PARTIAL', 'CONFIRMED', 'TRANSMITTED'])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('wms_order_incoming_schedules as pending')->whereColumn('pending.order_candidate_id', 'c.id')->whereIn('pending.status', ['PENDING', 'PARTIAL']))
            ->when(! $readOnly && $db->transactionLevel() > 0, fn ($q) => $q->lockForUpdate())
            ->select(['c.id', 'c.item_id', 'c.order_quantity', 'c.quantity_type as candidate_quantity_type', 's.expected_quantity', 's.quantity_type', 'e.capacity_case', 'e.capacity_carton']), 'c.item_id', (int) $policy->item_id);
        foreach ($orderSchedules->groupBy('id') as $group) {
            $total = $group->sum(fn ($s) => $this->pieces((int) $s->expected_quantity, $s->quantity_type, $s));
            $ordered = $this->pieces((int) $group->first()->order_quantity, $group->first()->candidate_quantity_type, $group->first());
            throw_if($total > $ordered, \RuntimeException::class, '同じ発注候補の入荷予定数量が発注数量を超えています。管理者に重複・分割内容を確認してください。');
        }
        $pendingReceipts = $this->forItem('external_receipts', $db->table('wms_order_incoming_schedules')->where('warehouse_id', $policy->warehouse_id)
            ->where('order_source', '!=', 'TRANSFER')->whereNull('stock_transfer_id')->whereNull('transfer_candidate_id')
            ->whereIn('status', ['CONFIRMED', 'TRANSMITTED'])->where('received_quantity', '>', 0)->whereNotNull('purchase_queue_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchase_create_queue as pq')->whereColumn('pq.id', 'wms_order_incoming_schedules.purchase_queue_id')
                ->where(['pq.status' => 'FINISHED', 'pq.is_success' => true])->whereNotNull('pq.slip_number'))
            ->when(! $readOnly && $db->transactionLevel() > 0, fn ($q) => $q->lockForUpdate()), 'item_id', (int) $policy->item_id);
        throw_if($pendingReceipts->isNotEmpty(), \RuntimeException::class, '外部発注の仕入連携が未完了です。在庫反映の完了後に再計算してください。');
        $unmatchedConfirmed = $this->forItem('unmatched', $db->table('wms_order_candidates as c')->where('c.warehouse_id', $policy->warehouse_id)->whereIn('c.status', ['CONFIRMED', 'EXECUTED'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wms_order_incoming_schedules as s')->whereColumn('s.order_candidate_id', 'c.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wms_replenishment_effects as e')->where('e.source_type', 'order')->whereColumn('e.source_id', 'c.id')->whereIn('e.state', ['COMPLETED', 'CANCELLED']))->select('c.item_id'), 'c.item_id', (int) $policy->item_id)->isNotEmpty();
        throw_if($unmatchedConfirmed, \RuntimeException::class, '確定発注と入荷予定の対応に不整合があります。');
        $this->observation['incoming'] = $incoming;
        $this->observation['incoming_transfer'] = $transfer['incoming'];
        $this->observation['incoming_external'] = $incoming - $transfer['incoming'];
        $claims = $transfer['claims'];
        foreach ($this->forItem('claims_order', $db->table('wms_order_candidates')->where('warehouse_id', $policy->warehouse_id)->whereIn('status', ['PENDING', 'APPROVED'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wms_order_incoming_schedules as s')->whereColumn('s.order_candidate_id', 'wms_order_candidates.id')->whereIn('s.status', ['PENDING', 'PARTIAL', 'CONFIRMED', 'TRANSMITTED']))
            ->select(['item_id', 'order_quantity', 'quantity_type']), 'item_id', (int) $policy->item_id) as $c) {
            $claims += $this->pieces((int) $c->order_quantity, (string) $c->quantity_type, $item);
        }
        $this->observation['claims'] = $claims;
        // Missing/cancelled schedules do not imply cancellation of the order.
        $openLines = $this->forItem('open_lines', $db->table('wms_auto_order_run_lines as l')->join('wms_auto_order_runs as r', 'r.id', '=', 'l.run_id')
            ->where('l.client_id', $clientId)->where('l.warehouse_id', $policy->warehouse_id)
            ->where('r.is_preview', false)->whereNotNull('l.generated_at')->where('l.phase', '!=', 'ABORTED')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('wms_replenishment_effects as e')->where('e.state', 'COMPLETED')
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('e.source_type', 'order')->whereColumn('e.source_id', 'l.order_candidate_id'))
                        ->orWhere(fn ($q) => $q->where('e.source_type', 'transfer')->whereColumn('e.source_id', 'l.transfer_candidate_id')));
            })
            ->when($excludeLineId, fn ($q) => $q->where('l.id', '!=', $excludeLineId))->select('l.*'), 'l.item_id', (int) $policy->item_id);
        foreach ($openLines as $line) {
            if ($line->transfer_candidate_id && in_array((int) $line->transfer_candidate_id, $transfer['covered_candidate_ids'], true)) {
                continue;
            }
            $linked = $db->table('wms_auto_order_artifact_links')->where('line_id', $line->id)->where('artifact_type', 'incoming')->pluck('artifact_id');
            $actual = $db->table('wms_order_incoming_schedules')->whereIn('id', $linked)->get(['id', 'status']);
            throw_if($linked->isEmpty() || $actual->count() !== $linked->count() || $actual->contains(fn ($s) => ! in_array($s->status, ['PENDING', 'PARTIAL', 'CONFIRMED', 'TRANSMITTED'], true)), \RuntimeException::class, '既発注と入荷予定の照合が必要です。');
        }

        $pair = null;
        $sourceAllocationId = null;
        if ($policy->channel === 'EOS') {
            $orderDay = Carbon::parse($businessDate);
            if (! $orderDay->isSunday() && now()->hour >= 13) {
                $orderDay->addDay();
            }
            $arrival = $this->once('arrival:'.$policy->contractor_id.':'.$policy->warehouse_id, fn () => app(JxOrderArrivalDateAdjustmentService::class)->earliestArrivalDate((int) $policy->contractor_id, (int) $policy->warehouse_id, $orderDay));
            throw_unless($arrival, \RuntimeException::class, '入荷可能日がありません。');
        } else {
            throw_unless($policy->channel === 'LOGISTICS' && $policy->source_warehouse_id && $policy->source_warehouse_id != $policy->warehouse_id, \RuntimeException::class, '移動元倉庫を設定してください。');
            $source = $db->table('warehouses')->where(['id' => $policy->source_warehouse_id, 'client_id' => $clientId, 'is_active' => 1, 'is_virtual' => 0])->first();
            $pair = $db->table('warehouse_stock_transfer_delivery_courses')->where(['from_warehouse_id' => $policy->source_warehouse_id, 'to_warehouse_id' => $policy->warehouse_id])->first();
            throw_unless($source && $pair && (int) $pair->delivery_course_id === (int) $policy->delivery_course_id, \RuntimeException::class, '倉庫間配送コースの設定が不一致です。');
            $clientLead = $this->once('client_picking_lead', fn () => $db->table('client_settings')->where('client_id', $clientId)->value('default_picking_lead_days'));
            throw_unless($pair->picking_lead_days !== null || $clientLead !== null, \RuntimeException::class, '倉庫別または顧客既定の出庫リード日が未設定です。');
            $pair->effective_picking_lead_days = (int) ($pair->picking_lead_days ?? $clientLead);
            $sourceAllocationId = $allocationIds[0];
            $arrival = $this->once('logistics-arrival:'.$policy->contractor_id.':'.$policy->warehouse_id,
                fn () => app(LogisticsArrivalDateService::class)->resolve($policy, $businessDate));
        }
        $search = app(OrderRegistrationSearchService::class);
        $orderingCode = $this->proposalItemIds
            ? $this->once('ordering_codes', fn () => $db->table('item_search_information')->whereIn('item_id', $this->proposalItemIds)->where('is_used_for_ordering', true)->where('is_active', true)->whereRaw("search_string REGEXP '[1-9]'")->orderBy('id')->get(['item_id', 'search_string'])->unique('item_id')->mapWithKeys(fn ($row) => [$row->item_id => str_pad((string) $row->search_string, 13, '0', STR_PAD_LEFT)]))->get($item->id)
            : $search->orderingCodeForItem((int) $item->id);
        $capacity = (int) $item->capacity_case;
        $packingUnit = 1;
        throw_unless($capacity > 0 && $orderingCode, \RuntimeException::class, '入数または発注CDが未設定です。');
        $probe = new \App\Models\WmsOrderCandidate(['order_quantity' => 1, 'quantity_type' => QuantityType::PIECE, 'ordering_code' => $orderingCode]);
        $probe->setRelation('item', $item);
        $output = app(OrderOutputQuantityResolver::class)->resolve($probe);
        if ($policy->channel === 'EOS' && $output['ordering_unit_quantity'] !== null) {
            // Select only quantities represented exactly by the existing converter.
            $packingUnit = $capacity;
        }

        return [
            'item_id' => (int) $item->id, 'item_code' => (string) $item->code, 'item_name' => $item->name,
            'warehouse_code' => (string) $warehouse->code, 'warehouse_name' => $warehouse->name,
            'contractor_code' => (string) $contractor->code, 'contractor_name' => $contractor->name,
            'supplier_id' => (int) $relation->supplier_id, 'stock' => $stock, 'incoming' => $incoming, 'claims' => $claims,
            'incoming_transfer' => $transfer['incoming'], 'incoming_external' => $incoming - $transfer['incoming'],
            'packing_unit' => $packingUnit, 'quantity_type' => $packingUnit > 1 ? 'CASE' : 'PIECE', 'capacity_case' => (int) $item->capacity_case,
            'capacity_carton' => (int) $item->capacity_carton, 'ordering_code' => $orderingCode,
            'business_date' => $businessDate, 'arrival_date' => $arrival->toDateString(),
            'jx_route_hash' => $policy->channel === 'EOS' ? $this->once('jx_route:'.$policy->contractor_id, fn () => app(JxRouteFingerprint::class)->fingerprint((int) $policy->contractor_id)) : null,
            // Price is not used by quantity/date simulation; its accessor refreshes a cache.
            'budget_unit_price' => $readOnly ? '0' : (string) ($item->current_price?->purchase_unit_price ?? '0'),
            'route_snapshot' => $pair ? (array) $pair : null, 'warehouse_config' => $config,
            'source_stock_allocation_id' => $sourceAllocationId,
            'policy' => $policy->toArray(), 'observed_at' => now()->toIso8601String(),
        ];
    }

    public function pieces(int $quantity, string $type, object $item): int
    {
        $unit = match ($type) {
            'CASE' => (int) $item->capacity_case,
            'CARTON' => (int) $item->capacity_carton,
            'PIECE' => 1,
            default => 0,
        };
        throw_if($quantity < 0 || $unit < 1 || $quantity > intdiv(PHP_INT_MAX, $unit), \RuntimeException::class, '数量単位が不明です。');

        return $quantity * $unit;
    }

    private function purchaseApplied(object $schedule, bool $readOnly): bool
    {
        if (! $schedule->purchase_queue_id) {
            return false;
        }
        $db = DB::connection('sakemaru');

        return $this->once('purchase_applied:'.$schedule->purchase_queue_id, fn () => $db->table('purchase_create_queue')
            ->where(['id' => $schedule->purchase_queue_id, 'status' => 'FINISHED', 'is_success' => true])->whereNotNull('slip_number')
            ->when(! $readOnly && $db->transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first(['id']) !== null);
    }
}
