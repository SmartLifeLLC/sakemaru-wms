<?php

namespace App\Enums\AutoOrder;

enum CandidateStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case CONFIRMED = 'CONFIRMED';
    case EXCLUDED = 'EXCLUDED';
    case EXECUTED = 'EXECUTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => '承認前',
            self::APPROVED => '承認済',
            self::CONFIRMED => '発注確定',
            self::EXCLUDED => '除外',
            self::EXECUTED => '送信済',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::APPROVED => 'success',
            self::CONFIRMED => 'info',
            self::EXCLUDED => 'danger',
            self::EXECUTED => 'primary',
        };
    }

    /**
     * 編集可能かどうか
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::PENDING, self::APPROVED, self::CONFIRMED => true,
            self::EXCLUDED, self::EXECUTED => false,
        };
    }

    /**
     * 発注確定可能かどうか
     */
    public function canConfirm(): bool
    {
        return match ($this) {
            self::APPROVED, self::CONFIRMED => true,
            default => false,
        };
    }
}
