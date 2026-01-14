<?php

namespace App\Enums;

enum PickerSkillLevel: int
{
    case TRAINEE = 1;
    case JUNIOR = 2;
    case SENIOR = 3;
    case EXPERT = 4;
    case MASTER = 5;

    /**
     * 日本語ラベル（画面表示用）
     */
    public function label(): string
    {
        return match ($this) {
            self::TRAINEE => '研修中',
            self::JUNIOR => '一般',
            self::SENIOR => '熟練',
            self::EXPERT => 'スペシャリスト',
            self::MASTER => '達人',
        };
    }

    /**
     * 説明（ホバー時のツールチップなどに使用）
     */
    public function description(): string
    {
        return match ($this) {
            self::TRAINEE => '補助が必要。難易度の高い作業は不可。',
            self::JUNIOR => '標準的な作業が可能。',
            self::SENIOR => '作業が早く、判断業務も可能。',
            self::EXPERT => 'イレギュラー対応や新人指導が可能。',
            self::MASTER => '全ての業務を統括できるレベル。',
        };
    }

    /**
     * UI表示用のカラー定義
     */
    public function color(): string
    {
        return match ($this) {
            self::TRAINEE => 'gray',
            self::JUNIOR => 'info',
            self::SENIOR => 'success',
            self::EXPERT => 'warning',
            self::MASTER => 'danger',
        };
    }

    /**
     * Get all skill levels as options for select fields
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
