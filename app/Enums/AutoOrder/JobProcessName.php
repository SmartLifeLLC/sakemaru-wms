<?php

namespace App\Enums\AutoOrder;

enum JobProcessName: string
{
    case STOCK_SNAPSHOT = 'STOCK_SNAPSHOT';
    case SATELLITE_CALC = 'SATELLITE_CALC';
    case HUB_CALC = 'HUB_CALC';
    case ORDER_EXECUTION = 'ORDER_EXECUTION';
    case ORDER_TRANSMISSION = 'ORDER_TRANSMISSION';

    public function label(): string
    {
        return match ($this) {
            self::STOCK_SNAPSHOT => '在庫スナップショット',
            self::SATELLITE_CALC => 'Satellite計算',
            self::HUB_CALC => 'Hub計算',
            self::ORDER_EXECUTION => '発注実行',
            self::ORDER_TRANSMISSION => '発注送信',
        };
    }
}
