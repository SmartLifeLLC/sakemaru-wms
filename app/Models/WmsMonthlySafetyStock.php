<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 月別安全在庫（発注点）設定
 *
 * 季節変動に対応するため、月ごとの発注点を管理する。
 * 日次バッチで該当月のデータを item_contractors.safety_stock に同期する。
 */
class WmsMonthlySafetyStock extends WmsModel
{
    protected $table = 'wms_monthly_safety_stocks';

    protected $fillable = [
        'item_id',
        'warehouse_id',
        'contractor_id',
        'month',
        'safety_stock',
    ];

    protected $casts = [
        'month' => 'integer',
        'safety_stock' => 'integer',
    ];

    // ========================================
    // Relations
    // ========================================

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * 対応する item_contractor レコードを取得
     */
    public function getItemContractorAttribute(): ?ItemContractor
    {
        return ItemContractor::where('item_id', $this->item_id)
            ->where('warehouse_id', $this->warehouse_id)
            ->where('contractor_id', $this->contractor_id)
            ->first();
    }

    // ========================================
    // Scopes
    // ========================================

    /**
     * 指定月のデータに絞り込む
     */
    public function scopeForMonth(Builder $query, int $month): Builder
    {
        return $query->where('month', $month);
    }

    /**
     * 指定倉庫のデータに絞り込む
     */
    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * 指定商品のデータに絞り込む
     */
    public function scopeForItem(Builder $query, int $itemId): Builder
    {
        return $query->where('item_id', $itemId);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * 月のラベルを取得
     */
    public function getMonthLabelAttribute(): string
    {
        return $this->month . '月';
    }

    /**
     * 月の選択肢を取得
     */
    public static function getMonthOptions(): array
    {
        return collect(range(1, 12))
            ->mapWithKeys(fn ($month) => [$month => $month . '月'])
            ->toArray();
    }
}
