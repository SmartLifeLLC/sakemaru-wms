<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsDailyStat extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_daily_stats';

    protected $fillable = [
        'warehouse_id',
        'target_date',
        'picking_slip_count',
        'picking_item_count',
        'unique_item_count',
        'stockout_unique_count',
        'stockout_total_count',
        'delivery_course_count',
        'total_ship_qty',
        'total_amount_ex',
        'total_amount_in',
        'total_container_deposit',
        'total_opportunity_loss',
        'category_breakdown',
        'last_calculated_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'picking_slip_count' => 'integer',
        'picking_item_count' => 'integer',
        'unique_item_count' => 'integer',
        'stockout_unique_count' => 'integer',
        'stockout_total_count' => 'integer',
        'delivery_course_count' => 'integer',
        'total_ship_qty' => 'integer',
        'total_amount_ex' => 'decimal:2',
        'total_amount_in' => 'decimal:2',
        'total_container_deposit' => 'decimal:2',
        'total_opportunity_loss' => 'decimal:2',
        'category_breakdown' => 'array',
        'last_calculated_at' => 'datetime',
    ];

    /**
     * 倉庫リレーション
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Warehouse::class, 'warehouse_id');
    }

    /**
     * 最終集計から指定分数が経過しているかチェック
     *
     * @param int $minutes
     * @return bool
     */
    public function isStale(int $minutes = 30): bool
    {
        if (!$this->last_calculated_at) {
            return true;
        }

        return $this->last_calculated_at->diffInMinutes(now()) >= $minutes;
    }

    /**
     * カテゴリ別内訳を取得
     *
     * @return array
     */
    public function getCategoryBreakdown(): array
    {
        return $this->category_breakdown ?? ['categories' => []];
    }

    /**
     * 特定カテゴリのデータを取得
     *
     * @param int $categoryId
     * @return array|null
     */
    public function getCategoryData(int $categoryId): ?array
    {
        $breakdown = $this->getCategoryBreakdown();
        return $breakdown['categories'][$categoryId] ?? null;
    }
}
