<?php

namespace App\Enums;

enum TemperatureType: string
{
    case NORMAL = 'NORMAL';      // 常温
    case CONSTANT = 'CONSTANT';  // 定温
    case CHILLED = 'CHILLED';    // 冷蔵
    case FROZEN = 'FROZEN';      // 冷凍

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => '常温',
            self::CONSTANT => '定温',
            self::CHILLED => '冷蔵',
            self::FROZEN => '冷凍',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NORMAL => 'gray',
            self::CONSTANT => 'warning',
            self::CHILLED => 'info',
            self::FROZEN => 'primary',
        };
    }

    /**
     * Get all temperature types as options for select fields
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
