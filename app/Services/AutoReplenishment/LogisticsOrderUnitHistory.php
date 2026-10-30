<?php

namespace App\Services\AutoReplenishment;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Compare actual case multiples, not the legacy slip's often PIECE-labelled output. */
class LogisticsOrderUnitHistory
{
    public function read(int $client, int $source, array $capacities, ?string $businessDate = null): array
    {
        if ($source < 1 || ! $capacities) {
            return [];
        }
        $db = DB::connection('sakemaru');
        $businessDate ??= $db->table('client_settings')->where('client_id', $client)->value('system_date');
        throw_unless($businessDate, \RuntimeException::class, '業務日が未設定です。');
        $asOf = min(CarbonImmutable::now('Asia/Tokyo')->toDateString(), CarbonImmutable::parse($businessDate)->toDateString());
        $end = CarbonImmutable::parse($asOf)->subDay();
        $period = ['start' => max($end->subDays(89)->toDateString(), config('replenishment.recommendation_not_before', '2026-08-01')),
            'end' => $end->toDateString()];
        // File cache only: preview must never update a database-backed cache.
        // Per-item keys let a single batch query also serve subsequent per-item confirmations.
        $cache = Cache::store('file');
        $keys = [];
        foreach ($capacities as $item => $capacity) {
            $keys[$item] = 'auto-order-unit-history:v1:'.hash('sha256', json_encode([$db->getDatabaseName(), $client, $source, $item, $capacity, $period]));
        }
        $cached = $cache->many(array_values($keys));
        $missing = array_keys(array_filter($keys, fn ($key) => ! is_array($cached[$key] ?? null)));
        $counts = collect();
        if ($missing && $period['start'] <= $period['end']) {
            $factor = "CASE ti.quantity_type WHEN 'CASE' THEN ti.capacity_case WHEN 'CARTON' THEN ti.capacity_carton WHEN 'PIECE' THEN 1 ELSE 0 END";
            $shipments = $db->table('trade_items as ti')->join('items as i', 'i.id', '=', 'ti.item_id')
                ->join('trades as t', 't.id', '=', 'ti.trade_id')->join('stock_transfers as st', 'st.trade_id', '=', 't.id')
                ->join('stock_allocations as a', 'a.id', '=', 'ti.stock_allocation_id')
                ->where(['ti.client_id' => $client, 'ti.is_active' => 1, 'i.client_id' => $client, 'i.is_active' => 1,
                    't.client_id' => $client, 't.is_active' => 1, 't.is_latest' => 1, 't.trade_category' => 'STOCK_TRANSFER', 't.is_returned' => 0, 't.trade_direction' => 'NORMAL',
                    'st.client_id' => $client, 'st.is_active' => 1, 'st.from_warehouse_id' => $source, 'a.client_id' => $client, 'a.is_active' => 1, 'a.code' => 1])
                ->whereIn('ti.item_id', $missing)->where('i.capacity_case', '>', 1)
                ->where(fn ($q) => $q->where('st.is_delivered', 1)->orWhere('st.picking_status', 'SHIPPED'))
                ->whereBetween('st.picking_date', array_values($period))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('wms_order_incoming_schedules as s')
                    ->join('wms_stock_transfer_candidates as c', 'c.id', '=', 's.transfer_candidate_id')
                    ->where('s.order_source', 'TRANSFER')->whereColumn('s.stock_transfer_id', 'st.id')->whereColumn('s.item_id', 'ti.item_id')
                    ->whereColumn('s.warehouse_id', 'st.to_warehouse_id')->whereColumn('c.item_id', 'ti.item_id')
                    ->whereColumn('c.hub_warehouse_id', 'st.from_warehouse_id')->whereColumn('c.satellite_warehouse_id', 'st.to_warehouse_id'))
                ->groupBy('st.id', 'ti.item_id', 'i.capacity_case')
                ->select(['ti.item_id', 'i.capacity_case'])->selectRaw('SUM(CAST(ti.total_piece_quantity AS SIGNED)) pieces')
                ->havingRaw("MAX(CASE WHEN COALESCE(ti.has_shortage,0) <> 0 OR COALESCE(ti.capacity_case,0) <> i.capacity_case
                    OR COALESCE(ti.quantity,0) <= 0 OR COALESCE(($factor),0) <= 0
                    OR COALESCE(ti.total_piece_quantity,0) <= 0
                    OR ti.quantity * ($factor) <> CAST(ti.total_piece_quantity AS SIGNED) THEN 1 ELSE 0 END) = 0");
            $counts = $db->query()->fromSub($shipments, 'shipments')->groupBy('item_id')->select('item_id')
                ->selectRaw('SUM(CASE WHEN pieces % capacity_case = 0 THEN 1 ELSE 0 END) case_count, SUM(CASE WHEN pieces % capacity_case <> 0 THEN 1 ELSE 0 END) piece_count')
                ->get()->keyBy('item_id');
        }
        $result = [];
        foreach ($keys as $item => $key) {
            $result[$item] = $cached[$key] ?? ['source_warehouse_id' => $source, 'period' => $period,
                'case_count' => (int) ($counts->get($item)?->case_count ?? 0), 'piece_count' => (int) ($counts->get($item)?->piece_count ?? 0)];
            if (! isset($cached[$key])) {
                $cache->put($key, $result[$item], 900);
            }
        }

        return $result;
    }
}
