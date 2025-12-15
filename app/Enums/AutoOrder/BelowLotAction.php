<?php

namespace App\Enums\AutoOrder;

enum BelowLotAction: string
{
    case ALLOW = 'ALLOW';
    case BLOCK = 'BLOCK';
    case ADD_FEE = 'ADD_FEE';
    case ADD_SHIPPING = 'ADD_SHIPPING';
    case NEED_APPROVAL = 'NEED_APPROVAL';

    public function label(): string
    {
        return match ($this) {
            self::ALLOW => '許可',
            self::BLOCK => 'ブロック',
            self::ADD_FEE => '手数料追加',
            self::ADD_SHIPPING => '送料追加',
            self::NEED_APPROVAL => '承認必要',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ALLOW => 'success',
            self::BLOCK => 'danger',
            self::ADD_FEE => 'warning',
            self::ADD_SHIPPING => 'warning',
            self::NEED_APPROVAL => 'info',
        };
    }
}
