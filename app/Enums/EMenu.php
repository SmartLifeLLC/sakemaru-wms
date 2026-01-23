<?php

namespace App\Enums;

enum EMenu: string
{
    // 入荷管理
    case INBOUND_DASHBOARD = 'inbound.dashboard';
    case WMS_ORDER_INCOMING_SCHEDULES = 'inbound.wms_order_incoming_schedules';
    case WMS_INCOMING_COMPLETED = 'inbound.wms_incoming_completed';
    case WMS_INCOMING_TRANSMITTED = 'inbound.wms_incoming_transmitted';
    case PURCHASES = 'inbound.purchases';
    case RECEIPT_INSPECTIONS = 'inbound.receipt_inspections';

    // 出荷管理
    case OUTBOUND_DASHBOARD = 'outbound.dashboard';
    case WMS_PICKER_ATTENDANCE = 'outbound.wms_picker_attendance';
    case WMS_PICKING_WAITINGS = 'outbound.wms_picking_waitings';
    case PICKING_TASKS = 'outbound.picking_tasks';
    case WAVES = 'outbound.waves';
    case PICKING_ROUTE_VISUALIZATION = 'outbound.picking_route_visualization';
    case WMS_PICKING_ITEM_RESULTS = 'outbound.wms_picking_item_results';
    case DELIVERY_COURSE_CHANGE = 'outbound.delivery_course_change';
    case SHIPMENT_INSPECTIONS = 'outbound.shipment_inspections';
    case WMS_SHIPMENT_SLIPS = 'outbound.wms_shipment_slips';

    // 欠品管理
    case WMS_SHORTAGES = 'shortage.wms_shortages';
    case WMS_SHORTAGES_WAITING_APPROVALS = 'shortage.wms_shortages_waiting_approvals';

    // 倉庫移動
    case WMS_SHORTAGE_ALLOCATIONS = 'horizontal_shipment.wms_shortage_allocations';
    case WMS_STOCK_TRANSFER_CANDIDATES = 'horizontal_shipment.wms_stock_transfer_candidates';

    // 自動発注
    case WMS_ORDER_CANDIDATES = 'auto_order.wms_order_candidates';
    case WMS_ORDER_CONFIRMATION_WAITING = 'auto_order.wms_order_confirmation_waiting';
    case WMS_ORDER_CONFIRMED = 'auto_order.wms_order_confirmed';
    case WMS_AUTO_ORDER_JOBS = 'auto_order.wms_auto_order_jobs';

    // 在庫管理
    case REAL_STOCKS = 'inventory.real_stocks';

    // マスタ管理
    case WAREHOUSES = 'master.warehouses';
    case WAREHOUSE_CONTRACTORS = 'master.warehouse_contractors';
    case CONTRACTORS = 'master.contractors';
    case ITEM_CONTRACTORS = 'master.item_contractors';
    case WMS_MONTHLY_SAFETY_STOCKS = 'master.wms_monthly_safety_stocks';
    case WMS_ITEM_SUPPLY_SETTINGS = 'master.wms_item_supply_settings';
    case WMS_WAREHOUSE_CALENDARS = 'master.wms_warehouse_calendars';
    case WMS_CONTRACTOR_HOLIDAYS = 'master.wms_contractor_holidays';
    case WMS_ORDER_JX_SETTINGS = 'master.wms_order_jx_settings';
    case LOCATIONS = 'master.locations';
    case WMS_LOCATIONS = 'master.wms_locations';
    case WMS_PICKING_AREAS = 'master.wms_picking_areas';
    case WMS_PICKERS = 'master.wms_pickers';
    case WMS_PICKING_ASSIGNMENT_STRATEGIES = 'master.wms_picking_assignment_strategies';
    case FLOOR_PLAN_EDITOR = 'master.floor_plan_editor';

    // 統計データ
    case EARNINGS = 'statistics.earnings';

    // ログ
    case WMS_PICKING_LOGS = 'logs.wms_picking_logs';
    case WMS_JX_TRANSMISSION_LOGS = 'logs.wms_jx_transmission_logs';
    case WMS_IMPORT_LOGS = 'logs.wms_import_logs';

    // システム設定
    case WAVE_SETTINGS = 'settings.wave_settings';
    case CLIENT_PRINTER_COURSE_SETTINGS = 'settings.client_printer_course_settings';

    // テストデータ
    case TEST_DATA_GENERATOR = 'test_data.generator';

    public function category(): EMenuCategory
    {
        return match ($this) {
            self::INBOUND_DASHBOARD,
            self::WMS_ORDER_INCOMING_SCHEDULES,
            self::WMS_INCOMING_COMPLETED,
            self::WMS_INCOMING_TRANSMITTED,
            self::PURCHASES,
            self::RECEIPT_INSPECTIONS => EMenuCategory::INBOUND,

            self::OUTBOUND_DASHBOARD,
            self::WMS_PICKING_WAITINGS,
            self::PICKING_TASKS,
            self::WAVES,
            self::PICKING_ROUTE_VISUALIZATION,
            self::WMS_PICKING_ITEM_RESULTS,
            self::DELIVERY_COURSE_CHANGE,
            self::SHIPMENT_INSPECTIONS,
            self::WMS_SHIPMENT_SLIPS => EMenuCategory::OUTBOUND,

            self::WMS_SHORTAGES,
            self::WMS_SHORTAGES_WAITING_APPROVALS => EMenuCategory::SHORTAGE,

            self::WMS_SHORTAGE_ALLOCATIONS,
            self::WMS_STOCK_TRANSFER_CANDIDATES => EMenuCategory::HORIZONTAL_SHIPMENT,

            self::WMS_ORDER_CANDIDATES,
            self::WMS_ORDER_CONFIRMATION_WAITING,
            self::WMS_ORDER_CONFIRMED,
            self::WMS_AUTO_ORDER_JOBS => EMenuCategory::AUTO_ORDER,

            self::REAL_STOCKS => EMenuCategory::INVENTORY,

            // 倉庫マスタ
            self::WAREHOUSES,
            self::LOCATIONS,
            self::WMS_LOCATIONS,
            self::WMS_PICKING_AREAS,
            self::FLOOR_PLAN_EDITOR => EMenuCategory::MASTER_WAREHOUSE,

            // 発注マスタ
            self::WAREHOUSE_CONTRACTORS,
            self::CONTRACTORS,
            self::ITEM_CONTRACTORS,
            self::WMS_MONTHLY_SAFETY_STOCKS,
            self::WMS_ITEM_SUPPLY_SETTINGS,
            self::WMS_WAREHOUSE_CALENDARS,
            self::WMS_CONTRACTOR_HOLIDAYS,
            self::WMS_ORDER_JX_SETTINGS => EMenuCategory::MASTER_ORDER,

            // ピッキングマスタ
            self::WMS_PICKERS,
            self::WMS_PICKER_ATTENDANCE,
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => EMenuCategory::MASTER_PICKING,

            self::EARNINGS => EMenuCategory::STATISTICS,

            self::WMS_PICKING_LOGS,
            self::WMS_JX_TRANSMISSION_LOGS,
            self::WMS_IMPORT_LOGS => EMenuCategory::LOGS,

            self::WAVE_SETTINGS,
            self::CLIENT_PRINTER_COURSE_SETTINGS => EMenuCategory::SETTINGS,

            self::TEST_DATA_GENERATOR => EMenuCategory::TEST_DATA,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::INBOUND_DASHBOARD => '入荷ダッシュボード',
            self::WMS_ORDER_INCOMING_SCHEDULES => '入庫予定',
            self::WMS_INCOMING_COMPLETED => '入庫完了',
            self::WMS_INCOMING_TRANSMITTED => '仕入連携済み',
            self::PURCHASES => '発注データ',
            self::RECEIPT_INSPECTIONS => '入荷検品',

            self::OUTBOUND_DASHBOARD => '出荷ダッシュボード',
            self::WMS_PICKER_ATTENDANCE => 'ピッカー勤怠管理',
            self::WMS_PICKING_WAITINGS => 'ピッキング待機',
            self::PICKING_TASKS => 'ピッキングタスク',
            self::WAVES => '出荷波動管理',
            self::PICKING_ROUTE_VISUALIZATION => 'ピッキング経路確認',
            self::WMS_PICKING_ITEM_RESULTS => 'ピッキング商品リスト',
            self::DELIVERY_COURSE_CHANGE => '配送コース変更',
            self::SHIPMENT_INSPECTIONS => '出荷検品',
            self::WMS_SHIPMENT_SLIPS => '出荷伝票',

            self::WMS_SHORTAGES => '欠品一覧',
            self::WMS_SHORTAGES_WAITING_APPROVALS => '承認待ち欠品',

            self::WMS_SHORTAGE_ALLOCATIONS => '横持ち出荷依頼',

            self::WMS_STOCK_TRANSFER_CANDIDATES => '移動候補一覧',
            self::WMS_ORDER_CANDIDATES => '発注候補一覧',
            self::WMS_ORDER_CONFIRMATION_WAITING => '発注確定待ち',
            self::WMS_ORDER_CONFIRMED => '発注確定済み',
            self::WMS_AUTO_ORDER_JOBS => 'ジョブ履歴',

            self::REAL_STOCKS => '在庫管理',

            self::WAREHOUSES => '倉庫',
            self::WAREHOUSE_CONTRACTORS => '発注先別ロット条件',
            self::CONTRACTORS => '発注先',
            self::ITEM_CONTRACTORS => '商品発注先',
            self::WMS_MONTHLY_SAFETY_STOCKS => '月別発注点',
            self::WMS_ITEM_SUPPLY_SETTINGS => '供給設定',
            self::WMS_WAREHOUSE_CALENDARS => '倉庫カレンダー',
            self::WMS_CONTRACTOR_HOLIDAYS => '発注先休日',
            self::WMS_ORDER_JX_SETTINGS => 'JX接続設定',
            self::LOCATIONS => 'ロケーション',
            self::WMS_LOCATIONS => 'WMSロケーション',
            self::WMS_PICKING_AREAS => 'ピッキングエリア',
            self::WMS_PICKERS => 'ピッカー管理',
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => 'ピッキング割当戦略',
            self::FLOOR_PLAN_EDITOR => 'フロア図エディタ',

            self::EARNINGS => '売上データ',

            self::WMS_PICKING_LOGS => 'ピッキングログ',
            self::WMS_JX_TRANSMISSION_LOGS => 'JX送受信履歴',
            self::WMS_IMPORT_LOGS => 'インポート履歴',

            self::WAVE_SETTINGS => '波動設定',

            self::CLIENT_PRINTER_COURSE_SETTINGS => 'プリンター設定',

            self::TEST_DATA_GENERATOR => 'テストデータ生成',
        };
    }

    public function icon(): ?string
    {
        return match ($this) {
            self::INBOUND_DASHBOARD => 'heroicon-o-presentation-chart-line',
            self::WMS_ORDER_INCOMING_SCHEDULES => 'heroicon-o-inbox-arrow-down',
            self::WMS_INCOMING_COMPLETED => 'heroicon-o-check-circle',
            self::WMS_INCOMING_TRANSMITTED => 'heroicon-o-cloud-arrow-up',
            self::PURCHASES => 'heroicon-o-shopping-cart',
            self::RECEIPT_INSPECTIONS => 'heroicon-o-clipboard-document-check',

            self::OUTBOUND_DASHBOARD => 'heroicon-o-presentation-chart-bar',
            self::WMS_PICKER_ATTENDANCE => 'heroicon-o-calendar-days',
            self::WMS_PICKING_WAITINGS => 'heroicon-o-clock',
            self::PICKING_TASKS => 'heroicon-o-clipboard-document-list',
            self::WAVES => 'heroicon-o-queue-list',
            self::PICKING_ROUTE_VISUALIZATION => 'heroicon-o-map',
            self::WMS_PICKING_ITEM_RESULTS => 'heroicon-o-list-bullet',
            self::DELIVERY_COURSE_CHANGE => 'heroicon-o-arrow-path',
            self::SHIPMENT_INSPECTIONS => 'heroicon-o-check-circle',
            self::WMS_SHIPMENT_SLIPS => 'heroicon-o-document-text',

            self::WMS_SHORTAGES => 'heroicon-o-exclamation-triangle',
            self::WMS_SHORTAGES_WAITING_APPROVALS => 'heroicon-o-hand-raised',

            self::WMS_SHORTAGE_ALLOCATIONS => 'heroicon-o-truck',

            self::WMS_STOCK_TRANSFER_CANDIDATES => 'heroicon-o-arrows-right-left',
            self::WMS_ORDER_CANDIDATES => 'heroicon-o-shopping-cart',
            self::WMS_ORDER_CONFIRMATION_WAITING => 'heroicon-o-clipboard-document-check',
            self::WMS_ORDER_CONFIRMED => 'heroicon-o-check-badge',
            self::WMS_AUTO_ORDER_JOBS => 'heroicon-o-queue-list',

            self::REAL_STOCKS => 'heroicon-o-cube-transparent',

            self::WAREHOUSES => 'heroicon-o-building-office-2',
            self::WAREHOUSE_CONTRACTORS => 'heroicon-o-building-storefront',
            self::CONTRACTORS => 'heroicon-o-truck',
            self::ITEM_CONTRACTORS => 'heroicon-o-document-text',
            self::WMS_MONTHLY_SAFETY_STOCKS => 'heroicon-o-calendar-days',
            self::WMS_ITEM_SUPPLY_SETTINGS => 'heroicon-o-cog-6-tooth',
            self::WMS_WAREHOUSE_CALENDARS => 'heroicon-o-calendar-days',
            self::WMS_CONTRACTOR_HOLIDAYS => 'heroicon-o-calendar',
            self::WMS_ORDER_JX_SETTINGS => 'heroicon-o-server',
            self::LOCATIONS => 'heroicon-o-map-pin',
            self::WMS_LOCATIONS => 'heroicon-o-squares-2x2',
            self::WMS_PICKING_AREAS => 'heroicon-o-squares-plus',
            self::WMS_PICKERS => 'heroicon-o-user-group',
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => 'heroicon-o-adjustments-horizontal',
            self::FLOOR_PLAN_EDITOR => 'heroicon-o-map',

            self::EARNINGS => 'heroicon-o-currency-yen',

            self::WMS_PICKING_LOGS => 'heroicon-o-rectangle-stack',
            self::WMS_JX_TRANSMISSION_LOGS => 'heroicon-o-arrows-up-down',
            self::WMS_IMPORT_LOGS => 'heroicon-o-arrow-up-tray',

            self::WAVE_SETTINGS => 'heroicon-o-cog-6-tooth',
            self::CLIENT_PRINTER_COURSE_SETTINGS => 'heroicon-o-printer',

            self::TEST_DATA_GENERATOR => 'heroicon-o-beaker',
        };
    }

    public function sort(): int
    {
        return match ($this) {
            // 入荷管理
            self::INBOUND_DASHBOARD => 1,
            self::WMS_ORDER_INCOMING_SCHEDULES => 2,
            self::WMS_INCOMING_COMPLETED => 3,
            self::WMS_INCOMING_TRANSMITTED => 4,
            self::PURCHASES => 5,
            self::RECEIPT_INSPECTIONS => 6,

            // 出荷管理
            self::WAVES => 0,
            self::DELIVERY_COURSE_CHANGE => 1,
            self::WMS_PICKING_WAITINGS => 3,
            self::PICKING_ROUTE_VISUALIZATION => 4,
            self::PICKING_TASKS => 5,
            self::WMS_PICKING_ITEM_RESULTS => 7,
            self::SHIPMENT_INSPECTIONS => 8,
            self::WMS_SHIPMENT_SLIPS => 10,
            self::OUTBOUND_DASHBOARD => 11,

            // 欠品管理
            self::WMS_SHORTAGES => 1,
            self::WMS_SHORTAGES_WAITING_APPROVALS => 2,

            // 倉庫移動
            self::WMS_SHORTAGE_ALLOCATIONS => 1,
            self::WMS_STOCK_TRANSFER_CANDIDATES => 2,

            // 自動発注
            self::WMS_ORDER_CANDIDATES => 1,
            self::WMS_ORDER_CONFIRMATION_WAITING => 2,
            self::WMS_ORDER_CONFIRMED => 3,
            self::WMS_AUTO_ORDER_JOBS => 4,

            // 在庫管理
            self::REAL_STOCKS => 1,

            // 倉庫マスタ
            self::WAREHOUSES => 1,
            self::LOCATIONS => 2,
            self::WMS_LOCATIONS => 3,
            self::WMS_PICKING_AREAS => 4,
            self::FLOOR_PLAN_EDITOR => 5,

            // 発注マスタ
            self::WAREHOUSE_CONTRACTORS => 1,
            self::CONTRACTORS => 2,
            self::ITEM_CONTRACTORS => 3,
            self::WMS_MONTHLY_SAFETY_STOCKS => 4,
            self::WMS_ITEM_SUPPLY_SETTINGS => 5,
            self::WMS_WAREHOUSE_CALENDARS => 6,
            self::WMS_CONTRACTOR_HOLIDAYS => 7,
            self::WMS_ORDER_JX_SETTINGS => 8,

            // ピッキングマスタ
            self::WMS_PICKERS => 1,
            self::WMS_PICKER_ATTENDANCE => 2,
            self::WMS_PICKING_ASSIGNMENT_STRATEGIES => 3,

            // 統計データ
            self::EARNINGS => 1,

            // ログ
            self::WMS_PICKING_LOGS => 1,
            self::WMS_JX_TRANSMISSION_LOGS => 2,
            self::WMS_IMPORT_LOGS => 3,

            // システム設定
            self::WAVE_SETTINGS => 1,
            self::CLIENT_PRINTER_COURSE_SETTINGS => 2,

            // テストデータ
            self::TEST_DATA_GENERATOR => 1,
        };
    }
}
