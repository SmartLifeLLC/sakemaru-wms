# Work Plan: add-default-warehouses

- **ID**: add-default-warehouses
- **作成日**: 2026-03-31
- **最終更新**: 2026-03-31
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260331/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（add-default-warehouses-boot.md）
2. add-default-warehouses-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから add-default-warehouses-plan.md の該当セクションを読んで作業再開

## 概要

トップナビゲーションに倉庫切り替えドロップダウンを追加。`users.wms_selected_warehouse_id` を使い、各ページのデフォルト倉庫フィルタをこの値で制御する。AWSリージョン選択のようなUI。

## 重要な設計制約

- FK禁止（アプリケーションレベルでリレーション管理）
- `migrate:fresh` / `migrate:refresh` 禁止（共有DB）
- `default_warehouse_id` は既存カラムで変更しない。新しく `wms_selected_warehouse_id` を使う
- `wms_selected_warehouse_id` カラムは既にDBに存在する（マイグレーション不要）

## 対象ファイル

### 新規作成
- `app/Livewire/WarehouseSelector.php` — Livewireコンポーネント（ドロップダウンUI）
- `resources/views/livewire/warehouse-selector.blade.php` — ドロップダウンView

### 既存変更
- `app/Models/Sakemaru/User.php` — `wms_selected_warehouse_id` をfillable追加、リレーション追加
- `app/Providers/Filament/AdminPanelProvider.php` — `renderHook` でドロップダウンをナビに配置
- 各ページの `default_warehouse_id` 参照 → ページ改修時に個別に `getSelectedWarehouseId()` へ切り替え

### 参照のみ（変更禁止）
- `app/Models/WmsPicker.php` — pickerの `default_warehouse_id` はそのまま

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Userモデル拡張 | 完了 | 2026-03-31 | fillable, リレーション, getSelectedWarehouseId() 追加 |
| P2: 倉庫切り替えUI作成 | 完了 | 2026-03-31 | Livewire + renderHook, ドロップダウンUI |
| P3: 各ページの参照切り替え（個別対応） | 完了 | 2026-03-31 | 一括置換せず、ページ改修時に個別対応する方針 |
| P4: 動作確認 | 完了 | 2026-03-31 | 構文チェック全パス、ルート正常 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### DB状態
- `users.wms_selected_warehouse_id` カラム: 既存（unsignedBigInteger, nullable）
- `users.default_warehouse_id` カラム: 既存（初期デフォルト倉庫。変更しない）
- 倉庫一覧: `warehouses` テーブル（sakemaru接続）

### `default_warehouse_id` 参照箇所（34ファイル）
- Filament Pages (ListRecords系): 20+ ファイル — PresetView のデフォルト倉庫
- DashboardShortageAllocationsWidget: 1ファイル
- FloorPlanEditor, TestDataGenerator: 2ファイル
- AuthController (API): 1ファイル — API認証レスポンス
- WmsPickerForm: 1ファイル — Picker設定用（変更しない）
- WmsPicker model: 1ファイル（変更しない）

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

### P1: Userモデル拡張
- 完了日: 2026-03-31
- 実績:
  - `User.php`: `wms_selected_warehouse_id` をfillable追加
  - `selectedWarehouse()` BelongsToリレーション追加
  - `getSelectedWarehouseId()` ヘルパー追加（wms_selected_warehouse_id ?? default_warehouse_id）

### P2: 倉庫切り替えUI作成
- 完了日: 2026-03-31
- 実績:
  - `app/Livewire/WarehouseSelector.php` 新規作成
  - `resources/views/livewire/warehouse-selector.blade.php` 新規作成
  - `resources/views/livewire/warehouse-selector-hook.blade.php` 新規作成
  - `AdminPanelProvider.php` に `PanelsRenderHook::TOPBAR_START` でrenderHook追加

### P3: 各ページの参照切り替え（個別対応）
- 完了日: 2026-03-31
- 実績:
  - 方針変更: 一括置換は行わず、ページ改修時に個別対応する
  - 対応済み: DashboardShortageAllocationsWidget, ListWmsShortageAllocations
  - 残りは各ページの改修時に `getSelectedWarehouseId()` へ切り替え

### P4: 動作確認
- 完了日: 2026-03-31
- 実績:
  - 全ファイル構文チェック: エラーなし（WarehouseSelector.php, User.php, AdminPanelProvider.php, blade2件）
  - ルートキャッシュクリア・ビューキャッシュクリア: 正常
  - adminルート一覧: 正常にロード
  - Livewire 3 auto-discovery: 自動検出対応（明示的なdiscoverコマンド不要）
