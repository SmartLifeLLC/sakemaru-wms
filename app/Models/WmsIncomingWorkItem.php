<?php

namespace App\Models;

use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 入荷作業中データ
 *
 * Handyでの入荷作業中のデータを保存
 */
class WmsIncomingWorkItem extends WmsModel
{
    protected $table = 'wms_incoming_work_items';

    protected $fillable = [
        'incoming_schedule_id',
        'picker_id',
        'warehouse_id',
        'work_quantity',
        'work_arrival_date',
        'work_expiration_date',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'work_arrival_date' => 'date',
        'work_expiration_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_WORKING = 'WORKING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    // Relationships

    public function incomingSchedule(): BelongsTo
    {
        return $this->belongsTo(WmsOrderIncomingSchedule::class, 'incoming_schedule_id');
    }

    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // Scopes

    public function scopeWorking($query)
    {
        return $query->where('status', self::STATUS_WORKING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeForPicker($query, int $pickerId)
    {
        return $query->where('picker_id', $pickerId);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }
}
