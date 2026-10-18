<?php

namespace App\Services\AutoReplenishment;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\JxOrderArrivalDateAdjustmentService;
use App\Services\AutoOrder\OrderOutputQuantityResolver;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Bounded SELECT-only history. Never use a requested quantity as a shipped quantity. */
class SettingsRecommendationHistory
{
    private array $cache = [];

    private function once(string $key, callable $read): mixed
    {
        return $this->cache[$key] ??= $read();
    }

    private function rows($query): array
    {
        $rows = $query->limit(10001)->get();
        throw_if($rows->count() > 10000, \RuntimeException::class, '対象実績が1万件を超えています。管理者に算定期間を確認してください。');

        return $rows->all();
    }

    public function read(int $clientId, int $warehouseId, array $route): array
    {
        $db = DB::connection('sakemaru');
        $warehouse = $this->once("warehouse:{$clientId}:{$warehouseId}", fn () => $db->table('warehouses')->where(['client_id' => $clientId, 'id' => $warehouseId, 'is_active' => 1, 'is_virtual' => 0])->first());
        $itemId = (int) ($route['item_id'] ?? 0);
        $item = $this->once("item:{$clientId}:{$itemId}", fn () => Item::where(['client_id' => $clientId, 'is_active' => true])->find($itemId));
        throw_unless($warehouse && $item && ! $item->is_ended, \RuntimeException::class, '商品または選択倉庫が無効です。');
        $preserved = config('replenishment.initial_reset_preserve_warehouse_codes.'.config('wms.client'), []);
        throw_if(in_array((int) $warehouse->code, $preserved, true), \RuntimeException::class, 'この倉庫は実績からの自動設定対象外です。既存の数量設定を維持してください。');
        $channel = $route['channel'] ?? '';
        $contractor = (int) ($route['contractor_id'] ?? 0);
        $eligible = $this->once('eligible:'.$channel, fn () => app(OrderRouteEligibility::class)->contractorIds($channel));
        app(OrderRouteEligibility::class)->assertEligible($channel, $contractor, $eligible);
        $relations = $db->table('item_contractors')->where(['client_id' => $clientId, 'warehouse_id' => $warehouseId, 'item_id' => $item->id, 'contractor_id' => $contractor])
            ->when(! empty($route['supplier_id']), fn ($q) => $q->where('supplier_id', $route['supplier_id']))->get(['supplier_id']);
        throw_unless($relations->count() === 1, \RuntimeException::class, '商品の発注先・仕入先を1つ選択してください。');
        $supplier = (int) $relations->first()->supplier_id;
        $today = CarbonImmutable::now('Asia/Tokyo')->startOfDay();
        $systemDate = $this->once('system_date:'.$clientId, fn () => $db->table('client_settings')->where('client_id', $clientId)->value('system_date'));
        throw_unless($systemDate, \RuntimeException::class, '業務日が未設定です。');
        $asOf = min($today->toDateString(), CarbonImmutable::parse($systemDate)->toDateString());
        $end = CarbonImmutable::parse($asOf)->subDay();
        $start = max($end->subDays(89)->toDateString(), config('replenishment.recommendation_not_before', '2026-08-01'));
        throw_if($start > $end->toDateString(), \RuntimeException::class, '算定対象期間の完了実績がありません。');
        $period = ['start' => $start, 'end' => $end->toDateString()];
        $allocation = $this->once('allocation:'.$clientId, fn () => $db->table('stock_allocations')->where(['client_id' => $clientId, 'code' => 1, 'is_active' => 1])->pluck('id')->all());
        throw_unless(count($allocation) === 1, \RuntimeException::class, '在庫区分「正常品」を特定できません。');
        $allocation = (int) $allocation[0];
        $packing = 1;
        if ($channel === 'EOS') {
            $code = $this->once('ordering_code:'.$itemId, fn () => app(OrderRegistrationSearchService::class)->orderingCodeForItem($itemId));
            throw_unless($code && $item->capacity_case > 0, \RuntimeException::class, '発注CDまたはケース入数が未設定です。');
            $probe = new WmsOrderCandidate(['order_quantity' => 1, 'quantity_type' => QuantityType::PIECE, 'ordering_code' => $code]);
            $probe->setRelation('item', $item);
            $packing = app(OrderOutputQuantityResolver::class)->resolve($probe)['ordering_unit_quantity'] !== null ? (int) $item->capacity_case : 1;
            $receipts = $this->once("eos:{$item->id}:{$contractor}:{$supplier}:{$start}:{$period['end']}", fn () => $this->eosReceipts($clientId, (int) $item->id, $contractor, $supplier, $allocation, $period));
        } else {
            $source = (int) ($route['source_warehouse_id'] ?? 0);
            throw_unless($db->table('warehouses')->where(['id' => $source, 'client_id' => $clientId, 'is_active' => 1, 'is_virtual' => 0])->exists(), \RuntimeException::class, '有効な移動元倉庫を選択してください。');
            throw_unless($source && $source !== $warehouseId && $db->table('warehouse_stock_transfer_delivery_courses')->where(['from_warehouse_id' => $source, 'to_warehouse_id' => $warehouseId, 'delivery_course_id' => $route['delivery_course_id'] ?? 0])->exists(), \RuntimeException::class, '移動元倉庫・配送コースを選択してください。');
            $receipts = $this->once("transfer:{$item->id}:{$contractor}:{$source}:{$start}:{$period['end']}", fn () => $this->transferReceipts($clientId, (int) $item->id, $contractor, $source, $allocation, $period));
        }
        $demand = $this->dailyDemand($clientId, $warehouseId, (int) $item->id, $allocation, $period);
        $lead = $this->once("lead:{$warehouseId}:{$contractor}:{$asOf}", function () use ($warehouseId, $contractor, $asOf) {
            $resolver = app(JxOrderArrivalDateAdjustmentService::class);
            $max = 1;
            for ($i = 0; $i < 7; $i++) {
                $day = \Carbon\Carbon::parse($asOf)->addDays($i);
                $arrival = $resolver->earliestArrivalDate($contractor, $warehouseId, $day);
                throw_unless($arrival, \RuntimeException::class, '既存の配送設定から入荷可能日を算定できません。');
                $max = max($max, (int) $day->diffInDays($arrival));
            }

            return $max;
        });

        return ['scope' => ['client_id' => $clientId, 'warehouse_id' => $warehouseId, 'warehouse_code' => (string) $warehouse->code,
            'item_id' => (int) $item->id, 'item_code' => (string) $item->code, 'channel' => $channel, 'contractor_id' => $contractor,
            'supplier_id' => $supplier, 'source_warehouse_id' => $channel === 'LOGISTICS' ? (int) $route['source_warehouse_id'] : null,
            'delivery_course_id' => $channel === 'LOGISTICS' ? (int) $route['delivery_course_id'] : null,
            'capacity_case' => (int) $item->capacity_case, 'packing_unit' => $packing],
            'period' => $period, 'receipts' => $receipts, 'daily' => $demand['daily'], 'warnings' => $demand['warnings'], 'nominal_lead_days' => $lead];
    }

    private function transferBase(int $client, int $item, int $allocation, array $period)
    {
        return DB::connection('sakemaru')->table('trade_items as ti')->join('trades as t', 't.id', '=', 'ti.trade_id')->join('stock_transfers as st', 'st.trade_id', '=', 't.id')
            ->where(['ti.client_id' => $client, 'ti.item_id' => $item, 'ti.is_active' => 1, 'ti.stock_allocation_id' => $allocation,
                't.client_id' => $client, 't.is_active' => 1, 't.is_latest' => 1, 't.trade_category' => 'STOCK_TRANSFER', 't.is_returned' => 0, 't.trade_direction' => 'NORMAL', 'st.client_id' => $client, 'st.is_active' => 1])
            ->where(fn ($q) => $q->where('st.is_delivered', 1)->orWhere('st.picking_status', 'SHIPPED'))
            ->whereBetween('st.picking_date', array_values($period))->whereRaw('CAST(ti.total_piece_quantity AS SIGNED)>0');
    }

    private function transferLink($q, ?int $contractor = null): void
    {
        $q->selectRaw('1')->from('wms_order_incoming_schedules as s')->join('wms_stock_transfer_candidates as c', 'c.id', '=', 's.transfer_candidate_id')
            ->whereColumn('s.stock_transfer_id', 'st.id')->whereColumn('s.item_id', 'ti.item_id')->where('s.order_source', 'TRANSFER')
            ->whereColumn('s.warehouse_id', 'st.to_warehouse_id')->whereColumn('c.item_id', 'ti.item_id')
            ->whereColumn('c.hub_warehouse_id', 'st.from_warehouse_id')->whereColumn('c.satellite_warehouse_id', 'st.to_warehouse_id')
            // Older WMS transfer candidates did not store a contractor. Their exact warehouse pair still proves the logistics route.
            ->when($contractor, fn ($q) => $q->where(fn ($q) => $q->where('c.contractor_id', $contractor)->orWhereNull('c.contractor_id')));
    }

    private function transferReceipts(int $client, int $item, int $contractor, int $source, int $allocation, array $period): array
    {
        $rows = $this->rows($this->transferBase($client, $item, $allocation, $period)->where('st.from_warehouse_id', $source)
            ->whereExists(fn ($q) => $this->transferLink($q, $contractor))
            ->select(['st.id', 'st.to_warehouse_id', 'st.picking_date', 'st.delivered_date', 'ti.id as line_id', 'ti.quantity', 'ti.quantity_type', 'ti.capacity_case', 'ti.capacity_carton', 'ti.total_piece_quantity'])->orderBy('st.id'));
        $links = DB::connection('sakemaru')->table('wms_order_incoming_schedules as s')->join('wms_stock_transfer_candidates as c', 'c.id', '=', 's.transfer_candidate_id')
            ->join('stock_transfers as st', 'st.id', '=', 's.stock_transfer_id')
            ->where('s.order_source', 'TRANSFER')->whereColumn('s.warehouse_id', 'st.to_warehouse_id')
            ->whereColumn('c.hub_warehouse_id', 'st.from_warehouse_id')->whereColumn('c.satellite_warehouse_id', 'st.to_warehouse_id')
            ->where('s.item_id', $item)->where('c.item_id', $item)->where(fn ($q) => $q->where('c.contractor_id', $contractor)->orWhereNull('c.contractor_id'))->whereIn('s.stock_transfer_id', array_unique(array_column($rows, 'id')))
            ->get(['s.stock_transfer_id', 's.order_date', 's.id as schedule_id', 'c.id as candidate_id'])->groupBy('stock_transfer_id');
        $groups = [];
        foreach ($rows as $row) {
            $linksForRow = $links->get($row->id, collect());
            $order = $linksForRow->min('order_date');
            $groups[$row->id] ??= ['warehouse_id' => (int) $row->to_warehouse_id, 'pieces' => 0, 'date' => $row->picking_date,
                'lead_days' => $order && $row->delivered_date ? (int) CarbonImmutable::parse($order)->diffInDays(CarbonImmutable::parse($row->delivered_date), false) : null,
                'source' => ['type' => 'stock_transfer', 'id' => (int) $row->id, 'candidate_ids' => $linksForRow->pluck('candidate_id')->unique()->values()->all()]];
            $groups[$row->id]['pieces'] += $this->actualPieces($row);
        }

        return array_values($groups);
    }

    private function eosReceipts(int $client, int $item, int $contractor, int $supplier, int $allocation, array $period): array
    {
        $db = DB::connection('sakemaru');
        $links = $this->rows($db->table('wms_order_incoming_schedules as s')->join('wms_order_candidates as c', 'c.id', '=', 's.order_candidate_id')
            ->join('purchase_create_queue as q', 'q.id', '=', 's.purchase_queue_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where(['s.item_id' => $item, 'c.item_id' => $item, 'c.contractor_id' => $contractor, 'w.client_id' => $client, 'w.is_virtual' => 0, 'q.status' => 'FINISHED', 'q.is_success' => 1])
            ->where(fn ($q) => $q->where('c.order_channel', 'EOS')->orWhere(fn ($q) => $q->whereNull('c.order_channel')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('wms_order_jx_documents as jx')->whereColumn('jx.id', 'c.wms_order_jx_document_id')->where('jx.document_type', 'PURCHASE'))))
            ->whereColumn('s.contractor_id', 'c.contractor_id')
            ->where('w.code', '!=', 91)->whereColumn('c.warehouse_id', 's.warehouse_id')->whereRaw('COALESCE(s.supplier_id,c.supplier_id)=?', [$supplier])
            ->where('s.received_quantity', '>', 0)->whereBetween('s.actual_arrival_date', array_values($period))
            ->select(['s.id', 's.warehouse_id', 's.order_date', 's.purchase_queue_id', 'c.id as candidate_id', 'c.wms_order_jx_document_id', 'q.slip_number'])
            ->selectRaw("JSON_EXTRACT(q.items,'$.created_purchase_serial_ids') split_serials")->orderBy('s.id'));
        $serialQueues = [];
        $queueLinks = [];
        foreach ($links as $link) {
            $queueLinks[$link->purchase_queue_id][] = $link;
            $serials = json_decode($link->split_serials ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?: [$link->slip_number];
            foreach ($serials as $serial) {
                if (ctype_digit((string) $serial) && (int) $serial > 0) {
                    $serialQueues[(string) $serial][(int) $link->purchase_queue_id] = true;
                }
            }
        }
        if (! $serialQueues) {
            return [];
        }
        $rows = $this->rows($db->table('trade_items as ti')->join('trades as t', 't.id', '=', 'ti.trade_id')->join('purchases as p', 'p.trade_id', '=', 't.id')
            ->where(['ti.client_id' => $client, 'ti.item_id' => $item, 'ti.stock_allocation_id' => $allocation, 'ti.is_active' => 1,
                't.client_id' => $client, 't.is_active' => 1, 't.is_latest' => 1, 't.trade_category' => 'PURCHASE', 't.is_returned' => 0, 't.trade_direction' => 'NORMAL',
                'p.client_id' => $client, 'p.is_active' => 1, 'p.is_direct_delivery' => 0, 'p.supplier_id' => $supplier])
            ->whereIn('t.serial_id', array_keys($serialQueues))->whereRaw('CAST(ti.total_piece_quantity AS SIGNED)>0')
            ->whereRaw('COALESCE(p.delivered_date,t.process_date) BETWEEN ? AND ?', array_values($period))
            ->select(['p.id', 'p.warehouse_id', 't.serial_id', 'ti.id as line_id', 'ti.quantity', 'ti.quantity_type', 'ti.capacity_case', 'ti.capacity_carton', 'ti.total_piece_quantity'])
            ->selectRaw('COALESCE(p.delivered_date,t.process_date) receipt_date')->orderBy('p.id'));
        $groups = [];
        foreach ($rows as $row) {
            $queues = array_keys($serialQueues[(string) $row->serial_id]);
            throw_unless(count($queues) === 1, \RuntimeException::class, '仕入連携と実伝票の対応が重複しています。管理者に確認してください。');
            $queue = $queues[0];
            $matching = array_filter($queueLinks[$queue], fn ($l) => (int) $l->warehouse_id === (int) $row->warehouse_id);
            if (! $matching) {
                continue;
            }
            $key = $queue.':'.$row->warehouse_id.':'.$row->receipt_date;
            $order = min(array_column($matching, 'order_date'));
            $groups[$key] ??= ['warehouse_id' => (int) $row->warehouse_id, 'pieces' => 0, 'date' => $row->receipt_date,
                'lead_days' => (int) CarbonImmutable::parse($order)->diffInDays(CarbonImmutable::parse($row->receipt_date), false),
                'source' => ['type' => 'purchase', 'queue_id' => $queue, 'purchase_ids' => [], 'candidate_ids' => array_values(array_unique(array_column($matching, 'candidate_id'))),
                    'jx_document_ids' => array_values(array_unique(array_filter(array_column($matching, 'wms_order_jx_document_id'))))]];
            $groups[$key]['pieces'] += $this->actualPieces($row);
            $groups[$key]['source']['purchase_ids'][] = (int) $row->id;
        }

        return array_values($groups);
    }

    private function actualPieces(object $row): int
    {
        $factor = match ($row->quantity_type) {
            'CASE' => (int) $row->capacity_case, 'CARTON' => (int) $row->capacity_carton, 'PIECE' => 1,
            default => 0,
        };
        $pieces = (int) $row->quantity * $factor;
        throw_unless($factor > 0 && $pieces > 0 && $pieces === (int) $row->total_piece_quantity, \RuntimeException::class, '実績の数量区分・入数・総バラ数が一致しません。管理者に確認してください。');

        return $pieces;
    }

    private function dailyDemand(int $client, int $warehouse, int $item, int $allocation, array $period): array
    {
        $db = DB::connection('sakemaru');
        $coverage = $this->once('coverage:'.$warehouse.':'.implode(':', $period), fn () => $db->table('ret_pos_stock_applications')->where('warehouse_id', $warehouse)->whereBetween('business_date', array_values($period))->distinct()->pluck('business_date')->all());
        $daily = [];
        for ($date = CarbonImmutable::parse($period['start']); $date->toDateString() <= $period['end']; $date = $date->addDay()) {
            $daily[$date->toDateString()] = $coverage && ! in_array($date->toDateString(), $coverage, true) ? null : 0;
        }
        $pos = $db->table('ret_pos_stock_applications')->where(['warehouse_id' => $warehouse, 'item_id' => $item])->whereBetween('business_date', array_values($period))
            ->groupBy('business_date')->selectRaw('business_date date, SUM(quantity) pieces')->get();
        $components = $db->table('ret_pos_stock_application_set_items as si')->join('ret_pos_stock_applications as a', 'a.id', '=', 'si.ret_pos_stock_application_id')
            ->where(['si.warehouse_id' => $warehouse, 'si.item_id' => $item, 'si.stock_applied' => 1])->whereBetween('a.business_date', array_values($period))
            ->groupBy('a.business_date')->selectRaw('a.business_date date, SUM(si.quantity) pieces')->get();
        $earnings = $db->table('trade_items as ti')->join('trades as t', 't.id', '=', 'ti.trade_id')->join('earnings as e', 'e.trade_id', '=', 't.id')
            ->where(['ti.client_id' => $client, 'ti.item_id' => $item, 'ti.is_active' => 1, 'ti.stock_allocation_id' => $allocation,
                't.client_id' => $client, 't.is_active' => 1, 't.is_latest' => 1, 't.trade_category' => 'EARNING', 'e.is_active' => 1, 'e.warehouse_id' => $warehouse, 'e.is_direct_delivery' => 0, 'e.is_delivered' => 1])
            ->whereRaw('COALESCE(e.delivered_date,t.process_date) BETWEEN ? AND ?', array_values($period))->groupByRaw('COALESCE(e.delivered_date,t.process_date)')
            ->selectRaw("COALESCE(e.delivered_date,t.process_date) date,SUM(CASE WHEN t.is_returned=1 OR t.trade_direction='RETURN' THEN -ABS(CAST(ti.total_piece_quantity AS SIGNED)) ELSE CAST(ti.total_piece_quantity AS SIGNED) END) pieces")->get();
        $transfers = $this->transferBase($client, $item, $allocation, $period)->where('st.from_warehouse_id', $warehouse)
            ->whereExists(fn ($q) => $this->transferLink($q))->groupBy('st.picking_date')->selectRaw('st.picking_date date,SUM(CAST(ti.total_piece_quantity AS SIGNED)) pieces')->get();
        foreach ([$pos, $components, $earnings, $transfers] as $rows) {
            foreach ($rows as $row) {
                if (array_key_exists($row->date, $daily) && $daily[$row->date] !== null) {
                    $daily[$row->date] += (int) $row->pieces;
                }
            }
        }
        $daily = array_map(fn ($q) => $q === null ? null : max(0, $q), $daily);

        return ['daily' => $daily, 'warnings' => $coverage ? [] : ['POS適用実績がない倉庫のため、確定売上出荷・WMS発注連携出庫で需要を算定しています。']];
    }
}
