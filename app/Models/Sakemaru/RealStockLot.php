<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lotEarnings(): HasMany
    {
        return $this->hasMany(RealStockLotEarning::class);
    }

    public function buyerRestrictions(): HasMany
    {
        return $this->hasMany(RealStockLotBuyerRestriction::class);
    }

    public function restrictedBuyers(): BelongsToMany
    {
        return $this->belongsToMany(Buyer::class, 'real_stock_lot_buyer_restrictions', 'real_stock_lot_id', 'buyer_id');
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

    /**
     * ロットに得意先制限があるかどうか
     */
    public function hasBuyerRestrictions(): bool
    {
        return $this->buyerRestrictions()->exists();
    }

    /**
     * ロットが特定の得意先に販売可能かチェック
     * 制限レコードがない場合は全得意先に販売可能
     */
    public function canSellToBuyer(int $buyerId): bool
    {
        // 制限レコードがない場合は全得意先に販売可能
        if (! $this->hasBuyerRestrictions()) {
            return true;
        }

        // 制限レコードがある場合は、指定された得意先のみ販売可能
        return $this->buyerRestrictions()
            ->where('buyer_id', $buyerId)
            ->exists();
    }

    /**
     * ロットが特定の得意先に対して引当可能かどうか
     * （在庫状態 + 得意先制限の両方をチェック）
     */
    public function isAvailableForBuyer(int $buyerId): bool
    {
        return $this->isAvailable() && $this->canSellToBuyer($buyerId);
    }

    /**
     * ロットが引当可能かどうか（在庫状態のみ）
     */
    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->available_quantity > 0
            && ($this->expiration_date === null || $this->expiration_date > now());
    }

    /**
     * 得意先制限を考慮したFIFO順スコープ
     */
    public function scopeAvailableForBuyer($query, int $buyerId)
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereRaw('current_quantity > reserved_quantity')
            ->where(function ($q) use ($buyerId) {
                // 制限がないロット
                $q->whereDoesntHave('buyerRestrictions')
                    // または制限があり、対象得意先が許可されているロット
                    ->orWhereHas('buyerRestrictions', function ($subQ) use ($buyerId) {
                        $subQ->where('buyer_id', $buyerId);
                    });
            });
    }
}
