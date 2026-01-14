<?php

namespace App\Models;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注先設定（WMS側で管理）
 *
 * sakemaru本家のcontractorsテーブルは変更せず、
 * WMS固有の送信設定をこのテーブルで管理する。
 */
class WmsContractorSetting extends WmsModel
{
    protected $table = 'wms_contractor_settings';

    protected $fillable = [
        'contractor_id',
        'transmission_type',
        'wms_order_jx_setting_id',
        'wms_order_ftp_setting_id',
        'supply_warehouse_id',
        'format_strategy_class',
        'transmission_time',
        'is_transmission_sun',
        'is_transmission_mon',
        'is_transmission_tue',
        'is_transmission_wed',
        'is_transmission_thu',
        'is_transmission_fri',
        'is_transmission_sat',
        'is_auto_transmission',
    ];

    protected $casts = [
        'transmission_type' => TransmissionType::class,
        'is_transmission_sun' => 'boolean',
        'is_transmission_mon' => 'boolean',
        'is_transmission_tue' => 'boolean',
        'is_transmission_wed' => 'boolean',
        'is_transmission_thu' => 'boolean',
        'is_transmission_fri' => 'boolean',
        'is_transmission_sat' => 'boolean',
        'is_auto_transmission' => 'boolean',
    ];

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

    public function supplyWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'supply_warehouse_id');
    }

    /**
     * 送信曜日を日本語で取得
     */
    public function getTransmissionDaysLabelAttribute(): string
    {
        $days = [];
        if ($this->is_transmission_sun) {
            $days[] = '日';
        }
        if ($this->is_transmission_mon) {
            $days[] = '月';
        }
        if ($this->is_transmission_tue) {
            $days[] = '火';
        }
        if ($this->is_transmission_wed) {
            $days[] = '水';
        }
        if ($this->is_transmission_thu) {
            $days[] = '木';
        }
        if ($this->is_transmission_fri) {
            $days[] = '金';
        }
        if ($this->is_transmission_sat) {
            $days[] = '土';
        }

        return empty($days) ? '-' : implode('・', $days);
    }

    /**
     * 指定された曜日に送信するかどうか
     *
     * @param  int  $dayOfWeek  0=日, 1=月, ..., 6=土
     */
    public function shouldTransmitOn(int $dayOfWeek): bool
    {
        return match ($dayOfWeek) {
            0 => $this->is_transmission_sun,
            1 => $this->is_transmission_mon,
            2 => $this->is_transmission_tue,
            3 => $this->is_transmission_wed,
            4 => $this->is_transmission_thu,
            5 => $this->is_transmission_fri,
            6 => $this->is_transmission_sat,
            default => false,
        };
    }

    /**
     * 発注先IDから設定を取得（なければ作成）
     */
    public static function findOrCreateByContractor(int $contractorId): self
    {
        return self::firstOrCreate(
            ['contractor_id' => $contractorId],
            ['transmission_type' => TransmissionType::MANUAL_CSV]
        );
    }

    /**
     * 発注先が倉庫間移動（INTERNAL）かどうかを判定
     */
    public static function isInternalContractor(int $contractorId): bool
    {
        $setting = self::where('contractor_id', $contractorId)->first();

        return $setting?->transmission_type === TransmissionType::INTERNAL;
    }

    /**
     * 発注先に対応する供給倉庫IDを取得（INTERNAL時）
     */
    public static function getSupplyWarehouseId(int $contractorId): ?int
    {
        return self::where('contractor_id', $contractorId)
            ->where('transmission_type', TransmissionType::INTERNAL)
            ->value('supply_warehouse_id');
    }

    /**
     * 全INTERNAL発注先のマッピングを取得
     * [contractor_id => supply_warehouse_id]
     */
    public static function getAllInternalMappings(): array
    {
        return self::where('transmission_type', TransmissionType::INTERNAL)
            ->whereNotNull('supply_warehouse_id')
            ->pluck('supply_warehouse_id', 'contractor_id')
            ->toArray();
    }
}
