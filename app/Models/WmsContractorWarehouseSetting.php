<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注先×倉庫ごとの設定
 *
 * 発注先が倉庫ごとに指定する納入先指定コード等を管理する。
 */
class WmsContractorWarehouseSetting extends WmsModel
{
    protected $table = 'wms_contractor_warehouse_settings';

    protected $fillable = [
        'warehouse_id',
        'contractor_id',
        'designated_code',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * 倉庫×発注先の納入先指定コードを取得
     */
    public static function getDesignatedCode(int $warehouseId, int $contractorId): ?string
    {
        return self::where('warehouse_id', $warehouseId)
            ->where('contractor_id', $contractorId)
            ->value('designated_code');
    }
}
