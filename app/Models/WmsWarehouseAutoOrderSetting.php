<?php

namespace App\Models;

use App\Enums\AutoOrder\ConfirmationLevel;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫別自動発注設定
 *
 * @property int $id
 * @property int $warehouse_id
 * @property bool $is_auto_order_enabled
 * @property bool $exclude_sunday_arrival
 * @property bool $exclude_holiday_arrival
 * @property ConfirmationLevel $confirmation_level
 */
class WmsWarehouseAutoOrderSetting extends WmsModel
{
    protected $table = 'wms_warehouse_auto_order_settings';

    protected $fillable = [
        'warehouse_id',
        'is_auto_order_enabled',
        'exclude_sunday_arrival',
        'exclude_holiday_arrival',
        'confirmation_level',
    ];

    protected $casts = [
        'is_auto_order_enabled' => 'boolean',
        'exclude_sunday_arrival' => 'boolean',
        'exclude_holiday_arrival' => 'boolean',
        'confirmation_level' => ConfirmationLevel::class,
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * 自動発注有効な倉庫のみ取得
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_auto_order_enabled', true);
    }

    /**
     * 倉庫IDから確定レベルを取得（未設定はSTATUS1）
     */
    public static function getConfirmationLevel(int $warehouseId): ConfirmationLevel
    {
        $setting = self::where('warehouse_id', $warehouseId)->first();

        return $setting?->confirmation_level ?? ConfirmationLevel::STATUS1;
    }
}
