<?php

namespace App\Enums;

enum EMenuCategory: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
    case INVENTORY = 'inventory';
    case MASTER = 'master';
    case STATISTICS = 'statistics';
    case SETTINGS = 'settings';
    case LOGS = 'logs';
    case TEST_DATA = 'test_data';

    public function label(): string
    {
        return match ($this) {
            self::INBOUND => '入荷管理',
            self::OUTBOUND => '出荷管理',
            self::INVENTORY => '在庫管理',
            self::MASTER => 'マスタ管理',
            self::STATISTICS => '統計データ',
            self::SETTINGS => 'システム設定',
            self::LOGS => 'ログ',
            self::TEST_DATA => 'テストデータ',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::INBOUND => 'heroicon-o-arrow-down-tray',
            self::OUTBOUND => 'heroicon-o-arrow-up-tray',
            self::INVENTORY => 'heroicon-o-cube',
            self::MASTER => 'heroicon-o-document-text',
            self::STATISTICS => 'heroicon-o-chart-bar',
            self::SETTINGS => 'heroicon-o-cog-6-tooth',
            self::LOGS => 'heroicon-o-document-magnifying-glass',
            self::TEST_DATA => 'heroicon-o-beaker',
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::INBOUND => 1,
            self::OUTBOUND => 2,
            self::INVENTORY => 3,
            self::MASTER => 4,
            self::STATISTICS => 5,
            self::LOGS => 97,
            self::SETTINGS => 98,
            self::TEST_DATA => 99, // Last
        };
    }
}
