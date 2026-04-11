# 発注・移動候補テーブルに商品コード・検索コード列を追加

- **作成日**: 2026-04-04
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260403/20260404-235733-add-item-search-code-columns/`

## 背景・目的

現在、`wms_order_candidates`（発注候補）と `wms_stock_transfer_candidates`（移動候補）テーブルには `item_id` のみが保存されている。商品コード（`item_code`）や検索コード（`search_code`）はリレーション経由または動的クエリで取得しているため：

1. **検索性能の問題**: テーブル一覧で検索CDを検索する際、`item_search_information` テーブルへの動的クエリ（N+1）が発生する
2. **インデックスが効かない**: `item_code` がテーブルにないため、商品コードでの直接検索・ソートにDBインデックスを活用できない
3. **一貫性の欠如**: `wms_order_incoming_schedules` には `search_code` があるが、`item_code` がない。候補テーブルには両方ない

全テーブルに `item_code` と `search_code` を持たせ、生成時に保存することで検索性能を改善する。

## 現状の実装

### テーブル構造

| テーブル | item_code | search_code | 備考 |
|---------|-----------|-------------|------|
| `wms_order_candidates` | ❌ なし | ❌ なし | `item_id` のみ |
| `wms_stock_transfer_candidates` | ❌ なし | ❌ なし | `item_id` のみ |
| `wms_order_incoming_schedules` | ❌ なし | ✅ あり(varchar 500) | `item_id` + `search_code` |

### データ生成箇所

| サービス | 対象テーブル | 生成方法 |
|---------|------------|---------|
| `OrderCandidateCalculationService` | order_candidates | `::insert()` 一括 (line 945) |
| `OrderCandidateCalculationService` | transfer_candidates | `::insert()` 一括 (line 695) |
| `OrderCreateJobHandler` | order_candidates | `::create()` 個別 (line 304) |
| `TransferCreateJobHandler` | transfer_candidates | `::create()` 個別 (line 291) |
| `OrderExecutionService` | incoming_schedules | `::create()` 個別 (line 197等) |
| `TransferCandidateExecutionService` | incoming_schedules | `::create()` 個別 (line 207) |

### 検索コード取得ロジック

`getSearchCodeForItem()` が2箇所に実装済み:
- `OrderExecutionService` (line 348)
- `TransferCandidateExecutionService` (line 247)

```php
DB::connection('sakemaru')
    ->table('item_search_information')
    ->where('item_id', $itemId)
    ->where('is_used_for_ordering', true)
    ->where('is_active', true)
    ->value('search_string');
```

### UI表示箇所

| 画面 | item_code | search_code | ファイル |
|------|-----------|-------------|---------|
| 発注候補一覧 | `item.code`(リレーション) | ❌ なし | `WmsOrderCandidatesTable.php` |
| 移動候補一覧 | `item.code`(リレーション) | 動的クエリ | `WmsStockTransferCandidatesTable.php` |
| 発注確認待ち | `item.code`(リレーション) | ❌ なし | `WmsOrderConfirmationWaitingTable.php` |
| 移動確認待ち | `item.code`(リレーション) | ❌ なし | `WmsTransferConfirmationWaitingTable.php` |
| 入荷予定一覧 | `item.code`(リレーション) | `search_code`(直接) | `WmsOrderIncomingSchedulesTable.php` |
| 入荷完了一覧 | `item.code`(リレーション) | `search_code`(直接) | `WmsIncomingCompletedTable.php` |
| 発注候補詳細モーダル | `$itemCode` | ❌ なし | `order-candidate-detail.blade.php` |
| 移動候補詳細モーダル | `$itemCode` | ❌ なし | `transfer-candidate-detail.blade.php` |
| 入荷予定詳細モーダル | `$itemCode` + `$searchCode` | ✅ 表示済み | `incoming-schedule-detail.blade.php` |

## 変更内容

### 概要

3テーブルに `item_code`・`search_code` カラムを追加し、データ生成時に保存。全リスト画面・詳細モーダルで直接カラムを参照するように統一する。

### 詳細設計

#### DB変更

**マイグレーション1: `wms_order_candidates` にカラム追加**
```php
Schema::connection('sakemaru')->table('wms_order_candidates', function (Blueprint $table) {
    $table->string('item_code', 20)->nullable()->after('item_id');
    $table->string('search_code', 500)->nullable()->after('item_code');
    $table->index('item_code', 'idx_wms_order_cand_item_code');
    $table->index('search_code', 'idx_wms_order_cand_search_code');
});
```

**マイグレーション2: `wms_stock_transfer_candidates` にカラム追加**
```php
Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
    $table->string('item_code', 20)->nullable()->after('item_id');
    $table->string('search_code', 500)->nullable()->after('item_code');
    $table->index('item_code', 'idx_wms_transfer_cand_item_code');
    $table->index('search_code', 'idx_wms_transfer_cand_search_code');
});
```

**マイグレーション3: `wms_order_incoming_schedules` に `item_code` 追加**
```php
Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
    $table->string('item_code', 20)->nullable()->after('item_id');
    $table->index('item_code', 'idx_wms_incoming_item_code');
});
```
※ `search_code` は既存（varchar 500）。インデックスが未設定の場合は追加。

**既存データのバックフィル**: マイグレーション内で `items.code` から `item_code` を、`item_search_information.search_string` から `search_code` を一括UPDATEする。

#### サービス変更

**1. `OrderCandidateCalculationService`**（一括INSERT）
- 発注候補INSERT配列に `item_code` と `search_code` を追加
- 移動候補INSERT配列に `item_code` と `search_code` を追加
- `search_code` 取得: 候補生成前に `item_search_information` を一括プリロード（パフォーマンス考慮）
- `item_code` 取得: `items` テーブルから一括プリロード

**2. `OrderCreateJobHandler`**（個別CREATE）
- `WmsOrderCandidate::create()` に `item_code` と `search_code` を追加

**3. `TransferCreateJobHandler`**（個別CREATE）
- `WmsStockTransferCandidate::create()` に `item_code` と `search_code` を追加

**4. `OrderExecutionService`**（入荷予定作成）
- `WmsOrderIncomingSchedule::create()` に `item_code` を追加
- `search_code` は既に設定済み

**5. `TransferCandidateExecutionService`**（入荷予定作成）
- `WmsOrderIncomingSchedule::create()` に `item_code` を追加
- `search_code` は既に設定済み

**6. 検索コード取得の共通化**
- `getSearchCodeForItem()` が2箇所に重複 → 一括取得用のヘルパーメソッドを `OrderCandidateCalculationService` に追加（大量データ用にIN句で一括取得）

#### UI変更

**テーブル一覧（6ファイル）**

| ファイル | 変更内容 |
|---------|---------|
| `WmsOrderCandidatesTable.php` | `search_code` カラム追加（`item.code` の右）、`item.code` → 直接カラム参照に変更 |
| `WmsStockTransferCandidatesTable.php` | 動的クエリ → 直接カラム参照に変更 |
| `WmsOrderConfirmationWaitingTable.php` | `search_code` カラム追加（`item.code` の右） |
| `WmsTransferConfirmationWaitingTable.php` | `search_code` カラム追加（`item.code` の右） |
| `WmsOrderIncomingSchedulesTable.php` | `item_code` カラム追加（`item.code` 置き換え）、`search_code` はそのまま |
| `WmsIncomingCompletedTable.php` | `item_code` カラム追加（`item.code` 置き換え）、`search_code` はそのまま |

**詳細モーダル（3ファイル）**

| ファイル | 変更内容 |
|---------|---------|
| `order-candidate-detail.blade.php` | `$searchCode` 表示追加（商品CD行に検索CD追加、incoming-schedule-detailと同様のレイアウト） |
| `transfer-candidate-detail.blade.php` | `$searchCode` 表示追加（同上） |
| `incoming-schedule-detail.blade.php` | 変更なし（既に両方表示済み） |

**モーダルのviewData追加（5ファイル）**

| ファイル | 変更内容 |
|---------|---------|
| `WmsOrderCandidatesTable.php` viewCalculation | `searchCode` viewData追加 |
| `WmsStockTransferCandidatesTable.php` edit | `searchCode` viewData追加 |
| `WmsOrderConfirmationWaitingTable.php` viewDetail | `searchCode` viewData追加 |
| `WmsTransferConfirmationWaitingTable.php` viewDetail | `searchCode` viewData追加 |
| `WmsOrderIncomingSchedulesTable.php` viewDetail | 変更なし（既に渡している） |
| `WmsIncomingCompletedTable.php` viewDetail | 変更なし（既に渡している） |

### 影響範囲

- 発注候補生成ジョブ全体（`ProcessOrderCandidateGenerationJob` 経由）
- 移動候補生成ジョブ全体
- 手動発注作成（`OrderCreateJobHandler`）
- 手動移動作成（`TransferCreateJobHandler`）
- 入荷予定作成（発注確定・移動確定時）
- 全リスト画面6箇所
- 全詳細モーダル5箇所

## 制約

- **FK禁止**: `item_code` は `items.code` への外部キーを設定しない（アプリケーションレベルで整合性管理）
- **migrate:fresh/refresh禁止**: 新規マイグレーションのみ使用
- **パフォーマンス**: `OrderCandidateCalculationService` の一括INSERT時、`item_search_information` への問い合わせはIN句で一括取得しN+1を回避
- **既存データ**: マイグレーション内でバックフィルを実行し、既存レコードにも `item_code`・`search_code` を設定
- **NULL許容**: カラムはnullable（マスタデータ欠損時にエラーにしない）

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_add_item_code_search_code_to_wms_order_candidates_table.php`
- `database/migrations/XXXX_add_item_code_search_code_to_wms_stock_transfer_candidates_table.php`
- `database/migrations/XXXX_add_item_code_to_wms_order_incoming_schedules_table.php`

### 既存変更
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — INSERT配列に `item_code`, `search_code` 追加
- `app/Services/AutoOrder/OrderCreateJobHandler.php` — create()に追加
- `app/Services/AutoOrder/TransferCreateJobHandler.php` — create()に追加
- `app/Services/AutoOrder/OrderExecutionService.php` — incoming schedule作成時に `item_code` 追加
- `app/Services/AutoOrder/TransferCandidateExecutionService.php` — incoming schedule作成時に `item_code` 追加
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` — search_codeカラム追加
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` — 動的クエリ→直接参照
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php` — search_codeカラム追加
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsTransferConfirmationWaitingTable.php` — search_codeカラム追加
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` — item_code直接参照化
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php` — item_code直接参照化
- `resources/views/filament/components/order-candidate-detail.blade.php` — searchCode表示追加
- `resources/views/filament/components/transfer-candidate-detail.blade.php` — searchCode表示追加

### 参照のみ
- `app/Models/WmsOrderCandidate.php`
- `app/Models/WmsStockTransferCandidate.php`
- `app/Models/WmsOrderIncomingSchedule.php`
- `resources/views/filament/components/incoming-schedule-detail.blade.php`（変更不要、参考用）

## 確認事項

1. **`item_code` の位置**: テーブル一覧で `item.code`（リレーション）を完全に `item_code`（直接カラム）に置き換えるか、それとも両方残すか？ → リレーション経由の `item.code` を残しつつ、検索用に直接カラムも使う方針が安全
置き換える。
2. **`search_code` のインデックス長**: varchar(500) にインデックスを貼る場合、プレフィックスインデックス（例: 先頭191文字）にすべきか？ → JANコード等は最大13桁程度なので実用上問題ないが、念のためプレフィックス指定を検討
検索CDは基本30文字で十分
3. **既存の `WmsStockTransferCandidatesTable` の動的クエリ**: 今回のセッションで追加したばかりの実装。カラム追加後に直接参照に差し替える
直接参照がよい。速度面で
