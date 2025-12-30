<?php

namespace App\Models;

use App\Enums\PickingStrategyType;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsPickingAssignmentStrategy extends Model
{
    protected $table = 'wms_picking_assignment_strategies';

    protected $fillable = [
        'warehouse_id',
        'name',
        'description',
        'strategy_key',
        'parameters',
        'is_default',
        'is_active',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'parameters' => 'array',
        'strategy_key' => PickingStrategyType::class,
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'warehouse_id' => 'integer',
        'creator_id' => 'integer',
        'last_updater_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $strategy) {
            // is_default が true に設定された場合、同じ warehouse_id の他のレコードの is_default を false に
            if ($strategy->is_default) {
                self::query()
                    ->where('warehouse_id', $strategy->warehouse_id)
                    ->where('id', '!=', $strategy->id ?? 0)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            // creator_id と last_updater_id を自動設定
            if (!$strategy->exists) {
                $strategy->creator_id = auth()->id() ?? 0;
            }
            $strategy->last_updater_id = auth()->id() ?? 0;
        });
    }

    /**
     * 倉庫リレーション（FK制約はないがアプリレベルで定義）
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * 指定倉庫のものを取得するスコープ
     */
    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * 有効なもののみを取得するスコープ
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * デフォルト戦略を取得するスコープ
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * 指定倉庫のデフォルト戦略を取得
     */
    public static function getDefaultForWarehouse(int $warehouseId): ?self
    {
        return self::forWarehouse($warehouseId)
            ->active()
            ->default()
            ->first();
    }

    /**
     * パラメータを取得（キー指定可）
     */
    public function getParameter(string $key, mixed $default = null): mixed
    {
        return data_get($this->parameters, $key, $default);
    }
}
