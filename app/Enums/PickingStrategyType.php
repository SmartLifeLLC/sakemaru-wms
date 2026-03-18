<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PickingStrategyType: string implements HasColor, HasLabel
{
    case EQUAL = 'equal';
    case SKILL_BASED = 'skill_based';
    case ZONE_PRIORITY = 'zone_priority';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::EQUAL => '商品数均等割り当て',
            self::SKILL_BASED => 'スキルレベル考慮割り当て',
            self::ZONE_PRIORITY => 'ゾーン優先割り当て',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::EQUAL => 'info',
            self::SKILL_BASED => 'warning',
            self::ZONE_PRIORITY => 'success',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::EQUAL => '商品数ベースで均等に配分します。配送コース単位でまとめて割り当てます。',
            self::SKILL_BASED => 'スキルレベルに応じて商品数の割り当て比率を調整します。',
            self::ZONE_PRIORITY => 'ゾーン（エリア）を優先して割り当てます。',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            fn (self $case) => [$case->value => $case->getLabel()]
        )->toArray();
    }

    /**
     * デフォルトのスキルレート（SKILL_BASED戦略用）
     * PickerSkillLevel Enum の rate() から導出
     */
    public static function defaultSkillRates(): array
    {
        return PickerSkillLevel::rateMap();
    }
}
