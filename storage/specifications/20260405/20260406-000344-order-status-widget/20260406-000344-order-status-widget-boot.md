# Work Plan: order-status-widget

- **ID**: order-status-widget
- **作成日**: 2026-04-06
- **最終更新**: 2026-04-06
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/20260405/20260406-000344-order-status-widget/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260406-000344-order-status-widget-boot.md）
2. 20260406-000344-order-status-widget-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

HUB倉庫ごとにサテライト店舗の当日発注ステータスを表示するウィジェット。ジョブ管理ページ上部・ダッシュボード・発注生成モーダルNOTICEの3箇所で利用。

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベル管理
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 共有DB
- **マイグレーション不要**: HUB判定は `wms_contractor_settings` から動的に取得
- **読み取り専用**: ウィジェットはデータ変更しない
- **発注ブロックしない**: NOTICEは警告のみ、発注は可能
- **HUB倉庫判定**: `wms_contractor_settings.transmission_type='INTERNAL' AND supply_warehouse_id IS NOT NULL` → supply_warehouse_idがHUB
- **サテライト範囲**: `wms_warehouse_auto_order_settings.is_auto_order_enabled=true` かつ HUBでない倉庫

## 対象ファイル

### 新規作成
- `app/Filament/Widgets/OrderStatusWidget.php` — ウィジェット本体
- `resources/views/filament/widgets/order-status-widget.blade.php` — Bladeテンプレート

### 既存変更
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` — ウィジェット配置 + モーダルNOTICE
- `app/Filament/Pages/Dashboard.php` — ウィジェット配置
- `resources/views/filament/components/contractor-selection.blade.php` — NOTICE表示追加

### 参照のみ（変更禁止）
- `app/Models/WmsAutoOrderJobControl.php`
- `app/Models/WmsWarehouseAutoOrderSetting.php`
- `app/Models/WmsContractorSetting.php`
- `app/Models/Sakemaru/Warehouse.php`
- `app/Enums/AutoOrder/SettlementStatus.php`
- `app/Filament/Widgets/DashboardShortageAllocationsWidget.php`（パターン参考）

## テストデータ

- `php artisan tinker` で `WmsAutoOrderJobControl::where('process_name', 'ORDER_CALC')->whereDate('started_at', today())->get()` で当日ジョブ確認

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: ウィジェット本体作成 | 完了 | 2026-04-06 | Widget + Blade 作成済み |
| P2: ジョブ管理ページにウィジェット配置 | 完了 | 2026-04-06 | getHeaderWidgets + getWidgets追加 |
| P3: ダッシュボードにウィジェット配置 | 完了 | 2026-04-06 | DashboardShortageAllocationsWidgetの下に追加 |
| P4: 発注生成モーダルにNOTICE追加 | 完了 | 2026-04-06 | modalDescriptionにサテライト未発注警告追加 |
| P5: 動作確認 | 完了 | 2026-04-06 | 全5ファイル構文OK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### HUB/サテライト倉庫データ（調査済み）
- HUB倉庫判定: `wms_contractor_settings` で `transmission_type='INTERNAL' AND supply_warehouse_id IS NOT NULL` → supply_warehouse_id
- 実データ: 91=華むすびの蔵センター, 101=オレンジ冷凍倉庫（扱う商品が異なる）
- wms_contractor_settings: 1,054件中INTERNAL行は2件のみ → クエリ負荷は極めて低い
- サテライト倉庫: `wms_warehouse_auto_order_settings.is_auto_order_enabled=true` かつ HUBでない倉庫
- `is_virtual=false, is_active=true` がベース条件（CustomModel.newQueryで自動適用）

### 発注ステータス3状態
- 未生成: 当日のORDER_CALCジョブなし → グレー
- 待機中: settlement_status=PENDING → オレンジ
- 完了: settlement_status=CONFIRMED → 緑

### 既存ウィジェットパターン
- `DashboardShortageAllocationsWidget`: Widget + Blade + Livewire properties + mount()
- columnSpan='full'、Blade内でAlpine.js使用

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: ウィジェット本体作成
- 完了日: -
- 成果物: OrderStatusWidget.php + Blade
- 実績:
  - (完了後に記入)

### P2: ジョブ管理ページにウィジェット配置
- 完了日: -
- 成果物: ListWmsAutoOrderJobControls.php
- 実績:
  - (完了後に記入)

### P3: ダッシュボードにウィジェット配置
- 完了日: -
- 成果物: Dashboard.php
- 実績:
  - (完了後に記入)

### P4: 発注生成モーダルにNOTICE追加
- 完了日: -
- 成果物: ListWmsAutoOrderJobControls.php + contractor-selection.blade.php
- 実績:
  - (完了後に記入)

### P5: 動作確認
- 完了日: -
- 実績:
  - (完了後に記入)
