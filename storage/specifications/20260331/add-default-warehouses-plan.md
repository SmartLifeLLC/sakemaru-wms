# 倉庫切り替えUI 作業計画

## 前提

- `users.wms_selected_warehouse_id` カラムは既にDB上に存在（マイグレーション不要）
- 現在34ファイルで `auth()->user()->default_warehouse_id` を参照してプリセットビューのデフォルト倉庫を決定
- AWSリージョン選択のようなドロップダウンUIをトップナビに追加する
- 選択された倉庫はDB保存し、ページリロード後も維持する

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Userモデル拡張 | `wms_selected_warehouse_id` のfillable・リレーション・ヘルパー追加 | Userモデルから選択倉庫を取得・更新できる |
| P2 | 倉庫切り替えUI作成 | トップナビにドロップダウンを配置 | UIから倉庫を切り替えてDB保存される |
| P3 | 各ページの参照切り替え | `default_warehouse_id` → `wms_selected_warehouse_id` にfallback付きで切り替え | 全ページで選択倉庫がデフォルトフィルタに反映 |
| P4 | 動作確認 | 倉庫切り替え→各ページ遷移で正しくフィルタされるか確認 | エラーなし・倉庫選択が維持される |

---

## P1: Userモデル拡張

### 目的

`User` モデルに `wms_selected_warehouse_id` を使えるようにする。

### 修正対象ファイル

- `app/Models/Sakemaru/User.php`

### 修正内容

1. `$fillable` に `wms_selected_warehouse_id` を追加
2. `selectedWarehouse()` リレーション追加（BelongsTo Warehouse）
3. `getSelectedWarehouseId(): ?int` ヘルパーメソッド追加
   - `wms_selected_warehouse_id` があればそれを返す
   - なければ `default_warehouse_id` をfallbackとして返す
   - → 各ページはこのメソッドを呼ぶだけでよい

### 完了条件

- `auth()->user()->getSelectedWarehouseId()` で正しい倉庫IDが返る
- `wms_selected_warehouse_id` が未設定の場合 `default_warehouse_id` にfallbackする

---

## P2: 倉庫切り替えUI作成

### 目的

トップナビゲーションにAWSリージョン選択風の倉庫切り替えドロップダウンを配置する。

### 修正対象ファイル

- `app/Livewire/WarehouseSelector.php`（新規）
- `resources/views/livewire/warehouse-selector.blade.php`（新規）
- `app/Providers/Filament/AdminPanelProvider.php`（renderHook追加）

### 実装方針

1. **Livewireコンポーネント** `WarehouseSelector`
   - `mount()`: 現在の選択倉庫と全倉庫リスト（`is_virtual=false`）をロード
   - `selectWarehouse($warehouseId)`: `wms_selected_warehouse_id` をDB更新 → ページリロード
   - 現在選択中の倉庫名をボタンに表示

2. **Blade View**
   - Alpine.js `x-data="{ open: false }"` でドロップダウン開閉
   - 倉庫一覧をリスト表示（現在選択中はハイライト）
   - 倉庫コード + 名前を表示
   - コンパクトなスタイル（トップナビに収まるサイズ）

3. **AdminPanelProvider**
   - `->renderHook('panels::topbar.start', fn () => view('livewire.warehouse-selector-hook'))` でトップバーに配置

### UI仕様

```
[🏭 華むすびの蔵センター ▼]
  ┌─────────────────────────┐
  │ [01] 本店               │
  │ [02] 二の宮店           │
  │ [03] 坂井店             │
  │ ...                     │
  │ [91] 華むすびの蔵センター ← 選択中（ハイライト） │
  │ [97] 営業部卸           │
  └─────────────────────────┘
```

### 完了条件

- トップナビに倉庫ドロップダウンが表示される
- 倉庫を切り替えると `users.wms_selected_warehouse_id` が更新される
- ページリロード後も選択が維持される

---

## P3: 各ページの参照切り替え

### 目的

全ページで `default_warehouse_id` の直接参照を `getSelectedWarehouseId()` に置き換える。

### 修正対象ファイル（WmsPicker関連を除く32ファイル）

以下のパターンを一括置換:

```php
// Before
$userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

// After
$userDefaultWarehouseId = auth()->user()?->getSelectedWarehouseId();
```

### 対象ファイルカテゴリ

1. **Filament ListRecords Pages**（PresetView のデフォルト倉庫）
   - ListWmsShortages, ListWmsShortagesWaitingApprovals, ListWmsShortagesApproved
   - ListWmsShortageAllocations
   - ListWmsPickingTasks, ListWmsPickingWaitings
   - ListWmsShipmentSlips
   - ListWmsOrderCandidates, ListWmsOrderConfirmed, ListWmsOrderConfirmationWaiting
   - ListWmsOrderIncomingSchedules, ListWmsOrderDataFiles
   - ListWmsMonthlySafetyStocks, ListExpirationAlerts
   - ListWmsItemStockSnapshots, ListWaves, ListRealStocks
   - ListItemContractors, ListWmsStockTransferCandidates
   - ListWmsPickingItemEdits, ListWmsPickerAttendance
   - ListDeliveryCourseChanges, DeliveryCourseChangeResource

2. **Widgets**
   - DashboardShortageAllocationsWidget

3. **Pages**
   - FloorPlanEditor, TestDataGenerator

4. **API**（変更要否を判断）
   - AuthController — APIレスポンスで返す倉庫IDはdefault_warehouse_idのままが適切

5. **変更しない**
   - WmsPicker.php — Pickerモデル独自のdefault_warehouse_id
   - WmsPickerForm.php — Pickerフォーム
   - WmsPickingTasksTable.php — テーブル定義

### 完了条件

- 全対象ファイルで `getSelectedWarehouseId()` を使用
- WmsPicker関連は変更なし
- APIの `default_warehouse_id` レスポンスは変更なし

---

## P4: 動作確認

### 確認項目

1. 倉庫切り替えUI
   - [ ] ドロップダウンが表示される
   - [ ] 倉庫を切り替えるとページがリロードされる
   - [ ] 再ログイン後も選択が維持される

2. 各ページのデフォルトフィルタ
   - [ ] ダッシュボード（横持ち出荷）: 選択倉庫がデフォルトタブ
   - [ ] 欠品一覧 / 承認待ち / 承認済み: 選択倉庫がデフォルトタブ
   - [ ] 横持ち出荷依頼: 選択倉庫がデフォルトタブ
   - [ ] ピッキングタスク: 選択倉庫がデフォルトフィルタ

3. fallback動作
   - [ ] `wms_selected_warehouse_id` が NULL の場合、`default_warehouse_id` で表示
   - [ ] 両方 NULL の場合、最初の倉庫 or 全表示

### 完了条件

- 上記全チェック項目がパス

---

## 制約（厳守）

- `migrate:fresh` / `migrate:refresh` / `db:wipe` 禁止
- FK禁止
- `WmsPicker.default_warehouse_id` は変更しない
- API (`AuthController`) の `default_warehouse_id` レスポンスは変更しない

## 全体完了条件

- トップナビで倉庫を切り替えられる
- 切り替えた倉庫が全ページのデフォルトフィルタに反映される
- 未選択時は `default_warehouse_id` にfallback
- エラーなし
