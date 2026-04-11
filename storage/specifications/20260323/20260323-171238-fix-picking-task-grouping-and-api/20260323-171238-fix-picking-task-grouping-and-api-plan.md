# ピッキングタスク生成グルーピング修正 & API パラメータ整理 作業計画

## 前提

- 仕様書: `20260323-171238-fix-picking-task-grouping-and-api.md`
- 本セッションで `PickingTaskController.php` に `started_at` / `completed_at` の返却を既に追加済み
- Androidアプリから `picking_area_id` が送られても無視する設計（バリデーションエラーにしない）
- 既存データの考慮は不要

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 波動生成 Earning グルーピング修正 | groupKey を `floor_id` → `floor_id_pickingAreaId` に変更 | コード変更完了 |
| P2 | 波動生成 Stock Transfer グルーピング修正 | groupKey + 既存タスク検索条件に `picking_area_id` を追加 | コード変更完了 |
| P3 | API picking_area_id パラメータ削除 | バリデーション・フィルタ・Swagger から削除 | コード変更完了 |
| P4 | 動作確認 | Pint実行 + テスト | Pint pass |

---

## P1: 波動生成 Earning グルーピング修正

### 目的

同じフロアに複数のピッキングエリアが存在する場合、現状は1つのタスクにまとめられてしまう。エリア別にタスクを分離する。

### 修正対象ファイル

- `app/Console/Commands/GenerateWavesCommand.php`

### 修正内容

**258行目付近**: グルーピングキーの変更

```php
// 変更前
$groupKey = ($floorId ?? 'null');

// 変更後
$groupKey = ($floorId ?? 'null') . '_' . ($pickingAreaId ?? 'null');
```

**256-257行目**: コメント更新

```php
// 変更前
// Group by floor_id only (no CASE/PIECE separation)
// All items on the same floor go into one picking task

// 変更後
// Group by floor_id × picking_area_id
// All items on the same floor and picking area go into one picking task
```

### 完了条件

- グルーピングキーが `floor_id × picking_area_id` に変更されていること
- コメントが更新されていること

---

## P2: 波動生成 Stock Transfer グルーピング修正

### 目的

Stock Transfer処理も同様に `floor_id × picking_area_id` でグルーピングする。また、既存タスク検索時の条件にも `picking_area_id` を追加する。

### 修正対象ファイル

- `app/Console/Commands/GenerateWavesCommand.php`

### 修正内容

**489行目付近**: グルーピングキーの変更

```php
// 変更前
$groupKey = 'ST_'.($floorId ?? 'null');

// 変更後
$groupKey = 'ST_'.($floorId ?? 'null') . '_' . ($pickingAreaId ?? 'null');
```

**490-494行目付近**: 既存タスク検索条件に `picking_area_id` を追加

```php
// 変更前
$existingTask = DB::connection('sakemaru')
    ->table('wms_picking_tasks')
    ->where('wave_id', $wave->id)
    ->where('floor_id', $floorId)
    ->first();

// 変更後
$existingTask = DB::connection('sakemaru')
    ->table('wms_picking_tasks')
    ->where('wave_id', $wave->id)
    ->where('floor_id', $floorId)
    ->where('wms_picking_area_id', $pickingAreaId)
    ->first();
```

### 完了条件

- グルーピングキーが `floor_id × picking_area_id` に変更されていること
- 既存タスク検索条件に `wms_picking_area_id` が含まれていること

---

## P3: API picking_area_id パラメータ削除

### 目的

`GET /api/picking/tasks` から `picking_area_id` パラメータを削除する。タスクがエリア別に分離されるため、`warehouse_id` + `picker_id` でのフィルタで十分。

Androidアプリから `picking_area_id` が送られても無視する（バリデーションエラーにしない）。

### 修正対象ファイル

- `app/Http/Controllers/Api/PickingTaskController.php`

### 修正内容

**バリデーション（262-266行目付近）**:

```php
// 変更前
$validated = $request->validate([
    'warehouse_id' => 'required|integer|exists:sakemaru.warehouses,id',
    'picker_id' => 'nullable|integer|exists:sakemaru.wms_pickers,id',
    'picking_area_id' => 'nullable|integer|exists:sakemaru.wms_picking_areas,id',
]);

// 変更後
$validated = $request->validate([
    'warehouse_id' => 'required|integer|exists:sakemaru.warehouses,id',
    'picker_id' => 'nullable|integer|exists:sakemaru.wms_pickers,id',
]);
```

**変数・フィルタ削除（270, 288-290行目付近）**:

削除対象:
```php
$pickingAreaId = $validated['picking_area_id'] ?? null;
// ...
if ($pickingAreaId) {
    $query->where('wms_picking_area_id', $pickingAreaId);
}
```

**Swagger（142-149行目付近）**: `picking_area_id` パラメータの `@OA\Parameter` ブロックを削除

**コメント（258行目付近）**: `picking_area_id` の記述を削除

### 完了条件

- バリデーションに `picking_area_id` が含まれていないこと
- クエリフィルタに `picking_area_id` が含まれていないこと
- Swaggerから `picking_area_id` パラメータが削除されていること
- Androidアプリから `picking_area_id` が送られてもバリデーションエラーにならないこと（存在しないパラメータは無視される）

---

## P4: 動作確認

### 手順

1. `./vendor/bin/pint` を実行してコードフォーマットを確認
2. `composer test` でテストが通ることを確認（関連テストがある場合）

### 完了条件

- Pint がエラーなく完了すること
- テストが通ること（関連テストが存在する場合）

---

## 制約（厳守）

- `php artisan migrate:fresh` / `migrate:refresh` は絶対禁止（共有DB）
- 外部キーは使用しない
- 計算ロジック（在庫引当・FEFO/FIFO等）は変更しない
- `picking_area_id` パラメータが送られてもバリデーションエラーにしない

## 全体完了条件

1. 波動生成で Earning / Stock Transfer ともに `floor_id × picking_area_id` でグルーピングされること
2. API `GET /api/picking/tasks` が `warehouse_id` + `picker_id` のみでフィルタすること
3. Pint / テストが pass すること
