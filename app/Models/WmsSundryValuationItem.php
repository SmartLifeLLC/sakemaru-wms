<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsSundryValuationItem extends WmsModel
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'amount' => 'decimal:2',
        'sale_price' => 'decimal:4',
        'net_sale_price' => 'decimal:6',
        'cost_rate' => 'decimal:4',
        'valuation_unit_price' => 'decimal:6',
        'revision' => 'integer',
        'price_date' => 'date',
        'price_start_date' => 'date',
        'counted_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
