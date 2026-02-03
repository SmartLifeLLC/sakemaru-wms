<?php

namespace App\Enums\AutoOrder;

enum OrderSource: string
{
    case AUTO = 'AUTO';
    case MANUAL = 'MANUAL';
    case TRANSFER = 'TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::AUTO => '自動発注',
            self::MANUAL => '手動発注',
            self::TRANSFER => '倉庫間移動',
        };
    }
}
