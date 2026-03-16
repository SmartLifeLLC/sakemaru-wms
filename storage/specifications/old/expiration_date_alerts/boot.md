# Work Plan: expiration-date-alerts

- **ID**: expiration-date-alerts
- **作成日**: 2026-02-25
- **最終更新**: 2026-02-25
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/expiration_date_alerts/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

賞味期限に問題がある在庫（期限切れ・アラート中）を一覧表示するFilamentリソースページを新規作成する。
メニュー「在庫 → 賞味期限管理」に配置し、倉庫別タブで表示する。

## 重要な設計制約

- データベース破壊コマンド（migrate:fresh, migrate:refresh等）禁止
- FK使用禁止（アプリケーション層でリレーション管理）
- Filament 4のインポートパス・APIに準拠（CLAUDE.md参照）
- テーブルデザイン仕様（table-design-specification.md）に準拠
- AdvancedTables + PresetView で倉庫タブを実装（WmsOrderCandidatesのパターンに準拠）

## 対象ファイル

### 新規作成
- `app/Filament/Resources/ExpirationAlerts/ExpirationAlertResource.php` - リソースクラス
- `app/Filament/Resources/ExpirationAlerts/Pages/ListExpirationAlerts.php` - リストページ（倉庫タブ含む）
- `app/Filament/Resources/ExpirationAlerts/Tables/ExpirationAlertsTable.php` - テーブル定義

### 既存変更
- `app/Enums/EMenu.php` - メニュー項目追加
- `app/Enums/EMenuCategory.php` - 必要に応じてカテゴリ追加（既存INVENTORYに入れるか検討）

### 参照のみ（変更禁止）
- `app/Models/Sakemaru/RealStockLot.php` - ロットモデル（スキーマ参照）
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` - 倉庫タブの実装パターン参照
- `app/Livewire/MegaMenu.php` - メガメニュー構造参照
- `storage/specifications/table-design-specification.md` - テーブルデザイン仕様

## テストデータ

- 既存の `real_stock_lots` テーブルに ACTIVE データがあればそのまま使用
- `php artisan wms:generate-test-data` でテストデータ生成可能

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: フロアプラン色表示 | 完了 | 2026-02-25 | 先行タスクで実装済み |
| P1: EMenuへのメニュー項目追加 | 完了 | 2026-02-25 | EXPIRATION_ALERTS追加 |
| P2: Filamentリソース・テーブル・リストページ作成 | 完了 | 2026-02-25 | 3ファイル新規作成 |
| P3: 倉庫別タブ実装 | 完了 | 2026-02-25 | P2と同時実装 |
| P4: 動作確認・Pint | 完了 | 2026-02-25 | 構文OK、Pint PASS |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### データベーススキーマ（調査済み）
- `real_stock_lots` テーブル主要カラム:
  - `location_id`, `real_stock_id`, `expiration_date`, `alert_date`
  - `status` (ACTIVE/DEPLETED/EXPIRED), `current_quantity`, `reserved_quantity`
- `real_stocks` テーブル: `item_id`, `warehouse_id`
- `items` テーブル: `code`, `name`, `capacity_case`, `volume`, `volume_unit`, `expiration_alert_days`
- `locations` テーブル: `code1`, `code2`, `code3`, `name`, `warehouse_id`

### 既存パターン（調査済み）
- 倉庫タブ: `AdvancedTables` + `HasWmsUserViews` trait + `PresetView`
- メニュー: `EMenu` enum → `EMenuCategory` → MegaMenu `buildMenuStructure()`
- 在庫カテゴリ: `EMenuCategory::INVENTORY` (sort: 8, label: '在庫管理')
- MegaMenu: `inventory` タブ内に `EMenuCategory::INVENTORY` カテゴリ

### Git ブランチ
- 作業ブランチ: feature/stock-transfer-map-view（現在のブランチで作業継続）
- ベースブランチ: main

---

## Phase完了記録

### P0: フロアプラン色表示
- 完了日: 2026-02-25
- 実績:
  - `FloorPlanEditor.php` zones()にexpiration_status追加（バルククエリ）
  - `floor-plan-editor.blade.php` getZoneColor()をexpiration_statusベースに変更
  - 色: expired=#FEE2E2(赤), alert=#FEF3C7(黄), normal=#E0F2FE(水色)

### P1: EMenuへのメニュー項目追加
- 完了日: 2026-02-25
- 実績:
  - `EMenu::EXPIRATION_ALERTS` を追加（category: INVENTORY, label: 賞味期限管理, sort: 2）
  - EMenuCategoryは既存INVENTORYをそのまま使用（変更不要）

### P2: Filamentリソース作成
- 完了日: 2026-02-25
- 成果物:
  - `app/Filament/Resources/ExpirationAlerts/ExpirationAlertResource.php`
  - `app/Filament/Resources/ExpirationAlerts/Tables/ExpirationAlertsTable.php`
  - `app/Filament/Resources/ExpirationAlerts/Pages/ListExpirationAlerts.php`
- 実績:
  - RealStockLotベースのリソース（読み取り専用、ListPageのみ）
  - JOINクエリでwarehouse/item/location情報を一括取得
  - ステータスバッジ（期限切れ=danger, アラート=warning）
  - 倉庫・ステータスフィルター、商品CD/名検索

### P3: 倉庫別タブ実装
- 完了日: 2026-02-25
- 実績:
  - AdvancedTables + HasWmsUserViews + PresetViewパターンで実装
  - アラート在庫が存在する倉庫のみタブ表示（30秒キャッシュ）
  - ユーザーデフォルト倉庫の自動選択

### P4: 動作確認・Pint
- 完了日: 2026-02-25
- 実績:
  - 全ファイル `php -l` 構文エラーなし
  - `./vendor/bin/pint --dirty` PASS（6ファイル）
