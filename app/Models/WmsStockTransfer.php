<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsStockTransfer extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'wms_stock_transfers';

    protected $fillable = [
        'item_id',
        'real_stock_id',
        'transfer_qty',
        'warehouse_id',
        'item_management_type',
        'source_location_id',
        'target_location_id',
        'worker_id',
        'worker_name',
        'transferred_at',
        'note',
    ];

    protected $casts = [
        'transfer_qty' => 'integer',
        'transferred_at' => 'datetime',
    ];

    // Item management type constants
    public const MANAGEMENT_TYPE_LOT = 'LOT';
    public const MANAGEMENT_TYPE_EXPIRATION = 'EXPIRATION';
    public const MANAGEMENT_TYPE_NONE = 'NONE';

    // Relationships
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function realStock(): BelongsTo
    {
        return $this->belongsTo(RealStock::class, 'real_stock_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function targetLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'target_location_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    // Scopes
    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForItem($query, int $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeForSourceLocation($query, int $locationId)
    {
        return $query->where('source_location_id', $locationId);
    }

    public function scopeForTargetLocation($query, int $locationId)
    {
        return $query->where('target_location_id', $locationId);
    }

    public function scopeTransferredBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('transferred_at', [$startDate, $endDate]);
    }
}
