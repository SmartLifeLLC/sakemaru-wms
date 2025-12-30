<?php

namespace App\Enums\AutoOrder;

enum CalculationType: string
{
    case INTERNAL = 'INTERNAL';
    case EXTERNAL = 'EXTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::INTERNAL => '倉庫間移動',
            self::EXTERNAL => '外部発注',
        };
    }
}
