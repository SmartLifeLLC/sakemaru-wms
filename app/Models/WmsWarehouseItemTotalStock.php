<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫×商品別在庫スナップショット
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $item_id
 * @property \Carbon\Carbon $snapshot_at
 * @property int $total_effective_piece
 * @property int $total_non_effective_piece
 * @property int $total_incoming_piece
 */
class WmsWarehouseItemTotalStock extends WmsModel
{
    protected $table = 'wms_warehouse_item_total_stocks';

    protected $fillable = [
        'warehouse_id',
        'item_id',
        'snapshot_at',
        'total_effective_piece',
        'total_non_effective_piece',
        'total_incoming_piece',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * 利用可能在庫数（有効在庫 + 入荷予定）
     */
    public function getAvailableStockAttribute(): int
    {
        return $this->total_effective_piece + $this->total_incoming_piece;
    }
}
