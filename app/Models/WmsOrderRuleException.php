<?php

namespace App\Models;

use App\Enums\AutoOrder\BelowLotAction;
use App\Enums\AutoOrder\RuleExceptionTargetType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ロットルールの例外設定
 */
class WmsOrderRuleException extends WmsModel
{
    protected $table = 'wms_order_rule_exceptions';

    protected $fillable = [
        'wms_warehouse_contractor_order_rule_id',
        'target_type',
        'target_id',
        'priority',
        'allows_case',
        'allows_piece',
        'min_case_qty',
        'case_multiple_qty',
        'min_piece_qty',
        'piece_multiple_qty',
        'below_lot_action',
    ];

    protected $casts = [
        'target_type' => RuleExceptionTargetType::class,
        'allows_case' => 'boolean',
        'allows_piece' => 'boolean',
        'below_lot_action' => BelowLotAction::class,
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WmsWarehouseContractorOrderRule::class, 'wms_warehouse_contractor_order_rule_id');
    }
}
