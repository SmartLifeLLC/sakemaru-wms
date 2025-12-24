<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注先-倉庫マッピング
 *
 * 発注先が内部倉庫を表す場合のマッピングを管理。
 * - マッピングが存在する → 内部倉庫（INTERNAL供給）
 * - マッピングが存在しない → 外部発注先（EXTERNAL供給）
 *
 * @property int $id
 * @property int $contractor_id
 * @property int $warehouse_id
 * @property string|null $memo
 */
class WmsContractorWarehouseMapping extends WmsModel
{
    protected $table = 'wms_contractor_warehouse_mappings';

    protected $fillable = [
        'contractor_id',
        'warehouse_id',
        'memo',
    ];

    // ==================== Relations ====================

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ==================== Static Helpers ====================

    /**
     * 発注先が内部倉庫かどうかを判定
     */
    public static function isInternalContractor(int $contractorId): bool
    {
        return self::where('contractor_id', $contractorId)->exists();
    }

    /**
     * 発注先に対応する倉庫IDを取得（内部倉庫の場合）
     */
    public static function getWarehouseId(int $contractorId): ?int
    {
        return self::where('contractor_id', $contractorId)->value('warehouse_id');
    }

    /**
     * 倉庫に対応する発注先IDを取得
     */
    public static function getContractorId(int $warehouseId): ?int
    {
        return self::where('warehouse_id', $warehouseId)->value('contractor_id');
    }

    /**
     * 全マッピングをキャッシュ付きで取得
     * [contractor_id => warehouse_id]
     */
    public static function getAllMappings(): array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = self::pluck('warehouse_id', 'contractor_id')->toArray();
        }

        return $cache;
    }

    /**
     * キャッシュをクリア
     */
    public static function clearCache(): void
    {
        // staticキャッシュのクリアは再起動が必要
        // 必要に応じてRedisキャッシュなどを使用
    }
}
