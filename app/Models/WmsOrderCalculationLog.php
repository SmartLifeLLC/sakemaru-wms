<?php

namespace App\Models;

use App\Enums\AutoOrder\CalculationType;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注計算ログ
 */
class WmsOrderCalculationLog extends WmsModel
{
    protected $table = 'wms_order_calculation_logs';

    public $timestamps = false;

    protected $fillable = [
        'batch_code',
        'warehouse_id',
        'item_id',
        'calculation_type',
        'current_effective_stock',
        'incoming_quantity',
        'safety_stock_setting',
        'lead_time_days',
        'calculated_shortage_qty',
        'calculated_order_quantity',
        'calculation_details',
    ];

    protected $casts = [
        'calculation_type' => CalculationType::class,
        'calculation_details' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
