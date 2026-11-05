<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Read-only display of enabled settings; daily holds and order decisions are separate. */
class AutoOrderDisplayStatus
{
    public function enabled(): bool
    {
        return config('replenishment.enabled') && (int) config('app.client_id') > 0;
    }

    public function activePolicies(): QueryBuilder
    {
        return DB::connection('sakemaru')->table('wms_item_reorder_policies as p')
            ->join('wms_auto_order_controls as c', 'c.client_id', '=', 'p.client_id')
            ->join('wms_warehouse_auto_order_settings as s', 's.warehouse_id', '=', 'p.warehouse_id')
            ->join('warehouses as w', function ($join): void {
                $join->on('w.id', '=', 'p.warehouse_id')->on('w.client_id', '=', 'p.client_id');
            })
            ->where('p.client_id', (int) config('app.client_id'))
            ->when(! $this->enabled(), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('p.is_enabled', true)
            ->where('p.is_configured', true)
            ->where('c.enabled', true)
            ->where('c.reset_state', 'COMPLETE')
            ->where('s.is_auto_order_enabled', true)
            ->where('w.is_active', true)
            ->where('w.is_virtual', false);
    }

    public function withItemContractorStatus(Builder $query): Builder
    {
        $query->addSelect('item_contractors.*');

        return $this->enabled()
            ? $query->selectSub($this->matchingItemContractor()->limit(1), 'is_auto_order_active')
            : $query->selectRaw('0 as is_auto_order_active');
    }

    public function filterItemContractors(Builder $query, bool $active): Builder
    {
        if (! $this->enabled()) {
            return $active ? $query->whereRaw('1 = 0') : $query;
        }

        // Keep filter constraints serializable for background table exports.
        $match = $this->matchingItemContractor();

        return $query->whereRaw(($active ? '' : 'not ').'exists ('.$match->toSql().')', $match->getBindings());
    }

    private function matchingItemContractor(): QueryBuilder
    {
        return $this->activePolicies()
            ->whereColumn('p.client_id', 'item_contractors.client_id')
            ->whereColumn('p.warehouse_id', 'item_contractors.warehouse_id')
            ->whereColumn('p.item_id', 'item_contractors.item_id')
            ->whereColumn('p.contractor_id', 'item_contractors.contractor_id')
            ->where(fn ($query) => $query->whereNull('p.supplier_id')->orWhereColumn('p.supplier_id', 'item_contractors.supplier_id'))
            ->selectRaw('1');
    }
}
