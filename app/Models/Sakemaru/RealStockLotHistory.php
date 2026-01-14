<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealStockLotHistory extends SakemaruModel
{
    protected $guarded = [];

    protected $table = 'real_stock_lot_histories';

    public const STATUS_DEPLETED = 'DEPLETED';

    public const STATUS_EXPIRED = 'EXPIRED';

    protected $casts = [
        'price' => 'decimal:2',
        'content_amount' => 'decimal:4',
        'container_amount' => 'decimal:4',
        'expiration_date' => 'date',
        'initial_quantity' => 'integer',
        'final_quantity' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function realStock(): BelongsTo
    {
        return $this->belongsTo(RealStock::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function tradeItem(): BelongsTo
    {
        return $this->belongsTo(TradeItem::class);
    }

    /**
     * RealStockLotからアーカイブレコードを作成
     */
    public static function createFromLot(RealStockLot $lot): self
    {
        return self::create([
            'original_lot_id' => $lot->id,
            'real_stock_id' => $lot->real_stock_id,
            'purchase_id' => $lot->purchase_id,
            'trade_item_id' => $lot->trade_item_id,
            'price' => $lot->price,
            'content_amount' => $lot->content_amount,
            'container_amount' => $lot->container_amount,
            'expiration_date' => $lot->expiration_date,
            'initial_quantity' => $lot->initial_quantity,
            'final_quantity' => $lot->current_quantity,
            'status' => $lot->status,
            'archived_at' => now(),
        ]);
    }
}
