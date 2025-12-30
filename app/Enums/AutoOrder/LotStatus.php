<?php

namespace App\Enums\AutoOrder;

enum LotStatus: string
{
    case RAW = 'RAW';
    case ADJUSTED = 'ADJUSTED';
    case BLOCKED = 'BLOCKED';
    case NEED_APPROVAL = 'NEED_APPROVAL';

    public function label(): string
    {
        return match ($this) {
            self::RAW => '未適用',
            self::ADJUSTED => '調整済',
            self::BLOCKED => 'ブロック',
            self::NEED_APPROVAL => '要承認',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::RAW => 'gray',
            self::ADJUSTED => 'warning',
            self::BLOCKED => 'danger',
            self::NEED_APPROVAL => 'info',
        };
    }
}
