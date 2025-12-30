<?php

namespace App\Models\Sakemaru;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\WmsContractorSetting;
use App\Models\WmsContractorSupplier;
use App\Models\WmsContractorWarehouseDeliveryDay;
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

    // ==================== WMS Contractor Setting ====================

    /**
     * この発注先が倉庫間移動（INTERNAL）かどうか
     */
    public function isInternalWarehouse(): bool
    {
        return $this->wmsSetting?->transmission_type === TransmissionType::INTERNAL;
    }

    /**
     * INTERNAL（倉庫間移動）の場合、供給元倉庫を取得
     */
    public function getSupplyWarehouse(): ?Warehouse
    {
        return $this->wmsSetting?->supplyWarehouse;
    }

    /**
     * INTERNAL（倉庫間移動）の場合、供給元倉庫IDを取得
     */
    public function getSupplyWarehouseId(): ?int
    {
        return $this->wmsSetting?->supply_warehouse_id;
    }

    /**
     * WMS送信設定（1:1）
     */
    public function wmsSetting(): HasOne
    {
        return $this->hasOne(WmsContractorSetting::class);
    }

    /**
     * WMS送信設定を取得（なければ作成）
     */
    public function getOrCreateWmsSetting(): WmsContractorSetting
    {
        return WmsContractorSetting::findOrCreateByContractor($this->id);
    }

    // ==================== WMS Contractor Suppliers ====================

    /**
     * 発注先に紐づく仕入先一覧
     */
    public function contractorSuppliers(): HasMany
    {
        return $this->hasMany(WmsContractorSupplier::class, 'contractor_id');
    }

    // ==================== WMS Contractor Warehouse Delivery Days ====================

    /**
     * 発注先×倉庫ごとの納品可能曜日設定
     */
    public function warehouseDeliveryDays(): HasMany
    {
        return $this->hasMany(WmsContractorWarehouseDeliveryDay::class, 'contractor_id');
    }
}
