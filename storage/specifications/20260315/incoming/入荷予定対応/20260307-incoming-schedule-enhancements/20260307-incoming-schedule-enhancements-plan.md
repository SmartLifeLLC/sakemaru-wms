# 入荷予定拡張：出荷実績取込・単価管理・CSVパーサー 作業計画

## 前提

- 入荷予定テーブル `wms_order_incoming_schedules` に `expected_quantity` / `received_quantity` / `shortage_quantity` は実装済み
- JXパーサー（`JxIncomingParser`）は実装済み、照合ロジック（`IncomingReceiveService`）も稼働中
- 発注確定時に `OrderExecutionService::createIncomingSchedulesFromCandidate()` で入荷予定作成済み
- 仕様書: `20260307-incoming-schedule-enhancements.md`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | DB変更 | shipped_quantity・単価カラム追加 + エラーテーブル作成 | マイグレーション実行成功 |
| P2 | モデル変更 | fillable/casts追加 + WmsIncomingImportError新規 | モデルのプロパティ・リレーション定義完了 |
| P3 | 自社単価取得サービス | PurchasePriceService（4段階フォールバック） | tinkerで単価取得動作確認 |
| P4 | 発注確定時の単価設定 | OrderExecutionService更新 | 発注確定時にunit_price/case_price設定される |
| P5 | 出荷実績照合ロジック拡張 | 商品マッピング3段階 + shipped_qty書き戻し + エラー記録 | JXデータ照合でshipped_quantity・単価・エラー記録される |
| P6 | アクト中食CSVパーサー | ActCsvIncomingParser新規 | サンプルCSVをパースしてDB登録成功 |
| P7 | Handy API拡張 | formatScheduleDetailにフィールド追加 | APIレスポンスに新フィールド含まれる |
| P8 | 発注データCSV伝票番号修正 | buildCsvContent更新 | CSV出力の伝票番号がDB上のslip_number（11桁）と一致 |
| P9 | Filament UI変更 | テーブルカラム追加 + CSVアップロード + エラーリスト画面 | 画面表示・操作確認 |

---

## P1: DB変更（マイグレーション）

### 目的

`wms_order_incoming_schedules` に出荷実績数・単価カラムを追加し、取込エラー管理テーブルを新規作成する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `database/migrations/xxxx_add_shipped_qty_and_prices_to_incoming_schedules.php` | カラム追加 |
| `database/migrations/xxxx_create_wms_incoming_import_errors.php` | エラーテーブル新規作成 |

### 修正方針

#### 1-1. `wms_order_incoming_schedules` にカラム追加

```php
$table->integer('shipped_quantity')->default(0)->after('expected_quantity')
    ->comment('出荷実績数（メーカーからの出荷実績）');
$table->decimal('unit_price', 12, 2)->nullable()->after('shortage_quantity')
    ->comment('仕入自社バラ単価');
$table->decimal('case_price', 12, 2)->nullable()->after('unit_price')
    ->comment('仕入自社ケース単価');
$table->decimal('partner_unit_price', 12, 2)->nullable()->after('case_price')
    ->comment('仕入先バラ単価（出荷実績から取込）');
$table->decimal('partner_case_price', 12, 2)->nullable()->after('partner_unit_price')
    ->comment('仕入先ケース単価（出荷実績から取込）');
$table->string('price_type', 10)->nullable()->after('partner_case_price')
    ->comment('単価タイプ: CASE or PIECE');
```

#### 1-2. `wms_incoming_import_errors` テーブル新規作成

仕様書の定義通り。コネクション: `sakemaru`。FK なし。

### 完了条件

- `php artisan migrate` が成功
- `php artisan migrate:status` で2つのマイグレーションが `Ran` 状態

---

## P2: モデル変更

### 目的

新カラムに対応するモデル更新と、エラーモデルの新規作成。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Models/WmsOrderIncomingSchedule.php` | fillable/casts に6カラム追加 |
| `app/Models/WmsIncomingImportError.php` | 新規モデル（WmsModel継承） |

### 修正方針

#### 2-1. `WmsOrderIncomingSchedule` モデル更新

fillable に追加:
```
'shipped_quantity', 'unit_price', 'case_price',
'partner_unit_price', 'partner_case_price', 'price_type'
```

casts に追加:
```php
'shipped_quantity' => 'integer',
'unit_price' => 'decimal:2',
'case_price' => 'decimal:2',
'partner_unit_price' => 'decimal:2',
'partner_case_price' => 'decimal:2',
```

#### 2-2. `WmsIncomingImportError` モデル新規作成

- `WmsModel` 継承
- テーブル名: `wms_incoming_import_errors`
- fillable: 全カラム
- casts: `is_resolved` → boolean, `resolved_at` → datetime, `raw_data` → array
- リレーション: `receivedFile()`, `receivedSlip()`, `receivedDetail()`, `resolvedByUser()`

### 完了条件

- モデルのfillable/castsが正しく定義されている
- `WmsIncomingImportError` がWmsModelを継承している

---

## P3: 自社単価取得サービス

### 目的

DB直接参照で自社仕入単価を取得する `PurchasePriceService` を新規作成。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/AutoOrder/PurchasePriceService.php` | 新規作成 |

### 修正方針

4段階フォールバックで単価取得:

1. `item_partner_prices WHERE partner_id = supplier_partner_id`（仕入先個別単価）
2. `item_partner_prices WHERE partner_id = partner_price_group_id`（単価グループ1）
3. `item_partner_prices WHERE partner_id = partner_price_group2_id`（単価グループ2）
4. `item_prices` → `items.purchase_price_type` で分岐（PRODUCER/COST/WHOLESALE）

**仕入先の特定:** `Partner::where('is_supplier', true)->firstWhere('code', $partnerCode)`

**戻り値:**
```php
['unit_price' => float, 'case_price' => float, 'source' => string]
```

**プリロード:** `preloadPrices(array $itemIds, ...)` でN+1回避。

**参考:** `HanaOrderJXFileGenerator::getCurrentCostPrice()` は Step 4 のみの簡易実装。新サービスはStep 1-3（仕入先個別単価）を含む完全版。

### 完了条件

- `PurchasePriceService::getPrice()` が4段階フォールバックで単価を返す
- `preloadPrices()` で複数商品の一括取得ができる

---

## P4: 発注確定時の単価設定

### 目的

発注確定時に入荷予定にバラ/ケース両方の自社単価を設定する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/AutoOrder/OrderExecutionService.php` | `createIncomingSchedulesFromCandidate()` 更新 |

### 修正方針

```php
$prices = $this->purchasePriceService->getPrice(
    $candidate->item_id,
    $supplierPartnerId,
    $candidate->warehouse_id,
    $orderDate
);

WmsOrderIncomingSchedule::create([
    // ...既存フィールド...
    'unit_price' => $prices['unit_price'],
    'case_price' => $prices['case_price'],
]);
```

- `PurchasePriceService` を DI で注入
- `createManualIncomingSchedule()` も同様に対応
- `partner_unit_price` / `partner_case_price` は発注確定時にはセットしない（出荷実績取込時にセット）

### 完了条件

- 発注確定後の入荷予定に `unit_price` / `case_price` が設定されている

---

## P5: 出荷実績照合ロジック拡張

### 目的

商品マッピングを3段階に拡張し、照合時に `shipped_quantity`・仕入先単価を書き戻し、エラー/ワーニングを記録する。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/AutoOrder/IncomingReceiveService.php` | matchSlip/matchDetail 拡張 |

### 修正方針

#### 5-1. 商品マッピング3段階

```
Step 1: slip_number → wms_order_incoming_schedules 検索
        → ordering_code と d_jan_code 比較 → マッチすれば確定
Step 2: d_jan_code / d_item_code → item_search_information.search_string 検索
Step 3: ITEM_NOT_FOUND → wms_incoming_import_errors に記録
```

#### 5-2. 照合時の書き戻し

```php
$schedule->update([
    'shipped_quantity' => $totalShippedQty,
    'partner_unit_price' => $partnerPiecePrice,
    'partner_case_price' => $partnerCasePrice,
    'price_type' => $priceType,  // 'CASE' or 'PIECE'
    'is_receive_matched' => true,
    'shortage_quantity' => max(0, $schedule->expected_quantity - $totalShippedQty),
]);
```

#### 5-3. 単価不一致チェック

- `price_type = 'CASE'` → `case_price` vs `partner_case_price`
- `price_type = 'PIECE'` → `unit_price` vs `partner_unit_price`
- 不一致 → `wms_incoming_import_errors` に `PRICE_MISMATCH` 記録
- 例外: JAN `9999999999996`（送料）はチェック対象外

#### 5-4. JX Dレコード単価タイプ判定

| ケース数 | バラ数 | price_type | 原単価の意味 |
|---------|--------|-----------|------------|
| > 0 | any | CASE | ケース単価 |
| 0 | > 0 | PIECE | バラ単価 |
| 0 | 0 | CASE | デフォルト |

カナカン（1021）: ケース発注でもバラで返るため `price_type = 'PIECE'`。

### 完了条件

- JXデータ照合で `shipped_quantity` が入荷予定に書き戻される
- 仕入先単価が `partner_unit_price` / `partner_case_price` に設定される
- 商品不明 → `ITEM_NOT_FOUND` エラー記録
- 単価不一致 → `PRICE_MISMATCH` ワーニング記録

---

## P6: アクト中食CSVパーサー

### 目的

アクト中食（code 1497）のCSV形式に対応する `ActCsvIncomingParser` を新規作成。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php` | 新規 |

### 修正方針

`IncomingFormatParserInterface` を実装。

**CSV仕様:** Shift_JIS、カンマ区切り、ヘッダーあり、65カラム。

**カラムマッピング（0-indexed）:**

| CSVカラム | Index | マッピング先 |
|----------|-------|------------|
| 得意先コード | 0 | `b_shop_code` |
| 伝票日付 | 1 | `b_delivery_date` |
| 伝票No | 2 | `slip_number`（グルーピングキー） |
| 行No | 4 | `d_line_number` |
| 商品コード | 6 | `d_jan_code` |
| 商品名１ | 7 | `d_product_name` |
| 入数１ | 11 | `d_pack_quantity` |
| ケース数 | 13 | `d_case_quantity` |
| 数量 | 15 | `total_quantity` |
| 売価単価 | 19 | `partner_unit_price` |
| JANコード | 29 | `d_jan_code`（補助） |
| 発注仕入先コード | 57 | `d_item_code` |

**商品マッピング:**
```
CSV.商品コード → item_connections.partner_item_code
WHERE partners.code = '1497' AND partners.is_supplier = true
```
パース開始前に `partner_item_code → item_id` 連想配列を一括ロード。

**処理フロー:**
1. Shift_JIS → UTF-8 変換
2. ヘッダー行スキップ
3. `伝票No` でグルーピング → 伝票単位に集約
4. `WmsIncomingReceivedFile` 作成（`format_type = 'CSV'`）
5. 各グループ → `WmsIncomingReceivedSlip` + `WmsIncomingReceivedDetail` 作成
6. 商品特定不可 → `ITEM_NOT_FOUND` エラー記録

**送料:** JAN `9999999999996` は特別扱い（単価比較対象外）。

### 完了条件

- サンプルCSV3ファイルすべてパース成功
- `WmsIncomingReceivedFile` / `Slip` / `Detail` が正しく作成される
- 商品不明時に `wms_incoming_import_errors` にエラー記録

---

## P7: Handy API拡張

### 目的

入荷作業画面で出荷実績数・単価を表示できるよう、APIレスポンスにフィールド追加。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Http/Controllers/Api/IncomingController.php` | `formatScheduleDetail()` 更新 |

### 修正方針

レスポンスに追加:
```php
'shipped_quantity' => $schedule->shipped_quantity,
'unit_price' => $schedule->unit_price,
'partner_unit_price' => $schedule->partner_unit_price,
```

### 完了条件

- APIレスポンスに3フィールドが含まれる

---

## P8: 発注データCSV伝票番号修正

### 目的

CSVダウンロード時の伝票番号をDB上の `slip_number`（11桁）に一致させ、出荷実績との照合を可能にする。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/AutoOrder/OrderDataFileService.php` | `buildCsvContent()` 更新 |

### 修正方針

**変更前:** 入荷予定日ごとの単純連番（1, 2, 3...）
**変更後:** `WmsOrderIncomingSchedule.slip_number` を使用

```php
// 候補IDから入荷予定のslip_numberを取得
$schedule = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)->first();
$slipNumber = $schedule?->slip_number ?? '';
```

明細行番号: 同一 `slip_number` 内での連番（伝票単位）。

### 完了条件

- CSV出力の伝票番号がDB上の11桁 `slip_number` と一致
- 明細行番号が伝票単位の連番

---

## P9: Filament UI変更

### 目的

入荷予定テーブルにカラム追加、CSVアップロード機能追加、エラーリスト画面新規作成。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` | カラム追加 |
| `app/Filament/Resources/WmsIncomingReceivedData/Pages/ListWmsIncomingReceivedData.php` | CSVアップロードアクション追加 |
| `app/Filament/Resources/WmsIncomingImportErrorResource.php` | 新規Resource |
| `app/Filament/Resources/WmsIncomingImportError/Pages/ListWmsIncomingImportErrors.php` | 新規ページ |
| `app/Filament/Resources/WmsIncomingImportError/Tables/WmsIncomingImportErrorsTable.php` | 新規テーブル |

### 修正方針

#### 9-1. 入荷予定テーブル カラム追加

- `shipped_quantity`（出荷実績数）: numeric, 70px, `expected_quantity` の後
- `unit_price`（自社単価）: money, toggleable hidden
- `partner_unit_price`（仕入先単価）: money, toggleable hidden
- 単価不一致バッジ: `unit_price != partner_unit_price` の場合にワーニング表示

#### 9-2. CSVアップロードアクション

`ListWmsIncomingReceivedData` のヘッダーアクションに「CSVデータ取込」を追加:
- 発注先選択（contractor_id）→ ファイルアップロード
- `ActCsvIncomingParser::parse()` を呼び出し

#### 9-3. エラーリスト画面

`WmsIncomingImportErrorResource` として新規作成:
- フィルター: `error_type`（ERROR/WARNING）、`is_resolved`
- カラム: ファイル名、伝票番号、商品コード、エラー種別、メッセージ、自社単価、仕入先単価、解決済み
- アクション: 「解決済みにする」ボタン

### 完了条件

- 入荷予定テーブルに新カラムが表示される
- CSVアップロードが動作する
- エラーリスト画面が表示・操作できる

---

## 制約（厳守）

1. **FK禁止**: リレーションはアプリケーション層で管理
2. **migrate:fresh/refresh 禁止**: 新規マイグレーションの追加のみ
3. **core側コード依存禁止**: 単価取得はDB直接参照
4. **sakemaru コネクション使用**: WMSテーブルは `Schema::connection('sakemaru')`
5. **N+1クエリ回避**: プリロード必須
6. **Filament 4パターン**: `Schemas\Components\Section`, `Actions\Action` 等の正しいインポートパス

## 全体完了条件

1. 全マイグレーション実行成功
2. 発注確定 → 入荷予定に自社単価設定される
3. JXデータ照合 → shipped_quantity・仕入先単価が書き戻される
4. アクト中食CSVパース → DB登録成功
5. 単価不一致 → ワーニング記録 + UI表示
6. 商品不明 → エラー記録 + UI表示
7. CSV伝票番号がDB上のslip_numberと一致
8. Handy API に新フィールド含まれる
