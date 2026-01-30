<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RealStockLotEarning extends SakemaruModel
{
    protected $guarded = [];

    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $casts = [
        'quantity' => 'integer',
        'purchase_price' => 'decimal:2',
        'purchase_amount' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'selling_amount' => 'decimal:2',
        'reserved_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function realStockLot(): BelongsTo
    {
        return $this->belongsTo(RealStockLot::class);
    }

    public function earning(): BelongsTo
    {
        return $this->belongsTo(Earning::class);
    }

    public function tradeItem(): BelongsTo
    {
        return $this->belongsTo(TradeItem::class);
    }

    /**
     * 粗利を計算
     */
    public function getGrossProfitAttribute(): float
    {
        return ($this->selling_amount ?? 0) - ($this->purchase_amount ?? 0);
    }

    /**
     * 粗利率を計算
     */
    public function getGrossProfitMarginAttribute(): ?float
    {
        if (! $this->selling_amount || $this->selling_amount == 0) {
            return null;
        }

        return ($this->getGrossProfitAttribute() / $this->selling_amount) * 100;
    }

    /**
     * 配送完了としてマーク
     */
    public function markAsDelivered(): void
    {
        $this->status = self::STATUS_DELIVERED;
        $this->delivered_at = now();
        $this->save();
    }

    /**
     * キャンセルとしてマーク
     */
    public function markAsCancelled(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->save();
    }
}
