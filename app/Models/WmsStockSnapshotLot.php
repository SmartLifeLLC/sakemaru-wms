<?php

namespace App\Models;

use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\RealStock;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsStockSnapshotLot extends WmsModel
{
    protected $table = 'wms_stock_snapshot_lots';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_date',
        'snapshot_time',
        'warehouse_id',
        'item_id',
        'real_stock_id',
        'lot_id',
        'location_id',
        'floor_id',
        'expiration_date',
        'purchase_id',
        'current_quantity',
        'reserved_quantity',
        'price',
        'real_stock_received_at',
        'lot_created_at',
        'captured_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'expiration_date' => 'date',
        'current_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'price' => 'decimal:2',
        'real_stock_received_at' => 'datetime',
        'lot_created_at' => 'datetime',
        'captured_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function realStock(): BelongsTo
    {
        return $this->belongsTo(RealStock::class, 'real_stock_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }
}
