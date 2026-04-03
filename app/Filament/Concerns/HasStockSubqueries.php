<?php

namespace App\Filament\Concerns;

use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\RealStock;
use Illuminate\Database\Eloquent\Builder;

trait HasStockSubqueries
{
    protected static function currentStockSubquery(string $mainTable): Builder
    {
        return RealStock::selectRaw('COALESCE(SUM(current_quantity), 0)')
            ->whereColumn('real_stocks.warehouse_id', "{$mainTable}.warehouse_id")
            ->whereColumn('real_stocks.item_id', "{$mainTable}.item_id");
    }

    protected static function availableStockSubquery(string $mainTable): Builder
    {
        return RealStock::selectRaw('COALESCE(SUM(available_quantity), 0)')
            ->whereColumn('real_stocks.warehouse_id', "{$mainTable}.warehouse_id")
            ->whereColumn('real_stocks.item_id', "{$mainTable}.item_id");
    }

    protected static function defaultLocationSubquery(string $mainTable): Builder
    {
        return Location::selectRaw("CONCAT(locations.code1, '-', locations.code2, '-', locations.code3)")
            ->join('item_incoming_default_locations', 'item_incoming_default_locations.location_id', '=', 'locations.id')
            ->whereColumn('item_incoming_default_locations.warehouse_id', "{$mainTable}.warehouse_id")
            ->whereColumn('item_incoming_default_locations.item_id', "{$mainTable}.item_id")
            ->limit(1);
    }
}
