<?php

namespace App\Models;

/**
 * 自動発注グローバル設定
 *
 * @property int $id
 * @property string $calc_logic_type
 * @property string $satellite_calc_time
 * @property string $hub_calc_time
 * @property string $execution_time
 * @property bool $is_auto_execution_enabled
 */
class WmsAutoOrderSetting extends WmsModel
{
    protected $table = 'wms_auto_order_settings';

    protected $fillable = [
        'calc_logic_type',
        'satellite_calc_time',
        'hub_calc_time',
        'execution_time',
        'is_auto_execution_enabled',
    ];

    protected $casts = [
        'is_auto_execution_enabled' => 'boolean',
    ];

    /**
     * 設定を取得（存在しなければ作成）
     */
    public static function getOrCreate(): self
    {
        return self::firstOrCreate([], [
            'calc_logic_type' => 'STANDARD',
            'satellite_calc_time' => '10:00:00',
            'hub_calc_time' => '10:30:00',
            'execution_time' => '12:00:00',
            'is_auto_execution_enabled' => false,
        ]);
    }
}
