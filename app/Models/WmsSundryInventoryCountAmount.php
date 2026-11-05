<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 棚卸し（雑貨）金額明細（在庫管理なしの商品。旧Accessと同じ中分類単位）。
 *
 * 理論金額 = 前残金額 + 仕入 + 移入 − 移出 − 売上原価 + 調整
 * （基幹の月次在庫金額評価の非管理品と同じ流量式）
 *
 * 前残金額は、前回確定した棚卸し（雑貨）の実棚金額、無ければ旧システム（Ｔ３在庫）の残高を使う。
 * 大分類合計は、ヘッダーの amount_report_references（在庫金額報告書ベース）と突き合わせる。
 */
class WmsSundryInventoryCountAmount extends WmsModel
{
    public const OPENING_SOURCE_LEGACY = 'legacy';

    public const OPENING_SOURCE_PREVIOUS_COUNT = 'previous_count';

    public const OPENING_SOURCE_MANUAL = 'manual';

    public const OPENING_SOURCE_DEFAULT = 'default';

    protected $fillable = [
        'sundry_inventory_count_id',
        'category1_id',
        'category1_code',
        'category1_name',
        'category2_id',
        'category2_code',
        'category2_name',
        'is_additional',
        'opening_date',
        'opening_amount',
        'opening_source',
        'purchase_amount',
        'transfer_in_amount',
        'transfer_out_amount',
        'sales_cost_amount',
        'adjustment_amount',
        'system_amount',
        'counted_amount',
        'difference_amount',
        'counted_by_name',
        'counted_at',
    ];

    protected $casts = [
        'is_additional' => 'boolean',
        'opening_date' => 'date',
        'opening_amount' => 'float',
        'purchase_amount' => 'float',
        'transfer_in_amount' => 'float',
        'transfer_out_amount' => 'float',
        'sales_cost_amount' => 'float',
        'adjustment_amount' => 'float',
        'system_amount' => 'float',
        'counted_amount' => 'float',
        'difference_amount' => 'float',
        'counted_at' => 'datetime',
    ];

    public function sundryInventoryCount(): BelongsTo
    {
        return $this->belongsTo(WmsSundryInventoryCount::class, 'sundry_inventory_count_id');
    }

    public function getOpeningSourceLabelAttribute(): string
    {
        return match ($this->opening_source) {
            self::OPENING_SOURCE_LEGACY => '旧システム残高',
            self::OPENING_SOURCE_PREVIOUS_COUNT => '前回棚卸の実棚',
            self::OPENING_SOURCE_MANUAL => '手入力',
            self::OPENING_SOURCE_DEFAULT => '未設定',
            default => (string) $this->opening_source,
        };
    }

    /**
     * 受払から理論金額と差異を再計算する（保存はしない）。
     */
    public function recalculate(): static
    {
        $this->system_amount = round(
            (float) ($this->opening_amount ?? 0)
            + (float) ($this->purchase_amount ?? 0)
            + (float) ($this->transfer_in_amount ?? 0)
            - (float) ($this->transfer_out_amount ?? 0)
            - (float) ($this->sales_cost_amount ?? 0)
            + (float) ($this->adjustment_amount ?? 0),
            2,
        );

        $this->difference_amount = $this->counted_amount === null
            ? null
            : round((float) $this->counted_amount - (float) $this->system_amount, 2);

        return $this;
    }
}
