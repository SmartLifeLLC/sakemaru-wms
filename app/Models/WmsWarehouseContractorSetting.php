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
        'transmission_time',
        'transmission_days',
        'is_auto_transmission',
        'is_active',
    ];

    protected $casts = [
        'transmission_type' => TransmissionType::class,
        'transmission_days' => 'array',
        'is_auto_transmission' => 'boolean',
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

    /**
     * 送信曜日を日本語で取得
     */
    public function getTransmissionDaysLabelAttribute(): string
    {
        if (empty($this->transmission_days)) {
            return '-';
        }

        $dayLabels = ['日', '月', '火', '水', '木', '金', '土'];

        return collect($this->transmission_days)
            ->sort()
            ->map(fn ($day) => $dayLabels[$day] ?? '')
            ->filter()
            ->implode('・');
    }
}
