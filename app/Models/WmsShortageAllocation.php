<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsShortageAllocation extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'wms_shortage_allocations';

    protected $fillable = [
        'shortage_id',
        'from_warehouse_id',
        'assign_qty_each',
        'assign_qty_type',
        'status',
        'created_by',
    ];

    protected $casts = [
        'assign_qty_each' => 'integer',
    ];

    // Status constants
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_PICKING = 'PICKING';
    public const STATUS_FULFILLED = 'FULFILLED';
    public const STATUS_SHORTAGE = 'SHORTAGE';
    public const STATUS_CANCELLED = 'CANCELLED';

    // Quantity type constants
    public const QTY_TYPE_CASE = 'CASE';
    public const QTY_TYPE_PIECE = 'PIECE';
    public const QTY_TYPE_CARTON = 'CARTON';

    // Relationships
    public function shortage(): BelongsTo
    {
        return $this->belongsTo(WmsShortage::class, 'shortage_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeReserved($query)
    {
        return $query->where('status', self::STATUS_RESERVED);
    }

    public function scopePicking($query)
    {
        return $query->where('status', self::STATUS_PICKING);
    }

    public function scopeFulfilled($query)
    {
        return $query->where('status', self::STATUS_FULFILLED);
    }

    public function scopeForShortage($query, int $shortageId)
    {
        return $query->where('shortage_id', $shortageId);
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isPicking(): bool
    {
        return $this->status === self::STATUS_PICKING;
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    public function hasShortage(): bool
    {
        return $this->status === self::STATUS_SHORTAGE;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
