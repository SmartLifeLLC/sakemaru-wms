<?php

namespace App\Models;

use App\Models\Sakemaru\DeliveryCourse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaveSetting extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_wave_settings';

    protected $fillable = [
        'name',
        'delivery_course_id',
        'picking_start_time',
        'picking_deadline_time',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'picking_start_time' => 'datetime:H:i:s',
        'picking_deadline_time' => 'datetime:H:i:s',
    ];

    public function waves(): HasMany
    {
        return $this->hasMany(Wave::class, 'wms_wave_setting_id');
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class);
    }

    /**
     * warehouse_id アクセサ
     *
     * deliveryCourse.warehouse_id から動的に取得する。
     * 呼び出し元で ->with('deliveryCourse') を使用して N+1 を避けること。
     */
    public function getWarehouseIdAttribute(): ?int
    {
        return $this->deliveryCourse?->warehouse_id;
    }
}
