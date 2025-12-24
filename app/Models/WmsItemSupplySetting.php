<?php

namespace App\Models;

use App\Enums\AutoOrder\SupplyType;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\ItemContractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 商品別供給設定（Multi-Echelon対応）
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $item_id
 * @property SupplyType $supply_type
 * @property int|null $source_warehouse_id
 * @property int|null $item_contractor_id
 * @property int $lead_time_days
 * @property int $daily_consumption_qty
 * @property int $hierarchy_level
 * @property bool $is_enabled
 */
class WmsItemSupplySetting extends WmsModel
{
    protected $table = 'wms_item_supply_settings';

    protected $fillable = [
        'warehouse_id',
        'item_id',
        'supply_type',
        'source_warehouse_id',
        'item_contractor_id',
        'lead_time_days',
        'daily_consumption_qty',
        'hierarchy_level',
        'is_enabled',
    ];

    protected $casts = [
        'supply_type' => SupplyType::class,
        'is_enabled' => 'boolean',
    ];

    // ==================== Relations ====================

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function itemContractor(): BelongsTo
    {
        return $this->belongsTo(ItemContractor::class);
    }

    // ==================== Scopes ====================

    /**
     * 有効な設定のみ
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * 内部移動（倉庫間）設定のみ
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('supply_type', SupplyType::INTERNAL);
    }

    /**
     * 外部発注設定のみ
     */
    public function scopeExternal(Builder $query): Builder
    {
        return $query->where('supply_type', SupplyType::EXTERNAL);
    }

    /**
     * 特定の階層レベル
     */
    public function scopeAtLevel(Builder $query, int $level): Builder
    {
        return $query->where('hierarchy_level', $level);
    }

    /**
     * 特定の倉庫の設定
     */
    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * 特定の供給元倉庫への移動設定
     */
    public function scopeSuppliedBy(Builder $query, int $sourceWarehouseId): Builder
    {
        return $query->where('supply_type', SupplyType::INTERNAL)
            ->where('source_warehouse_id', $sourceWarehouseId);
    }

    // ==================== Helpers ====================

    /**
     * 安全在庫を取得（item_contractorsから）
     */
    public function getSafetyStock(): int
    {
        // EXTERNALの場合はitem_contractor経由で取得
        if ($this->isExternal() && $this->itemContractor) {
            return $this->itemContractor->safety_stock ?? 0;
        }

        // INTERNALの場合はwarehouse_id + item_idで直接取得
        $itemContractor = ItemContractor::where('warehouse_id', $this->warehouse_id)
            ->where('item_id', $this->item_id)
            ->first();

        return $itemContractor?->safety_stock ?? 0;
    }

    /**
     * リードタイム中の消費予測数を計算
     */
    public function getConsumptionDuringLeadTime(): int
    {
        return $this->daily_consumption_qty * $this->lead_time_days;
    }

    /**
     * 内部移動設定かどうか
     */
    public function isInternal(): bool
    {
        return $this->supply_type === SupplyType::INTERNAL;
    }

    /**
     * 外部発注設定かどうか
     */
    public function isExternal(): bool
    {
        return $this->supply_type === SupplyType::EXTERNAL;
    }

    // ==================== 階層レベル自動計算 ====================

    /**
     * 供給ネットワーク全体の階層レベルを再計算
     */
    public static function recalculateHierarchyLevels(): void
    {
        // 外部発注設定を最上位として階層計算
        $allSettings = self::enabled()->get()->keyBy(function ($s) {
            return "{$s->warehouse_id}_{$s->item_id}";
        });

        $visited = [];
        $levels = [];

        foreach ($allSettings as $key => $setting) {
            self::calculateLevel($setting, $allSettings, $visited, $levels);
        }

        // 一括更新
        foreach ($levels as $key => $level) {
            [$warehouseId, $itemId] = explode('_', $key);
            self::where('warehouse_id', $warehouseId)
                ->where('item_id', $itemId)
                ->update(['hierarchy_level' => $level]);
        }
    }

    /**
     * 再帰的に階層レベルを計算
     */
    private static function calculateLevel(
        self $setting,
        $allSettings,
        array &$visited,
        array &$levels
    ): int {
        $key = "{$setting->warehouse_id}_{$setting->item_id}";

        // 循環検出
        if (isset($visited[$key]) && $visited[$key] === 'processing') {
            throw new \RuntimeException("Circular reference detected: {$key}");
        }

        // 既に計算済み
        if (isset($levels[$key])) {
            return $levels[$key];
        }

        $visited[$key] = 'processing';

        if ($setting->isExternal()) {
            // 外部発注は最上位
            $levels[$key] = self::getMaxHierarchyLevel($allSettings);
        } else {
            // 内部移動: 供給元の階層 + 1
            $sourceKey = "{$setting->source_warehouse_id}_{$setting->item_id}";
            if (isset($allSettings[$sourceKey])) {
                $sourceLevel = self::calculateLevel($allSettings[$sourceKey], $allSettings, $visited, $levels);
                $levels[$key] = max(0, $sourceLevel - 1);
            } else {
                // 供給元設定がない場合は0
                $levels[$key] = 0;
            }
        }

        $visited[$key] = 'done';
        return $levels[$key];
    }

    /**
     * 最大階層レベルを取得
     */
    private static function getMaxHierarchyLevel($allSettings): int
    {
        $maxDepth = 0;
        foreach ($allSettings as $setting) {
            if ($setting->isInternal()) {
                $maxDepth = max($maxDepth, 1);
            }
        }
        return $maxDepth;
    }
}
