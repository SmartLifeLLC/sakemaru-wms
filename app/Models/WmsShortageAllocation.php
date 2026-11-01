<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class WmsShortageAllocation extends WmsModel
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
        'started_at',
        'started_picker_id',
        'finished_picker_id',
        'is_synced',
        'is_synced_at',
        'cancelled_at',
        'cancelled_user_id',
        'cancel_reason',
        'cancel_previous_status',
        'stock_transfer_queue_id',
        'transfer_not_required_reason',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
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
        'started_at' => 'datetime',
        'is_synced' => 'boolean',
        'is_synced_at' => 'datetime',
    ];

    public function save(array $options = [])
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        // Keep the terminal-state check and UPDATE in one transaction even for legacy inline edits.
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            $current = self::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($current->isCancelled() || ($current->is_finished && collect(array_keys($allocation->getDirty()))
                ->diff(['stock_transfer_queue_id', 'transfer_not_required_reason', 'updated_at'])->isNotEmpty())) {
                throw ValidationException::withMessages(['status' => '終了済みの横持ち出荷依頼は変更できません。']);
            }
            if ($allocation->isDirty('picked_qty') && (filter_var($allocation->getAttributes()['picked_qty'], FILTER_VALIDATE_INT) === false || $allocation->picked_qty < 0 || $allocation->picked_qty > $allocation->assign_qty)) {
                throw ValidationException::withMessages(['picked_qty' => 'ピック数は0から予定数までの整数で入力してください。']);
            }
        });
        static::deleting(function (self $allocation): void {
            $current = self::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            $current->assertWorkable(true);
        });
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function assertWorkable(bool $allowPending = false): void
    {
        if ($this->is_finished || ! in_array($this->status, $allowPending
            ? [self::STATUS_PENDING, self::STATUS_RESERVED, self::STATUS_PICKING]
            : [self::STATUS_RESERVED, self::STATUS_PICKING], true)) {
            throw ValidationException::withMessages(['status' => 'この横持ち出荷依頼は操作できません。一覧を更新してください。']);
        }
    }

    public function getTransferRequestIdAttribute(): string
    {
        return "proxy-shipment-{$this->id}";
    }

    public function createQueue(): HasOne
    {
        return $this->hasOne(\App\Models\Sakemaru\StockTransferQueue::class, 'request_id', 'transfer_request_id')
            ->where('action_type', 'CREATE')
            ->select('stock_transfer_queue.*')
            ->selectSub(function ($query) {
                $query->from('stock_transfer_queue as other')->selectRaw('COUNT(*)')
                    ->whereColumn('other.stock_transfer_id', 'stock_transfer_queue.stock_transfer_id')
                    ->where('other.action_type', 'CREATE')->where('other.request_id', 'like', 'proxy-shipment-%');
            }, 'proxy_create_count');
    }

    public function scopeActiveRequest($query)
    {
        return $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', self::STATUS_CANCELLED));
    }

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

    public function startedPicker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'started_picker_id');
    }

    public function finishedPicker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'finished_picker_id');
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

    public function scopeReadyForProxyPicking($query)
    {
        return $query->where('is_confirmed', true)
            ->where('is_finished', false)
            ->whereIn('status', [self::STATUS_RESERVED, self::STATUS_PICKING]);
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

    public function isSynced(): bool
    {
        return $this->is_synced === true;
    }

    /**
     * ai-core同期が必要か（欠品完了済み・未同期）
     */
    public function needsSync(): bool
    {
        return $this->status === self::STATUS_SHORTAGE && $this->is_finished && ! $this->is_synced;
    }

    public function scopeNeedingSync($query)
    {
        return $query->where('status', self::STATUS_SHORTAGE)
            ->where('is_finished', true)
            ->where('is_synced', false);
    }

    /**
     * 残りの出荷数量を取得
     */
    public function getRemainingQtyAttribute(): int
    {
        return max(0, $this->assign_qty - $this->picked_qty);
    }
}
