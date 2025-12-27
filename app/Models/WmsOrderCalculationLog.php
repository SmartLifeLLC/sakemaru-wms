<?php

namespace App\Models;

use App\Enums\AutoOrder\CalculationType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注計算ログ
 *
 * @property int $id
 * @property string $batch_code
 * @property int $warehouse_id
 * @property int $item_id
 * @property CalculationType $calculation_type
 * @property int|null $contractor_id
 * @property int|null $source_warehouse_id
 * @property int $current_effective_stock
 * @property int $incoming_quantity
 * @property int $safety_stock_setting
 * @property int $lead_time_days
 * @property int $calculated_shortage_qty
 * @property int $calculated_order_quantity
 * @property array|null $calculation_details
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
        'contractor_id',
        'source_warehouse_id',
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

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }
}
