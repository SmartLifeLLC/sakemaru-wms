# ケース/バラ別行発注対応 作業計画

## 前提

- 仕様書: `20260407-181222-case-piece-order-split.md`
- DBスキーマ: `wms_order_candidates.quantity_type` ENUM('PIECE','CASE','CARTON') は既に存在
- 単価テーブル: `item_partner_prices` に `unit_price`/`case_price` 両方存在
- `PurchasePriceService.getPrice()` は `unit_price`/`case_price` 両方を返却済み
- 現在は全て `quantity_type=PIECE` でハードコード

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 自動発注ケース対応 | CalculationServiceでquantity_type=CASE、order_quantity=ケース数に変更 | 自動発注候補がCASEで生成される |
| P2 | JXファイル生成対応 | HanaOrderJXFileGeneratorのDレコードをquantity_typeベースに変更 | ケース行/バラ行で正しいケース数・バラ数・単価が出力される |
| P3 | 手動発注ケース/バラ別行 | UI排他制御削除、PHP側で2レコード生成 | ケース3+バラ2で2レコード作成される |
| P4 | 確定・入荷予定対応 | OrderExecutionServiceでprice_type設定 | 入荷予定にquantity_type/price_typeが正しく設定される |
| P5 | テーブル表示対応 | 発注候補・入荷予定テーブルにquantity_type表示 | ケース/バラが視覚的に区別できる |
| P6 | 入荷API対応 | capacity_case返却追加、IncomingConfirmationServiceでバラ数変換 | ケース行の入荷完了で正しいバラ数が在庫に加算される |
| P7 | 結合テスト | 自動発注→確定→JX生成→入荷の一連フロー検証 | 全フロー通過、既存PIECEデータとの互換性確認 |

---

## P1: 自動発注ケース対応

### 目的

自動発注候補生成で `quantity_type=CASE`、`order_quantity=ケース数` に変更する。自動発注は常にケース単位（purchase_unitで切上げ済みのため端数なし）。

### 修正対象ファイル

- `app/Services/AutoOrder/OrderCandidateCalculationService.php`

### 修正内容

**1-1. 外部発注候補の quantity_type 変更（行926付近）**

```php
// 変更前
'quantity_type' => QuantityType::PIECE->value,

// 変更後
'quantity_type' => QuantityType::CASE->value,
```

**1-2. order_quantity をケース数に変更（行926付近）**

現在: `order_quantity` = バラ数（purchase_unit で切上げ済み）
変更後: `order_quantity` = ケース数 = バラ数 / capacity_case

```php
// order_quantity 計算後に追加
$orderQuantityCase = intdiv($orderQuantity, $capacityCase);
// → order_quantity に $orderQuantityCase を設定
```

注意: `capacity_case` は Item モデルから取得。事前にプリロード済みか確認。

**1-3. purchase_unit_price をケース単価に変更**

`$supplierItemPrices` のプリロード（行372-408）で `case_price` も取得:

```php
// 変更前: unit_price のみ
'ipp.unit_price'

// 変更後: case_price も取得
'ipp.unit_price', 'ipp.case_price'
```

候補作成時（行903付近）:
```php
// 変更前
'purchase_unit_price' => $this->supplierItemPrices[$ic->item_id][$ic->supplier_id] ?? null,

// 変更後: case_price を使用
'purchase_unit_price' => $this->supplierItemCasePrices[$ic->item_id][$ic->supplier_id] ?? null,
```

**1-4. 内部移動候補は変更しない**（行671: PIECE のまま維持）

### 完了条件

- `php artisan test --filter=OrderCandidate` が通る（既存テストの修正含む）
- 自動発注候補生成を実行し、wms_order_candidates に `quantity_type='CASE'` のレコードが作成される
- `order_quantity` がケース数（バラ数 / capacity_case）になっている
- `purchase_unit_price` がケース単価になっている
- 内部移動候補は `quantity_type='PIECE'` のまま

---

## P2: JXファイル生成対応

### 目的

JXファイルのDレコード生成を `capacity_case` ベースの判定から `quantity_type` ベースの判定に変更。

### 修正対象ファイル

- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php`
- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator2.php`（同様の変更が必要か確認）

### 修正内容

**2-1. generateDRecord() (行391-428)**

```php
// 変更前 (行399-405)
if ($capacityCase <= 1) {
    $caseQty = 0;
    $pieceQty = $totalQty;
} else {
    $caseQty = intdiv($totalQty, $capacityCase);
    $pieceQty = 0;
}

// 変更後: quantity_type で判定
if ($candidate->quantity_type === QuantityType::CASE) {
    $caseQty = $totalQty;   // order_quantity がケース数
    $pieceQty = 0;
} else {
    $caseQty = 0;
    $pieceQty = $totalQty;  // order_quantity がバラ数
}
// 仕入入数は常に capacity_case（変更しない）
```

**2-2. getCurrentCostPrice() (行718-750)**

```php
// 変更前: capacity_case で判定
if ($capacityCase <= 1) {
    return (float) ($price->cost_unit_price ?? 0);

// 変更後: quantity_type で判定（候補を引数に追加）
if ($candidate->quantity_type === QuantityType::PIECE) {
    return (float) ($price->cost_unit_price ?? 0);  // バラ単価
} else {
    return (float) ($price->cost_case_price ?? 0);  // ケース単価
}
```

**2-3. HanaOrderJXFileGenerator2 の確認**

Generator2 の Dレコード生成に同様のロジックがあれば同じ変更を適用。

### 完了条件

- テスト用データでJXファイルを生成し:
  - CASE候補: ケース数フィールドに値、バラ数量=0
  - PIECE候補: ケース数=0、バラ数量フィールドに値
  - 仕入入数は両方とも capacity_case
  - 原単価がケース/バラそれぞれの正しい単価

---

## P3: 手動発注ケース/バラ別行対応

### 目的

手動発注追加で、ケースとバラを同時入力可能にし、それぞれ別の WmsOrderCandidate レコードとして生成する。

### 修正対象ファイル

- `resources/views/filament/components/order-candidate-create-items.blade.php`
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`

### 修正内容

**3-1. Blade: 排他制御の削除**

```javascript
// 削除: onCaseInput() 内の pieceQty null化（行58-63）
// 削除: onPieceInput() 内の caseQty null化（行65-70）
```

ケース欄とバラ欄を**両方同時入力可能**にする。

**3-2. Blade: syncToWire() の変更（行85-99）**

```javascript
// 変更前: 1商品1エントリ
const items = this.rows
    .filter(r => r.itemId && this.orderQty(r) > 0)
    .map(r => ({ ... }));

// 変更後: ケースとバラで別エントリ
const items = this.rows.flatMap(r => {
    if (!r.itemId) return [];
    const entries = [];
    if (r.caseQty > 0) entries.push({
        item_id: r.itemId,
        quantity_type: 'CASE',
        order_quantity: r.caseQty,
        case_qty: r.caseQty,
        piece_qty: 0,
        capacity_case: r.capacityCase || 1,
    });
    if (r.pieceQty > 0) entries.push({
        item_id: r.itemId,
        quantity_type: 'PIECE',
        order_quantity: r.pieceQty,
        case_qty: 0,
        piece_qty: r.pieceQty,
        capacity_case: r.capacityCase || 1,
    });
    return entries;
});
```

**3-3. PHP: 候補作成ロジック（行220-322）**

```php
// 変更前 (行286)
'quantity_type' => QuantityType::PIECE,

// 変更後: itemData から quantity_type を取得
'quantity_type' => QuantityType::from($itemData['quantity_type']),
```

単価も quantity_type に応じて切替:
```php
'purchase_unit_price' => $itemData['quantity_type'] === 'CASE'
    ? $casePriceForItem
    : $unitPriceForItem,
```

### 完了条件

- 手動発注でケース=3、バラ=2を入力 → 2つの WmsOrderCandidate が作成される
  - 1つ: quantity_type=CASE, order_quantity=3, purchase_unit_price=ケース単価
  - 1つ: quantity_type=PIECE, order_quantity=2, purchase_unit_price=バラ単価
- ケースのみ入力 → 1レコード（CASE）
- バラのみ入力 → 1レコード（PIECE）
- 両方0 → レコード作成されない

---

## P4: 確定・入荷予定対応

### 目的

発注確定時に入荷予定の `price_type` を `quantity_type` に基づいて正しく設定する。

### 修正対象ファイル

- `app/Services/AutoOrder/OrderExecutionService.php`

### 修正内容

**4-1. createIncomingSchedulesFromCandidate() (行151-245)**

`quantity_type` は既に候補から継承されている（行206, 232）。追加で `price_type` を設定:

```php
// 行206, 232 付近に追加
'price_type' => $candidate->quantity_type === QuantityType::CASE ? 'CASE' : 'PIECE',
```

### 完了条件

- 発注確定実行後、`wms_order_incoming_schedules` に:
  - CASE候補 → `quantity_type='CASE'`, `price_type='CASE'`
  - PIECE候補 → `quantity_type='PIECE'`, `price_type='PIECE'`
- `expected_quantity` が候補の `order_quantity` と一致

---

## P5: テーブル表示対応

### 目的

発注候補テーブルと入荷予定テーブルで quantity_type を視覚的に区別できるようにする。

### 修正対象ファイル

- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php`

### 修正内容

**5-1. 発注候補テーブル**

`order_quantity` カラムに quantity_type のラベルを suffix 表示:
```php
TextInputColumn::make('order_quantity')
    ->suffix(fn ($record) => $record->quantity_type?->name() ?? 'バラ')
```

**5-2. 入荷予定テーブル**

ケース/バラ計算カラム（行116-144）の調整:
- `quantity_type=CASE` の場合: `expected_quantity` がそのままケース数
- `quantity_type=PIECE` の場合: 既存の計算（expected_quantity / capacity_case）

### 完了条件

- 発注候補一覧で「3 ケース」「2 バラ」のように単位が表示される
- 入荷予定一覧でケース/バラが正しく表示される

---

## P6: 入荷API対応

### 目的

入荷API で capacity_case を返却し、入荷確定時にケース数→バラ数変換を行う。

### 修正対象ファイル

- `app/Http/Controllers/Api/IncomingController.php`
- `app/Services/AutoOrder/IncomingConfirmationService.php`

### 修正内容

**6-1. formatScheduleDetail() (行1051-1082)**

`capacity_case` フィールドを追加返却:
```php
'capacity_case' => $schedule->item?->capacity_case ?? 1,
```

**6-2. formatWorkItem() (行1087-1129)**

schedule 内に `capacity_case` を追加。

**6-3. IncomingConfirmationService — 在庫更新のバラ数変換**

`confirmIncoming()` (行30-87) と `recordPartialIncoming()` (行100-162):

```php
// 在庫加算時に quantity_type を考慮
$pieceQuantity = $schedule->quantity_type === QuantityType::CASE
    ? $workQuantity * ($schedule->item?->capacity_case ?? 1)
    : $workQuantity;

// $pieceQuantity を real_stocks に加算
```

### 完了条件

- GET /api/incoming/schedules/{id} のレスポンスに `capacity_case` が含まれる
- CASE行の入荷完了: work_quantity=3（ケース）→ real_stocks に 3×24=72（バラ）が加算
- PIECE行の入荷完了: work_quantity=5（バラ）→ real_stocks に 5（バラ）が加算

---

## P7: 結合テスト

### 目的

全フローを通しで検証し、既存 PIECE データとの互換性を確認する。

### テスト手順

**7-1. 自動発注フロー**

1. 自動発注候補生成を実行
2. `wms_order_candidates` で quantity_type=CASE, order_quantity=ケース数を確認
3. 発注確定を実行
4. `wms_order_incoming_schedules` で quantity_type=CASE, price_type=CASE を確認
5. JXファイルを生成し、Dレコードのケース数/バラ数/単価を確認

**7-2. 手動発注フロー**

1. 手動発注でケース=3、バラ=2を入力
2. 2レコード作成を確認
3. 発注確定
4. 入荷予定が2レコード（CASE/PIECE）で作成されることを確認

**7-3. 入荷フロー**

1. CASE行の入荷完了 → 在庫がバラ数で加算されることを確認
2. PIECE行の入荷完了 → 在庫がバラ数のまま加算されることを確認

**7-4. 既存データ互換**

1. 既存の quantity_type=PIECE レコードが正常に動作すること
2. CSVファイル生成が既存データで正常動作すること

### 完了条件

- 全テスト手順が成功
- 既存データに影響なし
- `php artisan test` 全テスト通過

---

## 制約（厳守）

1. **FK禁止**: 全リレーションはアプリケーションレベルで管理
2. **migrate:fresh/refresh/reset/db:wipe 絶対禁止**: 本番共有DB。テスト実行時も含む
3. **テスト時のDB操作**: `RefreshDatabase` トレイトや `migrate:fresh` を使うテストは禁止。テストデータは `setUp()` で個別に作成し、`tearDown()` または `DatabaseTransactions` トレイトで後片付けする
4. **既存データ互換**: 既存 PIECE レコードは変更しない
5. **仕入入数は変更しない**: ケース/バラ問わず `capacity_case` を使用
6. **移動候補は対象外**: 内部移動の quantity_type=PIECE は維持
7. **自動発注はケースのみ**: バラ端数は出ない前提（purchase_unit で切上げ済み）

## 全体完了条件

1. 自動発注候補が quantity_type=CASE で生成される
2. 手動発注でケース/バラ別レコードが作成される
3. JXファイルのDレコードが quantity_type に応じた正しい値を出力
4. 入荷予定に price_type が設定される
5. 入荷完了でケース数→バラ数変換が正しく行われる
6. 既存の PIECE データとの後方互換性が維持される
7. 全テスト通過
