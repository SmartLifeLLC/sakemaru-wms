<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsPickingLog extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_logs';

    public $timestamps = false; // Using created_at only

    protected $fillable = [
        'picker_id',
        'picker_code',
        'picker_name',
        'user_id',
        'user_name',
        'user_email',
        'action_type',
        'endpoint',
        'http_method',
        'picking_task_id',
        'picking_item_result_id',
        'wave_id',
        'earning_id',
        'delivery_course_id',
        'delivery_course_code',
        'delivery_course_name',
        'item_id',
        'item_code',
        'item_name',
        'real_stock_id',
        'location_id',
        'planned_qty',
        'planned_qty_type',
        'picked_qty',
        'picked_qty_type',
        'shortage_qty',
        'stock_qty_before',
        'stock_qty_after',
        'reserved_qty_before',
        'reserved_qty_after',
        'picking_qty_before',
        'picking_qty_after',
        'picker_id_before',
        'picker_id_after',
        'delivery_course_id_before',
        'delivery_course_id_after',
        'status_before',
        'status_after',
        'request_data',
        'response_data',
        'response_status_code',
        'operation_note',
        'ip_address',
        'user_agent',
        'device_id',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the picker that performed the action
     */
    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }

    /**
     * Get the picking task
     */
    public function pickingTask(): BelongsTo
    {
        return $this->belongsTo(WmsPickingTask::class, 'picking_task_id');
    }

    /**
     * Get the picking item result
     */
    public function pickingItemResult(): BelongsTo
    {
        return $this->belongsTo(WmsPickingItemResult::class, 'picking_item_result_id');
    }
}
