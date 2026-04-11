<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatsItemWarehouseSalesSummary extends WmsModel
{
    protected $table = 'stats_item_warehouse_sales_summaries';

    public $incrementing = false;

    protected $primaryKey = 'warehouse_id';

    protected $keyType = 'string';

    /**
     * 複合PKの一意キーを返す（Filamentテーブル用）
     */
    public function getKey(): mixed
    {
        return $this->warehouse_id . '-' . $this->item_id;
    }

    protected $fillable = [
        'warehouse_id',
        'item_id',
        'last_3d_qty',
        'last_7d_qty',
        'last_14d_qty',
        'last_30d_qty',
        'avg_3d_qty',
        'avg_7d_qty',
        'avg_14d_qty',
        'avg_30d_qty',
        'last_shipped_at',
        'calculated_at',
    ];

    protected $casts = [
        'last_shipped_at' => 'date',
        'calculated_at' => 'datetime',
        'avg_3d_qty' => 'decimal:2',
        'avg_7d_qty' => 'decimal:2',
        'avg_14d_qty' => 'decimal:2',
        'avg_30d_qty' => 'decimal:2',
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
}
