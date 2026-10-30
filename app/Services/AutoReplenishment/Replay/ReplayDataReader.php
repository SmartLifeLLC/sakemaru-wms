<?php

namespace App\Services\AutoReplenishment\Replay;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\DemandEventHistoryReader;
use App\Services\AutoReplenishment\RecommendationArrivalSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SELECT-only inputs for the replay: series list (policies + top sellers), masters, per-store daily
 * sales (WMS statistics from 2024-05-05, legacy retail CSV rows before) and today's arrival schedules.
 * Never writes.
 */
class ReplayDataReader
{
    public function __construct(private DemandEventHistoryReader $history) {}

    /** @return array<int, array{warehouse_id: int, item_id: int, contractor_id: ?int, source: string}> */
    public function seriesList(int $client, string $start, string $end, int $extraItems): array
    {
        $stores = $this->history->storeWarehouseIds($client);
        $policies = WmsItemReorderPolicy::where('client_id', $client)->whereIn('warehouse_id', $stores)->orderBy('warehouse_id')->orderBy('item_id')
            ->get(['warehouse_id', 'item_id', 'contractor_id']);
        $series = [];
        $policyItems = [];
        $contractorByWarehouse = [];
        foreach ($policies as $policy) {
            $series[] = ['warehouse_id' => (int) $policy->warehouse_id, 'item_id' => (int) $policy->item_id, 'contractor_id' => $policy->contractor_id ? (int) $policy->contractor_id : null, 'source' => 'policy'];
            $policyItems[(int) $policy->item_id] = true;
            if ($policy->contractor_id) {
                $contractorByWarehouse[(int) $policy->warehouse_id][(int) $policy->contractor_id] = ($contractorByWarehouse[(int) $policy->warehouse_id][(int) $policy->contractor_id] ?? 0) + 1;
            }
        }
        if ($extraItems > 0) {
            $policyWarehouses = array_values(array_unique(array_column($series, 'warehouse_id'))) ?: $stores;
            $top = DB::connection('sakemaru')->table('stats_item_warehouse_daily_sales')->whereIn('warehouse_id', $policyWarehouses)
                ->whereBetween('business_date', [$start, $end])->whereNotIn('item_id', array_keys($policyItems) ?: [-1])
                ->groupBy('item_id')->selectRaw('item_id, SUM(sales_piece_qty) pieces')->orderByDesc('pieces')->limit($extraItems)->pluck('item_id');
            foreach ($top as $itemId) {
                foreach ($policyWarehouses as $warehouse) {
                    $common = $contractorByWarehouse[$warehouse] ?? [];
                    arsort($common);
                    $series[] = ['warehouse_id' => (int) $warehouse, 'item_id' => (int) $itemId, 'contractor_id' => $common ? (int) array_key_first($common) : null, 'source' => 'top_seller'];
                }
            }
        }

        return $series;
    }

    public function items(int $client, array $itemIds): array
    {
        return DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('id', $itemIds ?: [-1])
            ->get(['id', 'code', 'name', 'capacity_case', 'item_category3_id'])->keyBy('id')->map(fn ($r) => (array) $r)->all();
    }

    public function warehouses(int $client, array $ids): array
    {
        return DB::connection('sakemaru')->table('warehouses')->where('client_id', $client)->whereIn('id', $ids ?: [-1])
            ->get(['id', 'code', 'name'])->keyBy('id')->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Daily sales per store × product; a store-date without any sales row for any product is unobserved (null).
     *
     * @return array<int, array<int, array<string, int|null>>> warehouse => item => date => pieces
     */
    public function demand(int $client, array $warehouses, array $items, string $start, string $end): array
    {
        $db = DB::connection('sakemaru');
        $series = [];
        $observed = [];
        $cursor = CarbonImmutable::parse($start);
        $last = CarbonImmutable::parse($end);
        $boundary = DemandEventHistoryReader::WMS_STATS_FROM;
        while ($cursor->lte($last)) {
            $segmentEnd = $cursor->endOfMonth()->min($last);
            $from = $cursor->toDateString();
            $to = $segmentEnd->toDateString();
            if ($to < $boundary) {
                $rows = $this->legacy($client, $warehouses, $items, $from, $to, $observed);
            } elseif ($from >= $boundary) {
                $rows = $this->wms($warehouses, $items, $from, $to, $observed);
            } else {
                $rows = array_merge($this->legacy($client, $warehouses, $items, $from, CarbonImmutable::parse($boundary)->subDay()->toDateString(), $observed),
                    $this->wms($warehouses, $items, $boundary, $to, $observed));
            }
            foreach ($rows as $row) {
                $series[(int) $row->warehouse_id][(int) $row->item_id][$row->date] = ($series[(int) $row->warehouse_id][(int) $row->item_id][$row->date] ?? 0) + (int) round((float) $row->pieces);
            }
            $cursor = $segmentEnd->addDay();
        }
        $result = [];
        foreach ($warehouses as $warehouse) {
            foreach ($items as $item) {
                $daily = [];
                for ($d = CarbonImmutable::parse($start); $d->lte($last); $d = $d->addDay()) {
                    $key = $d->toDateString();
                    $daily[$key] = isset($observed[$warehouse][$key]) ? (int) ($series[$warehouse][$item][$key] ?? 0) : null;
                }
                $result[$warehouse][$item] = $daily;
            }
        }

        return $result;
    }

    private function wms(array $warehouses, array $items, string $from, string $to, array &$observed): array
    {
        $db = DB::connection('sakemaru');
        foreach ($db->table('stats_item_warehouse_daily_sales')->whereIn('warehouse_id', $warehouses)->whereBetween('business_date', [$from, $to])
            ->groupBy('warehouse_id', 'business_date')->selectRaw('warehouse_id, business_date date')->get() as $row) {
            $observed[(int) $row->warehouse_id][$row->date] = true;
        }

        return $db->table('stats_item_warehouse_daily_sales')->whereIn('warehouse_id', $warehouses)->whereIn('item_id', $items)->whereBetween('business_date', [$from, $to])
            ->groupBy('warehouse_id', 'item_id', 'business_date')->selectRaw('warehouse_id, item_id, business_date date, SUM(sales_piece_qty) pieces')->get()->all();
    }

    private function legacy(int $client, array $warehouses, array $items, string $from, string $to, array &$observed): array
    {
        if ($from > $to) {
            return [];
        }
        $db = DB::connection('sakemaru');
        $base = fn () => $db->table('stats_sales_csv_item_lines')->where(['source_system' => 'legacy_t2_retail', 'period_type' => 'current'])
            ->where(fn ($q) => $q->where('client_id', $client)->orWhereNull('client_id'))->whereBetween('business_date', [$from, $to]);
        $run = $base()->groupBy('run_id')->selectRaw('run_id, COUNT(*) c')->orderByDesc('c')->orderByDesc('run_id')->value('run_id');
        if ($run === null) {
            return [];
        }
        foreach ($base()->where('run_id', $run)->whereIn('warehouse_id', $warehouses)->groupBy('warehouse_id', 'business_date')->selectRaw('warehouse_id, business_date date')->get() as $row) {
            $observed[(int) $row->warehouse_id][$row->date] = true;
        }

        return $base()->where('run_id', $run)->whereIn('warehouse_id', $warehouses)->whereIn('item_id', $items)
            ->groupBy('warehouse_id', 'item_id', 'business_date')->selectRaw('warehouse_id, item_id, business_date date, SUM(sales_quantity) pieces')->get()->all();
    }

    /** Today's warehouse × contractor arrival schedule applied to past dates (主評価). */
    public function arrivalProvider(int $warehouse, int $contractor): \Closure
    {
        $json = DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $warehouse)->value('replenishment_config');
        $config = $json ? (json_decode($json, true) ?: []) : [];

        return function (string $asOf) use ($warehouse, $contractor, $config) {
            return app(RecommendationArrivalSchedule::class)->calculate($warehouse, $contractor, 'EOS', $asOf, $config, 120)['opportunities'];
        };
    }
}
