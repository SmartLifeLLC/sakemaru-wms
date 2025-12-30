<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫別営業日カレンダー
 *
 * @property int $id
 * @property int $warehouse_id
 * @property \Carbon\Carbon $target_date
 * @property bool $is_holiday
 * @property string|null $holiday_reason
 * @property bool $is_manual_override
 */
class WmsWarehouseCalendar extends WmsModel
{
    protected $table = 'wms_warehouse_calendars';

    protected $fillable = [
        'warehouse_id',
        'target_date',
        'is_holiday',
        'holiday_reason',
        'is_manual_override',
    ];

    protected $casts = [
        'target_date' => 'date',
        'is_holiday' => 'boolean',
        'is_manual_override' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * 指定倉庫の指定日が休日かどうか
     */
    public static function isHoliday(int $warehouseId, Carbon $date): bool
    {
        $calendar = self::where('warehouse_id', $warehouseId)
            ->where('target_date', $date->toDateString())
            ->first();

        return $calendar?->is_holiday ?? false;
    }

    /**
     * 次の営業日を取得
     */
    public static function getNextBusinessDay(int $warehouseId, Carbon $fromDate): Carbon
    {
        $date = $fromDate->copy();
        $maxIterations = 30; // 最大30日先まで検索

        for ($i = 0; $i < $maxIterations; $i++) {
            if (!self::isHoliday($warehouseId, $date)) {
                return $date;
            }
            $date->addDay();
        }

        // 見つからない場合は元の日付を返す
        return $fromDate;
    }

    /**
     * 指定期間のカレンダーを取得
     */
    public function scopeForPeriod(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('target_date', [$startDate->toDateString(), $endDate->toDateString()]);
    }

    /**
     * 休日のみ取得
     */
    public function scopeHolidaysOnly(Builder $query): Builder
    {
        return $query->where('is_holiday', true);
    }

    /**
     * 営業日のみ取得
     */
    public function scopeBusinessDaysOnly(Builder $query): Builder
    {
        return $query->where('is_holiday', false);
    }
}
