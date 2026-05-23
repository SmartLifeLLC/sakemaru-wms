<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsInventoryCountItem extends WmsModel
{
    protected $fillable = [
        'inventory_count_id',
        'real_stock_id',
        'item_id',
        'item_code',
        'item_name',
        'barcode',
        'location_id',
        'floor_id',
        'floor_name',
        'location_code1',
        'location_code2',
        'location_code3',
        'location_no',
        'lot_id',
        'lot_no',
        'expiration_date',
        'received_at',
        'system_quantity',
        'first_count_quantity',
        'second_count_quantity',
        'final_count_quantity',
        'difference_quantity',
        'cost_price',
        'difference_amount',
        'input_count',
        'last_counted_at',
    ];

    protected $casts = [
        'expiration_date' => 'date',
        'received_at' => 'datetime',
        'system_quantity' => 'integer',
        'first_count_quantity' => 'integer',
        'second_count_quantity' => 'integer',
        'final_count_quantity' => 'integer',
        'difference_quantity' => 'integer',
        'cost_price' => 'decimal:4',
        'difference_amount' => 'decimal:2',
        'last_counted_at' => 'datetime',
    ];

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(WmsInventoryCount::class, 'inventory_count_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WmsInventoryCountItemLog::class, 'inventory_count_item_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function calculateDifference(): ?float
    {
        $finalQty = $this->final_count_quantity
            ?? $this->second_count_quantity
            ?? $this->first_count_quantity;

        if ($finalQty === null) {
            return null;
        }

        return (float) $finalQty - (float) $this->system_quantity;
    }
}
