# 全リストページ N+1クエリ解消・パフォーマンス最適化

- **作成日**: 2026-04-03
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260403/20260403-223607-list-page-query-optimization/`

## 背景・目的

入荷予定（WmsOrderIncomingSchedules）ページで以下の最適化を実施済み:
- `->state(function)` によるN+1クエリ → サブクエリ（`addSelect`）に変更
- フィルターの `options()` 全件ロード → `searchable()` + `getSearchResultsUsing()` に変更
- `getPresetViews()` のキャッシュ重複読み取り → プロパティキャッシュ

**同様の問題が全リストページに存在する。** 本仕様は全ページ一括で最適化を実施する作業計画。

## 1. 対象ページ一覧

### 優先度HIGH（N+1 + フィルター問題）

| # | リソース | List Page | Table File | 主な問題 |
|---|----------|-----------|------------|----------|
| 1 | WmsOrderCandidates | `Pages/ListWmsOrderCandidates.php` | `Tables/WmsOrderCandidatesTable.php` | warehouse/supplier/contractor N+1、batch_code全件ロード、Contractor/Supplier全件filter |
| 2 | WmsStockTransferCandidates | `Pages/ListWmsStockTransferCandidates.php` | `Tables/WmsStockTransferCandidatesTable.php` | deliveryCourse/satellite/hub/contractor/item N+1、calculationLog N+1、batch_code全件filter |
| 3 | WmsShipmentSlips | `Pages/ListWmsShipmentSlips.php` | `Tables/WmsShipmentSlipsTable.php` | grouped_tasks N+1、一部eager load済みだが不足 |
| 4 | WmsPickingTasks | `Pages/ListWmsPickingTasks.php` | `Tables/WmsPickingTasksTable.php` | pickingItemResults loop N+1、trade.serial_id N+1 |
| 5 | WmsOrderConfirmed | `Pages/ListWmsOrderConfirmed.php` | `Tables/WmsOrderConfirmedTable.php` | warehouse/item/contractor N+1、batch_code/Warehouse/Contractor全件filter |

### 優先度MEDIUM（N+1のみ）

| # | リソース | List Page | Table File | 主な問題 |
|---|----------|-----------|------------|----------|
| 6 | WmsShortages | `Pages/ListWmsShortages.php` | `Tables/WmsShortagesTable.php` | trade.partner/earning.delivery_course N+1、item.volume N+1 |
| 7 | WmsPickingItemResults | `Pages/ListWmsPickingItemResults.php` | `Tables/WmsPickingItemResultsTable.php` | trade/earning/buyer/partner/delivery_course chain N+1 |
| 8 | WmsShortagesWaitingApprovals | 同上 | `Tables/WmsShortagesWaitingApprovalsTable.php` | confirmedBy/wave/trade.partner/item N+1 |
| 9 | WmsOrderConfirmationWaiting | 同上 | `Tables/WmsOrderConfirmationWaitingTable.php` | batch_code/warehouse/item/contractor N+1 |
| 10 | WmsIncomingCompleted | 同上 | `Tables/WmsIncomingCompletedTable.php` | 軽微（eager loadで解決可能） |
| 11 | WmsIncomingTransmitted | 同上 | `Tables/WmsIncomingTransmittedTable.php` | 軽微（eager loadで解決可能） |
| 12 | WmsQueueJobs | 同上 | `Tables/WmsQueueJobsTable.php` | source_reference/result JSON parse（CPU負荷のみ） |

### 最適化済み

| # | リソース | 状況 |
|---|----------|------|
| - | WmsOrderIncomingSchedules | 済（サブクエリ化、filter最適化、キャッシュ最適化） |

## 2. 問題パターン分析

### パターンA: リレーション経由の個別クエリ（N+1）

```php
// 問題: 行ごとにSQLが発行される
TextColumn::make('current_stock')
    ->state(function ($record) {
        return RealStock::where('warehouse_id', $record->warehouse_id)
            ->sum('current_quantity');
    })
```

**解決策**: `addSelect` サブクエリ or eager load

```php
// サブクエリ化
->addSelect([
    'computed_current_stock' => RealStock::selectRaw('COALESCE(SUM(current_quantity), 0)')
        ->whereColumn('real_stocks.warehouse_id', 'main_table.warehouse_id')
        ->whereColumn('real_stocks.item_id', 'main_table.item_id'),
])
```

### パターンB: フィルターの全件ロード

```php
// 問題: 全Contractor（1070件）をメモリにロード
SelectFilter::make('contractor_id')
    ->options(fn () => Contractor::query()->get()->mapWithKeys(...))
```

**解決策**: `options()` 削除、`searchable()` + `getSearchResultsUsing()` のみ

```php
SelectFilter::make('contractor_id')
    ->searchable()
    ->getSearchResultsUsing(function (string $search): array {
        return Contractor::query()
            ->where('name', 'like', "%{$search}%")
            ->limit(50)->get()
            ->mapWithKeys(...)->toArray();
    })
```

### パターンC: PresetView のキャッシュ重複

```php
// 問題: getPresetViews() が1リクエストで複数回呼ばれる → cache()も複数回
$warehouseData = cache()->remember($cacheKey, 30, fn () => ...);
```

**解決策**: プロパティキャッシュで同一リクエスト内の再取得防止

### パターンD: 深いリレーションチェーン

```php
// 問題: trade -> earning -> delivery_course と3段階のリレーション
$record->trade->earning->delivery_course->name
```

**解決策**: `->with(['trade.earning.deliveryCourse'])` で一括eager load

## 3. 共通化・再利用構造

### 3.1 既存の共通パターン

- `HasExportAction` trait — エクスポートアクション共通化済み
- `PaginationOptions` enum — ページネーション設定共通
- `sticky-actions` CSS — 右固定列デザイン共通

### 3.2 新規共通化候補

#### (a) FilterHelper クラス

各テーブルで重複する `searchable` フィルターパターンを共通化:

```php
// app/Filament/Concerns/HasOptimizedFilters.php
trait HasOptimizedFilters
{
    protected static function contractorFilter(): SelectFilter
    {
        return SelectFilter::make('contractor_id')
            ->label('発注先')
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $search = mb_convert_kana($search, 'as');
                return Contractor::query()
                    ->where(fn ($q) => $q
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->orderBy('code')->limit(50)->get()
                    ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"])
                    ->toArray();
            });
    }

    protected static function warehouseFilter(): SelectFilter
    {
        return SelectFilter::make('warehouse_id')
            ->label('倉庫')
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $search = mb_convert_kana($search, 'as');
                return Warehouse::query()
                    ->where('is_active', true)
                    ->where(fn ($q) => $q
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->orderBy('code')->limit(50)->get()
                    ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                    ->toArray();
            });
    }

    protected static function supplierFilter(): SelectFilter
    {
        return SelectFilter::make('supplier_id')
            ->label('仕入先')
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                $search = mb_convert_kana($search, 'as');
                return Supplier::query()
                    ->with('partner')
                    ->whereHas('partner', fn ($q) => $q
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"))
                    ->limit(50)->get()
                    ->mapWithKeys(fn ($s) => [$s->id => "[{$s->partner?->code}]{$s->partner?->name}"])
                    ->toArray();
            });
    }

    protected static function batchCodeFilter(string $modelClass): SelectFilter
    {
        return SelectFilter::make('batch_code')
            ->label('実行CD')
            ->options(fn () => $modelClass::query()
                ->select('batch_code')
                ->distinct()
                ->orderByDesc('batch_code')
                ->limit(50)
                ->pluck('batch_code', 'batch_code')
                ->toArray())
            ->searchable();
    }

    protected static function statusFilter(string $enumClass): SelectFilter
    {
        return SelectFilter::make('status')
            ->label('ステータス')
            ->options(collect($enumClass::cases())->mapWithKeys(fn ($s) => [
                $s->value => $s->label(),
            ]));
    }
}
```

#### (b) StockSubqueryHelper

在庫系サブクエリの共通化:

```php
// app/Filament/Concerns/HasStockSubqueries.php
trait HasStockSubqueries
{
    protected static function currentStockSubquery(string $mainTable): \Illuminate\Database\Eloquent\Builder
    {
        return RealStock::selectRaw('COALESCE(SUM(current_quantity), 0)')
            ->whereColumn('real_stocks.warehouse_id', "{$mainTable}.warehouse_id")
            ->whereColumn('real_stocks.item_id', "{$mainTable}.item_id");
    }

    protected static function availableStockSubquery(string $mainTable): \Illuminate\Database\Eloquent\Builder { ... }
    protected static function defaultLocationSubquery(string $mainTable): \Illuminate\Database\Eloquent\Builder { ... }
}
```

## 4. 修正計画

### Phase 1: 共通パーツ準備

| ステップ | 内容 | 対象ファイル |
|----------|------|-------------|
| 1-1 | `HasOptimizedFilters` trait 作成 | `app/Filament/Concerns/HasOptimizedFilters.php`（新規） |
| 1-2 | `HasStockSubqueries` trait 作成 | `app/Filament/Concerns/HasStockSubqueries.php`（新規） |
| 1-3 | 既存 WmsOrderIncomingSchedules を新traitで書き換え（動作確認） | 既存ファイル |

### Phase 2: HIGH優先度ページ修正（5ページ）

| ステップ | リソース | 主な作業 |
|----------|----------|----------|
| 2-1 | WmsOrderCandidates | eager load追加、filter最適化、batch_code filter改善 |
| 2-2 | WmsStockTransferCandidates | eager load追加（deep chain）、calculationLog対策、filter最適化 |
| 2-3 | WmsShipmentSlips | grouped_tasks eager load補完 |
| 2-4 | WmsPickingTasks | pickingItemResults eager load、集計サブクエリ化 |
| 2-5 | WmsOrderConfirmed | eager load追加、filter最適化 |

### Phase 3: MEDIUM優先度ページ修正（7ページ）

| ステップ | リソース | 主な作業 |
|----------|----------|----------|
| 3-1 | WmsShortages | trade chain eager load |
| 3-2 | WmsPickingItemResults | deep chain eager load |
| 3-3 | WmsShortagesWaitingApprovals | eager load追加 |
| 3-4 | WmsOrderConfirmationWaiting | eager load追加 |
| 3-5 | WmsIncomingCompleted | eager load追加 |
| 3-6 | WmsIncomingTransmitted | eager load追加 |
| 3-7 | WmsQueueJobs | 軽微（必要に応じ） |

### Phase 4: テスト・検証

| ステップ | 内容 |
|----------|------|
| 4-1 | 各ページでDebugbar確認（モデル取得数、クエリ数、実行時間） |
| 4-2 | 100件表示時のパフォーマンス比較（before/after） |
| 4-3 | フィルター検索の動作確認 |
| 4-4 | PresetView タブ切り替えの動作確認 |

## 5. ページ別テスト計画

### テスト観点（全ページ共通）

| # | 観点 | 確認方法 |
|---|------|----------|
| 1 | N+1解消 | Debugbar Models タブでリレーションモデルの取得数が1（eager load）か確認 |
| 2 | クエリ数削減 | Debugbar Queries タブでSELECT文の総数が想定内か確認 |
| 3 | 表示内容不変 | 最適化前後で画面表示が同一か目視確認 |
| 4 | フィルター動作 | searchable フィルターで検索→選択→フィルタ適用が正常か確認 |
| 5 | ソート動作 | 各ソート可能カラムでASC/DESC切り替えが正常か確認 |
| 6 | ページネーション | 100件/500件/1000件表示切り替えが正常か確認 |
| 7 | モーダル表示 | 詳細モーダル等の表示データが正しいか確認 |

### ページ別テストマトリクス

| ページ | N+1 | Filter | Sort | Modal | PresetView |
|--------|-----|--------|------|-------|------------|
| WmsOrderCandidates | o | o (Contractor, Supplier, BatchCode) | o | o (計算詳細) | o (倉庫タブ) |
| WmsStockTransferCandidates | o | o (Contractor, BatchCode) | o | o (計算詳細) | o (倉庫タブ) |
| WmsShipmentSlips | o | - | o | o (伝票詳細) | o (倉庫タブ) |
| WmsPickingTasks | o | - | o | o | o |
| WmsOrderConfirmed | o | o (Warehouse, Contractor, BatchCode) | o | o | - |
| WmsShortages | o | - | o | o | o |
| WmsPickingItemResults | o | - | o | - | - |
| WmsShortagesWaitingApprovals | o | - | o | o | - |
| WmsOrderConfirmationWaiting | o | - | o | o | o |
| WmsIncomingCompleted | o | - | o | o | o |
| WmsIncomingTransmitted | o | - | o | o | - |
| WmsQueueJobs | - | - | o | o | - |

## 対象ファイル

### 新規作成

| ファイル | 用途 |
|----------|------|
| `app/Filament/Concerns/HasOptimizedFilters.php` | フィルター共通化trait |
| `app/Filament/Concerns/HasStockSubqueries.php` | 在庫サブクエリ共通化trait |

### 既存変更（Phase 2: HIGH）

| ファイル |
|----------|
| `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` |
| `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` |
| `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php` |
| `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` |
| `app/Filament/Resources/WmsShipmentSlips/Pages/ListWmsShipmentSlips.php` |
| `app/Filament/Resources/WmsShipmentSlips/Tables/WmsShipmentSlipsTable.php` |
| `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingTasks.php` |
| `app/Filament/Resources/WmsPickingTasks/Tables/WmsPickingTasksTable.php` |
| `app/Filament/Resources/WmsOrderConfirmed/Pages/ListWmsOrderConfirmed.php` |
| `app/Filament/Resources/WmsOrderConfirmed/Tables/WmsOrderConfirmedTable.php` |

### 既存変更（Phase 3: MEDIUM）

| ファイル |
|----------|
| `app/Filament/Resources/WmsShortages/Pages/ListWmsShortages.php` |
| `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php` |
| `app/Filament/Resources/WmsPickingItemResults/Pages/ListWmsPickingItemResults.php` |
| `app/Filament/Resources/WmsPickingItemResults/Tables/WmsPickingItemResultsTable.php` |
| `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php` |
| `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php` |
| `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php` |
| `app/Filament/Resources/WmsIncomingTransmitted/Tables/WmsIncomingTransmittedTable.php` |
| `app/Filament/Resources/WmsQueueJobs/Tables/WmsQueueJobsTable.php` |

### 参照のみ

| ファイル | 理由 |
|----------|------|
| `app/Filament/Resources/WmsOrderIncomingSchedules/` | 既に最適化済み。パターンの参考 |
| `app/Filament/Concerns/HasExportAction.php` | 既存trait構造の参考 |

## 制約

- **FK禁止**: サブクエリは `whereColumn` で結合（外部キー制約なし）
- **migrate:fresh 禁止**: スキーマ変更なし（本タスクはコード変更のみ）
- **表示内容不変**: 最適化前後で画面表示が変わらないこと
- **Filament 4 API**: `modifyQueryUsing`、`addSelect` を使用（Filament 3パターン不可）

## 確認事項（調査済み）

### 1. フィルター共通化範囲

調査結果: 以下5種を `HasOptimizedFilters` trait に含める。

| フィルター | 出現回数 | 共通化対象 |
|-----------|---------|-----------|
| warehouse_id | 16+ | o（searchable + getSearchResultsUsing） |
| contractor_id | 15+ | o（searchable + getSearchResultsUsing + kana変換） |
| supplier_id | 2-3 | o（partner経由の検索） |
| batch_code | 4 | o（distinct + limit 50） |
| status | 20+ | o（Enum変換パターン） |

共通化不要: delivery_course_id, partner_id, salesman_id, picker_id（各1-2箇所の専用ロジック）

### 2. `loadGroupedTasks()` の方針

**結論: 現行パターン維持。**

- N+1問題ではない（ページネーション後に1クエリで一括取得、合計2クエリ/ページ）
- 複合キー（delivery_course_id + wave_id + shipment_date）はEloquent標準リレーションで表現困難
- パフォーマンス良好、変更リスクが利点を上回る

WmsShipmentSlips の最適化対象は `loadGroupedTasks()` 以外の N+1（不足しているeager load等）に限定。

### 3. テスト基準

不要な重複クエリがなくなっていればOK。Debugbar で以下を確認:
- 同一テーブルへの重複SELECT がないこと
- N+1パターン（モデル取得数がページ件数を大幅に超えない）が解消されていること

### 4. 着手順序

Phase 1 → Phase 2（#1～#5順番に） → Phase 3（#6～#12順番に） → Phase 4
