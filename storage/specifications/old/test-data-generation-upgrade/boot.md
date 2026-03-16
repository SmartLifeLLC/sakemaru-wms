# Work Plan: test-data-generation-upgrade

- **ID**: test-data-generation-upgrade
- **作成日**: 2026-02-18
- **最終更新**: 2026-02-18
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/test-data-generation-upgrade/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`/admin/test-data-generator` の「倉庫テストデータ」タブに、在庫データ（real_stocks + real_stock_lots）のCSV保存/読込機能を追加する。複数回テストを繰り返すため、在庫状態のスナップショットを取って復元できるようにする。

## 重要な設計制約

- **DB破壊コマンド禁止**: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` 禁止
- **FK禁止**: 外部キー制約は使用しない（アプリケーション層で整合性管理）
- **DB接続**: `sakemaru` コネクションを使用
- **テーブル構造**: `real_stocks` と `real_stock_lots` は基幹システム側のテーブル。生成カラム `available_quantity` に注意
- **S3ストレージ**: `Storage::disk('s3')` で保存・取得。ZIPではなくS3 prefix（ディレクトリ）で管理
- **Filament 4パターン遵守**: Action, Schema, Componentsのインポートパスに注意
- **テスト環境限定**: `canAccess()` で non-production のみ表示

## 対象ファイル

### 既存変更
- `app/Filament/Pages/TestDataGenerator.php` - 「在庫データ保存」「在庫データ読込」アクション追加
- `resources/views/filament/pages/test-data-generator.blade.php` - 倉庫タブにカード2枚追加

### 参照のみ（変更禁止）
- `app/Models/Sakemaru/RealStock.php` - RealStockモデル
- `app/Models/Sakemaru/RealStockLot.php` - RealStockLotモデル
- `app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php` - CSVインポートパターンの参考

### 参考ファイル
- `storage/app/test_stock_data.csv` - 既存のCSVサンプル（旧フォーマット、real_stocksのみ）

## テストデータ

- 既存の酒類在庫生成 or 在庫データ生成で事前にデータを作成
- `/admin/test-data-generator?activeTab=warehouse` で動作確認

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 在庫データ保存（S3エクスポート） | 完了 | 2026-02-18 | saveStockDataAction() 実装完了 |
| P2: 在庫データ読込（S3インポート） | 完了 | 2026-02-18 | loadStockDataAction() 実装完了 |
| P3: UIカード追加・動作確認 | 完了 | 2026-02-18 | Bladeテンプレートにカード2枚追加 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### CSVフォーマット（P1完了時に確定）
- real_stocks.csv: 動的取得（available_quantity除外）
- real_stock_lots.csv: 全カラム
- 保存先: S3 `test-stock-snapshots/{timestamp}_{name}/` 配下

### テスト結果（各Phase完了時に記入）
- P1テスト: PHP構文チェック OK、Pint OK、Blade cache OK
- P2テスト: PHP構文チェック OK、Pint OK

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチで作業）
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: 在庫データ保存（S3エクスポート）
- 完了日: 2026-02-18
- 実績:
  - `saveStockDataAction()` を TestDataGenerator.php に追加
  - `generateCsvString()` ヘルパーメソッド追加
  - available_quantity（生成カラム）を動的に除外
  - スナップショット名（任意）付きでS3に保存
  - Storage import追加、getWarehouseActionNames() 更新

### P2: 在庫データ読込（S3インポート）
- 完了日: 2026-02-18
- 実績:
  - `loadStockDataAction()` を TestDataGenerator.php に追加
  - `parseCsvString()` ヘルパーメソッド追加
  - S3からスナップショット一覧をSelect表示（新しい順）
  - TRUNCATE→INSERT方式、1000件ずつバッチINSERT
  - FOREIGN_KEY_CHECKS制御、トランザクション安全性

### P3: UIカード追加・動作確認
- 完了日: 2026-02-18
- 実績:
  - test-data-generator.blade.php に2枚のカード追加
  - 在庫データ保存（success色）、在庫データ読込（warning色）
  - 既存カードと同一デザインパターン
