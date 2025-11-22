<?php

namespace App\Models;

use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsPickingTask extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_tasks';

    // Status constants
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PICKING = 'PICKING';
    public const STATUS_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'wave_id',
        'wms_picking_area_id',
        'warehouse_id',
        'warehouse_code',
        'floor_id',
        'temperature_type',
        'is_restricted_area',
        'delivery_course_id',
        'delivery_course_code',
        'shipment_date',
        'status',
        'task_type',
        'picker_id',
        'started_at',
        'completed_at',
        'print_requested_count',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_restricted_area' => 'boolean',
        'print_requested_count' => 'integer',
    ];

    /**
     * このタスクが属するウェーブ
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class, 'wave_id');
    }

    /**
     * このタスクが属するピッキングエリア
     */
    public function pickingArea(): BelongsTo
    {
        return $this->belongsTo(WmsPickingArea::class, 'wms_picking_area_id');
    }

    /**
     * このタスクが属する倉庫
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * このタスクが属する倉庫フロア
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class, 'floor_id');
    }

    /**
     * このタスクが属する配送コース
     */
    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'delivery_course_id');
    }

    /**
     * このタスクのピッキング明細
     */
    public function pickingItemResults(): HasMany
    {
        return $this->hasMany(WmsPickingItemResult::class, 'picking_task_id');
    }

    /**
     * ピッカー（担当者）
     */
    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }

    /**
     * スコープ：未割当タスク
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('picker_id');
    }

    /**
     * スコープ：進行中ステータス
     */
    public function scopeInProgress($query)
    {
        return $query->whereIn('status', ['PENDING', 'PICKING']);
    }

    /**
     * Get display-friendly wave code
     */
    public function getWaveCodeAttribute(): string
    {
        return $this->wave->wave_code ?? "Wave {$this->wave_id}";
    }

    /**
     * Get total item count for this task
     */
    public function getItemCountAttribute(): int
    {
        return $this->pickingItemResults()->count();
    }
}
