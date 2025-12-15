<?php

namespace App\Models;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫×発注先の接続設定
 */
class WmsWarehouseContractorSetting extends WmsModel
{
    protected $table = 'wms_warehouse_contractor_settings';

    protected $fillable = [
        'warehouse_id',
        'contractor_id',
        'transmission_type',
        'wms_order_jx_setting_id',
        'wms_order_ftp_setting_id',
        'format_strategy_class',
        'is_active',
    ];

    protected $casts = [
        'transmission_type' => TransmissionType::class,
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function jxSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxSetting::class, 'wms_order_jx_setting_id');
    }

    public function ftpSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderFtpSetting::class, 'wms_order_ftp_setting_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
