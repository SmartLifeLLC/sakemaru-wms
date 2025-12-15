<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商品発注設定
 *
 * @property int $id
 * @property int $item_id
 * @property int $warehouse_id
 * @property int|null $contractor_id
 * @property int $safety_stock
 * @property int|null $max_stock
 * @property int $lead_time_days
 * @property bool $is_auto_order_enabled
 * @property bool $is_holiday_delivery_available
 * @property int|null $daily_consumption_rate
 */
class WmsItemOrderSetting extends WmsModel
{
    protected $table = 'wms_item_order_settings';

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'contractor_id',
        'safety_stock',
        'max_stock',
        'lead_time_days',
        'is_auto_order_enabled',
        'is_holiday_delivery_available',
        'daily_consumption_rate',
    ];

    protected $casts = [
        'is_auto_order_enabled' => 'boolean',
        'is_holiday_delivery_available' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * 自動発注有効なもののみ
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_auto_order_enabled', true);
    }

    /**
     * 指定倉庫の設定のみ
     */
    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * リードタイム中の消費予測数を取得
     */
    public function getConsumptionDuringLeadTimeAttribute(): int
    {
        if (!$this->daily_consumption_rate) {
            return 0;
        }

        return $this->daily_consumption_rate * $this->lead_time_days;
    }
}
