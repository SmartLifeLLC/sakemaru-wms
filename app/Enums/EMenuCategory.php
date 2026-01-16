<?php

namespace App\Enums;

enum EMenuCategory: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
    case SHORTAGE = 'shortage';
    case HORIZONTAL_SHIPMENT = 'horizontal_shipment';
    case AUTO_ORDER = 'auto_order';
    case INVENTORY = 'inventory';
    case MASTER_WAREHOUSE = 'master_warehouse';
    case MASTER_ORDER = 'master_order';
    case MASTER_PICKING = 'master_picking';
    case STATISTICS = 'statistics';
    case SETTINGS = 'settings';
    case LOGS = 'logs';
    case TEST_DATA = 'test_data';

    public function label(): string
    {
        return match ($this) {
            self::INBOUND => '入荷管理',
            self::OUTBOUND => '出荷管理',
            self::SHORTAGE => '欠品管理',
            self::HORIZONTAL_SHIPMENT => '倉庫移動',
            self::AUTO_ORDER => '自動発注',
            self::INVENTORY => '在庫管理',
            self::MASTER_WAREHOUSE => '倉庫マスタ',
            self::MASTER_ORDER => '発注マスタ',
            self::MASTER_PICKING => 'ピッキングマスタ',
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
            self::SHORTAGE => 'heroicon-o-exclamation-triangle',
            self::HORIZONTAL_SHIPMENT => 'heroicon-o-truck',
            self::AUTO_ORDER => 'heroicon-o-clipboard-document-check',
            self::INVENTORY => 'heroicon-o-cube',
            self::MASTER_WAREHOUSE => 'heroicon-o-building-office-2',
            self::MASTER_ORDER => 'heroicon-o-shopping-cart',
            self::MASTER_PICKING => 'heroicon-o-user-group',
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
            self::SHORTAGE => 3,
            self::HORIZONTAL_SHIPMENT => 4,
            self::AUTO_ORDER => 5,
            self::INVENTORY => 6,
            self::MASTER_WAREHOUSE => 7,
            self::MASTER_ORDER => 8,
            self::MASTER_PICKING => 9,
            self::STATISTICS => 10,
            self::LOGS => 97,
            self::SETTINGS => 98,
            self::TEST_DATA => 99, // Last
        };
    }
}
