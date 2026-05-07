<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemContractor extends CustomModel
{
    protected $guarded = [];

    protected $casts = [
        'safety_stock' => 'integer',
        'max_stock' => 'integer',
        'min_stock' => 'integer',
        'purchase_unit' => 'integer',
        'auto_order_quantity' => 'integer',
        'is_auto_order' => 'boolean',
        'use_safety_stock_auto_update' => 'boolean',
    ];

    /**
     * item_contractors テーブルには is_active カラムがない
     */
    protected bool $hasIsActiveColumn = false;

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse_contractors(): HasMany
    {
        return $this->hasMany(WarehouseContractor::class, 'contractor_id', 'contractor_id');
    }
}
