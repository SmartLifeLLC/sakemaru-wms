<?php

namespace App\Enums\AutoOrder;

enum RuleExceptionTargetType: string
{
    case ITEM = 'ITEM';
    case CATEGORY = 'CATEGORY';
    case TEMPERATURE = 'TEMPERATURE';
    case BRAND = 'BRAND';

    public function label(): string
    {
        return match ($this) {
            self::ITEM => '商品',
            self::CATEGORY => 'カテゴリ',
            self::TEMPERATURE => '温度帯',
            self::BRAND => 'ブランド',
        };
    }
}
