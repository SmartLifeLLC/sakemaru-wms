<?php

namespace App\Enums;

enum EMenu: string
{
    // 入荷管理
    case INBOUND_DASHBOARD = 'inbound.dashboard';
    case PURCHASES = 'inbound.purchases';
    case RECEIPT_INSPECTIONS = 'inbound.receipt_inspections';

    // 出荷管理
    case OUTBOUND_DASHBOARD = 'outbound.dashboard';
    case WAVES = 'outbound.waves';
    case PICKING_TASKS = 'outbound.picking_tasks';
    case SHIPMENT_INSPECTIONS = 'outbound.shipment_inspections';

    // 在庫管理
    case REAL_STOCKS = 'inventory.real_stocks';

    // マスタ管理
    case WAREHOUSES = 'master.warehouses';
    case WAREHOUSE_CONTRACTORS = 'master.warehouse_contractors';
    case LOCATIONS = 'master.locations';
    case WMS_LOCATIONS = 'master.wms_locations';
    case WMS_PICKING_AREAS = 'master.wms_picking_areas';
    case WMS_PICKERS = 'master.wms_pickers';

    // 統計データ
    case EARNINGS = 'statistics.earnings';

    // システム設定
    case WAVE_SETTINGS = 'settings.wave_settings';

    // テストデータ
    case TEST_DATA_GENERATOR = 'test_data.generator';

    public function category(): EMenuCategory
    {
        return match ($this) {
            self::INBOUND_DASHBOARD,
            self::PURCHASES,
            self::RECEIPT_INSPECTIONS => EMenuCategory::INBOUND,

            self::OUTBOUND_DASHBOARD,
            self::WAVES,
            self::PICKING_TASKS,
            self::SHIPMENT_INSPECTIONS => EMenuCategory::OUTBOUND,

            self::REAL_STOCKS => EMenuCategory::INVENTORY,

            self::WAREHOUSES,
            self::WAREHOUSE_CONTRACTORS,
            self::LOCATIONS,
            self::WMS_LOCATIONS,
            self::WMS_PICKING_AREAS,
            self::WAVE_SETTINGS,
            self::WMS_PICKERS => EMenuCategory::MASTER,

            self::EARNINGS => EMenuCategory::STATISTICS,

            self::TEST_DATA_GENERATOR => EMenuCategory::TEST_DATA,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::INBOUND_DASHBOARD => '入荷ダッシュボード',
            self::PURCHASES => '発注データ',
            self::RECEIPT_INSPECTIONS => '入荷検品',

            self::OUTBOUND_DASHBOARD => '出荷ダッシュボード',
            self::WAVES => 'Wave管理',
            self::PICKING_TASKS => 'ピッキングタスク',
            self::SHIPMENT_INSPECTIONS => '出荷検品',

            self::REAL_STOCKS => '在庫管理',

            self::WAREHOUSES => '倉庫',
            self::WAREHOUSE_CONTRACTORS => '倉庫業者',
            self::LOCATIONS => 'ロケーション',
            self::WMS_LOCATIONS => 'WMSロケーション',
            self::WMS_PICKING_AREAS => 'ピッキングエリア',
            self::WMS_PICKERS => 'ピッカー',

            self::EARNINGS => '売上データ',

            self::WAVE_SETTINGS => 'Wave設定',

            self::TEST_DATA_GENERATOR => 'テストデータ生成',
        };
    }

    public function icon(): ?string
    {
        return match ($this) {
            self::INBOUND_DASHBOARD => 'heroicon-o-presentation-chart-line',
            self::PURCHASES => 'heroicon-o-shopping-cart',
            self::RECEIPT_INSPECTIONS => 'heroicon-o-clipboard-document-check',

            self::OUTBOUND_DASHBOARD => 'heroicon-o-presentation-chart-bar',
            self::WAVES => 'heroicon-o-queue-list',
            self::PICKING_TASKS => 'heroicon-o-clipboard-document-list',
            self::SHIPMENT_INSPECTIONS => 'heroicon-o-check-circle',

            self::REAL_STOCKS => 'heroicon-o-cube-transparent',

            self::WAREHOUSES => 'heroicon-o-building-office-2',
            self::WAREHOUSE_CONTRACTORS => 'heroicon-o-building-storefront',
            self::LOCATIONS => 'heroicon-o-map-pin',
            self::WMS_LOCATIONS => 'heroicon-o-squares-2x2',
            self::WMS_PICKING_AREAS => 'heroicon-o-squares-plus',
            self::WMS_PICKERS => 'heroicon-o-user-group',

            self::EARNINGS => 'heroicon-o-currency-yen',

            self::WAVE_SETTINGS => 'heroicon-o-adjustments-horizontal',

            self::TEST_DATA_GENERATOR => 'heroicon-o-beaker',
        };
    }

    public function sort(): int
    {
        return match ($this) {
            // 入荷管理
            self::INBOUND_DASHBOARD => 1,
            self::PURCHASES => 2,
            self::RECEIPT_INSPECTIONS => 3,

            // 出荷管理
            self::OUTBOUND_DASHBOARD => 1,
            self::WAVES => 2,
            self::PICKING_TASKS => 3,
            self::SHIPMENT_INSPECTIONS => 4,

            // 在庫管理
            self::REAL_STOCKS => 1,

            // マスタ管理
            self::WAREHOUSES => 1,
            self::WAREHOUSE_CONTRACTORS => 2,
            self::LOCATIONS => 3,
            self::WMS_LOCATIONS => 4,
            self::WMS_PICKING_AREAS => 5,
            self::WMS_PICKERS => 6,

            // 統計データ
            self::EARNINGS => 1,

            // システム設定
            self::WAVE_SETTINGS => 1,

            // テストデータ
            self::TEST_DATA_GENERATOR => 1,
        };
    }
}
