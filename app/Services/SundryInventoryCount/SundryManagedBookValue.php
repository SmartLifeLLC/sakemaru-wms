<?php

namespace App\Services\SundryInventoryCount;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Carry monthly book movements, without revaluing old stock at a new month's cost. */
class SundryManagedBookValue
{
    public function calculate(int $client, int $warehouse, int $category, string $month): ?array
    {
        $db = DB::connection('sakemaru');
        $start = '2026-05';
        if ($month < $start) {
            return null;
        }
        $months = [];
        for ($date = CarbonImmutable::parse($start.'-01'); $date->format('Y-m') <= $month; $date = $date->addMonth()) {
            $months[] = $date->format('Y-m');
        }
        $available = $db->table('stats_item_stock_movement_monthly_summaries')
            ->where('client_id', $client)->where('warehouse_id', $warehouse)->whereIn('closing_month', $months)
            ->distinct()->pluck('closing_month')->all();
        if (array_diff($months, $available) !== []) {
            return null;
        }
        $rows = $db->table('stats_item_stock_movement_monthly_summaries as m')
            ->join('items as i', 'i.id', '=', 'm.item_id')
            ->leftJoin('item_cost_snapshots as c', function ($join) use ($client): void {
                $join->on('c.item_id', '=', 'm.item_id')->on('c.target_year_month', '=', 'm.closing_month')->where('c.client_id', $client);
            })
            ->where('m.client_id', $client)->where('i.client_id', $client)->where('m.warehouse_id', $warehouse)
            ->whereIn('m.closing_month', $months)->where('i.item_category1_id', $category)->where('i.is_managed_stock', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_sets as s')->whereColumn('s.id', 'i.item_set_id')
                ->where('s.is_active', true)->where('s.set_type', 'OWNED'))
            ->orderBy('m.closing_month')->orderBy('m.item_id')
            ->get(['m.closing_month', 'm.item_id', 'm.opening_quantity', 'm.closing_quantity', 'm.real_stock_quantity_at_close',
                'c.id as cost_id', 'c.cost_unit_price']);
        $balance = 0.0;
        $previous = [];
        $evidence = [];
        foreach ($months as $index => $current) {
            $quantities = [];
            $movement = 0.0;
            foreach ($rows->where('closing_month', $current)->groupBy('item_id') as $item => $entries) {
                if ($entries->pluck('cost_id')->unique()->count() !== 1) {
                    return null;
                }
                $opening = (float) $entries->sum('opening_quantity');
                $closing = (float) $entries->sum(fn ($r) => $r->real_stock_quantity_at_close ?? $r->closing_quantity);
                $cost = (float) $entries->first()->cost_unit_price;
                if ($index > 0 && abs($opening - ($previous[$item] ?? 0)) > 0.0001) {
                    return null;
                }
                $valuedQuantity = $index === 0 ? $closing : $closing - $opening;
                if (abs($valuedQuantity) > 0.0001 && $entries->first()->cost_id === null) {
                    return null;
                }
                $movement += $valuedQuantity * $cost;
                $quantities[$item] = $closing;
            }
            foreach (array_diff_key($previous, $quantities) as $quantity) {
                if (abs($quantity) > 0.0001) {
                    return null;
                }
            }
            $balance = round($balance + $movement, 2);
            $evidence[] = ['month' => $current, 'movement_amount' => round($movement, 2), 'closing_amount' => $balance];
            $previous = $quantities;
        }
        return ['amount' => $balance, 'basis' => '2026-05-valued-close-then-monthly-book-movements', 'months' => $evidence];
    }
}
