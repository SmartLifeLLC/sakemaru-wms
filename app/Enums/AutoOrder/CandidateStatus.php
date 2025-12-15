<?php

namespace App\Enums\AutoOrder;

enum CandidateStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case EXCLUDED = 'EXCLUDED';
    case EXECUTED = 'EXECUTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '未承認',
            self::APPROVED => '承認済',
            self::EXCLUDED => '除外',
            self::EXECUTED => '実行済',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::APPROVED => 'success',
            self::EXCLUDED => 'warning',
            self::EXECUTED => 'info',
        };
    }
}
