<?php

namespace App\Enums\AutoOrder;

use Filament\Support\Contracts\HasLabel;

enum SupplyType: string implements HasLabel
{
    case INTERNAL = 'INTERNAL';
    case EXTERNAL = 'EXTERNAL';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::INTERNAL => '内部移動',
            self::EXTERNAL => '外部発注',
        };
    }

    /**
     * 内部移動（倉庫間移動）かどうか
     */
    public function isInternal(): bool
    {
        return $this === self::INTERNAL;
    }

    /**
     * 外部発注（仕入先への発注）かどうか
     */
    public function isExternal(): bool
    {
        return $this === self::EXTERNAL;
    }
}
