<?php

namespace App\Enums\AutoOrder;

enum CalculationType: string
{
    case SATELLITE = 'SATELLITE';
    case HUB = 'HUB';

    public function label(): string
    {
        return match ($this) {
            self::SATELLITE => 'Satellite計算',
            self::HUB => 'Hub計算',
        };
    }
}
