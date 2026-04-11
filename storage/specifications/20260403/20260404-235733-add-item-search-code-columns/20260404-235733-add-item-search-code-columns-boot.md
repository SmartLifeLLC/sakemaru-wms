# Work Plan: add-item-search-code-columns

- **ID**: add-item-search-code-columns
- **作成日**: 2026-04-05
- **最終更新**: 2026-04-05 (完了)
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260403/20260404-235733-add-item-search-code-columns/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260404-235733-add-item-search-code-columns-boot.md）
2. 20260404-235733-add-item-search-code-columns-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`wms_order_candidates`, `wms_stock_transfer_candidates`, `wms_order_incoming_schedules` に `item_code`・`search_code` カラムを追加。生成時に保存し、全リスト・モーダルで直接参照に統一する。

## 重要な設計制約

- **FK禁止**: 外部キーは使わない（アプリレベルで整合性管理）
- **migrate:fresh/refresh禁止**: 新規マイグレーションのみ
- **`item.code` → `item_code` 完全置き換え**: リレーション経由ではなく直接カラムを使用
- **`search_code` は varchar(30)**: 既存の incoming_schedules の varchar(500) は変更不要、新規追加分は varchar(30)
- **パフォーマンス**: 一括INSERT時は item_search_information を IN句で一括取得

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_add_item_code_search_code_to_wms_order_candidates_table.php`
- `database/migrations/XXXX_add_item_code_search_code_to_wms_stock_transfer_candidates_table.php`
- `database/migrations/XXXX_add_item_code_to_wms_order_incoming_schedules_table.php`

### 既存変更
- `app/Services/AutoOrder/OrderCandidateCalculationService.php`
- `app/Services/AutoOrder/OrderCreateJobHandler.php`
- `app/Services/AutoOrder/TransferCreateJobHandler.php`
- `app/Services/AutoOrder/OrderExecutionService.php`
- `app/Services/AutoOrder/TransferCandidateExecutionService.php`
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php`
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsTransferConfirmationWaitingTable.php`
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php`
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php`
- `resources/views/filament/components/order-candidate-detail.blade.php`
- `resources/views/filament/components/transfer-candidate-detail.blade.php`

### 参照のみ（変更禁止）
- `resources/views/filament/components/incoming-schedule-detail.blade.php`（参考レイアウト）

## テストデータ

```bash
php artisan tinker --execute="
\$c = \App\Models\WmsOrderCandidate::first();
echo 'order_candidate item_code: ' . \$c?->item_code . ', search_code: ' . \$c?->search_code;
"
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: マイグレーション | 完了 | 2026-04-05 | 3テーブル100%バックフィル済み |
| P2: サービス層 | 完了 | 2026-04-05 | 5サービスファイル更新、構文チェック通過 |
| P3: テーブル一覧UI | 完了 | 2026-04-05 | 6テーブルitem.code→item_code、search_code追加 |
| P4: 詳細モーダルUI | 完了 | 2026-04-05 | 2 blade + 6 viewData更新 |
| P5: 動作確認テスト | 完了 | 2026-04-05 | 全テスト通過 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーション（P1完了）
- マイグレーションファイル名: 2026_04_05_002320, 2026_04_05_002321, 2026_04_05_002322
- バックフィル: order_candidates 2210件, transfer_candidates 267件, incoming_schedules 1000件 (全100%)

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチで作業）
- ベースブランチ: main

---

## Phase完了記録

### P1: マイグレーション
- 完了日: 2026-04-05
- 実績:
  - 3マイグレーション作成・実行成功
  - 全テーブル100%バックフィル、item_code不一致0件

### P2: サービス層
- 完了日: 2026-04-05
- 実績:
  - OrderCandidateCalculationService: searchCodes一括プリロード追加、transfer/order INSERT配列にitem_code/search_code追加
  - OrderCreateJobHandler: create()にitem_code/search_code追加、getSearchCodeForItem()追加
  - TransferCreateJobHandler: create()にitem_code/search_code追加、getSearchCodeForItem()追加
  - OrderExecutionService: 3箇所のcreate()にitem_code追加
  - TransferCandidateExecutionService: create()にitem_code追加

### P3: テーブル一覧UI
- 完了日: 2026-04-05
- 実績:
  - 6テーブルでitem.code→item_code置き換え
  - 4テーブルにsearch_codeカラム新規追加
  - WmsStockTransferCandidatesTableの動的クエリ削除→直接カラム参照

### P4: 詳細モーダルUI
- 完了日: 2026-04-05
- 実績:
  - order-candidate-detail.blade.php: searchCode表示追加
  - transfer-candidate-detail.blade.php: searchCode表示追加
  - 6テーブルのviewDataにsearchCode追加、itemCodeをrecord直接参照に変更

### P5: 動作確認テスト
- 完了日: 2026-04-05
- 実績:
  - DB整合性: 全テーブル100%充填、不一致0件
  - 画面アクセス: 5ページ全てHTTP 200
  - インデックス: EXPLAIN確認、全3インデックス使用
  - Blade: 両テンプレートに$searchCode表示あり
  - リグレッション: item.nameリレーション健全、incoming search_code正常
