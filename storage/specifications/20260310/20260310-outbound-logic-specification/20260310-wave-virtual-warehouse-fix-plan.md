# 出荷波動生成の仮想倉庫対応修正 作業計画

## 前提

- 出荷波動生成（admin/waves）で実倉庫を選択しても、仮想倉庫に紐づく earnings が対象外になるバグが存在
- 原因: `where('warehouse_id', $warehouseId)` の完全一致フィルタ
- 解決策: `WarehouseResolver::resolveAllWarehouseIds()` で同一実倉庫の全IDを取得し `whereIn` に変更
- 確認済み回答:
  - 倉庫セレクトは**実倉庫のみ**表示
  - 在庫引当の warehouse_id は配送コース倉庫で**正しい**
  - ピッキングタスクの warehouse_id は配送コース倉庫で**正しい**

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | WarehouseResolver メソッド追加 | `resolveAllWarehouseIds()` を追加 | メソッドが存在し、Pint通過 |
| P1 | ListWaves.php 修正 | 6箇所のクエリ修正 + 倉庫セレクト実倉庫のみ | 7箇所の修正完了、Pint通過 |
| P2 | GenerateWavesCommand.php 修正 | 1箇所のクエリ修正 | 修正完了、Pint通過 |
| P3 | 検証・Pint | コードフォーマット確認・全体テスト | `./vendor/bin/pint` 通過、`composer test` 通過 |

---

## P0: WarehouseResolver メソッド追加

### 目的

仮想倉庫を含む全関連倉庫IDを取得するユーティリティメソッドを追加する。

### 修正対象ファイル

- `app/Services/WarehouseResolver.php`

### 修正内容

以下のメソッドを追加:

```php
/**
 * 指定倉庫と同一実倉庫に属する全倉庫IDを取得
 *
 * 例: resolveAllWarehouseIds(91) → [91, 92, 93]
 *     (91=実倉庫, 92,93=91をstock_warehouse_idとして持つ仮想倉庫)
 */
public static function resolveAllWarehouseIds(int $warehouseId): array
{
    $realId = self::resolveRealWarehouseId($warehouseId);

    return DB::connection('sakemaru')
        ->table('warehouses')
        ->where(function ($q) use ($realId) {
            $q->where('id', $realId)
              ->orWhere('stock_warehouse_id', $realId);
        })
        ->pluck('id')
        ->toArray();
}
```

### 完了条件

- メソッドが追加されている
- `./vendor/bin/pint app/Services/WarehouseResolver.php` が通過

---

## P1: ListWaves.php 修正

### 目的

手動波動生成の全クエリで仮想倉庫の伝票も対象に含まれるようにする。倉庫セレクトを実倉庫のみに制限する。

### 修正対象ファイル

- `app/Filament/Resources/Waves/Pages/ListWaves.php`

### 修正内容

#### 1. use 文追加

```php
use App\Services\WarehouseResolver;
```

#### 2. 倉庫セレクトを実倉庫のみに変更（L47）

```php
// 変更前
->options(Warehouse::query()->pluck('name', 'id'))

// 変更後
->options(Warehouse::query()->where('is_virtual', false)->pluck('name', 'id'))
```

#### 3. 配送コース選択肢の earnings クエリ（L60-79 付近）

`$warehouseId` から `$warehouseIds` を生成し、`whereIn` に変更:

```php
// クロージャ冒頭で追加
$warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);

// L72 変更
->whereIn('earnings.warehouse_id', $warehouseIds)
```

#### 4. 配送コース選択肢の stock_transfers クエリ（L87）

```php
->whereIn('st.from_warehouse_id', $warehouseIds)
```

#### 5. プレビュー earnings クエリ（L148）

```php
$warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);
// ...
->whereIn('earnings.warehouse_id', $warehouseIds)
```

#### 6. プレビュー stock_transfers クエリ（L166）

```php
->whereIn('st.from_warehouse_id', $warehouseIds)
```

#### 7. generateManualWave() の earnings 取得（L278）

```php
$warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);
// ...
->whereIn('warehouse_id', $warehouseIds)
```

#### 8. getEligibleStockTransfersQuery() の修正（L856-880）

メソッドシグネチャを変更するか、メソッド内部で `resolveAllWarehouseIds` を呼ぶ:

```php
// 変更前
->where('st.from_warehouse_id', $warehouseId)

// 変更後
$warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);
// ...
->whereIn('st.from_warehouse_id', $warehouseIds)
```

### 完了条件

- 7箇所すべての修正が完了（6クエリ + 1倉庫セレクト）
- `./vendor/bin/pint app/Filament/Resources/Waves/Pages/ListWaves.php` が通過

---

## P2: GenerateWavesCommand.php 修正

### 目的

自動波動生成の stock_transfers クエリで仮想倉庫も対象に含まれるようにする。

### 修正対象ファイル

- `app/Console/Commands/GenerateWavesCommand.php`

### 修正内容

#### getEligibleStockTransfersQuery() の修正（L716）

```php
// 変更前
->where('st.from_warehouse_id', $warehouseId)

// 変更後
$warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);
// ...
->whereIn('st.from_warehouse_id', $warehouseIds)
```

**注意:** `use App\Services\WarehouseResolver;` が既に import 済みか確認（L11 に存在）。

### 完了条件

- 修正が完了
- `./vendor/bin/pint app/Console/Commands/GenerateWavesCommand.php` が通過

---

## P3: 検証・Pint

### 目的

全体のコードフォーマットとテスト通過を確認する。

### 手順

1. `./vendor/bin/pint` を実行（全体）
2. `composer test` を実行
3. 修正した3ファイルの差分を確認（`git diff`）

### 完了条件

- Pint がエラーなく通過
- テストが通過（既存のテスト失敗は許容、新たな失敗がないこと）
- 全修正箇所が意図通りであることを確認

---

## 制約（厳守）

1. **migrate:fresh/refresh/reset 絶対禁止** — 本番データが削除される
2. **FK禁止** — アプリレベルでリレーション管理
3. **在庫引当ロジック変更禁止** — `StockAllocationService` は変更しない
4. **ピッキングタスク warehouse_id 変更禁止** — 配送コース倉庫のIDのまま

## 全体完了条件

1. `WarehouseResolver::resolveAllWarehouseIds()` が追加されている
2. ListWaves.php の7箇所（6クエリ + 1セレクト）が修正されている
3. GenerateWavesCommand.php の1箇所が修正されている
4. Pint 通過
5. テスト通過
