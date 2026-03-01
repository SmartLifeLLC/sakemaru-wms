<?php

namespace App\Enums\AutoOrder;

enum OriginType: string
{
    case AUTO = 'AUTO';
    case USER = 'USER';
    case DIST = 'DIST';

    public function label(): string
    {
        return match ($this) {
            self::AUTO => '自動発注',
            self::USER => '担当生成',
            self::DIST => '分配依頼',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AUTO => 'primary',
            self::USER => 'success',
            self::DIST => 'warning',
        };
    }
}
