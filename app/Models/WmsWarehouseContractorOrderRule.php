<?php

namespace App\Models;

use App\Enums\AutoOrder\BelowLotAction;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 倉庫×発注先のロットルール
 */
class WmsWarehouseContractorOrderRule extends WmsModel
{
    protected $table = 'wms_warehouse_contractor_order_rules';

    protected $fillable = [
        'warehouse_id',
        'contractor_id',
        'allows_case',
        'allows_piece',
        'piece_to_case_rounding',
        'allows_mixed',
        'mixed_unit',
        'mixed_limit_qty',
        'min_case_qty',
        'case_multiple_qty',
        'min_piece_qty',
        'piece_multiple_qty',
        'below_lot_action',
        'handling_fee',
        'shipping_fee',
    ];

    protected $casts = [
        'allows_case' => 'boolean',
        'allows_piece' => 'boolean',
        'allows_mixed' => 'boolean',
        'below_lot_action' => BelowLotAction::class,
        'handling_fee' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(WmsOrderRuleException::class, 'wms_warehouse_contractor_order_rule_id');
    }
}
