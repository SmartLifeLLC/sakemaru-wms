<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RealStockLot extends SakemaruModel
{
    protected $guarded = [];

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DEPLETED = 'DEPLETED';

    public const STATUS_EXPIRED = 'EXPIRED';

    protected $casts = [
        'price' => 'decimal:2',
        'content_amount' => 'decimal:4',
        'container_amount' => 'decimal:4',
        'expiration_date' => 'date',
        'initial_quantity' => 'integer',
        'current_quantity' => 'integer',
        'reserved_quantity' => 'integer',
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

    public function lotEarnings(): HasMany
    {
        return $this->hasMany(RealStockLotEarning::class);
    }

    /**
     * 引き当て可能な数量
     */
    public function getAvailableQuantityAttribute(): int
    {
        return $this->current_quantity - $this->reserved_quantity;
    }

    /**
     * FIFO順でアクティブなロットを取得
     * 有効期限が近い順（NULLは最後）、作成日時が古い順
     */
    public function scopeFifo($query)
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereRaw('current_quantity > reserved_quantity')
            ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('created_at', 'asc');
    }

    /**
     * 期限切れでないロットのみ
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expiration_date')
                ->orWhere('expiration_date', '>', now());
        });
    }

    /**
     * ロットを消費してDEPLETEDにするかチェック
     */
    public function checkAndMarkDepleted(): void
    {
        if ($this->current_quantity <= 0) {
            $this->status = self::STATUS_DEPLETED;
            $this->save();
        }
    }
}
