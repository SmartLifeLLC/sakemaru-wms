<?php

namespace App\Enums\AutoOrder;

enum SettlementStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '確定待ち',
            self::CONFIRMED => '確定済み',
            self::CANCELLED => 'キャンセル',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::CONFIRMED => 'success',
            self::CANCELLED => 'gray',
        };
    }
}
