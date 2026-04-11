# 発注仕様変更: ケース/バラ別行発注対応

- **作成日**: 2026-04-07
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260407/20260407-181222-case-piece-order-split/`

## 背景・目的

現在、発注候補は全て `quantity_type = PIECE`（バラ）で生成されている。しかし業務上、ケースとバラでは仕入単価が異なるため、ケース発注とバラ発注を**別々の行**（別レコード）として管理し、それぞれ正しい単価で発注データ（CSV/JXファイル）・入荷予定を生成する必要がある。

### 過去JX発注データ分析（実績ベース）

4問屋（COCACOLA/kanakan/kokubu/mitsubishi）の過去EOS発注データ **44,868商品行** を分析した結果:

| 区分 | 件数 | 割合 |
|------|------|------|
| ケースのみ (CS>0, PC=0) | 25,876 | 57.7% |
| バラのみ (CS=0, PC>0) | 18,992 | 42.3% |
| 同一行に混合 (CS>0 かつ PC>0) | **0** | **0.0%** |

**問屋別内訳**:

| 問屋 | ケースのみ | バラのみ | 混合 |
|------|----------|---------|------|
| COCACOLA | 99.8% | 0.2% | 0% |
| kanakan | 53.9% | 46.1% | 0% |
| kokubu | 56.1% | 43.9% | 0% |
| mitsubishi | 67.0% | 33.0% | 0% |

**重要な発見**:

1. **同一行にケースとバラが混在するデータは0件** — 必ずどちらか一方のみ
2. **バラ発注は入数がある商品でも発生** — 例: 入数=6 でバラ=2（6本入りだが2本のみ注文）
3. **同一届け先×同一商品でケース行とバラ行の両方が存在するケースが2件**:
   - kokubu: ホッピー330ml (JAN:4971701311107)
     - ケース行: 入数=24 × 1ケース + バラ行: 入数=1 × 5バラ
     - ケース行: 入数=24 × 1ケース + バラ行: 入数=1 × 10バラ
   - **注目**: バラ行の入数が「1」に変わっている → 同一商品でもケース/バラで入数が異なる

この分析結果は「ケースとバラは別行（別レコード）」の設計方針を裏付ける。

### 要件

| 項目 | 内容 |
|------|------|
| 自動発注 | **ケースのみ**（既存仕様維持）。`quantity_type = CASE` に変更 |
| 手動発注 | ケース **または** バラを選択可能。両方ある場合は **2レコード** に分割 |
| データ構造 | ケース行とバラ行は**別レコード**（単価・入数が異なるため同一行にできない） |
| CSV/JXファイル | ケース行・バラ行それぞれ独立した行として出力 |
| 入荷予定 | 発注候補の `quantity_type` を継承（ケース→ケース行、バラ→バラ行） |

### JXファイルのケース/バラ出力仕様（過去データに基づく）

JX固定長 Dレコード（128バイト）の関連フィールド:

| 位置 | 長さ | フィールド | ケース行の場合 | バラ行の場合 |
|------|------|-----------|--------------|------------|
| 89-94 | 6 | 仕入入数 | capacity_case（例: 24） | capacity_case（例: 24）※変更しない |
| 95-101 | 7 | ケース数 | 注文ケース数（例: 3） | 0 |
| 102-108 | 7 | バラ数量 | 0 | 注文バラ数（例: 5） |
| 109-118 | 10 | 原単価 | ケース単価 | バラ単価 |

**現在の `HanaOrderJXFileGenerator.php` の実装** (行399-405):
- `capacity_case <= 1` → バラ販売商品 → ケース数=0, バラ数量=totalQty
- `capacity_case > 1` → ケース販売商品 → ケース数=totalQty/capacity_case, バラ数量=0

この既存ロジックは `quantity_type` ではなく `capacity_case` で判定している。変更後は `quantity_type` を直接参照する方式に統一:
- `quantity_type=CASE` → ケース数=order_quantity, バラ数量=0, 仕入入数=capacity_case, 単価=ケース単価
- `quantity_type=PIECE` → ケース数=0, バラ数量=order_quantity, 仕入入数=1, 単価=バラ単価

## 現状の実装

### DB設計（対応済み箇所）

`wms_order_candidates` テーブル:
- `quantity_type` ENUM('PIECE', 'CASE', 'CARTON') — **存在するが常に PIECE で固定**
- `order_quantity` — バラ数で格納
- `purchase_unit_price` — 仕入先バラ単価

`wms_order_incoming_schedules` テーブル:
- `quantity_type` — 候補から継承（現在は常に PIECE）
- `unit_price` / `case_price` — バラ/ケース単価の両方を保持
- `price_type` — CASE or PIECE（**現在は未設定のためNULL**）

### 単価テーブル（既に対応済み）

`item_partner_prices`:
- `unit_price` (バラ単価) / `case_price` (ケース単価) — **両方存在**
- `tax_exempt_unit_price` / `tax_exempt_case_price` — 非課税分も対応

`item_prices`:
- `purchase_unit_price` / `purchase_case_price` — 仕入単価
- `tax_exempt_unit_price` / `tax_exempt_case_price` — 非課税分

### 現在のロジック

| 処理 | ファイル | 行 | 現状 |
|------|---------|-----|------|
| 自動発注候補生成（内部移動） | `OrderCandidateCalculationService.php` | 671 | `QuantityType::PIECE` 固定 |
| 自動発注候補生成（外部発注） | `OrderCandidateCalculationService.php` | 926 | `QuantityType::PIECE` 固定 |
| 仕入単価プリロード | `OrderCandidateCalculationService.php` | 372-408 | `unit_price` のみ取得 |
| 手動発注追加 | `ListWmsOrderCandidates.php` | 286 | `QuantityType::PIECE` 固定 |
| 入荷予定作成 | `OrderExecutionService.php` | 206,232 | 候補の `quantity_type` を継承 |
| CSV生成 | `OrderDataFileService.php` | 205-219 | 1商品1行、単価は `price_type` で分岐 |
| JX送信 | `OrderTransmissionService.php` | 168 | `quantity_type` をそのまま送信 |

## 変更内容

### 概要

1. **自動発注**: `quantity_type` を `PIECE` → `CASE` に変更、`order_quantity` をケース数で格納
2. **手動発注**: ケースとバラの入力で別レコードを生成（最大2行/商品）
3. **確定・ファイル生成**: `quantity_type` に応じた正しい単価・数量で出力

### 詳細設計

---

#### Phase 1: 自動発注のケース対応

**ファイル**: `app/Services/AutoOrder/OrderCandidateCalculationService.php`

**1-1. quantity_type を CASE に変更**

```
行671: QuantityType::PIECE → QuantityType::CASE
行926: QuantityType::PIECE → QuantityType::CASE
```

**1-2. order_quantity をケース数に変更**

現在: `order_quantity` = バラ数（例: 72バラ）
変更後: `order_quantity` = ケース数（例: 3ケース = 72バラ ÷ 入数24）

計算ロジック:
```
不足数 = 50バラ
purchase_unit = 24（最小仕入単位=ケース入数）
order_quantity_piece = ceil(50 / 24) × 24 = 72バラ
order_quantity_case = 72 / capacity_case = 3ケース
```

**1-3. purchase_unit_price をケース単価に変更**

```
行372-408: $supplierItemPrices のプリロードで case_price も取得
行903付近: purchase_unit_price にケース単価を設定
```

**影響**: 自動発注は常にケース単位。バラ端数は出ない（purchase_unit で切り上げ済み）。

---

#### Phase 2: 手動発注のケース/バラ別行対応

**ファイル**: `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`

**2-1. 1商品 → 最大2レコード生成**

現在（行220-322）: 1商品 → 1 WmsOrderCandidate

変更後:
```
ケース入力あり → WmsOrderCandidate (quantity_type=CASE, order_quantity=ケース数, purchase_unit_price=ケース単価)
バラ入力あり   → WmsOrderCandidate (quantity_type=PIECE, order_quantity=バラ数, purchase_unit_price=バラ単価)
```

**2-2. フォームUI変更**

**ファイル**: `resources/views/filament/components/order-candidate-create-items.blade.php`

現在: ケース欄とバラ欄は排他的（一方を入力すると他方がクリアされる）

変更: **両方同時に入力可能にする**
- ケース欄: `row.caseQty` — ケース数を入力
- バラ欄: `row.pieceQty` — バラ数を入力（端数分）
- `onCaseInput()` / `onPieceInput()` の排他制御を**削除**
- `orderQty()` の計算を変更: ケースとバラを独立して扱う

**2-3. syncToWire() の変更**

```javascript
// 変更前: 1商品1エントリ
items = rows.map(r => ({ item_id, order_quantity: orderQty(r), ... }))

// 変更後: ケースとバラで別エントリ
items = rows.flatMap(r => {
    const entries = [];
    if (r.caseQty > 0) entries.push({
        item_id, quantity_type: 'CASE',
        order_quantity: r.caseQty, // ケース数
    });
    if (r.pieceQty > 0) entries.push({
        item_id, quantity_type: 'PIECE',
        order_quantity: r.pieceQty, // バラ数
    });
    return entries;
});
```

**2-4. PHP側の候補作成（行220-322）**

```php
// 変更前
WmsOrderCandidate::create([
    'quantity_type' => QuantityType::PIECE,
    'order_quantity' => $orderQuantity,
    'purchase_unit_price' => $unitPrice,
]);

// 変更後: itemData から quantity_type を参照
WmsOrderCandidate::create([
    'quantity_type' => QuantityType::from($itemData['quantity_type']),
    'order_quantity' => $orderQuantity,
    'purchase_unit_price' => $itemData['quantity_type'] === 'CASE'
        ? $prices['case_price']
        : $prices['unit_price'],
]);
```

---

#### Phase 3: 確定・入荷予定の対応

**ファイル**: `app/Services/AutoOrder/OrderExecutionService.php`

**3-1. createIncomingSchedulesFromCandidate() (行151-245)**

- `quantity_type` は候補から継承（既に対応済み: 行206, 232）
- `price_type` を `quantity_type` に基づいて設定:

```php
'price_type' => $candidate->quantity_type === QuantityType::CASE ? 'CASE' : 'PIECE',
```

- `expected_quantity` はそのまま候補の `order_quantity` を使用
  - CASE候補: ケース数が入る
  - PIECE候補: バラ数が入る

**注意**: `expected_quantity` がケース数になると、入荷APIの数量計算に影響する（後述Phase 6）。

---

#### Phase 4: CSV/JXファイル生成の対応

**4-1. CSV生成**

**ファイル**: `app/Services/AutoOrder/OrderDataFileService.php`

`buildCsvContent()` (行155-255): `quantity_type` による分岐あり（行205-219）→ **追加変更不要**
ただし確認: ケース行の単価に `case_price` が正しく使われるよう `price_type` の設定が必要

**4-2. JX送信データ**

**ファイル**: `app/Services/AutoOrder/OrderTransmissionService.php`

`quantity_type` をそのまま送信（行168）→ **追加変更不要**

**4-3. JXファイル生成（Dレコード）** ★変更必須

**ファイル**: `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php`

現在 (行399-405): `capacity_case` の値でケース/バラを判定
```php
// 現在: capacity_caseベースの判定
if ($capacityCase <= 1) {
    $caseQty = 0;
    $pieceQty = $totalQty;  // バラ販売
} else {
    $caseQty = intdiv($totalQty, $capacityCase);
    $pieceQty = 0;  // ケース販売
}
```

変更後: `quantity_type` ベースの判定
```php
// 変更後: quantity_typeで明示的に判定
if ($candidate->quantity_type === QuantityType::CASE) {
    $caseQty = $totalQty;  // order_quantityがケース数
    $pieceQty = 0;
} else {
    $caseQty = 0;
    $pieceQty = $totalQty;  // order_quantityがバラ数
}
// 仕入入数はケース/バラ問わず常にcapacity_caseを使用（変更しない）
```

**仕入入数**: ケース/バラ問わず常に `capacity_case` をそのまま使用。変更しない。

**4-4. 原単価の取得** (行718-750)

現在: `capacity_case` で単価を切替
```php
if ($capacityCase <= 1) {
    return (float) ($price->cost_unit_price ?? 0);  // バラ単価
```

変更後: `quantity_type` で単価を切替
```php
if ($candidate->quantity_type === QuantityType::PIECE) {
    return (float) ($price->cost_unit_price ?? 0);  // バラ単価
} else {
    return (float) ($price->cost_case_price ?? 0);  // ケース単価
}
```

---

#### Phase 5: テーブル表示の対応

**ファイル**: `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php`

**5-1. quantity_type カラムの表示追加**

テーブルに `quantity_type` カラムを追加して「ケース」「バラ」を明示表示

**5-2. order_quantity の単位表示**

`order_quantity` のsuffixに `quantity_type` のラベルを表示:
```php
TextInputColumn::make('order_quantity')
    ->suffix(fn ($record) => $record->quantity_type?->name() ?? 'バラ')
```

**ファイル**: `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php`

**5-3. 入荷予定テーブルのケース/バラ表示**

- 行116-144: ケース・バラ計算カラム — `quantity_type` が CASE の場合は `expected_quantity` がそのままケース数

---

#### Phase 6: 入荷API対応（Android端末）

**ファイル**: `app/Http/Controllers/Api/IncomingController.php`

入荷予定の `expected_quantity` がケース数になると、APIの数量計算に影響する。

**6-1. GET /api/incoming/schedules — 入庫予定一覧 (行142-187, 950-1046)**

現状:
- ✅ `quantity_type`: 返却済み (行1026)
- ✅ `capacity_case`: 返却済み (行969)
- 数量フィールド (`expected_quantity`, `received_quantity`, `remaining_quantity`): quantity_typeの単位で格納

変更: Android側でquantity_typeに応じた表示が必要。API側は追加変更不要。

**6-2. GET /api/incoming/schedules/{id} — 入庫予定詳細 (行251-261, 1051-1082)**

現状:
- ✅ `quantity_type`: 返却済み (行1075)
- ❌ `capacity_case`: **未返却**

変更: `capacity_case` フィールドを追加返却。Android側でケース⇔バラ換算に必要。

**6-3. POST /api/incoming/work-items — 入荷作業開始 (行440-526)**

現状 (行488-493):
```php
$defaultWorkQuantity = ($schedule->shipped_quantity !== null && $schedule->shipped_quantity > 0)
    ? max(0, $schedule->shipped_quantity - $schedule->received_quantity)
    : $schedule->remaining_quantity;
```
- `quantity_type` 未考慮。CASE行のexpected_quantityがケース数なら、defaultWorkQuantityもケース数になる（入荷予定のquantity_typeの単位で統一されていれば問題なし）。

変更: `quantity_type` に応じた単位の統一確認。同じ単位体系であればロジック変更不要。

**6-4. POST /api/incoming/work-items/{id}/complete — 入荷完了 (行716-790)**

現状 (行735-766):
```php
$workQuantity = $workItem->work_quantity;
$remainingQty = $schedule->remaining_quantity;
$totalReceived = $schedule->received_quantity + $workQuantity;
```
- `quantity_type` 未考慮だが、同じ単位体系（ケース行→ケース数同士、バラ行→バラ数同士）であれば計算は正しい。

変更: **work_quantity と schedule の単位が一致する前提であれば変更不要**。Android側で入力する数量の単位をscheduleのquantity_typeに合わせる。

**6-5. IncomingConfirmationService (行30-87, 100-162)**

確定時に `real_stocks` テーブルの在庫数を更新する。在庫はバラ数で管理されているため:
- CASE行: `work_quantity（ケース数） × capacity_case` でバラ数に変換して在庫更新
- PIECE行: `work_quantity（バラ数）` そのまま

**変更必須**: `confirmIncoming()` / `recordPartialIncoming()` で quantity_type に応じたバラ数変換ロジック追加。

**6-6. API変更まとめ**

| エンドポイント | 変更内容 | 優先度 |
|--------------|---------|--------|
| GET /api/incoming/schedules/{id} | `capacity_case` フィールド追加 | 高 |
| IncomingConfirmationService | ケース数→バラ数変換ロジック追加 | **最高** |
| formatWorkItem() | `capacity_case` をschedule内に追加 | 高 |
| Android側 | quantity_typeに応じた数量表示・入力UI | 高 |

### 影響範囲

| 機能 | 影響 | 詳細 |
|------|------|------|
| 自動発注候補生成 | **変更** | quantity_type=CASE、order_quantity=ケース数 |
| 手動発注追加 | **変更** | ケース/バラ別レコード生成 |
| 発注候補テーブル | **変更** | quantity_type表示追加 |
| 発注確定・入荷予定 | **変更** | price_type設定追加 |
| CSV生成 | **軽微** | price_type正しく設定されれば既存ロジックで対応 |
| JXファイル生成 | **変更** | Dレコードのケース数/バラ数/仕入入数/原単価をquantity_typeベースに変更 |
| JX送信 | **変更不要** | quantity_typeをそのまま送信 |
| 移動候補 | **変更不要** | 内部移動はケース単位の既存仕様を維持 |
| 入荷予定テーブル | **変更** | quantity_typeに応じた表示調整 |
| 発注ステータスWidget | **変更不要** | ジョブ管理レベルのため影響なし |
| 入荷API | **変更** | capacity_case返却追加、IncomingConfirmationServiceのバラ数変換 |
| Android端末 | **変更** | quantity_typeに応じた数量表示・入力UI |

## 制約

1. **FK禁止**: 全リレーションはアプリケーションレベルで管理
2. **migrate:fresh/refresh禁止**: 本番共有DB
3. **既存データ互換**: 既存の PIECE レコードは変更しない（新規生成分からケース対応）
4. **自動発注はケースのみ**: バラ端数が出ない前提（purchase_unitで切上げ済み）
5. **移動候補は対象外**: 内部移動のquantity_typeは変更しない

## 対象ファイル

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | quantity_type→CASE、order_quantity→ケース数、case_priceプリロード |
| `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` | 手動発注で2レコード生成、quantity_type参照 |
| `resources/views/filament/components/order-candidate-create-items.blade.php` | ケース/バラ同時入力対応、排他制御削除 |
| `app/Services/AutoOrder/OrderExecutionService.php` | price_type設定追加 |
| `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` | quantity_typeカラム追加、suffix表示 |
| `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` | quantity_typeに応じた表示調整 |
| `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` | Dレコード: capacity_case判定→quantity_type判定に変更、原単価取得も変更 |
| `app/Http/Controllers/Api/IncomingController.php` | formatScheduleDetail()にcapacity_case追加、formatWorkItem()にcapacity_case追加 |
| `app/Services/AutoOrder/IncomingConfirmationService.php` | confirmIncoming/recordPartialIncomingでケース数→バラ数変換 |

### 参照のみ

| ファイル | 参照理由 |
|---------|---------|
| `app/Services/AutoOrder/OrderDataFileService.php` | CSV生成 — price_type対応済みか確認 |
| `app/Services/AutoOrder/OrderTransmissionService.php` | JX送信 — quantity_type送信済み、変更不要 |
| `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator2.php` | Generator2も存在 — 同様の変更が必要か確認 |
| `app/Services/AutoOrder/PurchasePriceService.php` | 単価取得 — case_price取得済み |
| `app/Models/Sakemaru/ItemPartnerPrice.php` | ケース単価メソッド確認 |
| `app/Models/Sakemaru/Item.php` | capacity_case参照 |
| `app/Enums/QuantityType.php` | Enum定義確認 |

## 確認事項

1. **自動発注のorder_quantity変更影響**: 既存のバラ数ベースのロジック（在庫比較、不足数計算等）にケース数が混在しないか？ → `OrderCandidateCalculationService` 内の不足数計算は全てバラ数で行い、最後にケース数に変換する方針
2. **入荷予定のexpected_quantity**: CASE行のexpected_quantityはケース数になる。入荷確認画面でケース数入力とバラ数入力を正しく区別できるか？
3. **CSVフォーマット**: 取引先が受け取るCSVで、ケース行の数量欄がケース数になることの影響（現在バラ数前提のCSVフォーマットはないか？）
4. **batch_code**: 同じバッチ内にケース行とバラ行が混在する形で問題ないか？
5. **入荷API — 在庫更新**: `IncomingConfirmationService` で `real_stocks` にバラ数で在庫加算する際、CASE行は `work_quantity × capacity_case` でバラ変換が必要。この変換をAPI側（Controller）で行うか、Service側で行うか？
6. **Android端末**: 入荷作業画面でquantity_typeに応じた数量入力UIが必要。ケース行は「ケース数」入力、バラ行は「バラ数」入力。API側でcapacity_caseを返却すればAndroid側で換算表示も可能。
7. **JXファイル — バラ行の仕入入数**: 仕入入数はケース/バラ問わず `capacity_case` を使用（変更しない）。過去データではバラ行で入数=1の例があったが、当システムでは統一する。
8. **HanaOrderJXFileGenerator2**: Generator2も同様の変更が必要か？対象問屋・フォーマット差異を確認。
