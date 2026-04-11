# 入荷予定拡張：出荷実績取込・単価管理・CSVパーサー

- **作成日**: 2026-03-07
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/incoming/入荷予定対応/20260307-incoming-schedule-enhancements/`
- **参照元**: `発注・入荷予定テスト/1.事前調査-結果.md`（追加要件A〜E）

## 背景・目的

発注→入荷→仕入の業務フローにおいて、以下の機能が不足している:

1. **出荷実績数量の管理**: 発注数（expected）と入荷確定数（received）の間に、メーカーからの出荷実績数（shipped）を記録するカラムがない
2. **単価管理**: 入荷予定に自社単価・仕入先単価がなく、出荷実績取込時の単価不一致検知ができない
3. **CSVパーサー**: JXパーサーのみ実装済み。アクト中食（code 1497）のCSV形式に未対応
4. **取込エラー管理**: 商品特定不可・単価不一致等のエラー/ワーニングを管理する仕組みがない

## 現状の実装

### `wms_order_incoming_schedules` の数量関連カラム

| カラム | 用途 | 備考 |
|-------|------|------|
| `expected_quantity` | 発注数 | 発注確定時に設定 |
| `received_quantity` | 入荷確定数 | 初期値0、ハンディで確定時に更新 |
| `shortage_quantity` | 欠品数 | 出荷実績照合時に設定 |

→ `shipped_quantity`（出荷実績数）が欠落。

### 単価

入荷予定に単価カラムなし。発注時の単価情報は`wms_order_candidates`に保持されるが入荷予定にコピーされない。

### パーサー

`IncomingFormatParserInterface` の実装は `JxIncomingParser` のみ。`receive_format` 設定は存在するがCSVパーサーは未実装。

### エラー管理

出荷実績取込時のエラー/ワーニングを保持するテーブル・画面なし。

---

## 変更内容

### 概要

1. `wms_order_incoming_schedules` に `shipped_quantity`・`unit_price`・`partner_unit_price` カラム追加
2. 出荷実績取込エラー管理テーブル `wms_incoming_import_errors` 新規作成
3. アクト中食CSVパーサー `ActCsvIncomingParser` 新規作成
4. 既存の照合ロジック・API・UI を新カラムに対応

---

### 詳細設計

#### P1. DB変更

##### 1-1. `wms_order_incoming_schedules` にカラム追加

```php
// マイグレーション
$table->integer('shipped_quantity')->default(0)->after('expected_quantity')
    ->comment('出荷実績数（メーカーからの出荷実績）');

// 自社単価（バラ・ケース両方）
$table->decimal('unit_price', 12, 2)->nullable()->after('shortage_quantity')
    ->comment('仕入自社バラ単価');
$table->decimal('case_price', 12, 2)->nullable()->after('unit_price')
    ->comment('仕入自社ケース単価');

// 仕入先単価（バラ・ケース両方）
$table->decimal('partner_unit_price', 12, 2)->nullable()->after('case_price')
    ->comment('仕入先バラ単価（出荷実績から取込）');
$table->decimal('partner_case_price', 12, 2)->nullable()->after('partner_unit_price')
    ->comment('仕入先ケース単価（出荷実績から取込）');

// 単価タイプ（出荷実績の単価がケースかバラか）
$table->string('price_type', 10)->nullable()->after('partner_case_price')
    ->comment('単価タイプ: CASE or PIECE');
```

**数量フロー（変更後）:**

| カラム | 用途 | 設定タイミング | 設定元 |
|-------|------|--------------|--------|
| `expected_quantity` | 発注数 | 発注確定時 | `WmsOrderCandidate.order_quantity` |
| `shipped_quantity` | 出荷実績数 | 出荷実績取込時 | JX Dレコード or CSV |
| `received_quantity` | 入荷確定数 | ハンディで入荷確定時 | 作業者入力 |
| `shortage_quantity` | 欠品数 | 出荷実績照合時 | `expected_quantity - shipped_quantity` |

**単価フロー:**

| カラム | 設定タイミング | 取得元 |
|-------|--------------|--------|
| `unit_price` | 発注確定時 | DB 4段階フォールバック（バラ単価） |
| `case_price` | 発注確定時 | DB 4段階フォールバック（ケース単価） |
| `partner_unit_price` | 出荷実績取込時 | JX: Dレコード原単価 / CSV: 売価単価（バラ単価） |
| `partner_case_price` | 出荷実績取込時 | JX: Dレコード原単価 / CSV: 売価単価（ケース単価） |
| `price_type` | 出荷実績取込時 | 出荷形態で判定（CASE/PIECE） |

**発注先別の単価タイプ:**

| 発注先 | コード | 発注形態 | 入荷予定 | 単価タイプ |
|--------|--------|---------|---------|-----------|
| カナカン | 1021 | ケース発注 | **バラ数量で入荷予定** | **PIECE**（バラ単価） |
| コカコーラ | 1017 | ケース発注 | ケース入荷予定 | CASE（ケース単価） |
| 国分 | 1202 | ケース発注 | ケース入荷予定 | CASE（ケース単価） |
| 三菱食品 | 1330 | ケース発注 | ケース入荷予定 | CASE（ケース単価） |

##### 1-2. `wms_incoming_import_errors` テーブル新規作成

```php
Schema::connection('sakemaru')->create('wms_incoming_import_errors', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('received_file_id')->comment('取込ファイルID');
    $table->unsignedBigInteger('received_slip_id')->nullable()->comment('伝票ID');
    $table->unsignedBigInteger('received_detail_id')->nullable()->comment('明細ID');
    $table->enum('error_type', ['ERROR', 'WARNING'])->comment('エラー種別');
    $table->string('error_code', 50)->comment('エラーコード');
    $table->text('error_message')->comment('エラーメッセージ');
    $table->json('raw_data')->nullable()->comment('元データ（全項目）');
    $table->string('item_code', 20)->nullable()->comment('照合試行した商品コード');
    $table->decimal('expected_price', 12, 2)->nullable()->comment('自社単価');
    $table->decimal('actual_price', 12, 2)->nullable()->comment('仕入先単価');
    $table->boolean('is_resolved')->default(false)->comment('解決済みフラグ');
    $table->unsignedBigInteger('resolved_by')->nullable();
    $table->datetime('resolved_at')->nullable();
    $table->timestamps();
});
```

**エラーコード定義:**

| error_code | error_type | 条件 |
|-----------|-----------|------|
| `ITEM_NOT_FOUND` | ERROR | 商品コードで商品を特定できない |
| `PRICE_MISMATCH` | WARNING | `unit_price != partner_unit_price` |
| `SLIP_NOT_FOUND` | WARNING | 伝票番号で入荷予定が見つからない（新規作成される） |

**例外:** JAN `9999999999996`（送料）は `PRICE_MISMATCH` チェック対象外。

---

#### P2. モデル変更

##### 2-1. `WmsOrderIncomingSchedule` モデル更新

```php
// fillable に追加
'shipped_quantity',
'unit_price',
'case_price',
'partner_unit_price',
'partner_case_price',
'price_type',

// casts に追加
'shipped_quantity' => 'integer',
'unit_price' => 'decimal:2',
'case_price' => 'decimal:2',
'partner_unit_price' => 'decimal:2',
'partner_case_price' => 'decimal:2',
```

##### 2-2. `WmsIncomingImportError` モデル新規作成

`WmsModel` を継承。`received_file_id` / `received_slip_id` / `received_detail_id` のリレーション定義。

---

#### P3. 自社単価取得サービス

##### 3-1. `PurchasePriceService` 新規作成

`app/Services/AutoOrder/PurchasePriceService.php`

DB直接参照で自社仕入単価を取得する（core側コードへの依存なし）。

**4段階フォールバック:**

```
Step 1: item_partner_prices WHERE partner_id = :supplier_partner_id
Step 2: item_partner_prices WHERE partner_id = :partner_price_group_id
Step 3: item_partner_prices WHERE partner_id = :partner_price_group2_id
Step 4: item_prices → items.purchase_price_type で分岐
         PRODUCER → producer_unit_price / producer_case_price
         COST     → cost_unit_price / cost_case_price
         WHOLESALE → wholesale_unit_price / wholesale_case_price
```

各ステップのSQL:
```sql
-- Step 1-3（partner_id を差し替えて3回検索）
SELECT * FROM item_partner_prices
WHERE item_id = :item_id
  AND partner_id = :partner_id
  AND (warehouse_id = :warehouse_id OR warehouse_id IS NULL)
  AND start_date <= :process_date
ORDER BY start_date DESC LIMIT 1;

-- Step 4（上記すべてNULLの場合）
SELECT ip.*, i.purchase_price_type
FROM item_prices ip
JOIN items i ON i.id = ip.item_id
WHERE ip.item_id = :item_id
  AND ip.start_date <= :process_date
ORDER BY ip.start_date DESC LIMIT 1;
```

**戻り値:** バラ単価・ケース単価の両方を返す。

```php
// 戻り値の形式
[
    'unit_price' => float,   // バラ単価
    'case_price' => float,   // ケース単価
    'source' => string,      // 'PARTNER' | 'PARTNER_PRICE_GROUP' | 'PARTNER_PRICE_GROUP2' | 'ITEM_PRICE'
]
```

**仕入先の特定:** `Partner::where('is_supplier', true)->firstWhere('code', $partner_code)` で仕入先のみ取得。`item_partner_prices` には仕入先・得意先の区別なくレコードが格納されているが、`is_supplier=true` の `partner_id` でフィルタすることで得意先の個別単価は参照されない。

**プリロード対応:** N+1回避のため、複数商品の単価を一括取得する `preloadPrices(array $itemIds, ...)` メソッドも用意。

---

#### P4. 発注確定時の単価設定

##### 4-1. `OrderExecutionService::createIncomingSchedulesFromCandidate()` 更新

発注確定時に バラ/ケース両方の単価を設定:

```php
$prices = $this->purchasePriceService->getPrice(
    $candidate->item_id,
    $candidate->supplier_id,  // 仕入先のpartner_id（is_supplier=trueで検索）
    $candidate->warehouse_id,
    $orderDate
);

WmsOrderIncomingSchedule::create([
    // ...既存フィールド...
    'unit_price' => $prices['unit_price'],
    'case_price' => $prices['case_price'],
    'partner_unit_price' => $prices['unit_price'],   // 初期値は自社単価と同一
    'partner_case_price' => $prices['case_price'],   // 初期値は自社単価と同一
]);
```

##### 4-2. 手動発注（`createManualIncomingSchedule()`）も同様に対応

---

#### P5. 出荷実績照合ロジックの拡張

##### 5-1. JX受信データの商品マッピング変更

**現状:** `d_item_code`（自社コード）で商品を特定。
**問題:** 自社コードが入っていないケースがある。

**変更後の商品マッピングフロー（3段階）:**

```
Step 1: 伝票番号 + 発注コードで照合
  → slip_number で wms_order_incoming_schedules を検索
  → 候補の ordering_code（発注時に使ったJANコード）と d_jan_code を比較
  → マッチすれば商品確定

Step 2: item_search_information から検索（Step 1 で見つからない場合）
  → d_jan_code または d_item_code で item_search_information.search_string を検索
  → マッチすれば商品確定

Step 3: 商品不明（ERROR）
  → wms_incoming_import_errors に ITEM_NOT_FOUND を記録
```

##### 5-2. `IncomingReceiveService::matchSlip()` 更新

照合時に `shipped_quantity` と 仕入先単価（ケース/バラ両方）を入荷予定に書き戻す:

```php
// 照合成功時
$schedule->update([
    'shipped_quantity' => $totalShippedQty,
    'partner_unit_price' => $partnerPiecePrice,  // 仕入先バラ単価
    'partner_case_price' => $partnerCasePrice,   // 仕入先ケース単価
    'price_type' => $priceType,                  // 'CASE' or 'PIECE'
    'is_receive_matched' => true,
    'shortage_quantity' => max(0, $schedule->expected_quantity - $totalShippedQty),
]);
```

##### 5-3. 単価不一致チェック追加

照合時に自社単価と仕入先単価を `price_type` に応じて比較:
- `price_type = 'CASE'` → `case_price` vs `partner_case_price` を比較
- `price_type = 'PIECE'` → `unit_price` vs `partner_unit_price` を比較
- 不一致 → `wms_incoming_import_errors` に `PRICE_MISMATCH` を記録
- 例外: JAN `9999999999996`（送料）はチェック対象外

##### 5-4. 仕入先単価の判定（JX Dレコード）

原単価（10桁、下2桁小数）の種別判定:

| ケース数 | バラ数 | price_type | 原単価の意味 |
|---------|--------|-----------|------------|
| > 0 | any | `CASE` | ケース単価 → `partner_case_price` に設定 |
| 0 | > 0 | `PIECE` | バラ単価 → `partner_unit_price` に設定 |
| 0 | 0（欠品） | `CASE` | ケース単価（デフォルト） |

**カナカン（1021）の特殊ケース:** ケース発注でもバラ数量で入荷予定が返る。`price_type = 'PIECE'`、単価もバラ単価。

---

#### P6. アクト中食CSVパーサー

##### 6-1. `ActCsvIncomingParser` 新規作成

`app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php`

`IncomingFormatParserInterface` を実装。

**CSV仕様（Shift_JIS、カンマ区切り、ヘッダーあり）:**

| CSVカラム | 用途 | マッピング先 |
|----------|------|------------|
| 得意先コード (col 0) | 倉庫コード | `b_shop_code` |
| 伝票日付 (col 1) | 納品日 | `b_delivery_date` |
| 伝票No (col 2) | 伝票番号 | `slip_number`（グルーピングキー） |
| 行No (col 4) | 行番号 | `d_line_number` |
| 商品コード (col 6) | 商品コード | `d_jan_code`（JAN形式） |
| 商品名１ (col 7) | 品名 | `d_product_name` |
| 入数１ (col 11) | 入数 | `d_pack_quantity` |
| ケース数 (col 13) | ケース数 | `d_case_quantity` |
| 数量 (col 15) | 総数量 | `total_quantity` |
| 売価単価 (col 19) | 仕入先単価 | `partner_unit_price` |
| JANコード (col 29) | JANコード | `d_jan_code`（補助） |
| 発注仕入先コード (col 57) | 自社コード | `d_item_code` |

**商品マッピング方式:**

```
CSV.商品コード → item_connections.partner_item_code
WHERE partners.code = '1497' AND partners.is_supplier = true
```

- `item_connections` は sakemaru DB 側のテーブル
- N+1回避: パース開始前に `partner_item_code → item_id` の連想配列を一括ロード

**CSVパース処理:**

1. Shift_JIS → UTF-8 変換
2. ヘッダー行スキップ
3. 行を `伝票No` でグルーピング → 伝票単位に集約
4. 各グループ:
   - 1件目の行情報で `WmsIncomingReceivedSlip` 作成（`format_type = 'CSV'`）
   - 各行で `WmsIncomingReceivedDetail` 作成
5. 商品特定不可の場合 → `wms_incoming_import_errors` に `ITEM_NOT_FOUND` 記録

**送料の特別扱い:**
- JAN `9999999999996` = 送料。相手単価をそのまま採用、単価比較対象外

---

#### P7. Handy API 拡張

##### 7-1. `IncomingController::formatScheduleDetail()` 更新

レスポンスに追加:

```php
'shipped_quantity' => $schedule->shipped_quantity,
'unit_price' => $schedule->unit_price,
'partner_unit_price' => $schedule->partner_unit_price,
```

---

#### P8. 発注データCSVの伝票番号修正

##### 8-0. `OrderDataFileService::buildCsvContent()` 更新

**現状:** CSVの伝票番号が入荷予定日ごとの単純連番（1, 2, 3...）になっており、DB上の `slip_number`（11桁: `YYYYMMDDNNN`）と一致しない。

**変更:** DB上の `wms_order_incoming_schedules.slip_number` を使用する。

```php
// 変更前
$slipNumberMap[$date] = $slipNo++;
// ...
$slipNo,  // 伝票番号（連番）

// 変更後: 候補IDから入荷予定のslip_numberを取得
$schedule = WmsOrderIncomingSchedule::where('order_candidate_id', $candidate->id)->first();
$schedule?->slip_number ?? '',  // DB上の伝票番号（11桁）
```

**明細行番号:** 同一 `slip_number` 内での連番に変更（現状の入荷予定日単位ではなく伝票単位）。

これにより、CSVダウンロードした伝票番号でアクト中食等の出荷実績データと照合が可能になる。

#### P9. Filament UI 変更

##### 8-1. 入荷予定テーブル（`WmsOrderIncomingSchedulesTable`）

カラム追加:
- `shipped_quantity`（出荷実績数）
- `unit_price`（自社単価）
- `partner_unit_price`（仕入先単価）
- 単価不一致時のワーニングバッジ

##### 8-2. 出荷実績取込データ画面（`ListWmsIncomingReceivedData`）

ヘッダーアクション追加:
- 「CSVデータ取込」ボタン（ファイルアップロード → `ActCsvIncomingParser`）
- 発注先選択（contractor_id）をアップロード前に選択

##### 8-3. 出荷実績エラーリスト画面（新規）

`WmsIncomingImportErrorResource` として新規作成:
- フィルター: error_type（ERROR/WARNING）、is_resolved
- カラム: ファイル名、伝票番号、商品コード、エラー種別、メッセージ、自社単価、仕入先単価、解決済み
- アクション: 「解決済みにする」ボタン

---

### 影響範囲

| ファイル | 影響内容 |
|---------|---------|
| `WmsOrderIncomingSchedule` モデル | fillable/casts追加 |
| `OrderExecutionService` | 発注確定時の単価設定追加 |
| `IncomingReceiveService` | 照合時の shipped_quantity/partner_unit_price 書き戻し + エラー記録 |
| `IncomingController` API | レスポンスフィールド追加 |
| `IncomingTransmissionService` | purchase_create_queue に単価情報を含める（要確認） |
| `WmsOrderIncomingSchedulesTable` | テーブルカラム追加 |
| `ListWmsIncomingReceivedData` | CSVアップロードアクション追加 |

---

## 制約

1. **FK禁止**: `wms_incoming_import_errors` のリレーションはアプリケーション層で管理
2. **migrate:fresh/refresh 禁止**: 新規マイグレーションの追加のみ
3. **core側コードへの依存禁止**: 単価取得は DB 直接参照（`PurchasePriceService`）
4. **sakemaru コネクション使用**: すべてのWMSテーブルは `sakemaru` DB接続
5. **N+1クエリ回避**: 商品マッピング・単価取得はプリロード必須

---

## 対象ファイル

### 新規作成

| ファイル | 内容 |
|---------|------|
| `database/migrations/xxxx_add_shipped_qty_and_prices_to_incoming_schedules.php` | カラム追加 |
| `database/migrations/xxxx_create_wms_incoming_import_errors.php` | エラーテーブル |
| `app/Models/WmsIncomingImportError.php` | エラーモデル |
| `app/Services/AutoOrder/PurchasePriceService.php` | 自社単価取得サービス |
| `app/Services/AutoOrder/IncomingParsers/ActCsvIncomingParser.php` | アクト中食CSVパーサー |
| `app/Filament/Resources/WmsIncomingImportError/` | エラーリスト画面一式 |

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Models/WmsOrderIncomingSchedule.php` | fillable/casts に3カラム追加 |
| `app/Services/AutoOrder/OrderExecutionService.php` | 発注確定時に unit_price 設定 |
| `app/Services/AutoOrder/IncomingReceiveService.php` | 照合時に shipped_quantity/partner_unit_price 書き戻し + エラー記録 |
| `app/Http/Controllers/Api/IncomingController.php` | `formatScheduleDetail()` にフィールド追加 |
| `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` | カラム追加 |
| `app/Filament/Resources/WmsIncomingReceivedData/Pages/ListWmsIncomingReceivedData.php` | CSVアップロードアクション追加 |
| `app/Filament/Resources/WmsIncomingCompleted/Tables/WmsIncomingCompletedTable.php` | カラム追加（単価表示） |
| `app/Services/AutoOrder/IncomingTransmissionService.php` | 仕入データに単価を含める（要確認） |
| `app/Services/AutoOrder/OrderDataFileService.php` | CSVの伝票番号をDB上の`slip_number`に変更 |

### 参照のみ

| ファイル | 参照理由 |
|---------|---------|
| `app/Contracts/IncomingFormatParserInterface.php` | CSVパーサーが実装するインターフェース |
| `app/Services/AutoOrder/IncomingParsers/JxIncomingParser.php` | 実装パターン参照 |
| `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` | 既存の単価取得ロジック（`getCurrentCostPrice`）参照 |
| `storage/specifications/incoming/入荷予定対応/1497アクト中食sample/` | CSVサンプルデータ |

---

## 確認事項

1. **`IncomingTransmissionService`（仕入データ生成）**: `purchase_create_queue` に `unit_price`/`partner_unit_price` を含めるべきか？含める場合のJSON構造は？
2. **アクト中食CSV の伝票番号**: CSVの`伝票No`は WMS の `slip_number` と照合可能か？それとも別体系か？（別体系の場合、照合キーの設計が必要）
3. **顧客別パーサー選択**: `wms_contractor_settings.receive_format` の値として `'JX'` / `'CSV_ACT'` 等の文字列を使うか、パーサークラス名を直接保持するか？
4. **ウェブアップロード後の自動照合**: CSVアップロード直後に `matchWithSchedules()` を自動実行するか、JX同様に別操作とするか？
