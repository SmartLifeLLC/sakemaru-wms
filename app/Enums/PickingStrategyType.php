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
            self::EQUAL => '均等割り当て',
            self::SKILL_BASED => 'スキルレベル考慮',
            self::ZONE_PRIORITY => 'エリア優先',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::EQUAL => 'gray',
            self::SKILL_BASED => 'info',
            self::ZONE_PRIORITY => 'warning',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::EQUAL => 'タスクを作業者に均等に配分します',
            self::SKILL_BASED => '作業者のスキルレベルに応じてタスクを配分します',
            self::ZONE_PRIORITY => '特定のエリア（温度帯など）を優先して割り当てます',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            fn (self $case) => [$case->value => $case->getLabel()]
        )->toArray();
    }
}
