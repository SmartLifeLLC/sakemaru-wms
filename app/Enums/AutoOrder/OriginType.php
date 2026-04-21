<?php

namespace App\Enums\AutoOrder;

enum OriginType: string
{
    case AUTO_SAFETY_STOCK = 'AUTO_SAFETY_STOCK';
    case AUTO_SALES_BASED = 'AUTO_SALES_BASED';
    case MANUAL_SAFETY_STOCK = 'MANUAL_SAFETY_STOCK';
    case MANUAL_SALES_BASED = 'MANUAL_SALES_BASED';
    case USER = 'USER';
    case DIST = 'DIST';

    public function label(): string
    {
        return match ($this) {
            self::AUTO_SAFETY_STOCK => '自動発注（安全在庫）',
            self::AUTO_SALES_BASED => '自動発注（実績）',
            self::MANUAL_SAFETY_STOCK => '手動発注（安全在庫）',
            self::MANUAL_SALES_BASED => '手動発注（実績）',
            self::USER => '担当生成',
            self::DIST => '分配依頼',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AUTO_SAFETY_STOCK => 'primary',
            self::AUTO_SALES_BASED => 'info',
            self::MANUAL_SAFETY_STOCK => 'success',
            self::MANUAL_SALES_BASED => 'warning',
            self::USER => 'success',
            self::DIST => 'warning',
        };
    }
}
