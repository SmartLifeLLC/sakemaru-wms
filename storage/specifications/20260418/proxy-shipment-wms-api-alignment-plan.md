# 横持ち出荷 API Envelope 統一 作業計画

## 前提

- 横持ち出荷API 5エンドポイントは実装済み・動作確認済み
- 現在のレスポンスは `response()->json()` で直接返しており、共通 `ApiController` のヘルパーを使っていない
- Android側は共通 `ApiEnvelope<T>` を前提としており、`result.data` に payload を閉じる必要がある
- `ApiController` に `success()` / `error()` / `notFound()` / `validationError()` ヘルパーが既にある

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Controller を ApiController 継承に変更 | `extends Controller` → `extends ApiController`、全メソッドで `$this->success()` / `$this->error()` を使用 | 全5エンドポイントが共通Envelope形式で返る |
| P2 | QueryService の戻り値構造変更 | `listForWarehouse()` の戻り値を `{ items, summary, meta }` 構造に変更 | Controller で `$this->success($result)` するだけで正しい構造になる |
| P3 | Handy V2 フロント修正 | Store のレスポンス読み取りパスを変更 | ブラウザで一覧→ピッキング→完了が動作する |
| P4 | API仕様書更新 | `proxy-shipment-api-specification.md` を Envelope 統一後の形に更新 | Android 開発者が仕様書通りに実装可能 |
| P5 | ビルド・テスト | `npm run build` + `php artisan test --filter=ProxyShipmentApiTest` | ビルド成功 + テストエラーなし |

---

## P1: Controller を ApiController 継承に変更

### 目的

`ProxyShipmentController` を `ApiController` 継承に変更し、全レスポンスを共通Envelope形式に統一する。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Http/Controllers/Api/ProxyShipmentController.php` | 継承変更 + 全メソッドのレスポンス修正 |

### 手順

1. `extends Controller` → `extends ApiController` に変更
2. `use App\Http\Controllers\Controller;` の import を削除

3. 各メソッドのレスポンスを変更:

**index（一覧）:**
```php
// Before
return response()->json(['is_success' => true, 'code' => 'SUCCESS', 'result' => $result]);

// After
return $this->success($result, '横持ち出荷一覧を取得しました');
```

**show（詳細）:**
```php
// Before
return response()->json(['is_success' => true, 'code' => 'SUCCESS', 'result' => ['data' => ...]]);

// After
return $this->success($detail, '横持ち出荷詳細を取得しました');
```

**start / update:**
```php
// Before
return response()->json(['is_success' => true, 'code' => 'SUCCESS', 'result' => ['data' => ..., 'message' => ...]]);

// After
return $this->success($data, 'メッセージ');
```

**complete:**
```php
// Before: result.stock_transfer_queue_id を result 直下に置いていた
// After: stock_transfer_queue_id を data の中に含める
$data = $this->queryService->formatAllocation($result['allocation']);
$data['stock_transfer_queue_id'] = $result['stock_transfer_queue_id'];
return $this->success($data, '横持ち出荷を完了しました');
```

4. エラーレスポンスを `abort()` から `$this->error()` / `$this->notFound()` / `$this->validationError()` に変更:

**findAndValidateAllocation:**
```php
// Before
abort(404, '横持ち出荷が見つかりません');
abort(422, '指定された倉庫と一致しません');

// After
// abort() は例外を投げるため、メソッドの戻り値型と合わない。
// Controller 内で直接 return するパターンに変更する必要がある。
// → findAndValidateAllocation の戻り値を WmsShortageAllocation|JsonResponse にするか、
//   例外ハンドラで統一形式に変換するか検討。
//
// 最もシンプルな方法: abort() はそのまま残し、
// App\Exceptions\Handler でJSON形式に統一する。
// ただし prompt の要件は「error_message 形式で返す」なので、
// Controller 内で throw せず return $this->error() するのが最も確実。
```

→ `findAndValidateAllocation` を廃止し、各メソッド内でバリデーション + early return する方式に変更。
  または、`findAndValidateAllocation` 内で例外を投げ、Controller で catch して `$this->error()` を返す方式にする。

**推奨方式**: 専用例外 + try-catch パターン

```php
// Controller のアクションメソッド
public function start(Request $request, int $id): JsonResponse
{
    // ...
    try {
        $allocation = $this->findAndValidateAllocation($id, $validated['warehouse_id']);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return $this->notFound('横持ち出荷が見つかりません');
    } catch (\InvalidArgumentException $e) {
        return $this->validationError([], $e->getMessage());
    }
    // ...
}
```

`findAndValidateAllocation` 内では `abort()` の代わりに例外を throw:
```php
throw new \Illuminate\Database\Eloquent\ModelNotFoundException('横持ち出荷が見つかりません');
throw new \InvalidArgumentException('指定された倉庫と一致しません');
```

5. complete の 422 エラーも同様に `$this->validationError()` に変更

### 完了条件

- 全5エンドポイントが `{ is_success, code, result: { data, message, debug_message } }` 形式で返る
- エラーが `{ is_success: false, code, result: { data: null, error_message, errors, debug_message } }` 形式で返る
- `php -l` で構文エラーなし

---

## P2: QueryService の戻り値構造変更

### 目的

`listForWarehouse()` の戻り値を `{ items, summary, meta }` 構造にし、Controller で `$this->success($result)` するだけで正しいEnvelopeになるようにする。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/Shortage/ProxyShipmentQueryService.php` | `listForWarehouse()` の戻り値構造変更 |

### 手順

1. `listForWarehouse()` の return を変更:

```php
// Before
return [
    'data' => $data,
    'summary' => [...],
    'meta' => [...],
];

// After
return [
    'items' => $data,
    'summary' => [...],
    'meta' => [...],
];
```

Controller 側では `$this->success($result)` で返すと:
```json
{
  "result": {
    "data": {
      "items": [...],
      "summary": {...},
      "meta": {...}
    },
    "message": "...",
    "debug_message": null
  }
}
```

### 完了条件

- `listForWarehouse()` の戻り値キーが `items` / `summary` / `meta`
- Controller で `$this->success($result)` するだけで正しい構造になる

---

## P3: Handy V2 フロント修正

### 目的

レスポンス構造変更に合わせて、Store のデータ読み取りパスを修正する。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `resources/js/handy-v2/stores/proxy-shipment.js` | レスポンスパス変更 |

### 手順

1. `loadAllocations()` のレスポンス読み取りを変更:

```javascript
// Before
if (response.is_success && response.result) {
    this.allocations = response.result.data || [];
    this.summary = response.result.summary || null;
    if (response.result.meta?.business_date && !this.shipmentDateFilter) {

// After
if (response.is_success && response.result?.data) {
    this.allocations = response.result.data.items || [];
    this.summary = response.result.data.summary || null;
    if (response.result.data.meta?.business_date && !this.shipmentDateFilter) {
        this.shipmentDateFilter = response.result.data.meta.business_date;
        this.businessDate = response.result.data.meta.business_date;
```

2. `completeAllocation()` のレスポンス読み取りを変更:

```javascript
// Before
this.lastResult = {
    allocation: response.result.data,
    stockTransferQueueId: response.result.stock_transfer_queue_id,

// After
this.lastResult = {
    allocation: response.result.data,
    stockTransferQueueId: response.result.data.stock_transfer_queue_id,
```

3. 他のメソッド（`loadDetail`, `startAllocation`, `updateAllocation`）はレスポンス構造が `result.data` のままなので変更不要。

### 完了条件

- ブラウザで一覧画面が表示される
- フィルタが動作する
- ピッキング→完了フローが動作する

---

## P4: API仕様書更新

### 目的

`proxy-shipment-api-specification.md` を Envelope 統一後の形に更新する。

### 修正対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `storage/specifications/20260418/proxy-shipment-api-specification.md` | Envelope統一後のレスポンス例に更新 |

### 手順

1. 共通レスポンス形式に `debug_message` を追加
2. 一覧レスポンス例: `result.data[]` → `result.data.items[]`、`summary`/`meta` を `result.data` 内に移動
3. 完了レスポンス例: `result.stock_transfer_queue_id` → `result.data.stock_transfer_queue_id`
4. エラー例: `result.message` → `result.error_message`、`result.errors` 追加
5. `debug_message` の説明追加

### 完了条件

- レスポンス例が全て共通Envelope形式に統一されている
- Android開発者がこの仕様書通りに実装すれば共通 `ApiEnvelope<T>` で読める

---

## P5: ビルド・テスト

### 目的

フロント・バックエンドの整合性を確認する。

### 手順

1. `php -l` で全変更ファイルの構文チェック
2. `npm run build` で Vite ビルド
3. `php artisan test --filter=ProxyShipmentApiTest` でテスト実行
4. テストのレスポンスアサーションを Envelope 形式に合わせて修正（必要な場合）

### 完了条件

- `npm run build` 成功
- テストエラーなし（Skipped は許容）
- `php -l` エラーなし

---

## 制約（厳守）

1. **migrate:fresh / migrate:refresh 絶対禁止** — 共有DB
2. **payload の中身は変えない** — JSON の包み方を揃えるだけ
3. **PickingTaskController を変更しない** — 既存出荷APIは変更しない
4. **ApiController を変更しない** — 共通ヘルパーはそのまま使う

## 全体完了条件

1. 横持ち出荷 5 API が既存共通 Envelope に準拠している
2. 一覧 API の `summary` / `meta` が `result.data` 配下に入っている
3. 完了 API の `stock_transfer_queue_id` が `result.data` 配下に入っている
4. 422 / 404 / 500 のエラーが `error_message` 形式で返る
5. Handy V2 フロントが正常動作する
6. API仕様書が更新されている
