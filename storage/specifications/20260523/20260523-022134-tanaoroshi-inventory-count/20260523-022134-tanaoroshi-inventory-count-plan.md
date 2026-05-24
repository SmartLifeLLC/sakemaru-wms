# 棚卸し機能 作業計画

## 前提

- 棚卸し関連のDB・モデル・UI・APIはすべて新規作成（既存ファイルなし）
- TCPDF (`tecnickcom/tcpdf` ^6.10) は composer.json に導入済み
- sakemaru-ai-core に `BaseTcpdfService` の実装パターンあり（参考）
- WMSプロジェクトにも `PickingListPdfService`, `PurchaseOrderPdfService` の実績あり
- `StockDisposalController` の REASONS 定数に `INVENTORY` を追加して在庫調整に利用
- 仕様書: 同ディレクトリの `20260523-022134-tanaoroshi-inventory-count.md`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | DB + モデル + マイグレーション | 3テーブル作成、3モデル定義、サービス骨格 | migrate成功、Tinkerでリレーション確認 |
| P2 | Web UI + スナップショット | Filament Resource、作成・一覧・詳細ページ、スナップショット取得 | 管理画面から棚卸し作成→スナップショット表示 |
| P3 | 棚卸指示書PDF | TCPDF横向きPDF、フロアグループ、ロケーション順、バーコード | PDFダウンロード、サンプルPDFと目視比較 |
| P4 | Android API | 6エンドポイント、バーコードスキャン、冪等性 | curl/Postmanで全API動作確認 |
| P5 | 差異確認 + 差異リストPDF | 差異計算ロジック、差異リストPDF、UIフィルター | 差異計算→PDF出力→UI表示 |
| P6 | 確定 + 在庫調整反映 | StockDisposal連携、楽観ロック、ステータス遷移 | 確定→real_stocks反映→ステータス確認 |
| P7 | メガメニュー + 統合テスト | メニュー追加、全フロー通しテスト | 作成→入力→差異→確定の一連操作完了 |

---

## P1: DB + モデル + マイグレーション

### 目的

棚卸し機能の基盤となる3テーブルと3モデルを作成する。

### 事前調査

以下のコマンドで既存テーブル構造を確認する:

```bash
# real_stocks テーブル構造（スナップショット元）
php artisan tinker --execute="Schema::connection('sakemaru')->getColumnListing('real_stocks')" 

# floors テーブルのフロア名サンプル
php artisan tinker --execute="App\Models\Sakemaru\Floor::limit(10)->get(['id','name','warehouse_id'])->toArray()"

# items テーブルのバーコード関連カラム
php artisan tinker --execute="Schema::connection('sakemaru')->getColumnListing('items')" 

# locations テーブル構造
php artisan tinker --execute="Schema::connection('sakemaru')->getColumnListing('locations')"
```

調査結果を boot.md の「作業中コンテキスト > DB調査結果」に記録する。

### 作成するファイル

#### 1. マイグレーション（3ファイル）

**`create_wms_inventory_counts_table.php`**:
- 仕様書のカラム定義に従う
- `status` は VARCHAR(20) で実装（ENUM非推奨）、デフォルト `'draft'`
- `lock_mode` は BOOLEAN DEFAULT FALSE
- インデックス: `(warehouse_id, count_date)`, `(status)`

**`create_wms_inventory_count_items_table.php`**:
- 仕様書のカラム定義に従う（floor_id, floor_name, location_code1/2/3 含む）
- インデックス:
  - `(inventory_count_id, item_id)`
  - `(inventory_count_id, floor_id, location_code1, location_code2, location_code3)`
  - `(inventory_count_id, barcode)`

**`create_wms_inventory_count_item_logs_table.php`**:
- 仕様書のカラム定義に従う
- `request_uuid` に UNIQUE制約
- インデックス: `(inventory_count_item_id, created_at)`

#### 2. モデル（3ファイル）

**`WmsInventoryCount`** (extends WmsModel):
```php
protected $fillable = [
    'count_no', 'client_id', 'warehouse_id', 'warehouse_code', 'warehouse_name',
    'count_date', 'status', 'lock_mode', 'snapshot_taken_at', 'started_at',
    'confirmed_at', 'confirmed_by', 'memo', 'created_by',
];
protected $casts = [
    'count_date' => 'date',
    'lock_mode' => 'boolean',
    'snapshot_taken_at' => 'datetime',
    'started_at' => 'datetime',
    'confirmed_at' => 'datetime',
];
```
- リレーション: items(), warehouse(), confirmedByUser(), createdByUser()
- generateCountNo(): `WaveGroup::generateGroupNo()` パターンを参考に `IC-YYYYMMDD-{8 random}`
- scopeByStatus($query, $status)
- ステータス定数: DRAFT, COUNTING, CHECKED, CONFIRMED, CANCELLED

**`WmsInventoryCountItem`** (extends WmsModel):
- リレーション: inventoryCount(), logs(), item()
- calculateDifference(): final_count_quantity - system_quantity

**`WmsInventoryCountItemLog`** (extends WmsModel):
- リレーション: countItem()
- `$timestamps = false` + `created_at` のみ手動設定

#### 3. サービス骨格

**`app/Services/InventoryCount/InventoryCountService.php`**:
- メソッドスタブのみ（P2以降で実装）
- `create()`, `takeSnapshot()`, `startCounting()`, `registerCount()`, `calculateDifferences()`, `confirm()`, `cancel()`

### 完了条件

1. `php artisan migrate` が成功
2. `php artisan tinker` で以下を確認:
   - `WmsInventoryCount::create([...])` が成功
   - `$count->items()->create([...])` が成功
   - `$item->logs()->create([...])` が成功
   - `$count->items` でリレーション取得成功
   - `WmsInventoryCount::generateCountNo()` が `IC-20260523-XXXXXXXX` 形式を返す

---

## P2: Web UI（一覧・作成・詳細）+ スナップショット

### 目的

管理画面から棚卸しを作成し、倉庫内全在庫のスナップショットを取得して詳細ページに表示する。

### 依存

P1 完了が前提。

### 作成するファイル

#### 1. InventoryCountService — takeSnapshot 実装

```
real_stocks
  JOIN items ON items.id = real_stocks.item_id
  JOIN locations ON locations.id = real_stocks.location_id
  LEFT JOIN floors ON floors.id = locations.floor_id
  WHERE real_stocks.warehouse_id = :warehouse_id
  AND real_stocks.quantity != 0
```

- チャンク処理（1000件ずつ）でOOM回避
- `items` からバーコード（JANコード）取得 → カラム名はP1の調査結果に依存
- `locations` から code1/code2/code3 取得
- `floors` から name 取得（フロア名）
- cost_price は real_stocks または items のいずれかから取得（要調査）

#### 2. Filament Resource

**`WmsInventoryCountResource.php`**:
- model: WmsInventoryCount
- navigationIcon: `heroicon-o-clipboard-document-check`
- navigationGroup: '在庫管理'
- slug: `wms-inventory-counts`

**`WmsInventoryCountTable.php`（一覧テーブル）**:
- カラム: count_no / warehouse_name / count_date / status(Badge) / 進捗(入力済/全体) / created_by表示名 / created_at
- フィルター: warehouseFilter(), statusFilter(InventoryCountStatus相当)
- デフォルトソート: id DESC
- recordActions: 「詳細」ボタン → ViewWmsInventoryCount へ遷移
- toolbarActions: 「棚卸し作成」アクション

**「棚卸し作成」アクション（モーダル）**:
- schema: 倉庫Select / 棚卸し日DatePicker(デフォルト当日) / メモTextarea
- action: InventoryCountService::create() → takeSnapshot()（非同期ジョブ or 同期）→ 詳細ページへリダイレクト
- モーダルデザイン: `~/.claude/design-knowledge/modal-design.md` 準拠

**`ListWmsInventoryCounts.php`（一覧ページ）**:
- HasWmsUserViews トレイト使用

**`ViewWmsInventoryCount.php`（詳細ページ）**:
- WmsPickingWait ページを参考にした構造
- ヘッダーウィジェット: No/倉庫/日付/ステータス/進捗（入力済/全体）
- アクションボタン: 「カウント開始」「棚卸指示書PDF」（P3で実装）

**`WmsInventoryCountItemTable.php`（明細テーブル）**:
- カラム: フロア / エリア(code1) / ロケーション / 商品CD / 商品名(grow) / ロットNo / 賞味期限 / 理論数量 / 1回目 / 2回目 / 最終 / 差異数量 / 差異金額
- グルーピング: floor_name（Filamentのgroupsメソッド使用）
- フィルター: フロア(SelectFilter) / エリアcode1(SelectFilter) / 未入力のみ / 差異ありのみ
- デフォルトソート: floor_name → location_code1 → code2 → code3 ASC
- sticky-actions クラス適用
- テーブルデザイン仕様書準拠（CD表記、コードと名前分離、商品名grow()）

### 完了条件

1. `/admin/wms-inventory-counts` で一覧ページが表示
2. 「棚卸し作成」モーダルから倉庫選択→作成→スナップショット取得が成功
3. 詳細ページでスナップショット明細がフロアグループ/ロケーション順で表示
4. フロア・エリアフィルターが動作
5. 「カウント開始」アクションで status が draft → counting に遷移

---

## P3: 棚卸指示書PDF出力

### 目的

サンプルPDF（`棚卸指示書（H）.pdf`）に準じた棚卸指示書をTCPDFで出力する。

### 依存

P2 完了が前提。

### 参照

- `storage/specifications/20260523/棚卸指示書（H）.pdf` — レイアウト参考
- sakemaru-ai-core: `app/Services/Pdf/BaseTcpdfService.php` — TCPDF基底パターン
- WMS: `app/Services/PickingList/PickingListPdfService.php` — 既存PDF出力参考

### 作成するファイル

**`app/Services/InventoryCount/InventoryInstructionPdfService.php`**:

**レイアウト仕様**（サンプルPDFから）:
- 用紙: A4 横向き（Landscape）
- ヘッダー: 棚卸指示書 / 棚卸日 / 倉庫CD・倉庫名 / 印刷日時 / ページ番号
- 明細行: アイテムCD / アイテム名称 / ロケーションNO / ロットNO / 入庫日 / 賞味期限 / メーカー / 容量 / 規格 / 理論在庫数量 / 仕入原価 / 合計金額 / バーコード画像
- ソート: floor_name → location_code1 → code2 → code3 ASC（ロケーション歩行順）
- フロア切り替え時に改ページ（1F / 2F / YXxxx のグルーピング）
- バーコード: TCPDF の write1DBarcode() でJANコード/Code128出力

**UI連携**: ViewWmsInventoryCount ページに「棚卸指示書PDF」アクションボタン追加

### 完了条件

1. 詳細ページから「棚卸指示書PDF」ボタンでPDFダウンロード
2. レイアウトがサンプルPDFと概ね一致（ヘッダー情報、カラム構成、バーコード表示）
3. フロア別改ページが機能
4. ロケーション歩行順にソートされている

---

## P4: Android API（実棚入力）

### 目的

Android端末からバーコードスキャン→実棚数量入力→保存ができるAPIを提供する。

### 依存

P2 完了が前提（P3と並列実行可能）。

### 作成するファイル

**`app/Http/Controllers/Api/InventoryCountController.php`**:

| メソッド | パス | 説明 |
|---|---|---|
| `index()` | GET `/api/wms/inventory-counts` | status=counting の棚卸し一覧 |
| `show($id)` | GET `/api/wms/inventory-counts/{id}` | ヘッダー情報 |
| `items($id)` | GET `/api/wms/inventory-counts/{id}/items` | 明細一覧（filter: floor, code1, uncounted, has-difference） |
| `scan($id)` | POST `/api/wms/inventory-counts/{id}/scan` | バーコード/商品CDで明細検索 |
| `count($itemId)` | POST `/api/wms/inventory-count-items/{itemId}/count` | 実棚数量登録 |
| `logs($itemId)` | GET `/api/wms/inventory-count-items/{itemId}/logs` | 入力履歴 |

**count() の冪等性**:
```
request_uuid でログテーブル検索
→ 存在すれば既存結果を返す（重複送信防止）
→ 存在しなければ:
  1. countItem の first/second_count_quantity を更新
  2. input_count++, last_counted_at 更新
  3. ログレコード作成（device_id, user_id, count_round, old/new quantity, request_uuid）
```

**scan() の検索ロジック**:
```
wms_inventory_count_items
  WHERE inventory_count_id = :id
  AND (barcode = :keyword OR item_code = :keyword)
```

**認証**: 既存のAPI認証（Basic Auth + X-API-Key）を使用

**ルート追加**: `routes/api.php` に棚卸しエンドポイントを追加

### 完了条件

1. 全6エンドポイントが curl/Postman で正常動作
2. count API で request_uuid 重複送信時に同じ結果が返る（冪等性）
3. items API で floor, code1 フィルターが機能
4. scan API でバーコード・商品CDどちらでも検索可能

---

## P5: 差異確認 + 差異リストPDF

### 目的

実棚入力後の差異計算と差異リストPDFを実装する。

### 依存

P4 完了が前提。

### 実装内容

#### 1. InventoryCountService — calculateDifferences 実装

```php
// 全明細の差異計算
foreach ($inventoryCount->items as $item) {
    $finalQty = $item->final_count_quantity
        ?? $item->second_count_quantity
        ?? $item->first_count_quantity;
    
    if ($finalQty !== null) {
        $item->final_count_quantity = $finalQty;
        $item->difference_quantity = $finalQty - $item->system_quantity;
        $item->difference_amount = $item->difference_quantity * $item->cost_price;
    }
    $item->save();
}
$inventoryCount->update(['status' => 'checked']);
```

#### 2. 差異リストPDF

**`app/Services/InventoryCount/InventoryDiffListPdfService.php`**:

**レイアウト仕様**（サンプルPDFから）:
- 用紙: A4 縦向き（Portrait）
- ヘッダー: 棚卸差異リスト / 棚卸日 / 倉庫CD・倉庫名 / 印刷日時 / ページ番号
- 明細行: アイテムCD / アイテム名称 / ロケーションNO / ロットNO / 賞味期限 / 入力回数 / 理論数量 / 実数量 / 差異数量 / 仕入原価 / 差異金額
- ソート: item_code ASC（商品コード順。サンプルPDFの並び順に合わせる）

#### 3. UI更新

- ViewWmsInventoryCount に「差異計算」アクションボタン追加（counting → checked）
- 「差異リストPDF」アクションボタン追加（checked/confirmed時のみ表示）
- 明細テーブルの「差異ありのみ」フィルターが difference_quantity != 0 で動作

### 完了条件

1. 「差異計算」ボタンで全明細の差異が計算される
2. 差異リストPDFがダウンロードできる
3. 差異ありフィルターで差異のある明細のみ表示
4. status が counting → checked に遷移

---

## P6: 確定 + 在庫調整反映

### 目的

棚卸しを確定し、差異分の在庫調整をStockDisposalController経由で反映する。

### 依存

P5 完了が前提。

### 実装内容

#### 1. StockDisposalController 変更

`REASONS` 定数に `'INVENTORY'` を追加:
```php
private const REASONS = [
    'EXPIRED',
    'DAMAGED',
    'STORE_PROMOTION_GIFT',
    'STORE_PROMOTION_TASTING',
    'CUSTOMER_PROMOTION_COOP',
    'ENTERTAINMENT_CONDOLENCE',
    'LOST',
    'OTHER',
    'INVENTORY',  // 追加
];
```

#### 2. InventoryCountService — confirm 実装

```
トランザクション内で:
  1. status = 'confirmed', confirmed_at = now(), confirmed_by = userId
  2. difference_quantity != 0 の明細に対して:
     - StockDisposalController の内部ロジック or Service を呼び出し
     - reason: 'INVENTORY'
     - 正差異（実棚 > 理論）= 数量加算
     - 負差異（実棚 < 理論）= 数量減算
  3. real_stocks.quantity 更新（wms_lock_version チェック）
  4. 楽観ロック失敗時はリトライ or エラー返却
```

#### 3. UI更新

- ViewWmsInventoryCount に「確定」アクションボタン追加
- 確認ダイアログ: 「棚卸しを確定します。差異分の在庫調整が実行されます。この操作は取り消せません。」
- モーダルデザイン: danger色の確認ダイアログ（`~/.claude/design-knowledge/modal-design.md` 準拠）
- 「取消」アクション: confirmed以外 → cancelled

### 完了条件

1. 「確定」ボタンで差異分の在庫調整が実行される
2. real_stocks の quantity が差異分だけ増減している
3. wms_stock_disposal_api_logs に reason='INVENTORY' のレコードが作成される
4. status が checked → confirmed に遷移
5. confirmed後は編集不可（ボタン非表示）
6. 「取消」ボタンで cancelled に遷移

---

## P7: メガメニュー + 統合テスト

### 目的

メガメニューに棚卸しメニューを追加し、全フローの通しテストを行う。

### 依存

P6 完了が前提。

### 実装内容

#### 1. メガメニュー追加

- 「在庫管理」グループ内に「棚卸し」リンクを追加
- `~/.claude/design-knowledge/mega-menu.md` 準拠

#### 2. 統合テスト

以下のフローを https://wms.sakemaru.test で通しテスト:

1. 「棚卸し作成」→ 倉庫選択 → スナップショット取得
2. 詳細ページで明細確認（フロアグループ、ロケーション順、フィルター）
3. 「棚卸指示書PDF」ダウンロード → 内容確認
4. 「カウント開始」→ status=counting 確認
5. Android API で実棚数量入力（curl）
6. 詳細ページで入力値反映確認
7. 「差異計算」→ 差異値確認
8. 「差異リストPDF」ダウンロード → 内容確認
9. 「確定」→ real_stocks 反映確認
10. メガメニューからのアクセス確認

### 完了条件

1. 上記10ステップが全て正常動作
2. メガメニューから棚卸し一覧にアクセスできる
3. 作成→入力→差異→確定の一連操作が完了
4. `php artisan test` で既存テストが壊れていない

---

## 制約（厳守）

1. **FK禁止**: テーブル間参照はアプリケーション層で管理。マイグレーションに `->foreign()` を書かない
2. **migrate:fresh/refresh/reset 禁止**: `php artisan migrate` のみ使用
3. **real_stocks は confirm 時のみ更新**: カウント中に直接触らない
4. **楽観ロック必須**: real_stocks 更新時は必ず `wms_lock_version` チェック
5. **冪等性**: `request_uuid` で重複防止。同じUUIDのリクエストは同じ結果を返す
6. **Filament 4 仕様準拠**: `storage/specifications/filament4spec.md` のインポートパス・アクション設定に従う
7. **テーブルデザイン仕様準拠**: CD表記、コード名前分離、商品名grow()、sticky-actions
8. **モーダルデザイン仕様準拠**: ヘッダー紺色、ボタン右寄せ、実行ボタンdanger色

## 全体完了条件

1. 全7 Phase が完了
2. 棚卸し作成→スナップショット→指示書PDF→HT入力→差異計算→差異リストPDF→確定→在庫反映 の全フローが動作
3. メガメニューからアクセス可能
4. `php artisan test` 全パス
5. boot.md の全Phase完了記録が埋まっている
