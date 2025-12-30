<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注先×倉庫ごとの納品可能曜日設定
 *
 * Oracle「Ｍ３仕入先店舗納品曜日」からの同期先テーブル
 *
 * @property int $id
 * @property int $contractor_id
 * @property int $warehouse_id
 * @property bool $delivery_mon
 * @property bool $delivery_tue
 * @property bool $delivery_wed
 * @property bool $delivery_thu
 * @property bool $delivery_fri
 * @property bool $delivery_sat
 * @property bool $delivery_sun
 */
class WmsContractorWarehouseDeliveryDay extends WmsModel
{
    protected $table = 'wms_contractor_warehouse_delivery_days';

    protected $fillable = [
        'contractor_id',
        'warehouse_id',
        'delivery_mon',
        'delivery_tue',
        'delivery_wed',
        'delivery_thu',
        'delivery_fri',
        'delivery_sat',
        'delivery_sun',
    ];

    protected $casts = [
        'delivery_mon' => 'boolean',
        'delivery_tue' => 'boolean',
        'delivery_wed' => 'boolean',
        'delivery_thu' => 'boolean',
        'delivery_fri' => 'boolean',
        'delivery_sat' => 'boolean',
        'delivery_sun' => 'boolean',
    ];

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * 指定した曜日に納品可能かどうかを判定
     *
     * @param int $dayOfWeek Carbon dayOfWeek (0=日曜, 1=月曜, ..., 6=土曜)
     * @return bool
     */
    public function canDeliverOn(int $dayOfWeek): bool
    {
        return match ($dayOfWeek) {
            0 => $this->delivery_sun,
            1 => $this->delivery_mon,
            2 => $this->delivery_tue,
            3 => $this->delivery_wed,
            4 => $this->delivery_thu,
            5 => $this->delivery_fri,
            6 => $this->delivery_sat,
            default => false,
        };
    }

    /**
     * 納品可能な曜日の配列を取得
     *
     * @return array<int> dayOfWeek values (0-6)
     */
    public function getDeliveryDays(): array
    {
        $days = [];
        if ($this->delivery_sun) $days[] = 0;
        if ($this->delivery_mon) $days[] = 1;
        if ($this->delivery_tue) $days[] = 2;
        if ($this->delivery_wed) $days[] = 3;
        if ($this->delivery_thu) $days[] = 4;
        if ($this->delivery_fri) $days[] = 5;
        if ($this->delivery_sat) $days[] = 6;
        return $days;
    }

    /**
     * 発注先×倉庫の組み合わせで設定を取得
     */
    public static function findByContractorAndWarehouse(int $contractorId, int $warehouseId): ?self
    {
        return static::where('contractor_id', $contractorId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }
}
