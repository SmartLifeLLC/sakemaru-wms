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
        'shipment_date',
        'warehouse_id',
        'item_id',
        'trade_id',
        'earning_id',
        'delivery_course_id',
        'trade_item_id',
        'order_qty',
        'planned_qty',
        'picked_qty',
        'shortage_qty',
        'allocation_shortage_qty',
        'picking_shortage_qty',
        'qty_type_at_order',
        'case_size_snap',
        'is_confirmed',
        'confirmed_by',
        'confirmed_at',
        'is_synced',
        'is_synced_at',
        'source_reservation_id',
        'source_pick_result_id',
        'parent_shortage_id',
        'status',
        'confirmed_user_id',
        'updater_id',
        'reason_code',
        'note',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'order_qty' => 'integer',
        'planned_qty' => 'integer',
        'picked_qty' => 'integer',
        'shortage_qty' => 'integer',
        'allocation_shortage_qty' => 'integer',
        'picking_shortage_qty' => 'integer',
        'case_size_snap' => 'integer',
        'is_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
        'is_synced' => 'boolean',
        'is_synced_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_BEFORE = 'BEFORE';
    public const STATUS_REALLOCATING = 'REALLOCATING';
    public const STATUS_SHORTAGE = 'SHORTAGE';
    public const STATUS_PARTIAL_SHORTAGE = 'PARTIAL_SHORTAGE';

    // Status labels for UI
    public const STATUS_LABELS = [
        self::STATUS_BEFORE => '処理前',
        self::STATUS_REALLOCATING => '再引当中',
        self::STATUS_SHORTAGE => '欠品確定',
        self::STATUS_PARTIAL_SHORTAGE => '部分欠品',
    ];

    // Deprecated status constants (for backward compatibility)
    public const STATUS_OPEN = 'BEFORE';  // Alias for BEFORE
    public const STATUS_CONFIRMED = 'SHORTAGE';  // Alias for SHORTAGE

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

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'confirmed_by');
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
    public function scopeBefore($query)
    {
        return $query->where('status', self::STATUS_BEFORE);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_BEFORE);  // Alias for backward compatibility
    }

    public function scopeReallocating($query)
    {
        return $query->where('status', self::STATUS_REALLOCATING);
    }

    public function scopeShortage($query)
    {
        return $query->where('status', self::STATUS_SHORTAGE);
    }

    public function scopePartialShortage($query)
    {
        return $query->where('status', self::STATUS_PARTIAL_SHORTAGE);
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

    public function scopeUnsynced($query)
    {
        return $query->where('is_synced', false);
    }

    public function scopeConfirmedButNotSynced($query)
    {
        return $query->where('is_confirmed', true)
            ->where('is_synced', false);
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

    public function isBefore(): bool
    {
        return $this->status === self::STATUS_BEFORE;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_BEFORE;  // Alias for backward compatibility
    }

    public function isReallocating(): bool
    {
        return $this->status === self::STATUS_REALLOCATING;
    }

    public function isShortage(): bool
    {
        return $this->status === self::STATUS_SHORTAGE;
    }

    public function isPartialShortage(): bool
    {
        return $this->status === self::STATUS_PARTIAL_SHORTAGE;
    }

    public function isSynced(): bool
    {
        return $this->is_synced === true;
    }

    public function needsSync(): bool
    {
        return $this->is_confirmed && !$this->is_synced;
    }

    /**
     * 欠品の残量を計算
     * 横持ち出荷で充足した分を差し引く
     *
     * @return int 受注単位ベースの残欠品数
     */
    public function getRemainingQtyAttribute(): int
    {
        // withSumでロード済みの場合はそれを使用
        // Check if the aggregate was loaded using withSum()
        if (array_key_exists('allocations_total_qty', $this->attributes)) {
            $allocated = $this->attributes['allocations_total_qty'] ?? 0;
        } else {
            $allocated = $this->allocations()->sum('assign_qty');
        }

        return max(0, $this->shortage_qty - $allocated);
    }

    /**
     * CASE受注かどうか
     */
    public function isCaseOrder(): bool
    {
        return $this->qty_type_at_order === self::QTY_TYPE_CASE;
    }

    /**
     * PIECE受注かどうか
     */
    public function isPieceOrder(): bool
    {
        return $this->qty_type_at_order === self::QTY_TYPE_PIECE;
    }

    /**
     * 数量を表示用フォーマットに変換
     * 受注単位に応じて適切な表示を返す
     *
     * @param int $qty 数量（受注単位ベース）
     * @return string フォーマットされた文字列（例: "2ケース", "15バラ"）
     */
    public function formatQuantity(int $qty): string
    {
        if ($qty <= 0) {
            return '-';
        }

        $unitLabel = \App\Enums\QuantityType::tryFrom($this->qty_type_at_order)?->name() ?? $this->qty_type_at_order;
        return "{$qty}{$unitLabel}";
    }
}
