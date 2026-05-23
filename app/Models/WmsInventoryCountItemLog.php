<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsInventoryCountItemLog extends WmsModel
{
    public $timestamps = false;

    protected $fillable = [
        'inventory_count_item_id',
        'device_id',
        'user_id',
        'count_round',
        'old_quantity',
        'new_quantity',
        'request_uuid',
        'created_at',
    ];

    protected $casts = [
        'old_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
        'created_at' => 'datetime',
    ];

    public function countItem(): BelongsTo
    {
        return $this->belongsTo(WmsInventoryCountItem::class, 'inventory_count_item_id');
    }
}
