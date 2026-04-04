<?php

namespace App\Models;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use App\Models\Sakemaru\Supplier;
use App\Models\Sakemaru\Warehouse;
use Carbon\Carbon;
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
        'item_code',
        'search_code',
        'contractor_id',
        'supplier_id',
        'location_id',
        'order_candidate_id',
        'transfer_candidate_id',
        'source_warehouse_id',
        'stock_transfer_id',
        'manual_order_number',
        'order_source',
        'slip_number',
        'expected_quantity',
        'received_quantity',
        'quantity_type',
        'order_date',
        'expected_arrival_date',
        'actual_arrival_date',
        'expiration_date',
        'status',
        'confirmed_at',
        'confirmed_by',
        'confirmed_picker_id',
        'is_receive_matched',
        'shipped_quantity',
        'unit_price',
        'case_price',
        'partner_unit_price',
        'partner_case_price',
        'price_type',
        'shortage_quantity',
        'purchase_queue_id',
        'purchase_slip_number',
        'note',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'actual_arrival_date' => 'date',
        'expiration_date' => 'date',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'status' => IncomingScheduleStatus::class,
        'order_source' => OrderSource::class,
        'quantity_type' => QuantityType::class,
        'is_receive_matched' => 'boolean',
        'shortage_quantity' => 'integer',
        'shipped_quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'case_price' => 'decimal:2',
        'partner_unit_price' => 'decimal:2',
        'partner_case_price' => 'decimal:2',
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

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function orderCandidate(): BelongsTo
    {
        return $this->belongsTo(WmsOrderCandidate::class, 'order_candidate_id');
    }

    public function transferCandidate(): BelongsTo
    {
        return $this->belongsTo(WmsStockTransferCandidate::class, 'transfer_candidate_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function confirmedByPicker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'confirmed_picker_id');
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

    public function scopeFromTransfer(Builder $query): Builder
    {
        return $query->where('order_source', OrderSource::TRANSFER);
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
     * 伝票番号を採番
     *
     * フォーマット: {YYYYMMDD}{連番3桁} = 11桁数字のみ
     * 例: 20260305001
     * JX Bレコードの伝票番号フィールド（11バイト）にそのまま格納可能
     *
     * @param  string|null  $orderDate  発注日（Y-m-d形式）。nullの場合は今日
     */
    public static function generateSlipNumber(?string $orderDate = null): string
    {
        $date = $orderDate ?? now()->format('Y-m-d');
        $dateStr = Carbon::parse($date)->format('Ymd');

        $maxSlip = self::where('slip_number', 'like', $dateStr.'%')
            ->where('slip_number', 'REGEXP', '^[0-9]{11}$')
            ->orderByRaw('CAST(SUBSTRING(slip_number, 9) AS UNSIGNED) DESC')
            ->value('slip_number');

        $nextSeq = $maxSlip ? (int) substr($maxSlip, 8) + 1 : 1;

        return $dateStr.str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
    }

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
    public function confirm(int $confirmedBy, ?string $actualDate = null, ?int $pickerId = null): void
    {
        $this->update([
            'status' => IncomingScheduleStatus::CONFIRMED,
            'confirmed_at' => now(),
            'confirmed_by' => $pickerId ? null : $confirmedBy,
            'confirmed_picker_id' => $pickerId,
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
