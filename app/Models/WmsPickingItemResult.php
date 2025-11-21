<?php

namespace App\Models;

use App\Models\Sakemaru\Earning;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsPickingItemResult extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_item_results';

    // Status constants
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PICKING = 'PICKING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_SHORTAGE = 'SHORTAGE';

    protected $fillable = [
        'picking_task_id',
        'earning_id',
        'trade_id',
        'trade_item_id',
        'item_id',
        'real_stock_id',
        'location_id',
        'walking_order',
        'distance_from_previous',
        'ordered_qty',
        'ordered_qty_type',
        'planned_qty',
        'planned_qty_type',
        'picked_qty',
        'picked_qty_type',
        'shortage_qty',
        'shortage_allocated_qty',
        'shortage_allocated_qty_type',
        'is_ready_to_shipment',
        'shipment_ready_at',
        'status',
        'picker_id',
        'picked_at',
    ];

    protected $casts = [
        'walking_order' => 'integer',
        'distance_from_previous' => 'integer',
        'ordered_qty' => 'decimal:2',
        'planned_qty' => 'decimal:2',
        'picked_qty' => 'decimal:2',
        'shortage_qty' => 'decimal:2',
        'shortage_allocated_qty' => 'integer',
        'is_ready_to_shipment' => 'boolean',
        'picked_at' => 'datetime',
        'shipment_ready_at' => 'datetime',
    ];

    /**
     * このピッキング明細が属するタスク
     */
    public function pickingTask(): BelongsTo
    {
        return $this->belongsTo(WmsPickingTask::class, 'picking_task_id');
    }

    /**
     * このピッキング明細が属する売上伝票
     */
    public function earning(): BelongsTo
    {
        return $this->belongsTo(Earning::class, 'earning_id');
    }

    /**
     * このピッキング明細が属する取引（売上伝票）
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Trade::class, 'trade_id');
    }

    /**
     * このピッキング明細が属する取引明細
     */
    public function tradeItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\TradeItem::class, 'trade_item_id');
    }

    /**
     * このピッキング明細の商品
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * このピッキング明細のロケーション
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * このピッキング明細に紐づく欠品
     * wms_shortages.source_pick_result_id = wms_picking_item_results.id
     */
    public function shortage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WmsShortage::class, 'source_pick_result_id', 'id');
    }

    /**
     * スコープ：ピッキング順序でソート
     * Sorts by walking_order (warehouse movement sequence)
     */
    public function scopeOrderedForPicking($query)
    {
        return $query->orderBy('walking_order', 'asc')
                    ->orderBy('item_id', 'asc');
    }

    /**
     * スコープ：未完了のアイテム
     */
    public function scopePending($query)
    {
        return $query->where('status', 'PICKING');
    }

    /**
     * Get item name with code
     */
    public function getItemNameWithCodeAttribute(): string
    {
        $item = $this->item;
        if (!$item) {
            return "Item {$this->item_id}";
        }
        return "[{$item->code}] {$item->name}";
    }

    /**
     * Get location display
     */
    public function getLocationDisplayAttribute(): string
    {
        $location = $this->location;
        if (!$location) {
            return "-";
        }

        $locationCode = trim("{$location->code1} {$location->code2} {$location->code3}");

        // Add location name if available
        if (!empty($location->name)) {
            return "{$locationCode} - {$location->name}";
        }

        return $locationCode;
    }

    /**
     * Check if item is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    /**
     * Check if item has any shortage (physical or soft)
     * Note: has_shortage is a generated column, so this accessor reads from DB
     */
    public function hasShortage(): bool
    {
        return $this->has_shortage ?? false;
    }

    /**
     * Check if item has physical shortage (warehouse discrepancy)
     * True when: status == COMPLETED AND planned_qty > picked_qty
     */
    public function hasPhysicalShortage(): bool
    {
        return $this->has_physical_shortage ?? false;
    }

    /**
     * Check if item has soft shortage (allocation shortage)
     * True when: ordered_qty > planned_qty
     */
    public function hasSoftShortage(): bool
    {
        return $this->has_soft_shortage ?? false;
    }

    /**
     * Get quantity type display text
     */
    public static function getQuantityTypeLabel(string $type): string
    {
        return match ($type) {
            'CASE' => 'ケース',
            'PIECE' => 'バラ',
            default => $type,
        };
    }

    /**
     * Get ordered quantity type display
     */
    public function getOrderedQtyTypeDisplayAttribute(): string
    {
        return self::getQuantityTypeLabel($this->ordered_qty_type ?? 'PIECE');
    }

    /**
     * Get planned quantity type display
     */
    public function getPlannedQtyTypeDisplayAttribute(): string
    {
        return self::getQuantityTypeLabel($this->planned_qty_type ?? 'PIECE');
    }

    /**
     * Get picked quantity type display
     */
    public function getPickedQtyTypeDisplayAttribute(): string
    {
        return self::getQuantityTypeLabel($this->picked_qty_type ?? 'PIECE');
    }
}
