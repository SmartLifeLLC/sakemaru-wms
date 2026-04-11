# 発注ステータスウィジェット（HUB/サテライト倉庫）

- **作成日**: 2026-04-06
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/20260405/20260406-000344-order-status-widget/

## 背景・目的

HUB倉庫（華むすびの蔵センター、オレンジ冷凍倉庫）の発注データ生成時に、サテライト倉庫（各店舗）の発注が完了しているかどうかを一目で確認したい。サテライト倉庫の発注が未完了のままHUB倉庫の発注を行うと、移動候補の計算に影響が出る。

現状の問題:
- 各サテライト倉庫の発注状況が一画面で確認できない
- HUB倉庫の発注時にサテライト倉庫の未発注状態に気づけない

## 現状の実装

### 発注ステータス判定

`wms_auto_order_job_controls`テーブルで倉庫ごとの発注状況を追跡:
- `warehouse_id`: 対象倉庫
- `process_name`: `ORDER_CALC`（発注候補計算）
- `status`: `SUCCESS`（成功）
- `settlement_status`: `PENDING`（待機中）/ `CONFIRMED`（確定済）
- `started_at`: 実行日時（当日判定に使用）

### 倉庫ステータスの3状態

| 状態 | 条件 | UIカラー |
|------|------|----------|
| 未生成 | 当日のORDER_CALCジョブなし | グレー（未対応） |
| 待機中 | settlement_status=PENDING | オレンジ（生成済・未確定） |
| 完了 | settlement_status=CONFIRMED | 緑（確定済） |

### HUB/サテライト倉庫の関係

- HUB倉庫: `wms_stock_transfer_candidates.hub_warehouse_id` に登場する倉庫（91=華むすびの蔵センター, 101=オレンジ冷凍倉庫）
- サテライト倉庫: HUB以外の`is_virtual=false, is_active=true`の倉庫
- 現在のHUB倉庫は実質的に固定（華むすびの蔵センター=91が主要HUB）

### 既存ウィジェットパターン

- `DashboardShortageAllocationsWidget`: カスタムWidget + Blade + Livewire、ダッシュボードに配置
- `WmsOutboundOverview`: StatsOverviewWidget、統計表示

### 表示場所

1. `admin/wms-auto-order-job-controls` — ページ上部（テーブルの上）
2. `/admin`（ダッシュボード）— `DashboardShortageAllocationsWidget`の下

## 変更内容

### 概要

HUB倉庫ごとにサテライト倉庫の当日発注ステータスを表示するウィジェットを作成。3箇所（ジョブ管理ページ上部、ダッシュボード、発注生成モーダル内NOTICE）で利用。

### 詳細設計

#### ウィジェットUI（デザインサンプル準拠）

```
┌────────────────────────────────────────────────────────┐
│ HUB倉庫                  サテライト店舗 (7/10)         │
│                                                        │
│ ┌─────────────────────┐  ✅ 本店  ✅ 二の宮店  坂井店 │
│ │ 華むすびの蔵センター │  ✅ サンドーム前店  光陽店     │
│ │            待機中    │> ✅ プラザ店  ✅ ヴィオ店      │
│ └─────────────────────┘  敦賀店  ⏳ 越前店  ✅ 江守店  │
│                                                        │
└────────────────────────────────────────────────────────┘
```

- 左側: HUB倉庫名 + HUB自身のステータスバッジ
- 右側: サテライト倉庫をチップ（ピル）で表示
  - 緑（✅）: 確定済（CONFIRMED）
  - オレンジ（⏳）: 待機中（PENDING）
  - グレー: 未生成
- ヘッダー: 「サテライト店舗 (完了数/全数)」

#### サテライト倉庫の定義

`is_virtual=false, is_active=true`の倉庫のうち、`wms_warehouse_auto_order_settings.is_auto_order_enabled=true`の倉庫をサテライトとする（HUB倉庫自身を除く）。

HUB倉庫の定義: `wms_contractor_settings` から動的に判定（マイグレーション不要）:
```sql
SELECT DISTINCT supply_warehouse_id AS hub_warehouse_id
FROM wms_contractor_settings
WHERE transmission_type = 'INTERNAL'
  AND supply_warehouse_id IS NOT NULL;
-- 結果: 91（華むすびの蔵センター）, 101（オレンジ冷凍倉庫）
-- テーブル1,054件中INTERNAL行は2件のみ → 負荷は極めて低い
```

#### 発注生成モーダルへのNOTICE

HUB倉庫（例: 華むすびの蔵センター）で「発注・移動候補生成」ボタンを押した際、サテライト倉庫に未発注（未生成）がある場合、モーダル内に警告を表示:

```
⚠️ 以下のサテライト倉庫の発注がまだ完了していません:
坂井店、光陽店、敦賀店
サテライト倉庫の発注が完了してからHUB倉庫の発注を行うことを推奨します。
```

#### DB変更

なし（既存テーブルのクエリのみ）

#### 新規ファイル

1. `app/Filament/Widgets/OrderStatusWidget.php` — ウィジェット本体（Livewire）
2. `resources/views/filament/widgets/order-status-widget.blade.php` — Blade テンプレート

#### 既存変更

1. `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php`
   - `getHeaderWidgets()` にウィジェット追加
   - `getGenerateByWarehouseAction()` モーダルにサテライト未発注NOTICE追加

2. `app/Filament/Pages/Dashboard.php`
   - `getHeaderWidgets()` にウィジェット追加（DashboardShortageAllocationsWidgetの後）

### 影響範囲

- ジョブ管理ページの上部にウィジェットが追加される
- ダッシュボードにウィジェットが追加される
- 発注生成モーダルに警告が追加される（HUB倉庫時のみ）

## 制約

- FK禁止: 全リレーションはアプリケーションレベル
- migrate:fresh/refresh/reset/db:wipe 禁止
- 既存の発注フローに影響を与えない（NOTICE表示のみ、ブロックしない）
- ウィジェットは読み取り専用（データ変更なし）

## 対象ファイル

### 新規作成
- `app/Filament/Widgets/OrderStatusWidget.php`
- `resources/views/filament/widgets/order-status-widget.blade.php`

### 既存変更
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php`
- `app/Filament/Pages/Dashboard.php`

### 参照のみ
- `app/Models/WmsAutoOrderJobControl.php`
- `app/Models/WmsWarehouseAutoOrderSetting.php`
- `app/Models/Sakemaru/Warehouse.php`
- `app/Enums/AutoOrder/SettlementStatus.php`
- `app/Enums/AutoOrder/JobStatus.php`
- `app/Filament/Widgets/DashboardShortageAllocationsWidget.php`（パターン参考）

## 確認事項（回答済み）

1. **HUB倉庫の特定方法**: → `wms_contractor_settings` から動的判定（マイグレーション不要）
2. **サテライト倉庫の範囲**: → `is_auto_order_enabled=true` のみ
3. **複数HUB倉庫**: → 91, 101 両方HUB。扱う商品が異なる
4. **NOTICE表示**: → 推奨レベル（発注可能、ブロックしない）
