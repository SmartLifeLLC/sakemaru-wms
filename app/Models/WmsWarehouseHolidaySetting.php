<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫別休日設定
 *
 * @property int $id
 * @property int $warehouse_id
 * @property array|null $regular_holiday_days
 * @property bool $is_national_holiday_closed
 */
class WmsWarehouseHolidaySetting extends WmsModel
{
    protected $table = 'wms_warehouse_holiday_settings';

    protected $fillable = [
        'warehouse_id',
        'regular_holiday_days',
        'is_national_holiday_closed',
    ];

    protected $casts = [
        'regular_holiday_days' => 'array',
        'is_national_holiday_closed' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * 指定曜日が定休日かチェック
     * @param int $dayOfWeek 0=日, 1=月, 2=火, 3=水, 4=木, 5=金, 6=土
     */
    public function isRegularHoliday(int $dayOfWeek): bool
    {
        return in_array($dayOfWeek, $this->regular_holiday_days ?? [], true);
    }

    /**
     * 曜日ラベルを取得
     */
    public static function getDayLabels(): array
    {
        return [
            0 => '日曜日',
            1 => '月曜日',
            2 => '火曜日',
            3 => '水曜日',
            4 => '木曜日',
            5 => '金曜日',
            6 => '土曜日',
        ];
    }
}
