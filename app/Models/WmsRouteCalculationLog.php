<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsRouteCalculationLog extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_route_calculation_logs';

    protected $fillable = [
        'picking_task_id',
        'warehouse_id',
        'floor_id',
        'algorithm',
        'cell_size',
        'front_point_delta',
        'location_count',
        'total_distance',
        'calculation_time_ms',
        'location_order',
        'metadata',
    ];

    protected $casts = [
        'location_order' => 'array',
        'metadata' => 'array',
    ];

    public function pickingTask(): BelongsTo
    {
        return $this->belongsTo(WmsPickingTask::class, 'picking_task_id');
    }
}
