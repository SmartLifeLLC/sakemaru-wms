<?php

namespace App\Enums\AutoOrder;

enum IncomingScheduleStatus: string
{
    case PENDING = 'PENDING';
    case PARTIAL = 'PARTIAL';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '未入庫',
            self::PARTIAL => '一部入庫',
            self::CONFIRMED => '入庫完了',
            self::CANCELLED => 'キャンセル',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::PARTIAL => 'info',
            self::CONFIRMED => 'success',
            self::CANCELLED => 'danger',
        };
    }
}
