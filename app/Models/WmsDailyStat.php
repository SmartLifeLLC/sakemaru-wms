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
        'total_slip_count',
        'shipped_slip_count',
        'unshipped_slip_count',
        'unique_buyer_count',
        'picking_slip_count',
        'picking_item_count',
        'unique_item_count',
        'stockout_unique_count',
        'stockout_total_count',
        'allocation_shortage_qty',
        'confirmed_shortage_count',
        'confirmed_shortage_qty',
        'shortage_slip_count',
        'delivery_course_count',
        'wave_count',
        'picking_task_count',
        'completed_task_count',
        'shipped_task_count',
        'total_ship_qty',
        'total_order_qty',
        'total_planned_qty',
        'total_amount_ex',
        'total_amount_in',
        'total_container_deposit',
        'total_opportunity_loss',
        'category_breakdown',
        'last_calculated_at',
    ];

    protected $casts = [
        'target_date' => 'date',
        'total_slip_count' => 'integer',
        'shipped_slip_count' => 'integer',
        'unshipped_slip_count' => 'integer',
        'unique_buyer_count' => 'integer',
        'picking_slip_count' => 'integer',
        'picking_item_count' => 'integer',
        'unique_item_count' => 'integer',
        'stockout_unique_count' => 'integer',
        'stockout_total_count' => 'integer',
        'allocation_shortage_qty' => 'integer',
        'confirmed_shortage_count' => 'integer',
        'confirmed_shortage_qty' => 'integer',
        'shortage_slip_count' => 'integer',
        'delivery_course_count' => 'integer',
        'wave_count' => 'integer',
        'picking_task_count' => 'integer',
        'completed_task_count' => 'integer',
        'shipped_task_count' => 'integer',
        'total_ship_qty' => 'integer',
        'total_order_qty' => 'integer',
        'total_planned_qty' => 'integer',
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
     */
    public function isStale(int $minutes = 30): bool
    {
        if (! $this->last_calculated_at) {
            return true;
        }

        return $this->last_calculated_at->diffInMinutes(now()) >= $minutes;
    }

    /**
     * カテゴリ別内訳を取得
     */
    public function getCategoryBreakdown(): array
    {
        return $this->category_breakdown ?? ['categories' => []];
    }

    /**
     * 特定カテゴリのデータを取得
     */
    public function getCategoryData(int $categoryId): ?array
    {
        $breakdown = $this->getCategoryBreakdown();

        return $breakdown['categories'][$categoryId] ?? null;
    }
}
