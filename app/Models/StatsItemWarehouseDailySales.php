<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatsItemWarehouseDailySales extends WmsModel
{
    protected $table = 'stats_item_warehouse_daily_sales';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'warehouse_id',
        'item_id',
        'business_date',
        'shipped_piece_qty',
        'shipped_case_qty',
        'shipped_bottle_qty',
    ];

    protected $casts = [
        'business_date' => 'date',
        'shipped_piece_qty' => 'integer',
        'shipped_case_qty' => 'integer',
        'shipped_bottle_qty' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForItem(Builder $query, int $itemId): Builder
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeForDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('business_date', [$from, $to]);
    }
}
