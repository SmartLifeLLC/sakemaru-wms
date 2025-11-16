<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Trade;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsShortage extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'wms_shortages';

    protected $fillable = [
        'wave_id',
        'warehouse_id',
        'item_id',
        'trade_id',
        'trade_item_id',
        'order_qty_each',
        'planned_qty_each',
        'picked_qty_each',
        'shortage_qty_each',
        'allocation_shortage_qty',
        'picking_shortage_qty',
        'qty_type_at_order',
        'case_size_snap',
        'source_reservation_id',
        'source_pick_result_id',
        'parent_shortage_id',
        'status',
        'confirmed_user_id',
        'confirmed_at',
        'updater_id',
        'reason_code',
        'note',
    ];

    protected $casts = [
        'order_qty_each' => 'integer',
        'planned_qty_each' => 'integer',
        'picked_qty_each' => 'integer',
        'shortage_qty_each' => 'integer',
        'allocation_shortage_qty' => 'integer',
        'picking_shortage_qty' => 'integer',
        'case_size_snap' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_REALLOCATING = 'REALLOCATING';
    public const STATUS_FULFILLED = 'FULFILLED';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_CANCELLED = 'CANCELLED';

    // Reason code constants
    public const REASON_NONE = 'NONE';
    public const REASON_NO_STOCK = 'NO_STOCK';
    public const REASON_DAMAGED = 'DAMAGED';
    public const REASON_MISSING_LOC = 'MISSING_LOC';
    public const REASON_OTHER = 'OTHER';

    // Quantity type constants
    public const QTY_TYPE_CASE = 'CASE';
    public const QTY_TYPE_PIECE = 'PIECE';
    public const QTY_TYPE_CARTON = 'CARTON';

    // Relationships
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class, 'wave_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    public function parentShortage(): BelongsTo
    {
        return $this->belongsTo(WmsShortage::class, 'parent_shortage_id');
    }

    public function childShortages(): HasMany
    {
        return $this->hasMany(WmsShortage::class, 'parent_shortage_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(WmsShortageAllocation::class, 'shortage_id');
    }

    public function confirmedUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'confirmed_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updater_id');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeReallocating($query)
    {
        return $query->where('status', self::STATUS_REALLOCATING);
    }

    public function scopeHasAllocationShortage($query)
    {
        return $query->where('allocation_shortage_qty', '>', 0);
    }

    public function scopeHasPickingShortage($query)
    {
        return $query->where('picking_shortage_qty', '>', 0);
    }

    public function scopeForWave($query, int $waveId)
    {
        return $query->where('wave_id', $waveId);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    // Helper methods
    public function hasAllocationShortage(): bool
    {
        return $this->allocation_shortage_qty > 0;
    }

    public function hasPickingShortage(): bool
    {
        return $this->picking_shortage_qty > 0;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isReallocating(): bool
    {
        return $this->status === self::STATUS_REALLOCATING;
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    /**
     * 欠品の残量を計算
     * 移動出荷で充足した分を差し引く
     * キャンセル以外の全ての移動出荷を差し引く
     */
    public function getRemainingQtyEachAttribute(): int
    {
        $allocated = $this->allocations()
            ->whereNotIn('status', [WmsShortageAllocation::STATUS_CANCELLED])
            ->sum('assign_qty_each');

        return max(0, $this->shortage_qty_each - $allocated);
    }

    /**
     * PIECE数量をCASE表示用に変換
     */
    public function convertToCaseDisplay(int $qtyEach): array
    {
        if ($this->case_size_snap <= 1) {
            return ['case' => 0, 'piece' => $qtyEach];
        }

        return [
            'case' => intdiv($qtyEach, $this->case_size_snap),
            'piece' => $qtyEach % $this->case_size_snap,
        ];
    }

    /**
     * CASE受注かどうか
     */
    public function isCaseOrder(): bool
    {
        return $this->qty_type_at_order === self::QTY_TYPE_CASE;
    }
}
