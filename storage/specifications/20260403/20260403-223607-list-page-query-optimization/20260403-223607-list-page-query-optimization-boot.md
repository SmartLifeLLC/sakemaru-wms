# Work Plan: list-page-query-optimization

- **ID**: list-page-query-optimization
- **作成日**: 2026-04-03
- **最終更新**: 2026-04-03
- **ステータス**: 進行中
- **ディレクトリ**: `storage/specifications/20260403/20260403-223607-list-page-query-optimization/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260403-223607-list-page-query-optimization-boot.md）
2. 20260403-223607-list-page-query-optimization-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

全12リストページのN+1クエリ解消・フィルター最適化・キャッシュ重複削減を実施。共通trait（HasOptimizedFilters, HasStockSubqueries）を作成し、各ページに適用する。

## 重要な設計制約

- **FK禁止**: サブクエリは `whereColumn` で結合
- **migrate:fresh 禁止**: スキーマ変更なし（コード変更のみ）
- **表示内容不変**: 最適化前後で画面表示が変わらないこと
- **Filament 4 API**: `modifyQueryUsing`、`addSelect` を使用
- **loadGroupedTasks() 維持**: WmsShipmentSlips の複合キーグルーピングは現行パターン維持

## 対象ファイル

### 新規作成
- `app/Filament/Concerns/HasOptimizedFilters.php` — フィルター共通化trait
- `app/Filament/Concerns/HasStockSubqueries.php` — 在庫サブクエリ共通化trait

### 既存変更（Phase 2: HIGH 5ページ）
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`
- `app/Filament/Resources/WmsShipmentSlips/Pages/ListWmsShipmentSlips.php`
- `app/Filament/Resources/WmsShipmentSlips/Tables/WmsShipmentSlipsTable.php`
- `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingTasks.php`
- `app/Filament/Resources/WmsPickingTasks/Tables/WmsPickingTasksTable.php`
- `app/Filament/Resources/WmsOrderConfirmed/Pages/ListWmsOrderConfirmed.php`
- `app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php`

### 既存変更（Phase 3: MEDIUM 7ページ）
- `app/Filament/Resources/WmsShortages/Pages/ListWmsShortages.php`
- `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php`
- `app/Filament/Resources/WmsPickingItemResults/Pages/ListWmsPickingItemResults.php`
- `app/Filament/Resources/WmsPickingItemResults/Tables/WmsPickingItemResultsTable.php`
- `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php`
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php`
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php`
- `app/Filament/Resources/WmsIncomingTransmitted/Tables/WmsIncomingTransmittedTable.php`
- `app/Filament/Resources/WmsQueueJobs/Tables/WmsQueueJobsTable.php`

### 参照のみ（変更禁止）
- `app/Filament/Resources/WmsOrderIncomingSchedules/` — 最適化済みパターンの参考
- `app/Filament/Concerns/HasExportAction.php` — 既存trait構造の参考

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 共通trait作成 | 未着手 | - | HasOptimizedFilters + HasStockSubqueries + IncomingSchedules書き換え検証 |
| P2-1: WmsOrderCandidates | 未着手 | - | eager load + filter最適化 + batch_code改善 |
| P2-2: WmsStockTransferCandidates | 未着手 | - | deep chain eager load + calculationLog + filter |
| P2-3: WmsShipmentSlips | 未着手 | - | grouped_tasks以外のeager load補完 |
| P2-4: WmsPickingTasks | 未着手 | - | pickingItemResults eager load + 集計サブクエリ化 |
| P2-5: WmsOrderConfirmed | 未着手 | - | eager load + filter最適化 |
| P3-1: WmsShortages | 未着手 | - | trade chain eager load |
| P3-2: WmsPickingItemResults | 未着手 | - | deep chain eager load |
| P3-3: WmsShortagesWaitingApprovals | 未着手 | - | eager load追加 |
| P3-4: WmsOrderConfirmationWaiting | 未着手 | - | eager load追加 |
| P3-5: WmsIncomingCompleted | 未着手 | - | eager load追加 |
| P3-6: WmsIncomingTransmitted | 未着手 | - | eager load追加 |
| P3-7: WmsQueueJobs | 未着手 | - | 軽微（必要に応じ） |
| P4: テスト・検証 | 未着手 | - | Debugbar確認 + パフォーマンス比較 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 各ページのN+1状況（P2/P3実施時に記入）
- WmsOrderCandidates: (実施後に記入)
- WmsStockTransferCandidates: (実施後に記入)
- WmsShipmentSlips: (実施後に記入)
- WmsPickingTasks: (実施後に記入)
- WmsOrderConfirmed: (実施後に記入)
- WmsShortages: (実施後に記入)
- WmsPickingItemResults: (実施後に記入)
- WmsShortagesWaitingApprovals: (実施後に記入)
- WmsOrderConfirmationWaiting: (実施後に記入)
- WmsIncomingCompleted: (実施後に記入)
- WmsIncomingTransmitted: (実施後に記入)
- WmsQueueJobs: (実施後に記入)

### trait適用状況（P1完了時に記入）
- HasOptimizedFilters: (実施後に記入)
- HasStockSubqueries: (実施後に記入)
- IncomingSchedules書き換え検証: (実施後に記入)

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: 共通trait作成
- 完了日: -
- 実績:
  - (完了後に記入)

### P2-1: WmsOrderCandidates
- 完了日: -
- 実績:
  - (完了後に記入)

### P2-2: WmsStockTransferCandidates
- 完了日: -
- 実績:
  - (完了後に記入)

### P2-3: WmsShipmentSlips
- 完了日: -
- 実績:
  - (完了後に記入)

### P2-4: WmsPickingTasks
- 完了日: -
- 実績:
  - (完了後に記入)

### P2-5: WmsOrderConfirmed
- 完了日: -
- 実績:
  - (完了後に記入)

### P3-1: WmsShortages
- 完了日: -
- 実績:
  - (完了後に記入)

### P3-2: WmsPickingItemResults
- 完了日: -
- 実績:
  - (完了後に記入)

### P3-3: WmsShortagesWaitingApprovals
- 完了日: -
- 実績:
  - (完了後に記入)

### P3-4: WmsOrderConfirmationWaiting
- 完了日: -
- 実績:
  - (完了後に記入)

### P3-5: WmsIncomingCompleted
- 完了日: -
- 実績:
  - (完了後に記入)

### P3-6: WmsIncomingTransmitted
- 完了日: -
- 実績:
  - (完了後に記入)

### P3-7: WmsQueueJobs
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: テスト・検証
- 完了日: -
- 実績:
  - (完了後に記入)
