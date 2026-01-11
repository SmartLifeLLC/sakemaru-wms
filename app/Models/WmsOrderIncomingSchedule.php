<?php

namespace App\Models;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注入庫予定
 *
 * 発注確定後の入庫予定を管理
 * 自動発注・手動発注の両方に対応
 */
class WmsOrderIncomingSchedule extends WmsModel
{
    protected $table = 'wms_order_incoming_schedules';

    protected $fillable = [
        'warehouse_id',
        'item_id',
        'contractor_id',
        'supplier_id',
        'order_candidate_id',
        'manual_order_number',
        'order_source',
        'expected_quantity',
        'received_quantity',
        'quantity_type',
        'order_date',
        'expected_arrival_date',
        'actual_arrival_date',
        'status',
        'confirmed_at',
        'confirmed_by',
        'purchase_queue_id',
        'purchase_slip_number',
        'note',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'actual_arrival_date' => 'date',
        'confirmed_at' => 'datetime',
        'status' => IncomingScheduleStatus::class,
        'order_source' => OrderSource::class,
        'quantity_type' => QuantityType::class,
    ];

    // Relationships

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function orderCandidate(): BelongsTo
    {
        return $this->belongsTo(WmsOrderCandidate::class, 'order_candidate_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // Scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', IncomingScheduleStatus::PENDING);
    }

    public function scopePartial(Builder $query): Builder
    {
        return $query->where('status', IncomingScheduleStatus::PARTIAL);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', IncomingScheduleStatus::CONFIRMED);
    }

    public function scopeNotCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            IncomingScheduleStatus::PENDING,
            IncomingScheduleStatus::PARTIAL,
        ]);
    }

    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeExpectedBefore(Builder $query, string $date): Builder
    {
        return $query->where('expected_arrival_date', '<=', $date);
    }

    public function scopeFromAutoOrder(Builder $query): Builder
    {
        return $query->where('order_source', OrderSource::AUTO);
    }

    public function scopeFromManualOrder(Builder $query): Builder
    {
        return $query->where('order_source', OrderSource::MANUAL);
    }

    // Accessors

    /**
     * 残り入庫数量
     */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->expected_quantity - $this->received_quantity);
    }

    /**
     * 入庫完了かどうか
     */
    public function getIsFullyReceivedAttribute(): bool
    {
        return $this->received_quantity >= $this->expected_quantity;
    }

    // Methods

    /**
     * 入庫数量を追加
     */
    public function addReceivedQuantity(int $quantity): void
    {
        $this->received_quantity += $quantity;

        if ($this->received_quantity >= $this->expected_quantity) {
            $this->status = IncomingScheduleStatus::CONFIRMED;
        } elseif ($this->received_quantity > 0) {
            $this->status = IncomingScheduleStatus::PARTIAL;
        }

        $this->save();
    }

    /**
     * 入庫確定
     */
    public function confirm(int $confirmedBy, ?string $actualDate = null): void
    {
        $this->update([
            'status' => IncomingScheduleStatus::CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => $confirmedBy,
            'actual_arrival_date' => $actualDate ?? now()->format('Y-m-d'),
            'received_quantity' => $this->expected_quantity,
        ]);
    }

    /**
     * キャンセル
     */
    public function cancel(): void
    {
        $this->update([
            'status' => IncomingScheduleStatus::CANCELLED,
        ]);
    }
}
