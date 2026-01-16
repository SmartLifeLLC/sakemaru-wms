<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫間移動の配送コース設定
 *
 * 移動元倉庫→移動先倉庫の組み合わせに対して配送コースを設定
 */
class WarehouseStockTransferDeliveryCourse extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'warehouse_stock_transfer_delivery_courses';

    protected $fillable = [
        'from_warehouse_id',
        'to_warehouse_id',
        'delivery_course_id',
        'creator_id',
        'last_updater_id',
    ];

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class);
    }

    /**
     * 指定された倉庫間の配送コースIDを取得
     */
    public static function getDeliveryCourseId(int $fromWarehouseId, int $toWarehouseId): ?int
    {
        $setting = static::where('from_warehouse_id', $fromWarehouseId)
            ->where('to_warehouse_id', $toWarehouseId)
            ->first();

        return $setting?->delivery_course_id;
    }
}
