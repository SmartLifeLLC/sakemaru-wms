# 発注ステータスウィジェット 作業計画

## 前提

- 仕入先別発注候補生成機能が実装済み（batch_code倉庫ID付き、仕入先選択UI）
- `wms_auto_order_job_controls` に `warehouse_id` カラム追加済み
- HUB倉庫（91=華むすびの蔵センター, 101=オレンジ冷凍倉庫）は実データで確認済み
- **HUB判定方式**: マイグレーション不要。`wms_contractor_settings` の既存データから動的に判定
  - `transmission_type='INTERNAL' AND supply_warehouse_id IS NOT NULL` → supply_warehouse_idがHUB倉庫
  - テーブルは1,054件、INTERNAL行は2件のみ → クエリ負荷は極めて低い

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | ウィジェット本体 | OrderStatusWidget + Blade作成 | 構文OK、HUB倉庫ごとにサテライトステータス表示 |
| P2 | ジョブ管理ページ配置 | ListWmsAutoOrderJobControlsにウィジェット追加 | admin/wms-auto-order-job-controlsで表示 |
| P3 | ダッシュボード配置 | Dashboard.phpにウィジェット追加 | /adminで横持ち出荷の下に表示 |
| P4 | モーダルNOTICE | HUB発注時にサテライト未発注警告 | 未発注サテライトがある場合にNOTICE表示 |
| P5 | 動作確認 | 構文チェック・画面確認 | 全ファイル構文OK |

---

## P1: ウィジェット本体作成

### 目的

HUB倉庫ごとにサテライト店舗の当日発注ステータスを表示するウィジェットを作成。

### HUB/サテライト判定ロジック

マイグレーション不要。`wms_contractor_settings` から動的に判定:

```php
// HUB倉庫ID取得
$hubWarehouseIds = DB::connection('sakemaru')
    ->table('wms_contractor_settings')
    ->where('transmission_type', 'INTERNAL')
    ->whereNotNull('supply_warehouse_id')
    ->distinct()
    ->pluck('supply_warehouse_id'); // [91, 101]

// サテライト倉庫 = is_auto_order_enabled=true かつ HUBでない倉庫
$satelliteSettings = WmsWarehouseAutoOrderSetting::where('is_auto_order_enabled', true)
    ->whereNotIn('warehouse_id', $hubWarehouseIds)
    ->get();
```

### 修正方針

**Widget（Livewire）:**
1. `OrderStatusWidget extends Widget`
2. `mount()` でデータ取得:
   - HUB倉庫: `wms_contractor_settings` から動的判定（上記クエリ）
   - サテライト倉庫: `is_auto_order_enabled=true` かつ HUBでない倉庫
   - 当日ジョブ: `WmsAutoOrderJobControl::where('process_name', 'ORDER_CALC')->whereDate('started_at', today())->whereNotNull('warehouse_id')`
3. 各倉庫のステータス判定:
   - ジョブなし → `none`（グレー）
   - `settlement_status=PENDING` → `pending`（オレンジ）
   - `settlement_status=CONFIRMED` → `confirmed`（緑）
4. columnSpan = 'full'

**Blade（デザインサンプル準拠）:**
- 左側: HUB倉庫カード（倉庫名 + 自身のステータスバッジ）、選択時にオレンジボーダー
- 右側: サテライト店舗チップ（ピル）表示、2行wrap
- ヘッダー: 「サテライト店舗 (完了数/全数)」
- カラー: 緑(confirmed)=bg-green-100 text-green-700、オレンジ(pending)=bg-orange-100 text-orange-700、グレー(none)=bg-gray-100 text-gray-500

### 修正対象ファイル

- `app/Filament/Widgets/OrderStatusWidget.php`（新規）
- `resources/views/filament/widgets/order-status-widget.blade.php`（新規）

### 完了条件

- `php -l` 構文OK
- ウィジェットが単体で動作可能

---

## P2: ジョブ管理ページにウィジェット配置

### 目的

`admin/wms-auto-order-job-controls` のテーブル上部にウィジェットを配置。

### 修正方針

`ListWmsAutoOrderJobControls` に `getHeaderWidgets()` メソッド追加:
```php
protected function getHeaderWidgets(): array
{
    return [
        OrderStatusWidget::class,
    ];
}
```

### 修正対象ファイル

- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php`

### 完了条件

- admin/wms-auto-order-job-controls でウィジェットが表示される

---

## P3: ダッシュボードにウィジェット配置

### 目的

`/admin`（ダッシュボード）の横持ち出荷ウィジェットの下に配置。

### 修正方針

`Dashboard.php` の `getHeaderWidgets()` に `OrderStatusWidget::class` を追加:
```php
return [
    DashboardShortageAllocationsWidget::class,
    OrderStatusWidget::class,
];
```

### 修正対象ファイル

- `app/Filament/Pages/Dashboard.php`

### 完了条件

- /admin でウィジェットが横持ち出荷の下に表示される

---

## P4: 発注生成モーダルにNOTICE追加

### 目的

HUB倉庫で「発注・移動候補生成」モーダルを開いた際、サテライト倉庫に未発注がある場合にNOTICE表示。

### 修正方針

`getGenerateByWarehouseAction()` 内で:
1. 現在の倉庫がHUBかどうか判定（`wms_contractor_settings` でINTERNALのsupply_warehouse_idに該当するか）
2. HUBの場合、サテライト倉庫の当日発注状況を取得
3. 未発注サテライトがあれば、`contractor-selection.blade.php` の上部にNOTICE表示
   - もしくは `modalDescription` にNOTICE文を追加

NOTICE内容:
```
⚠️ 以下のサテライト倉庫の発注がまだ完了していません: 坂井店、光陽店、敦賀店
サテライト倉庫の発注完了後にHUB倉庫の発注を行うことを推奨します。
```

### 修正対象ファイル

- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php`
- `resources/views/filament/components/contractor-selection.blade.php`（NOTICE表示追加）

### 完了条件

- HUB倉庫選択時にサテライト未発注のNOTICEが表示される
- サテライト倉庫選択時にはNOTICE非表示
- 全サテライト発注済みの場合はNOTICE非表示

---

## P5: 動作確認

### 完了条件

- 全ファイル `php -l` 構文OK
- admin/wms-auto-order-job-controls でウィジェット表示
- /admin でウィジェット表示
- HUB倉庫の発注モーダルでNOTICE表示

---

## 制約（厳守）

- FK禁止: 全リレーションはアプリケーションレベル
- migrate:fresh/refresh/reset/db:wipe 禁止
- マイグレーション不要（既存テーブルのクエリのみ）
- ウィジェットは読み取り専用
- 発注フローをブロックしない（NOTICEのみ）
- 既存の発注ロジック変更禁止

## 全体完了条件

- P1〜P5全て完了
- 構文エラーなし
- 3箇所（ジョブ管理・ダッシュボード・モーダル）で正しく表示
