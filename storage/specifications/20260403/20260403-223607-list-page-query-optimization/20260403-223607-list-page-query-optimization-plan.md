# 全リストページ N+1クエリ解消・パフォーマンス最適化 作業計画

## 前提

- WmsOrderIncomingSchedules ページで最適化を実施済み（パターンの参考）
  - `addSelect` サブクエリ化、フィルター`options()`削除、プロパティキャッシュ
- 仕様書: `20260403-223607-list-page-query-optimization.md`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 共通trait作成 | HasOptimizedFilters + HasStockSubqueries 作成、IncomingSchedulesで検証 | trait作成済み、IncomingSchedules書き換え後も表示不変 |
| P2-1 | WmsOrderCandidates | eager load + filter最適化 + batch_code改善 | N+1解消、filter動作正常 |
| P2-2 | WmsStockTransferCandidates | deep chain eager load + calculationLog + filter | N+1解消、filter動作正常 |
| P2-3 | WmsShipmentSlips | grouped_tasks以外のeager load補完 | N+1解消（loadGroupedTasks維持） |
| P2-4 | WmsPickingTasks | pickingItemResults eager load + 集計サブクエリ化 | N+1解消 |
| P2-5 | WmsOrderConfirmed | eager load + filter最適化 | N+1解消、filter動作正常 |
| P3-1 | WmsShortages | trade chain eager load | N+1解消 |
| P3-2 | WmsPickingItemResults | deep chain eager load | N+1解消 |
| P3-3 | WmsShortagesWaitingApprovals | eager load追加 | N+1解消 |
| P3-4 | WmsOrderConfirmationWaiting | eager load追加 | N+1解消 |
| P3-5 | WmsIncomingCompleted | eager load追加 | N+1解消 |
| P3-6 | WmsIncomingTransmitted | eager load追加 | N+1解消 |
| P3-7 | WmsQueueJobs | 軽微（必要に応じ） | 確認のみでもOK |
| P4 | テスト・検証 | 全ページDebugbar確認 | 重複クエリなし確認 |

---

## P1: 共通trait作成

### 目的

各ページで重複するフィルター定義と在庫サブクエリを共通化し、保守性を向上させる。

### 作業手順

#### 1-1. HasOptimizedFilters trait 作成

**ファイル**: `app/Filament/Concerns/HasOptimizedFilters.php`（新規）

5つのフィルターメソッドを実装:

```php
trait HasOptimizedFilters
{
    // warehouseFilter(): SelectFilter — searchable + getSearchResultsUsing (code/name検索, is_active, kana変換)
    // contractorFilter(): SelectFilter — searchable + getSearchResultsUsing (code/name検索, kana変換)
    // supplierFilter(): SelectFilter — searchable + getSearchResultsUsing (partner経由, kana変換)
    // batchCodeFilter(string $modelClass): SelectFilter — distinct + limit 50 + orderByDesc
    // statusFilter(string $enumClass): SelectFilter — Enum::cases() → label()
}
```

実装の参考: 仕様書セクション3.2(a) の完全なコード定義。

#### 1-2. HasStockSubqueries trait 作成

**ファイル**: `app/Filament/Concerns/HasStockSubqueries.php`（新規）

3つのサブクエリメソッドを実装:

```php
trait HasStockSubqueries
{
    // currentStockSubquery(string $mainTable): Builder — SUM(current_quantity)
    // availableStockSubquery(string $mainTable): Builder — SUM(available_quantity)
    // defaultLocationSubquery(string $mainTable): Builder — CONCAT(code1-code2-code3) via item_incoming_default_locations
}
```

実装の参考: `ListWmsOrderIncomingSchedules.php` の `addSelect` セクション（L159-171）。

#### 1-3. WmsOrderIncomingSchedules を新traitで書き換え検証

**ファイル**:
- `app/Filament/Resources/WmsOrderIncomingSchedules/Pages/ListWmsOrderIncomingSchedules.php`
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php`

作業:
1. List Page: `use HasStockSubqueries;` 追加、`addSelect` 内をtrait呼び出しに置換
2. Table: `use HasOptimizedFilters;` 追加、contractor/warehouseフィルターをtrait呼び出しに置換
3. 画面表示が最適化前と同一であることを確認

### 完了条件

- 2つのtrait が作成されている
- IncomingSchedules が新traitを使用し、表示内容が変わらない
- `php artisan test` が通る（既存テストに影響なし）

---

## P2-1: WmsOrderCandidates

### 目的

発注候補リストのN+1クエリ解消とフィルター最適化。

### 調査手順

1. `Tables/WmsOrderCandidatesTable.php` を読み、`->state()` や `->formatStateUsing()` でリレーションアクセスしている箇所を特定
2. `Pages/ListWmsOrderCandidates.php` を読み、現在の `modifyQueryUsing` / `with()` を確認
3. フィルター定義で `->options()` を使っている箇所を特定

### 修正方針

1. **List Page**: `modifyQueryUsing` に不足している eager load を追加（warehouse, item, contractor, supplier, orderCandidate 等）
2. **Table**: `->options()` 全件ロードを `HasOptimizedFilters` trait のメソッドに置換
3. **Table**: batch_code フィルターを `batchCodeFilter(WmsOrderCandidate::class)` に置換
4. **Table**: `->state()` で在庫計算をしている箇所があれば `addSelect` サブクエリ化

### 修正対象ファイル

- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`

### 完了条件

- N+1パターンが解消されている（Debugbar確認可能な状態）
- フィルターが searchable 動作する
- 表示内容が変わらない

---

## P2-2: WmsStockTransferCandidates

### 目的

移動候補リストのN+1解消。deep chain リレーション（deliveryCourse/satellite/hub/contractor/item）と calculationLog の最適化。

### 調査手順

1. `Tables/WmsStockTransferCandidatesTable.php` を読み、リレーションアクセス箇所を特定
2. `Pages/ListWmsStockTransferCandidates.php` を読み、現在の eager load を確認
3. calculationLog の取得パターンを確認（N+1 or 一括）

### 修正方針

1. **List Page**: 必要なeager loadを `->with()` に追加（deep chain含む）
2. **Table**: フィルターを `HasOptimizedFilters` trait に置換
3. **Table**: calculationLog が行ごと取得なら、eager load or サブクエリ化

### 修正対象ファイル

- `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`

### 完了条件

- N+1解消、フィルター動作正常、表示不変

---

## P2-3: WmsShipmentSlips

### 目的

出荷伝票リストのeager load補完。`loadGroupedTasks()` は維持。

### 調査手順

1. `Tables/WmsShipmentSlipsTable.php` を読み、リレーションアクセス箇所を特定
2. `Pages/ListWmsShipmentSlips.php` を読み、現在の `with()` と `loadGroupedTasks()` を確認
3. `loadGroupedTasks()` 以外で N+1 になっている箇所を特定

### 修正方針

1. **List Page**: 不足しているeager loadを追加
2. `loadGroupedTasks()` は変更しない（複合キーグルーピング、パフォーマンス良好）
3. 必要に応じてプロパティキャッシュ追加

### 修正対象ファイル

- `app/Filament/Resources/WmsShipmentSlips/Pages/ListWmsShipmentSlips.php`
- `app/Filament/Resources/WmsShipmentSlips/Tables/WmsShipmentSlipsTable.php`

### 完了条件

- loadGroupedTasks以外のN+1が解消、表示不変

---

## P2-4: WmsPickingTasks

### 目的

ピッキングタスクリストの pickingItemResults ループN+1 と trade.serial_id N+1 の解消。

### 調査手順

1. `Tables/WmsPickingTasksTable.php` を読み、`pickingItemResults` のループ箇所を特定
2. `Pages/ListWmsPickingTasks.php` を読み、現在のeager loadを確認
3. 集計（件数、完了数等）が行ごとに計算されていないか確認

### 修正方針

1. **List Page**: `pickingItemResults` をeager loadに追加
2. **Table**: 集計値がN+1なら `addSelect` サブクエリ化（COUNT, SUM等）
3. trade.serial_id アクセスも eager load で解決

### 修正対象ファイル

- `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingTasks.php`
- `app/Filament/Resources/WmsPickingTasks/Tables/WmsPickingTasksTable.php`

### 完了条件

- N+1解消、表示不変

---

## P2-5: WmsOrderConfirmed

### 目的

確定済み発注リストのN+1解消とフィルター最適化。

### 調査手順

1. `Tables/WmsOrderConfirmedTable.php` を読み、リレーションアクセス箇所を特定
2. `Pages/ListWmsOrderConfirmed.php` を読み、現在の状況を確認
3. フィルターで `->options()` を使っている箇所を特定

### 修正方針

1. **List Page**: eager load追加
2. **Table**: フィルターを `HasOptimizedFilters` trait に置換
3. 在庫系表示があれば `HasStockSubqueries` 適用

### 修正対象ファイル

- `app/Filament/Resources/WmsOrderConfirmed/Pages/ListWmsOrderConfirmed.php`
- `app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php`

### 完了条件

- N+1解消、フィルター動作正常、表示不変

---

## P3-1: WmsShortages

### 目的

欠品リストの trade chain (trade.partner, earning.delivery_course) と item.volume の N+1 解消。

### 修正方針

1. `->with(['trade.partner', 'trade.earning.deliveryCourse', 'item'])` で一括eager load
2. `->state()` でリレーションアクセスしている箇所をeager loadされたデータ参照に変更

### 修正対象ファイル

- `app/Filament/Resources/WmsShortages/Pages/ListWmsShortages.php`
- `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php`

### 完了条件

- N+1解消、表示不変

---

## P3-2: WmsPickingItemResults

### 目的

ピッキング結果リストの deep chain (trade/earning/buyer/partner/delivery_course) N+1 解消。

### 修正方針

1. List Page に `->with()` で deep chain eager load 追加
2. テーブルカラムがリレーション経由でアクセスしている箇所を確認・最適化

### 修正対象ファイル

- `app/Filament/Resources/WmsPickingItemResults/Pages/ListWmsPickingItemResults.php`
- `app/Filament/Resources/WmsPickingItemResults/Tables/WmsPickingItemResultsTable.php`

### 完了条件

- N+1解消、表示不変

---

## P3-3: WmsShortagesWaitingApprovals

### 修正方針

1. confirmedBy/wave/trade.partner/item の eager load 追加

### 修正対象ファイル

- `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php`
- 対応する List Page（存在する場合）

### 完了条件

- N+1解消、表示不変

---

## P3-4: WmsOrderConfirmationWaiting

### 修正方針

1. batch_code/warehouse/item/contractor の eager load 追加
2. フィルターがあれば `HasOptimizedFilters` 適用

### 修正対象ファイル

- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php`
- 対応する List Page

### 完了条件

- N+1解消、表示不変

---

## P3-5: WmsIncomingCompleted

### 修正方針

1. 軽微なeager load追加で解決可能

### 修正対象ファイル

- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php`
- 対応する List Page

### 完了条件

- N+1解消、表示不変

---

## P3-6: WmsIncomingTransmitted

### 修正方針

1. 軽微なeager load追加で解決可能

### 修正対象ファイル

- `app/Filament/Resources/WmsIncomingTransmitted/Tables/WmsIncomingTransmittedTable.php`
- 対応する List Page

### 完了条件

- N+1解消、表示不変

---

## P3-7: WmsQueueJobs

### 修正方針

1. source_reference/result JSON parse は CPU 負荷のみ（N+1ではない）
2. 確認のみで変更不要の可能性あり

### 修正対象ファイル

- `app/Filament/Resources/WmsQueueJobs/Tables/WmsQueueJobsTable.php`

### 完了条件

- 確認完了（変更不要であればその旨記録）

---

## P4: テスト・検証

### 目的

全ページの最適化結果を検証する。

### 検証手順

1. 各ページを Debugbar 有効状態で表示
2. 以下を確認:
   - **Models タブ**: リレーションモデルの取得数がページ件数を大幅に超えていないこと
   - **Queries タブ**: 同一テーブルへの重複SELECT がないこと
   - **実行時間**: 著しい悪化がないこと
3. フィルターの searchable 動作確認（検索→選択→適用）
4. ソート・ページネーション動作確認
5. モーダル表示データの正確性確認

### ページ別チェックリスト

| ページ | N+1 | Filter | Sort | Modal | PresetView |
|--------|-----|--------|------|-------|------------|
| WmsOrderCandidates | [] | [] | [] | [] | [] |
| WmsStockTransferCandidates | [] | [] | [] | [] | [] |
| WmsShipmentSlips | [] | - | [] | [] | [] |
| WmsPickingTasks | [] | - | [] | [] | [] |
| WmsOrderConfirmed | [] | [] | [] | [] | - |
| WmsShortages | [] | - | [] | [] | [] |
| WmsPickingItemResults | [] | - | [] | - | - |
| WmsShortagesWaitingApprovals | [] | - | [] | [] | - |
| WmsOrderConfirmationWaiting | [] | - | [] | [] | [] |
| WmsIncomingCompleted | [] | - | [] | [] | [] |
| WmsIncomingTransmitted | [] | - | [] | [] | - |
| WmsQueueJobs | - | - | [] | [] | - |

### 完了条件

- 全ページでN+1パターンが解消されていること
- 重複クエリがないこと
- 表示内容が最適化前と同一であること

---

## 制約（厳守）

1. **FK禁止**: サブクエリは `whereColumn` で結合（外部キー制約なし）
2. **migrate:fresh/refresh/reset/db:wipe 禁止**: 本タスクはスキーマ変更なし
3. **表示内容不変**: 最適化前後で画面の表示データが変わらないこと
4. **Filament 4 API 準拠**: `modifyQueryUsing`, `addSelect` を使用
5. **loadGroupedTasks() 維持**: WmsShipmentSlips の複合キーグルーピングは変更しない
6. **計算ロジック変更禁止**: 表示値の計算方法は変えない（クエリ方法のみ変更）

## 全体完了条件

- 全12ページでN+1クエリが解消されている
- フィルターが全件ロードしていない（searchable方式に統一）
- 共通traitが適用可能なページ全てに適用されている
- Debugbar で重複クエリが確認されないこと
