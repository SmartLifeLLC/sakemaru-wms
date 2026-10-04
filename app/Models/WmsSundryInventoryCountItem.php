<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 棚卸し（雑貨）数量明細（在庫管理ありの商品）。
 *
 * 金額は 数量 × 原価(cost_price) で評価する。
 */
class WmsSundryInventoryCountItem extends WmsModel
{
    protected $fillable = [
        'sundry_inventory_count_id',
        'item_id',
        'item_code',
        'item_name',
        'category2_id',
        'category2_code',
        'category2_name',
        'is_additional',
        'cost_price',
        'system_quantity',
        'counted_quantity',
        'difference_quantity',
        'system_amount',
        'counted_amount',
        'difference_amount',
        'counted_by_name',
        'counted_at',
    ];

    protected $casts = [
        'is_additional' => 'boolean',
        'cost_price' => 'float',
        'system_quantity' => 'float',
        'counted_quantity' => 'float',
        'difference_quantity' => 'float',
        'system_amount' => 'float',
        'counted_amount' => 'float',
        'difference_amount' => 'float',
        'counted_at' => 'datetime',
    ];

    public function sundryInventoryCount(): BelongsTo
    {
        return $this->belongsTo(WmsSundryInventoryCount::class, 'sundry_inventory_count_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * 理論数・実棚数・原価から金額と差異を再計算する（保存はしない）。
     */
    public function recalculate(): static
    {
        $cost = (float) ($this->cost_price ?? 0);
        $systemQuantity = (float) ($this->system_quantity ?? 0);

        $this->system_amount = round($systemQuantity * $cost, 2);

        if ($this->counted_quantity === null) {
            $this->counted_amount = null;
            $this->difference_quantity = null;
            $this->difference_amount = null;

            return $this;
        }

        $countedQuantity = (float) $this->counted_quantity;
        $differenceQuantity = round($countedQuantity - $systemQuantity, 3);

        $this->counted_amount = round($countedQuantity * $cost, 2);
        $this->difference_quantity = $differenceQuantity;
        $this->difference_amount = round($differenceQuantity * $cost, 2);

        return $this;
    }
}
