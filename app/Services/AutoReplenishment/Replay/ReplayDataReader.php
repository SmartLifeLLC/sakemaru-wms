<?php

namespace App\Services\AutoReplenishment\Replay;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\DemandEventHistoryReader;
use App\Services\AutoReplenishment\GenerationSchedule;
use App\Services\AutoReplenishment\PalletUnitService;
use App\Services\AutoReplenishment\RecommendationArrivalSchedule;
use App\Services\AutoReplenishment\SettingsRecommendationHistory;
use App\Services\AutoReplenishment\SettingsRecommendationService;
use App\Services\AutoReplenishment\ShippingQuantityService;
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
            ->get(['id', 'client_id', 'warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id', 'order_unit_mode', 'channel', 'strategy_key', 'is_configured',
                'reorder_point_pieces', 'target_stock_pieces', 'min_order_pieces', 'max_order_pieces', 'order_multiple_pieces', 'calculation_basis']);
        $pallets = app(PalletUnitService::class);
        $shipping = app(ShippingQuantityService::class);
        $series = [];
        $policyItems = [];
        $contractorByWarehouse = [];
        foreach ($policies as $policy) {
            $saved = null;
            if ($policy->is_configured && (int) $policy->reorder_point_pieces > 0 && (int) $policy->min_order_pieces > 0 && (int) $policy->max_order_pieces > 0 && (int) $policy->order_multiple_pieces > 0) {
                $saved = ['reorder_point_pieces' => (int) $policy->reorder_point_pieces, 'target_stock_pieces' => (int) ($policy->target_stock_pieces ?: $policy->reorder_point_pieces),
                    'min_order_pieces' => (int) $policy->min_order_pieces, 'max_order_pieces' => (int) $policy->max_order_pieces, 'order_multiple_pieces' => (int) $policy->order_multiple_pieces,
                    'strategy_key' => (string) $policy->strategy_key, 'calculation_basis' => is_array($policy->calculation_basis) ? $policy->calculation_basis : null];
            }
            // The editor-equivalent route of the saved policy (same as the weekly refresh's form): channel, contractor, supplier,
            // source warehouse / delivery course, output-unit mode and the bound quantity constraint (pallet / registered shipping quantities).
            $shippingMode = 'STANDARD';
            if ($pallets->binding((int) $policy->id)) {
                $shippingMode = 'PALLET';
            }
            if ($policy->channel === 'LOGISTICS' && $shipping->binding((int) $policy->id)) {
                $shippingMode = 'SHIPMENT';
            }
            $route = ['item_id' => (int) $policy->item_id, 'channel' => (string) ($policy->channel ?: 'EOS'), 'contractor_id' => $policy->contractor_id ? (int) $policy->contractor_id : null,
                'supplier_id' => $policy->supplier_id ? (int) $policy->supplier_id : null, 'source_warehouse_id' => $policy->source_warehouse_id ? (int) $policy->source_warehouse_id : null,
                'delivery_course_id' => $policy->delivery_course_id ? (int) $policy->delivery_course_id : null, 'order_unit_mode' => (string) ($policy->order_unit_mode ?: 'AUTO'),
                'shipping_unit_mode' => $shippingMode, 'strategy_key' => (string) $policy->strategy_key];
            $series[] = ['warehouse_id' => (int) $policy->warehouse_id, 'item_id' => (int) $policy->item_id, 'contractor_id' => $policy->contractor_id ? (int) $policy->contractor_id : null,
                'channel' => (string) ($policy->channel ?: 'EOS'), 'source' => 'policy', 'saved_policy' => $saved, 'policy_id' => (int) $policy->id, 'route' => $route];
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
                    // Assumption: the store's most common EOS contractor; the product's own contractor is not resolved.
                    $series[] = ['warehouse_id' => (int) $warehouse, 'item_id' => (int) $itemId, 'contractor_id' => $common ? (int) array_key_first($common) : null,
                        'channel' => 'EOS', 'source' => 'top_seller', 'saved_policy' => null, 'policy_id' => null, 'route' => null];
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

    /**
     * The production inputs of one recommendation that the replay cannot derive from sales alone, read for a past calculation date:
     * the linked receipts of the 90-day window that ended before $asOf (same extraction as the editor/weekly refresh), the output unit
     * resolved as of that date, and the warehouse × product quantity constraint (pallet / registered shipping quantities) with the
     * calculator options it implies. Masters are today's (no history of pallet/profile versions exists); the result says so.
     *
     * Returns fn (array $series, string $asOf, array $period): array{receipts, order_unit, packing_unit, options, shipping_unit, basis,
     * scope: array{channel, contractor_id, supplier_id, source_warehouse_id, delivery_course_id, order_unit_mode}, masters_as_of: string}.
     * Throws RuntimeException with the production message when the route cannot be calculated (e.g. pallet master missing).
     */
    public function conditionsProvider(int $client): \Closure
    {
        $history = app(SettingsRecommendationHistory::class);
        $recommendations = app(SettingsRecommendationService::class);
        $items = [];
        $constraints = [];
        $results = []; // the base and event runs of a backtest ask for the same series × date: one read
        $today = CarbonImmutable::now('Asia/Tokyo')->toDateString();

        return function (array $series, string $asOf, array $period) use ($client, $history, $recommendations, &$items, &$constraints, &$results, $today): array {
            $cacheKey = ($series['policy_id'] ?? 0).':'.$series['warehouse_id'].':'.$series['item_id'].':'.$asOf.':'.$period['start'].':'.$period['end'];
            if (isset($results[$cacheKey])) {
                return $results[$cacheKey];
            }
            $route = $series['route'] ?? null;
            throw_unless(is_array($route) && ! empty($route['channel']) && ! empty($route['contractor_id']), \RuntimeException::class, '発注経路（方式・発注先）が未設定のため、本番と同じ条件で算定できません。');
            $itemId = (int) $series['item_id'];
            $warehouseId = (int) $series['warehouse_id'];
            $item = $items[$itemId] ??= \App\Models\Sakemaru\Item::where(['client_id' => $client, 'is_active' => true])->find($itemId);
            throw_unless($item, \RuntimeException::class, '商品または選択倉庫が無効です。');
            $channel = (string) $route['channel'];
            $contractor = (int) $route['contractor_id'];
            $source = $channel === 'LOGISTICS' ? (int) ($route['source_warehouse_id'] ?? 0) : 0;
            $supplier = $history->supplierFor($client, $warehouseId, $itemId, $contractor, $route['supplier_id'] ?? null);
            $allocation = $history->allocation($client);
            $key = ($series['policy_id'] ?? 0).':'.$warehouseId.':'.$itemId;
            $constraint = $constraints[$key] ??= $recommendations->unitConstraints($client, $route);
            $orderUnit = $history->orderUnit($client, $item, $channel, $source, (string) ($route['order_unit_mode'] ?? 'AUTO'), $asOf);
            $options = $recommendations->constrainedOptions($constraint, (int) $item->capacity_case, []);
            // The policy's binding row (bound version / pieces) as SnapshotReader reads it: the daily step runs the production assertBinding
            // against it, so a stale binding holds the order in the replay exactly as it does in production.
            $shippingUnit = $constraint['shipping_unit'];
            if ($constraint['mode'] === 'PALLET' && ! empty($series['policy_id'])) {
                $binding = app(PalletUnitService::class)->bindings($client, $warehouseId, [$itemId], false)->get((int) $series['policy_id']);
                $shippingUnit['binding'] = $binding ? (array) $binding : null;
            } elseif ($constraint['mode'] === 'SHIPMENT' && ! empty($series['policy_id'])) {
                $binding = app(ShippingQuantityService::class)->bindings($client, $warehouseId, [$itemId], false)->get((int) $series['policy_id']);
                $shippingUnit['binding'] = $binding ? (array) $binding : null;
            }

            return $results[$cacheKey] = ['receipts' => $history->linkedReceipts($client, $itemId, $channel, $contractor, $supplier, $source, $allocation, $period),
                'order_unit' => $orderUnit, 'packing_unit' => (int) $orderUnit['packing_unit'], 'options' => $options,
                'shipping_unit' => $shippingUnit, 'basis' => $constraint['basis'],
                'scope' => ['channel' => $channel, 'contractor_id' => $contractor, 'supplier_id' => $supplier, 'source_warehouse_id' => $source ?: null,
                    'delivery_course_id' => $channel === 'LOGISTICS' ? (int) ($route['delivery_course_id'] ?? 0) ?: null : null, 'order_unit_mode' => (string) ($route['order_unit_mode'] ?? 'AUTO')],
                'masters_as_of' => $today];
        };
    }

    /**
     * Today's warehouse × contractor arrival schedule applied to past dates (主評価), with the production
     * review interval (execution times included) from the warehouse's auto-order configuration.
     */
    public function scheduledCalendar(int $warehouse, int $contractor, string $channel = 'EOS'): ReplayCalendar
    {
        $json = DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $warehouse)->value('replenishment_config');
        $config = $json ? (json_decode($json, true) ?: []) : [];
        $interval = app(GenerationSchedule::class)->maxReviewIntervalDays($config);
        $provider = function (string $asOf) use ($warehouse, $contractor, $channel, $config) {
            return app(RecommendationArrivalSchedule::class)->calculate($warehouse, $contractor, $channel, $asOf, $config, 120)['opportunities'];
        };

        return ReplayCalendar::scheduled($provider, max(1, min(7, $interval)));
    }
}
