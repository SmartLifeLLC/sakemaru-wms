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
        'shipment_date',
        'delivery_course_id',
        'target_warehouse_id',
        'source_warehouse_id',
        'assign_qty',
        'picked_qty',
        'assign_qty_type',
        'purchase_price',
        'tax_exempt_price',
        'price',
        'status',
        'is_confirmed',
        'confirmed_at',
        'confirmed_user_id',
        'is_finished',
        'finished_at',
        'finished_user_id',
        'created_by',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'assign_qty' => 'integer',
        'picked_qty' => 'integer',
        'purchase_price' => 'decimal:2',
        'tax_exempt_price' => 'decimal:2',
        'price' => 'decimal:2',
        'is_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
        'is_finished' => 'boolean',
        'finished_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_PICKING = 'PICKING';
    public const STATUS_FULFILLED = 'FULFILLED';
    public const STATUS_SHORTAGE = 'SHORTAGE';

    // Quantity type constants
    public const QTY_TYPE_CASE = 'CASE';
    public const QTY_TYPE_PIECE = 'PIECE';
    public const QTY_TYPE_CARTON = 'CARTON';

    // Relationships
    public function shortage(): BelongsTo
    {
        return $this->belongsTo(WmsShortage::class, 'shortage_id');
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function confirmedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'confirmed_user_id');
    }

    public function finishedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'finished_user_id');
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\DeliveryCourse::class, 'delivery_course_id');
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

    public function scopeConfirmed($query)
    {
        return $query->where('is_confirmed', true);
    }

    public function scopeUnconfirmed($query)
    {
        return $query->where('is_confirmed', false);
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

    public function isConfirmed(): bool
    {
        return $this->is_confirmed === true;
    }

    public function isFinished(): bool
    {
        return $this->is_finished === true;
    }

    /**
     * 残りの出荷数量を取得
     *
     * @return int
     */
    public function getRemainingQtyAttribute(): int
    {
        return max(0, $this->assign_qty - $this->picked_qty);
    }
}
