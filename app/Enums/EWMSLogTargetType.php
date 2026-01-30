<?php

namespace App\Enums;

enum EWMSLogTargetType: string
{
    case PICKING_TASK = 'picking_task'; // ピッキングタスク
    case PICKING_ITEM = 'picking_item'; // ピッキング明細
    case WAVE = 'wave'; // Wave
    case EARNING = 'earning'; // 伝票

    /**
     * 対象タイプのラベルを取得
     */
    public function label(): string
    {
        return match ($this) {
            self::PICKING_TASK => 'ピッキングタスク',
            self::PICKING_ITEM => 'ピッキング明細',
            self::WAVE => 'Wave',
            self::EARNING => '伝票',
        };
    }

    /**
     * 全ての対象タイプの選択肢を取得
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
