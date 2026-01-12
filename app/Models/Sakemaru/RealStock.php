<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RealStock extends CustomModel
{
    use HasFactory;

    protected $guarded = [];

    /**
     * real_stocks テーブルには is_active カラムがない
     */
    protected bool $hasIsActiveColumn = false;

    protected $casts = [
        'expiration_date' => 'date',
        'received_at' => 'datetime',
        'wms_lock_version' => 'integer',
    ];

    public function stock_allocation(): belongsTo
    {
        return $this->belongsTo(StockAllocation::class);
    }

    public function warehouse(): belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function floor(): belongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function location(): belongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function item(): belongsTo
    {
        return $this->belongsTo(Item::class);
    }

    // WMS Scopes
    public function scopeFefoFifo($query)
    {
        return $query
            ->orderByRaw('CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiration_date', 'asc')
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc');
    }

    public function scopeAvailableForWms($query)
    {
        // available_quantity は生成カラム (= current_quantity - reserved_quantity)
        return $query->where('available_quantity', '>', 0);
    }

    /**
     * ロット一覧
     */
    public function lots(): HasMany
    {
        return $this->hasMany(RealStockLot::class);
    }

    /**
     * アクティブなロットのみ
     */
    public function activeLots(): HasMany
    {
        return $this->lots()->where('status', RealStockLot::STATUS_ACTIVE);
    }

    /**
     * ロット履歴（アーカイブ）
     */
    public function lotHistories(): HasMany
    {
        return $this->hasMany(RealStockLotHistory::class);
    }
}
