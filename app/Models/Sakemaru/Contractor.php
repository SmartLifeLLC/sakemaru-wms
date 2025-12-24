<?php

namespace App\Models\Sakemaru;

use App\Models\WmsContractorSupplier;
use App\Models\WmsContractorWarehouseMapping;
use App\Models\WmsWarehouseContractorSetting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contractor extends CustomModel
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function leadTime(): BelongsTo
    {
        return $this->belongsTo(LeadTime::class);
    }

    public function warehouse_contractors(): HasMany
    {
        return $this->hasMany(WarehouseContractor::class, 'contractor_id', 'id');
    }

    // ==================== WMS Warehouse Mapping ====================

    /**
     * 倉庫マッピング（この発注先が内部倉庫を表す場合）
     */
    public function warehouseMapping(): HasOne
    {
        return $this->hasOne(WmsContractorWarehouseMapping::class);
    }

    /**
     * この発注先が内部倉庫かどうか
     */
    public function isInternalWarehouse(): bool
    {
        return WmsContractorWarehouseMapping::isInternalContractor($this->id);
    }

    /**
     * 内部倉庫の場合、対応する倉庫を取得
     */
    public function getMappedWarehouse(): ?Warehouse
    {
        $warehouseId = WmsContractorWarehouseMapping::getWarehouseId($this->id);

        return $warehouseId ? Warehouse::find($warehouseId) : null;
    }

    /**
     * 内部倉庫の場合、対応する倉庫IDを取得
     */
    public function getMappedWarehouseId(): ?int
    {
        return WmsContractorWarehouseMapping::getWarehouseId($this->id);
    }

    // ==================== WMS Transmission Settings ====================

    /**
     * 倉庫別の送信設定
     */
    public function warehouseContractorSettings(): HasMany
    {
        return $this->hasMany(WmsWarehouseContractorSetting::class, 'contractor_id');
    }

    // ==================== WMS Contractor Suppliers ====================

    /**
     * 発注先に紐づく仕入先一覧
     */
    public function contractorSuppliers(): HasMany
    {
        return $this->hasMany(WmsContractorSupplier::class, 'contractor_id');
    }
}
