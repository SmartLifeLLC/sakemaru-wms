# 出荷API修正 — ロケーション表示が「冷凍」になる問題

- **作成日**: 2026-03-17
- **ステータス**: ドラフト
- **ディレクトリ**: `app/Http/Controllers/Api/`

## 背景・目的

Android出荷ピッキング画面でロケーションが「冷凍」と表示される。
原因はAPI側が `picking_area.name`（ピッキングエリア名 = 温度帯名）を返しており、Android側はこの値をロケーション表示に使用しているため。
本来はロケーション番号（例: `R01 A 5`）が表示されるべき。

## 現状の実装

### APIレスポンス構造（`PickingTaskController`）

`GET /api/picking/tasks` および `GET /api/picking/tasks/{id}` は以下の構造を返す:

```json
{
  "course": { "code": "910072", "name": "佐藤　尚紀" },
  "picking_area": { "code": "B", "name": "エリアB（バラ）" },
  "wave": { ... },
  "picking_list": [
    {
      "wms_picking_item_result_id": 1,
      "item_id": 111110,
      "item_name": "×白鶴特撰...",
      "planned_qty": 2,
      "picked_qty": 0,
      "status": "PENDING"
    }
  ]
}
```

**問題点**: `picking_list` の各アイテムにロケーション情報が含まれていない。

### データの流れ

1. `WmsPickingItemResult` は `location_id` カラムを持つ（`locations` テーブルへの参照）
2. `Location` モデルは `code1`, `code2`, `code3`, `name` フィールドを持つ
3. `WmsPickingItemResult::getLocationDisplayAttribute()` がロケーション表示用メソッド（`"{code1} {code2} {code3} - {name}"`）
4. しかし `formatItemResult()` メソッド（API用）はロケーション情報を返していない

### Android側の挙動

- `PickingAreaInfo` は `code` と `name` の2フィールド
- 画面では `originalTask.pickingAreaName` を表示（`OutboundPickingScreen.kt:461`）
- Android側のマッピングは正しく動作しており、APIが返す値をそのまま表示

## 変更内容

### 概要

`formatItemResult()` にロケーション情報を追加し、各ピッキングアイテムに正しいロケーション番号を返す。

### 詳細設計

#### API変更: `PickingTaskController::formatItemResult()`

**ファイル**: `app/Http/Controllers/Api/PickingTaskController.php` (Line 21-93)

`formatItemResult()` の返却配列に `location` フィールドを追加:

```php
// 追加するコード（formatItemResult内）
$location = $itemResult->location;
$locationCode = $location
    ? trim("{$location->code1} {$location->code2} {$location->code3}")
    : null;
$locationName = $location->name ?? null;

return [
    // ... 既存フィールド ...
    'location' => [
        'code' => $locationCode,     // "R01 A 5" のようなロケーション番号
        'name' => $locationName,     // ロケーション名（あれば）
    ],
    // ... 既存フィールド ...
];
```

#### リレーションの事前読み込み

`index()` と `show()` メソッドの `with()` に `pickingItemResults.location` を追加:

```php
// Line 244-249 (index) と Line 361-367 (show)
$query = WmsPickingTask::with([
    'pickingArea',
    'deliveryCourse',
    'pickingItemResults.item.item_search_information',
    'pickingItemResults.earning',
    'pickingItemResults.stockTransfer.to_warehouse',
    'pickingItemResults.location',  // ← 追加
])
```

#### APIレスポンス（変更後）

```json
{
  "picking_list": [
    {
      "wms_picking_item_result_id": 1,
      "item_id": 111110,
      "item_name": "×白鶴特撰...",
      "location": {
        "code": "R01 A 5",
        "name": "常温棚A-5"
      },
      "planned_qty": 2,
      "picked_qty": 0,
      "status": "PENDING"
    }
  ]
}
```

### 影響範囲

| 機能 | 影響 |
|------|------|
| Android出荷ピッキング画面 | ロケーション表示に `picking_list[].location.code` を使用可能に |
| `GET /api/picking/tasks` | レスポンスに `location` フィールド追加（後方互換） |
| `GET /api/picking/tasks/{id}` | 同上 |
| `GET /api/picking/items/{id}` | `showItem()` メソッドも同様の修正が必要か確認 |

**注意**: この変更は後方互換。既存フィールドは変更しない。新フィールド `location` の追加のみ。

## 制約

- FK禁止: `location_id` はアプリケーションレベルの参照（FK制約なし）
- `migrate:fresh` / `migrate:refresh` 禁止
- `locations` テーブルは基幹システム（sakemaru）との共有テーブル

## 対象ファイル

### 既存変更
- `app/Http/Controllers/Api/PickingTaskController.php` — `formatItemResult()`, `index()`, `show()` の修正

### 参照のみ
- `app/Models/WmsPickingItemResult.php` — `location()` リレーション、`getLocationDisplayAttribute()`
- `app/Models/Sakemaru/Location.php` — `code1`, `code2`, `code3`, `name` フィールド
- `app/Models/WmsPickingArea.php` — 現状の `code`, `name` フィールド確認
- `tests/Feature/Api/PickingApiTest.php` — テスト更新の参考

## 確認事項

1. **Android側の対応**: `location` フィールドをどのように表示するか？`pickingAreaName` の代わりに `location.code` を使う想定か、それとも別のUI箇所に表示するか？
2. **`showItem()` メソッド**: `GET /api/picking/items/{id}`（Line 457-）はDBクエリで直接取得しているため、locationの結合が必要。同時に修正するか？
3. **ロケーション未設定時**: `location_id` が NULL のアイテムは `location: null` を返す。Android側で「未設定」表示のハンドリングが必要。
4. **picking_area レスポンスの変更要否**: 現状の `picking_area.name`（エリア名）はそのまま残し、アイテム単位で `location` を追加する方針でよいか？それとも `picking_area` 自体の値も変更が必要か？
