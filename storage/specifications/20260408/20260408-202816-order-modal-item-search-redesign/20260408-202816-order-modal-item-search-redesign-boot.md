# Work Plan: order-modal-item-search-redesign

- **ID**: order-modal-item-search-redesign
- **作成日**: 2026-04-08
- **最終更新**: 2026-04-08
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260408/20260408-202816-order-modal-item-search-redesign/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260408-202816-order-modal-item-search-redesign-boot.md）
2. 20260408-202816-order-modal-item-search-redesign-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

発注・移動候補追加モーダルの商品入力UIを「検索フィルタ付き商品リスト＋行内数量入力」にリデザイン。出荷実績サマリテーブル（`stats_item_warehouse_sales_summaries`）を新設し、日別出荷テーブル（`stats_item_warehouse_daily_sales`）から集計。検索結果に出荷実績（3d/7d）と既存PENDING候補数量を表示する。

## 重要な設計制約

- FK禁止: 全テーブルで外部キーを設定しない
- `migrate:fresh` / `migrate:refresh` / `migrate:reset` 禁止
- 照合順序: `utf8mb4_general_ci` を指定
- 検索パフォーマンス: ページネーション必須
- 全角→半角変換: `mb_convert_kana($search, 'as')` を検索時に適用
- モーダルデザイン: `storage/specifications/20260311/modal-design/spec.md` に準拠
- 移動候補のケース/バラ分別: 自動計算時にケースで割り、余りをバラに

## 確認済み回答

| 質問 | 回答 |
|------|------|
| 出荷データソース | `stats_item_warehouse_daily_sales` |
| 最終入荷日 | 対応しない（検索項目に入れない） |
| 移動候補のケース/バラ分別 | ケース・バラ分別。自動計算時にケースで割り余りバラ |
| 検索結果の範囲 | 全商品。選択された倉庫のサマリのみ |
| サマリ集計タイミング | 日次更新時 |
| 既存候補のプリセット | 全PENDING |

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_create_stats_item_warehouse_daily_sales_table.php`
- `database/migrations/XXXX_create_stats_item_warehouse_sales_summaries_table.php`
- `app/Models/StatsItemWarehouseDailySales.php`
- `app/Models/StatsItemWarehouseSalesSummary.php`
- `app/Console/Commands/Stats/SyncSalesSummariesCommand.php`

### 既存変更
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`
- `resources/views/filament/components/order-candidate-create-items.blade.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php`
- `resources/views/filament/components/transfer-order-create-items.blade.php`

### 参照のみ（変更禁止）
- `app/Models/Sakemaru/Item.php`
- `app/Models/Sakemaru/ItemContractor.php`
- `app/Models/Sakemaru/ItemSearchInformation.php`
- `app/Models/Sakemaru/ItemCategory.php`
- `app/Models/WmsOrderCandidate.php`
- `app/Models/WmsStockTransferCandidate.php`

## テストデータ

- `php artisan wms:sync-sales-summaries` で日次サマリを生成
- 倉庫91の商品データで動作確認

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: DB・モデル作成 | 完了 | 2026-04-08 | テーブル既存、モデル2件作成 |
| P2: サマリ集計コマンド | 完了 | 2026-04-08 | wms:sync-sales-summaries コマンド作成・動作確認 |
| P3: 発注候補モーダルUI | 完了 | 2026-04-08 | 検索フィルタUI + searchItemsForModal + getSubCategories |
| P4: 移動候補モーダルUI | 完了 | 2026-04-08 | 検索フィルタUI + ケース/バラ自動分割 |
| P5: 動作確認・調整 | 完了 | 2026-04-08 | 全ファイル構文OK、ビルド成功 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### テーブル情報（P1完了）
- daily_sales: テーブル既存。カラム: business_date(date), shipped_piece_qty, shipped_case_qty, shipped_bottle_qty
- summaries: テーブル既存。仕様通りのカラム構成
- マイグレーション: 不要（テーブル既存のため削除済み）

### 検索メソッド（P3完了）
- Livewireメソッド名: searchItemsForModal(), getSubCategories()
- ページネーション件数: 25件/ページ
- 検索フィルタ: keyword, contractorId, category1/2/3, lastShippedFrom/To
- PENDING候補プリセット: 全PENDING対象

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: DB・モデル作成
- 完了日: 2026-04-08
- 実績:
  - テーブル2件とも既存（マイグレーション不要、作成したファイルは削除）
  - daily_salesのカラム名が仕様と異なる: target_date→business_date, shipped_amount→なし, shipped_bottle_qty追加
  - モデル作成: StatsItemWarehouseDailySales.php, StatsItemWarehouseSalesSummary.php

### P2: サマリ集計コマンド
- 完了日: 2026-04-08
- 実績:
  - app/Console/Commands/Stats/SyncSalesSummariesCommand.php 作成
  - `wms:sync-sales-summaries` コマンド（--warehouse-id, --dry-run オプション対応）
  - バッチupsert（1000件チャンク）で高速処理
  - dry-run動作確認済み（daily_salesデータなしで0件正常終了）

### P3: 発注候補モーダルUI
- 完了日: 2026-04-08
- 実績:
  - ListWmsOrderCandidates.php に searchItemsForModal(), getSubCategories() 追加
  - order-candidate-create-items.blade.php を全面リデザイン（検索フィルタ + 結果テーブル + ページネーション）
  - 出荷実績（3d/7d）表示、PENDING候補数量プリセット、カテゴリ連動セレクト

### P4: 移動候補モーダルUI
- 完了日: 2026-04-08
- 実績:
  - searchItemsForModal() + getSubCategories() を ListWmsStockTransferCandidates.php に追加
  - transfer-order-create-items.blade.php を検索フィルタ付きリスト形式に全面リデザイン
  - 登録アクションにケース/バラ自動分割ロジック実装（intdiv + mod）
  - 数量入力はバラ換算単一カラム、登録時にケース行・バラ行を自動生成
  - php -l 構文チェック通過

### P5: 動作確認・調整
- 完了日: 2026-04-08
- 実績:
  - 全PHPファイル php -l 構文チェック通過（6ファイル）
  - npm run build 成功（Vite + Tailwind CSS 4）
  - ブラウザE2E確認はユーザーに委任
