# Work Plan: fix-monthly-safety-stock-csv-import

- **ID**: fix-monthly-safety-stock-csv-import
- **作成日**: 2026-03-01
- **最終更新**: 2026-03-02
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/ordering/20260301-fix-monthly-safety-stock-csv-import/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260301-fix-monthly-safety-stock-csv-import-boot.md）
2. 20260301-fix-monthly-safety-stock-csv-import-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

HanaDB発注点分析CSV（`store_item_order_points.csv`）のフォーマットに対応する専用インポートジョブとUIアクションを追加する。既存の5カラム形式インポートは変更しない。

## 重要な設計制約

1. **FK禁止**: テーブルにFKを追加しない
2. **migrate:fresh/refresh/reset禁止**: DB破壊コマンドは絶対に使用しない
3. **既存機能維持**: 現行の `ImportMonthlySafetyStocksCsvJob` は一切変更しない
4. **負の値クランプ**: 分析CSVの負の値は `max(0, ...)` で0にする
5. **パフォーマンス**: `upsert()` 一括実行でN+1を防止（`updateOrCreate` ループは避ける）

## 対象ファイル

### 新規作成
- `app/Jobs/ImportOrderPointAnalysisCsvJob.php` — 発注点分析CSV用インポートジョブ

### 既存変更
- `app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php` — 新アクション追加

### 参照のみ（変更禁止）
- `app/Jobs/ImportMonthlySafetyStocksCsvJob.php` — 既存インポートジョブ（パターン参照）
- `app/Models/WmsMonthlySafetyStock.php` — モデル定義
- `app/Models/WmsImportLog.php` — インポートログ
- `app/Models/Sakemaru/Item.php` — 商品マスタ
- `app/Models/Sakemaru/Warehouse.php` — 倉庫マスタ
- `app/Models/Sakemaru/Contractor.php` — 発注先マスタ
- `app/Models/Sakemaru/ItemContractor.php` — 商品-発注先マスタ

## テストデータ

- 分析CSV: `/Users/jungsinyu/PycharmProjects/HanaDBTransfer/data-analysis/1.hana/1.ordering-system/2.order-point/store_item_order_points.csv`
- フォーマット: `store_code,item_code,avg_daily_sales,std_daily_sales,avg_daily_orders,lead_time_days,safety_stock,order_point,total_sales_qty_2y,total_order_qty_2y,sales_days_count`
- 約119,266行（ヘッダー除く）
- `store_code` は全行 `1`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: ImportOrderPointAnalysisCsvJob作成 | 完了 | 2026-03-02 | Jobクラス作成、pint通過 |
| P2: ListPage UIアクション追加 | 完了 | 2026-03-02 | アクション追加、pint通過 |
| P3: 動作確認 | 完了 | 2026-03-02 | シンタックスOK、ルート登録OK、tinkerインスタンス化OK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### CSVカラムマッピング
| Index | CSV Header | 用途 |
|-------|-----------|------|
| 0 | store_code | UI側で倉庫指定（無視） |
| 1 | item_code | items.code でルックアップ → item_id |
| 6 | safety_stock | インポート候補値①（安全在庫） |
| 7 | order_point | インポート候補値②（発注点）**デフォルト** |

### item_contractors 逆引き
- `item_contractors` テーブルから `(item_id, warehouse_id)` → `contractor_id` を取得
- 同一 (item_id, warehouse_id) に複数レコードがある場合 → 全contractor_idに対して登録

### パフォーマンス方針
- `WmsMonthlySafetyStock::upsert()` で一括挿入/更新
- チャンクサイズ: 1000行
- upsert バッチサイズ: 500件

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: ImportOrderPointAnalysisCsvJob作成
- 完了日: 2026-03-02
- 成果物: `app/Jobs/ImportOrderPointAnalysisCsvJob.php`
- 実績:
  - 新規Jobクラス作成（既存ImportMonthlySafetyStocksCsvJobと同構造）
  - upsert()バッチ処理（500件/バッチ）でパフォーマンス対応
  - item_contractorsのgroupBy逆引きで複数発注先に対応
  - BOM除去、Shift-JIS変換、負の値クランプ実装
  - pint通過

### P2: ListPage UIアクション追加
- 完了日: 2026-03-02
- 成果物: `app/Filament/Resources/WmsMonthlySafetyStocks/Pages/ListWmsMonthlySafetyStocks.php`
- 実績:
  - 「発注点分析CSVインポート」アクション追加（importAnalysisCsv）
  - モーダルフォーム: ファイル、倉庫選択(searchable)、月モード(live)、月、値カラム
  - dispatchAnalysisCsvImportJob()メソッド追加
  - use文追加: ImportOrderPointAnalysisCsvJob, Warehouse, Select
  - pint通過

### P3: 動作確認
- 完了日: 2026-03-02
- 実績:
  - PHP syntax check 通過（両ファイル）
  - route:list でルート登録確認
  - tinkerでJobインスタンス化確認
  - pint全ファイル通過
  - ブラウザでの実CSVインポートテストはユーザー実行待ち
