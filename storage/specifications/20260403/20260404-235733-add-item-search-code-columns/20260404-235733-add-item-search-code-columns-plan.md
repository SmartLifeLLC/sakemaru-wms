# 発注・移動候補テーブルに item_code・search_code 追加 作業計画

## 前提

- `wms_order_incoming_schedules` には `search_code`(varchar 500) が既にある（`item_code` はない）
- `wms_order_candidates` と `wms_stock_transfer_candidates` には両方ない
- `WmsStockTransferCandidatesTable` には動的クエリで `search_code` を取得する実装が今セッションで追加済み（P3で置き換え）
- 仕様書の確認事項への回答:
  - `item.code`（リレーション）→ `item_code`（直接カラム）に完全置き換え
  - `search_code` は varchar(30) で十分
  - 直接参照にする（速度面）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | マイグレーション | 3テーブルにカラム追加+インデックス+既存データバックフィル | マイグレーション実行成功、既存データにitem_code/search_codeが入っている |
| P2 | サービス層 | 候補・入荷予定の生成時にitem_code/search_codeを保存 | php -l 構文チェック通過、生成コードにitem_code/search_codeが含まれる |
| P3 | テーブル一覧UI | 6テーブルファイルで直接カラム参照に変更、search_codeカラム追加 | php -l 構文チェック通過、全テーブルでitem_code直接参照 |
| P4 | 詳細モーダルUI | 2モーダルbladeにsearchCode表示追加、viewData 4箇所追加 | php -l 構文チェック通過 |
| P5 | 動作確認テスト | 全6画面アクセス+検索+モーダル+データ整合性 | 全テストケース合格 |

---

## P1: マイグレーション

### 目的

3テーブルに `item_code`・`search_code` カラムを追加し、既存データをバックフィルする。

### 修正方針

**マイグレーション1**: `wms_order_candidates` に `item_code`(varchar 20) と `search_code`(varchar 30) 追加
- 位置: `item_id` の後
- インデックス: `idx_wms_order_cand_item_code`, `idx_wms_order_cand_search_code`
- バックフィル: `items.code` → `item_code`, `item_search_information.search_string` → `search_code`

**マイグレーション2**: `wms_stock_transfer_candidates` に `item_code`(varchar 20) と `search_code`(varchar 30) 追加
- 位置: `item_id` の後
- インデックス: `idx_wms_transfer_cand_item_code`, `idx_wms_transfer_cand_search_code`
- バックフィル: 同上

**マイグレーション3**: `wms_order_incoming_schedules` に `item_code`(varchar 20) 追加
- 位置: `item_id` の後
- インデックス: `idx_wms_incoming_item_code`
- バックフィル: `items.code` → `item_code`
- `search_code` は既存のため追加不要

**バックフィル方法**: JOIN UPDATE で一括処理

```sql
-- item_code バックフィル
UPDATE wms_order_candidates c
JOIN items i ON c.item_id = i.id
SET c.item_code = i.code;

-- search_code バックフィル
UPDATE wms_order_candidates c
JOIN item_search_information si ON c.item_id = si.item_id
  AND si.is_used_for_ordering = 1
  AND si.is_active = 1
SET c.search_code = si.search_string;
```

### 修正対象ファイル

- 新規: `database/migrations/XXXX_add_item_code_search_code_to_wms_order_candidates_table.php`
- 新規: `database/migrations/XXXX_add_item_code_search_code_to_wms_stock_transfer_candidates_table.php`
- 新規: `database/migrations/XXXX_add_item_code_to_wms_order_incoming_schedules_table.php`

### 完了条件

1. `php artisan migrate` 成功
2. 既存レコードの `item_code` に値が入っている
3. 既存レコードの `search_code` に値が入っている（該当するものがある場合）

---

## P2: サービス層

### 目的

候補データ・入荷予定データの生成時に `item_code` と `search_code` を保存する。

### 修正方針

#### 2-1: `OrderCandidateCalculationService` （一括INSERT — 最重要）

**発注候補INSERT（line ~800-945付近）**:
- INSERT配列に `'item_code' => $itemCode` と `'search_code' => $searchCode` を追加
- `item_code` は `items` テーブルから一括プリロード: `$itemCodes = Item::whereIn('id', $itemIds)->pluck('code', 'id')`
- `search_code` は `item_search_information` から一括プリロード:
  ```php
  $searchCodes = DB::connection('sakemaru')
      ->table('item_search_information')
      ->whereIn('item_id', $itemIds)
      ->where('is_used_for_ordering', true)
      ->where('is_active', true)
      ->pluck('search_string', 'item_id');
  ```

**移動候補INSERT（line ~550-695付近）**:
- 同様に `item_code` と `search_code` を追加

#### 2-2: `OrderCreateJobHandler` （個別CREATE）

- `WmsOrderCandidate::create()` に追加:
  ```php
  'item_code' => $item->code,
  'search_code' => $this->getSearchCodeForItem($itemId),
  ```
- `getSearchCodeForItem()` メソッドが無ければ追加（`OrderExecutionService` と同じロジック）

#### 2-3: `TransferCreateJobHandler` （個別CREATE）

- `WmsStockTransferCandidate::create()` に同様に追加

#### 2-4: `OrderExecutionService` （入荷予定作成）

- `WmsOrderIncomingSchedule::create()` に `'item_code' => $item->code ?? Item::find($candidate->item_id)?->code` 追加
- `search_code` は既に設定済み

#### 2-5: `TransferCandidateExecutionService` （入荷予定作成）

- `WmsOrderIncomingSchedule::create()` に `'item_code'` 追加
- `search_code` は既に設定済み

### 修正対象ファイル

- `app/Services/AutoOrder/OrderCandidateCalculationService.php`
- `app/Services/AutoOrder/OrderCreateJobHandler.php`
- `app/Services/AutoOrder/TransferCreateJobHandler.php`
- `app/Services/AutoOrder/OrderExecutionService.php`
- `app/Services/AutoOrder/TransferCandidateExecutionService.php`

### 完了条件

1. 全ファイルの `php -l` 構文チェック通過
2. 全INSERT/CREATE箇所に `item_code` と `search_code` が含まれている

---

## P3: テーブル一覧UI

### 目的

6つのテーブルファイルで `item.code`（リレーション経由）を `item_code`（直接カラム）に置き換え、`search_code` カラムを追加する。

### 修正方針

#### 共通変更パターン

**`item.code` → `item_code` 置き換え**:
```php
// Before
TextColumn::make('item.code')
    ->label('商品CD')
    ->searchable()
    ...

// After
TextColumn::make('item_code')
    ->label('商品CD')
    ->searchable()
    ...
```

**`search_code` カラム追加**（`item_code` の右に配置）:
```php
TextColumn::make('search_code')
    ->label('検索CD')
    ->searchable()
    ->toggleable()
    ->width('120px'),
```

#### ファイル別変更

| ファイル | item.code→item_code | search_code追加 | 動的クエリ削除 |
|---------|---------------------|----------------|--------------|
| `WmsOrderCandidatesTable.php` | ✅ | ✅ | - |
| `WmsStockTransferCandidatesTable.php` | ✅ | ✅（動的→直接に変更） | ✅ 動的クエリ削除 |
| `WmsOrderConfirmationWaitingTable.php` | ✅ | ✅ | - |
| `WmsTransferConfirmationWaitingTable.php` | ✅ | ✅ | - |
| `WmsOrderIncomingSchedulesTable.php` | ✅ | 変更なし（既存） | - |
| `WmsIncomingCompletedTable.php` | ✅ | 変更なし（既存） | - |

### 修正対象ファイル

- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php`
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsTransferConfirmationWaitingTable.php`
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php`
- `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php`

### 完了条件

1. 全ファイルの `php -l` 構文チェック通過
2. `item.code` への参照が残っていない（`grep` で確認）
3. `WmsStockTransferCandidatesTable` の動的クエリが削除されている

---

## P4: 詳細モーダルUI

### 目的

発注候補・移動候補の詳細モーダルに `searchCode` 表示を追加する。入荷予定モーダルは既に対応済み。

### 修正方針

#### 4-1: Blade テンプレート

**`order-candidate-detail.blade.php`**: 商品CD行に検索CDを追加（`incoming-schedule-detail.blade.php` と同じレイアウト）
```blade
<tr>
    <td>商品CD</td>
    <td>{{ $itemCode ?? '-' }}</td>
    <td>検索CD</td>
    <td>{{ $searchCode ?? '-' }}</td>
</tr>
```

**`transfer-candidate-detail.blade.php`**: 同様に追加

#### 4-2: viewData追加

以下4つのテーブルファイルのモーダル定義で `searchCode` viewDataを追加:
```php
'searchCode' => $record->search_code ?? '-',
```

| ファイル | アクション名 |
|---------|------------|
| `WmsOrderCandidatesTable.php` | `viewCalculation` |
| `WmsStockTransferCandidatesTable.php` | `edit` |
| `WmsOrderConfirmationWaitingTable.php` | `viewDetail` |
| `WmsTransferConfirmationWaitingTable.php` | `viewDetail` |

※ `WmsOrderIncomingSchedulesTable.php` と `WmsIncomingCompletedTable.php` は既に `searchCode` を渡している。`itemCode` のソースを `$record->item_code ?? $item?->code` に変更。

### 修正対象ファイル

- `resources/views/filament/components/order-candidate-detail.blade.php`
- `resources/views/filament/components/transfer-candidate-detail.blade.php`
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`（viewData）
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php`（viewData）
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsOrderConfirmationWaitingTable.php`（viewData）
- `app/Filament/Resources/WmsOrderConfirmationWaiting/Tables/WmsTransferConfirmationWaitingTable.php`（viewData）

### 完了条件

1. 全ファイルの `php -l` 構文チェック通過
2. 発注候補・移動候補の詳細モーダルに検索CDが表示される

---

## P5: 動作確認テスト

### 目的

全画面にブラウザアクセスし、item_code・search_codeの表示・検索・モーダル・データ整合性を確認する。

### テスト環境

- **URL**: https://wms.sakemaru.test
- **認証**: .env の `TEST_ADMIN_NAME` / `TEST_ADMIN_PASS` を使用
- **注意**: DB refresh/fresh は絶対に実行しない。テスト用レコードのdeleteは可能。

### テスト手順

WebFetch を使用してページアクセスし、HTMLレスポンスを検証する。  
Bash で `curl` + Cookie認証を使い、各ページのHTMLに期待するカラム・データが含まれるか確認する。

#### 5-0: 認証・セッション取得

```bash
# CSRFトークン取得 → ログイン → セッションCookie保存
curl -k -c cookies.txt -b cookies.txt https://wms.sakemaru.test/admin/login
curl -k -c cookies.txt -b cookies.txt -X POST https://wms.sakemaru.test/admin/login \
  -d "email=TEST_ADMIN_NAME&password=TEST_ADMIN_PASS&_token=CSRF_TOKEN"
```

#### 5-1: DBデータ整合性チェック

tinker で全テーブルのitem_code/search_codeが正しく入っているか確認する。

| チェック項目 | コマンド | 期待値 |
|------------|---------|--------|
| order_candidates item_code充填率 | `WmsOrderCandidate::whereNotNull('item_code')->count()` vs `total count` | 100%（item_idが有効な全レコード） |
| order_candidates search_code充填率 | `WmsOrderCandidate::whereNotNull('search_code')->count()` | > 0（search_info がある商品） |
| transfer_candidates item_code充填率 | `WmsStockTransferCandidate::whereNotNull('item_code')->count()` vs `total count` | 100% |
| transfer_candidates search_code充填率 | `WmsStockTransferCandidate::whereNotNull('search_code')->count()` | > 0 |
| incoming_schedules item_code充填率 | `WmsOrderIncomingSchedule::whereNotNull('item_code')->count()` vs `total count` | 100% |
| item_codeとitems.codeの一致 | `JOIN比較` | 0件の不一致 |

#### 5-2: 画面アクセステスト（6画面）

各画面にアクセスし、HTTPステータス200 + エラーなしを確認する。

| # | 画面名 | URL | 確認項目 |
|---|--------|-----|---------|
| 1 | 発注候補一覧 | `/admin/wms-order-candidates` | 200返却、商品CD・検索CDカラム表示 |
| 2 | 移動候補一覧 | `/admin/wms-stock-transfer-candidates` | 200返却、商品CD・検索CDカラム表示 |
| 3 | 発注確認待ち（発注タブ） | `/admin/wms-order-confirmation-waiting?tab=order` | 200返却、検索CDカラム表示 |
| 4 | 発注確認待ち（移動タブ） | `/admin/wms-order-confirmation-waiting?tab=transfer` | 200返却、検索CDカラム表示 |
| 5 | 入荷予定一覧 | `/admin/wms-order-incoming-schedules` | 200返却、商品CD直接カラム |
| 6 | 入荷完了一覧 | `/admin/wms-incoming-completed` | 200返却、商品CD直接カラム |

#### 5-3: 検索機能テスト

各画面で商品コードおよび検索コードによる検索が動作するか確認する。

| # | 画面 | 検索値 | 期待結果 |
|---|------|--------|---------|
| 1 | 発注候補 | item_code の一部（例: "14285"） | 該当レコードが表示 |
| 2 | 発注候補 | search_code の一部（例: "4901"） | 該当レコードが表示 |
| 3 | 移動候補 | item_code の一部 | 該当レコードが表示 |
| 4 | 移動候補 | search_code の一部 | 該当レコードが表示 |
| 5 | 入荷予定 | item_code の一部 | 該当レコードが表示 |
| 6 | 入荷完了 | search_code の一部 | 該当レコードが表示 |

#### 5-4: 詳細モーダルテスト

各モーダルに検索CDが正しく表示されるか確認する。Livewireコンポーネント経由のため、直接HTMLには含まれない可能性あり。Bladeテンプレートのコード確認 + tinkerでのviewData確認で代替する。

| # | 画面 | アクション | 確認項目 |
|---|------|----------|---------|
| 1 | 発注候補 | 詳細ボタン | viewDataに`searchCode`が含まれる |
| 2 | 移動候補 | 詳細ボタン | viewDataに`searchCode`が含まれる |
| 3 | 発注確認待ち（発注） | 詳細ボタン | viewDataに`searchCode`が含まれる |
| 4 | 発注確認待ち（移動） | 詳細ボタン | viewDataに`searchCode`が含まれる |
| 5 | order-candidate-detail.blade | テンプレート確認 | `$searchCode` 変数の表示あり |
| 6 | transfer-candidate-detail.blade | テンプレート確認 | `$searchCode` 変数の表示あり |

#### 5-5: ソートテスト

item_code カラムでのソートが動作するか確認（インデックスが効いていること）。

```bash
# tinker でEXPLAINを実行し、インデックスが使われていることを確認
php artisan tinker --execute="
\$result = DB::connection('sakemaru')->select('EXPLAIN SELECT * FROM wms_order_candidates ORDER BY item_code LIMIT 10');
print_r(\$result);
"
```

#### 5-6: リグレッション確認

変更による既存機能への影響がないことを確認する。

| # | 確認項目 | 方法 |
|---|---------|------|
| 1 | 発注候補テーブルのインライン編集（発注数変更） | tinkerでPENDINGレコード確認 |
| 2 | 移動候補テーブルのインライン編集（移動数変更） | tinkerでPENDINGレコード確認 |
| 3 | 承認・除外アクションが動作する | ステータス遷移のコードパス確認 |
| 4 | item.name（商品名）はリレーション経由のまま（変更なし） | grep確認 |
| 5 | 入荷予定の既存search_code表示が壊れていない | 画面アクセステスト5で確認 |

### 完了条件

1. 5-1: 全テーブルのitem_code充填率100%
2. 5-2: 全6画面にHTTP 200でアクセス可能
3. 5-3: 検索で該当レコードが返る（少なくとも各画面1パターン）
4. 5-4: モーダルテンプレートに`$searchCode`表示が存在
5. 5-5: EXPLAINでインデックス使用確認
6. 5-6: リグレッション項目全て問題なし

---

## 制約（厳守）

1. **FK禁止**: `item_code` に外部キーを設定しない
2. **migrate:fresh/refresh/reset/db:wipe 禁止**: 新規マイグレーションのみ使用
3. **既存データ保護**: バックフィルはJOIN UPDATEで実施、DELETE/TRUNCATEは禁止
4. **パフォーマンス**: `OrderCandidateCalculationService` での一括INSERT時はN+1を避け、IN句で一括取得
5. **照合順序**: 一時テーブルを使う場合は `utf8mb4_general_ci` を指定（既存テーブルとの不一致対策）
6. **テスト時**: DBのrefresh/freshは絶対に実行しない。テスト用レコードのdeleteは可能

## 全体完了条件

1. 全マイグレーション実行成功
2. 全サービスの構文チェック通過
3. 全UIファイルの構文チェック通過
4. 既存データに `item_code` が入っている
5. `item.code`（リレーション経由）への参照が対象ファイルから除去されている
6. P5テスト計画の全テストケース合格
