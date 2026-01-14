<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealStockLotBuyerRestriction extends SakemaruModel
{
    protected $fillable = [
        'real_stock_lot_id',
        'buyer_id',
    ];

    public function realStockLot(): BelongsTo
    {
        return $this->belongsTo(RealStockLot::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }
}
