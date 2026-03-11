# 出荷波動生成の仮想倉庫対応修正

- **作成日**: 2026-03-10
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/202503100000/20260310-outbound-logic-specification/`

## 背景・目的

admin/waves から出荷波動を生成しようとすると「対象伝票がない」と表示される。しかし earnings テーブルにはデータが存在する。

**原因:** 波動生成のクエリが `earnings.warehouse_id = $warehouseId` で完全一致フィルタしているため、仮想倉庫（例: warehouse_id=92, stock_warehouse_id=91）に紐づく earnings が、実倉庫91を選択した際に対象に含まれない。

**あるべき動作:** 実倉庫91を選択した場合、91番倉庫自体 + 91番倉庫を `stock_warehouse_id` として持つ全仮想倉庫の伝票が対象になるべき。

## 現状の実装（問題箇所）

### ListWaves.php（手動波動生成）

**6箇所** で `warehouse_id = $warehouseId` の完全一致フィルタが使われている:

| 行 | 箇所 | クエリ |
|----|------|--------|
| L72 | 配送コース選択肢 earnings | `->where('earnings.warehouse_id', $warehouseId)` |
| L87 | 配送コース選択肢 stock_transfers | `->where('st.from_warehouse_id', $warehouseId)` |
| L148 | プレビュー earnings | `->where('earnings.warehouse_id', $warehouseId)` |
| L166 | プレビュー stock_transfers | `->where('st.from_warehouse_id', $warehouseId)` |
| L278 | 生成処理 earnings | `->where('warehouse_id', $warehouseId)` |
| L865 | getEligibleStockTransfersQuery | `->where('st.from_warehouse_id', $warehouseId)` |

### GenerateWavesCommand.php（自動波動生成）

| 行 | 箇所 | 状態 |
|----|------|------|
| L84-88 | earnings カウント | `delivery_course_id` のみでフィルタ（warehouse_id なし）→ **問題なし** |
| L137-141 | earnings 取得 | `delivery_course_id` のみでフィルタ → **問題なし** |
| L716 | getEligibleStockTransfersQuery | `->where('st.from_warehouse_id', $warehouseId)` → **要修正** |

## 変更内容

### 概要

`WarehouseResolver` に「指定倉庫と同一実倉庫に属する全倉庫ID」を返すメソッドを追加し、波動生成の全クエリを `whereIn` に変更する。

### 詳細設計

#### 1. WarehouseResolver に新メソッド追加

```php
// app/Services/WarehouseResolver.php

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
            $q->where('id', $realId)                    // 実倉庫自身
              ->orWhere('stock_warehouse_id', $realId);  // 仮想倉庫
        })
        ->pluck('id')
        ->toArray();
}
```

#### 2. ListWaves.php の修正（6箇所）

**修正パターン:** `->where('earnings.warehouse_id', $warehouseId)` → `->whereIn('earnings.warehouse_id', $warehouseIds)`

```php
// 冒頭で倉庫IDリストを取得
$warehouseIds = WarehouseResolver::resolveAllWarehouseIds($warehouseId);
```

**修正箇所一覧:**

| 行 | 変更前 | 変更後 |
|----|--------|--------|
| L72 | `->where('earnings.warehouse_id', $warehouseId)` | `->whereIn('earnings.warehouse_id', $warehouseIds)` |
| L87 | `->where('st.from_warehouse_id', $warehouseId)` | `->whereIn('st.from_warehouse_id', $warehouseIds)` |
| L148 | `->where('earnings.warehouse_id', $warehouseId)` | `->whereIn('earnings.warehouse_id', $warehouseIds)` |
| L166 | `->where('st.from_warehouse_id', $warehouseId)` | `->whereIn('st.from_warehouse_id', $warehouseIds)` |
| L278 | `->where('warehouse_id', $warehouseId)` | `->whereIn('warehouse_id', $warehouseIds)` |
| L865 | `->where('st.from_warehouse_id', $warehouseId)` | `->whereIn('st.from_warehouse_id', $warehouseIds)` |

#### 3. GenerateWavesCommand.php の修正（1箇所）

| 行 | 変更前 | 変更後 |
|----|--------|--------|
| L716 | `->where('st.from_warehouse_id', $warehouseId)` | `->whereIn('st.from_warehouse_id', $warehouseIds)` |

#### 4. 倉庫選択UIの改善（任意）

倉庫セレクトボックスに実倉庫のみ表示する、または仮想倉庫を選択した場合にその親実倉庫に自動解決するオプションを検討。

```php
// 現状: 全倉庫を表示
->options(Warehouse::query()->pluck('name', 'id'))

// 案: 実倉庫のみ表示（仮想倉庫は自動的に含まれるため）
->options(
    Warehouse::query()
        ->where('is_virtual', false)
        ->pluck('name', 'id')
)
```

### DB変更

なし

### モデル変更

なし

### サービス変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/WarehouseResolver.php` | `resolveAllWarehouseIds()` メソッド追加 |

### UI変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/Waves/Pages/ListWaves.php` | 6箇所のクエリを `whereIn` に変更 |

### 影響範囲

| 機能 | 影響 |
|------|------|
| 手動波動生成（admin/waves） | 仮想倉庫の伝票も対象に含まれるようになる |
| 自動波動生成（cron） | stock_transfers のみ影響（earnings は元々 delivery_course のみでフィルタ） |
| 在庫引当 | 引当先の warehouse_id は `$waveSetting->warehouse_id`（配送コース倉庫）を使用しており変更不要 |
| ピッキングタスク | `warehouse_id` は `$waveSetting->warehouse_id` を使用しており変更不要 |

## 制約

- FK禁止（アプリレベルでリレーション管理）
- `migrate:fresh/refresh/reset` 禁止
- DB変更なし（新規マイグレーション不要）

## 対象ファイル

### 新規作成

なし

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Services/WarehouseResolver.php` | `resolveAllWarehouseIds()` 追加 |
| `app/Filament/Resources/Waves/Pages/ListWaves.php` | 6箇所の warehouse_id フィルタ修正 |
| `app/Console/Commands/GenerateWavesCommand.php` | 1箇所の warehouse_id フィルタ修正 |

### 参照のみ

| ファイル | 理由 |
|---------|------|
| `app/Models/WaveSetting.php` | warehouse_id アクセサ確認 |
| `app/Models/Sakemaru/Warehouse.php` | stock_warehouse_id カラム確認 |

## 確認事項

1. **倉庫セレクトボックス**: 実倉庫のみ表示にするか、全倉庫表示のままにするか？（仮想倉庫を選択した場合も自動解決するなら全倉庫表示でOK）=> 実倉庫のみ
2. **在庫引当の倉庫**: 引当時は `$waveSetting->warehouse_id`（= 配送コースの倉庫）を使用している。仮想倉庫の earnings に対しても配送コース倉庫の在庫から引当する現状の動作で正しいか？＝＞ただしい。ただし条件ある。配送コースの倉庫が仮想倉庫の実倉庫であれば問題ない。しかし、配送コースの倉庫が、出荷倉庫と実倉庫が異なる場合、在庫移動が必要。これは管轄外売上として対策したはず。出荷倉庫の実倉庫が配送コースの実倉庫と異なる場合、配送コースの在庫を出荷倉庫に在庫移動が必要
3. **ピッキングタスクの warehouse_id**: タスクの warehouse_id は配送コース倉庫（実倉庫）のIDが入る。これで正しいか？それで正しい。
