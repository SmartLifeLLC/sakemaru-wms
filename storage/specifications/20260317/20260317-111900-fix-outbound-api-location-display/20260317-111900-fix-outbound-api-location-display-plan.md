# 出荷API ロケーション表示修正 作業計画

## 前提

- Android出荷ピッキング画面で `picking_area.name`（エリア名/温度帯名）がロケーション番号として表示されている
- 各 `WmsPickingItemResult` は `location_id` を持ち、`locations` テーブルの `code1/code2/code3` でロケーション番号を構成できる
- API変更は後方互換（フィールド追加のみ）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | formatItemResult にロケーション追加 | `index()`, `show()` のレスポンスに location 情報を追加 | APIレスポンスに `location.code` と `location.name` が含まれる |
| P2 | showItem にロケーション追加 | `GET /api/picking/items/{id}` にも location を追加 | showItem のレスポンスにも location が含まれる |
| P3 | 動作確認 | API呼び出しでレスポンスを確認 | テストが通る or cURL でレスポンス確認 |

---

## P1: formatItemResult にロケーション追加

### 目的

`GET /api/picking/tasks` と `GET /api/picking/tasks/{id}` の `picking_list` 内の各アイテムにロケーション情報を含める。

### 修正対象ファイル

- `app/Http/Controllers/Api/PickingTaskController.php`

### 修正内容

#### 1. Eager Loading 追加（2箇所）

**index() メソッド（Line 244付近）**:
```php
$query = WmsPickingTask::with([
    'pickingArea',
    'deliveryCourse',
    'pickingItemResults.item.item_search_information',
    'pickingItemResults.earning',
    'pickingItemResults.stockTransfer.to_warehouse',
    'pickingItemResults.location',  // ← 追加
])
```

**show() メソッド（Line 361付近）**:
```php
$task = WmsPickingTask::with([
    'pickingArea',
    'deliveryCourse',
    'pickingItemResults.item.item_search_information',
    'pickingItemResults.earning',
    'pickingItemResults.stockTransfer.to_warehouse',
    'pickingItemResults.location',  // ← 追加
])->find($id);
```

#### 2. formatItemResult() にロケーション情報追加（Line 21-93）

`return` 配列の `slip_number` の後に追加:

```php
$location = $itemResult->location;
$locationCode = $location
    ? trim("{$location->code1} {$location->code2} {$location->code3}")
    : null;

return [
    // ... 既存フィールド（変更なし） ...
    'slip_number' => ...,
    'location' => $location ? [
        'code' => $locationCode,
        'name' => $location->name ?? null,
    ] : null,
];
```

### 完了条件

- `formatItemResult()` が `location` フィールドを返す
- `index()` と `show()` で `pickingItemResults.location` がeager loadされている

---

## P2: showItem にロケーション追加

### 目的

`GET /api/picking/items/{id}` のレスポンスにもロケーション情報を含める。

### 修正対象ファイル

- `app/Http/Controllers/Api/PickingTaskController.php` — `showItem()` メソッド（Line 457付近）

### 修正内容

`showItem()` は DBクエリ（`DB::connection('sakemaru')->table()`）を直接使用しているため、`locations` テーブルとの LEFT JOIN を追加。

```php
// location 取得を追加
$location = null;
if ($itemResult->location_id) {
    $location = DB::connection('sakemaru')
        ->table('locations')
        ->where('id', $itemResult->location_id)
        ->first(['code1', 'code2', 'code3', 'name']);
}

$locationCode = $location
    ? trim("{$location->code1} {$location->code2} {$location->code3}")
    : null;
```

レスポンス配列に追加:
```php
'location' => $location ? [
    'code' => $locationCode,
    'name' => $location->name ?? null,
] : null,
```

### 完了条件

- `showItem()` のレスポンスに `location` フィールドが含まれる

---

## P3: 動作確認

### 目的

修正後のAPIレスポンスが正しいことを確認。

### 手順

1. 既存テストの実行:
   ```bash
   php artisan test --filter=PickingApiTest
   ```

2. テストが存在しない場合、cURL で確認（開発サーバー起動中の場合）:
   ```bash
   curl -s http://localhost:8000/api/picking/tasks?warehouse_id=1 | jq '.result.data[0].picking_list[0].location'
   ```

3. レスポンスに `location.code` が含まれることを確認

### 完了条件

- テスト通過 or レスポンス確認で `location` フィールドが正しく返される
- `location_id` が NULL のアイテムでは `location: null` が返される

---

## 制約（厳守）

- `locations` テーブルのスキーマは変更禁止（共有テーブル）
- 既存のAPIフィールドを変更・削除しない（後方互換）
- FK制約の追加禁止
- `migrate:fresh` / `migrate:refresh` 禁止
- `picking_area` レスポンスは現状維持（エリア名はそのまま返す）

## 全体完了条件

- `GET /api/picking/tasks` のレスポンスに `picking_list[].location` が含まれる
- `GET /api/picking/tasks/{id}` のレスポンスに同上
- `GET /api/picking/items/{id}` のレスポンスに `location` が含まれる
- 既存フィールドに変更なし
