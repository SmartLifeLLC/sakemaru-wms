<?php

namespace App\Enums\AutoOrder;

enum ConfirmationLevel: string
{
    case STATUS1 = 'STATUS1';
    case STATUS2 = 'STATUS2';
    case STATUS3 = 'STATUS3';

    public function label(): string
    {
        return match ($this) {
            self::STATUS1 => '候補表示',
            self::STATUS2 => '承認まで',
            self::STATUS3 => '確定まで',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::STATUS1 => 'gray',
            self::STATUS2 => 'warning',
            self::STATUS3 => 'success',
        };
    }
}
