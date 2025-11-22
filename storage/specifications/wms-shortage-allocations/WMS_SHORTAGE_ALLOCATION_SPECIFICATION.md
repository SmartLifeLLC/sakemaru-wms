# WMS代理出荷（Shortage Allocation）仕様書

## 概要

WMSの欠品処理において、他の倉庫から商品を代理出荷する機能の仕様を定義します。

## 1. データ構造

### 1.1 wms_shortage_allocations テーブル

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|-----------|------|
| id | bigint | NO | - | 主キー |
| wms_shortage_id | bigint | NO | - | 欠品レコードID（wms_shortages参照） |
| source_warehouse_id | bigint | YES | NULL | 元倉庫ID（欠品発生倉庫） |
| purchase_price | decimal(10,2) | NO | 0 | 仕入単価（倉庫単価） |
| tax_exempt_price | decimal(10,2) | NO | 0 | 容器単価（税抜単価） |
| price | decimal(10,2) | YES | NULL | 販売単価（trade_itemsから取得） |
| is_confirmed | boolean | NO | false | 承認済みフラグ |
| confirmed_at | timestamp | YES | NULL | 承認日時 |
| confirmed_user_id | bigint | YES | NULL | 承認ユーザーID |
| created_at | timestamp | YES | NULL | 作成日時 |
| updated_at | timestamp | YES | NULL | 更新日時 |

### 1.2 インデックス

- `source_warehouse_id`: 元倉庫での検索用
- `is_confirmed`: 未承認レコードの検索用

### 1.3 外部キー制約

- `source_warehouse_id` → `warehouses.id` (ON DELETE RESTRICT)
- `confirmed_user_id` → `users.id` (ON DELETE SET NULL)

## 2. 単価取得ロジック

### 2.1 取得優先順位

代理出荷の単価は以下の優先順位で取得します：

1. **item_partner_prices（倉庫単価）**
   - 条件: `warehouse_id = source_warehouse_id AND partner_id IS NULL`
   - 最新の有効な単価: `start_date <= 現在日 AND is_active = true`
   - `start_date`が最も新しいレコード

2. **item_prices（原価単価）**
   - 条件: なし（商品マスタの一般的な原価）
   - 最新の有効な単価: `start_date <= 現在日 AND is_active = true`
   - `start_date`が最も新しいレコード

3. **デフォルト値**
   - 上記で取得できない場合: `purchase_price = 0`, `tax_exempt_price = 0`

### 2.2 数量タイプ別の単価フィールド

| 数量タイプ | item_partner_prices | item_prices |
|----------|---------------------|-------------|
| PIECE（バラ） | purchase_unit_price / tax_exempt_unit_price | cost_unit_price / tax_exempt_unit_price |
| CASE（ケース） | purchase_case_price / tax_exempt_case_price | cost_case_price / tax_exempt_case_price |
| CARTON（ボール） | (なし) / tax_exempt_carton_price | (なし) / tax_exempt_carton_price |

**注意**: CARTONの場合、purchase_price/cost_priceが存在しないため、フォールバックが発生する可能性があります。

### 2.3 SQL例

#### Priority 1: item_partner_prices

```sql
SELECT
    purchase_unit_price,    -- または purchase_case_price（数量タイプによる）
    tax_exempt_unit_price   -- または tax_exempt_case_price, tax_exempt_carton_price
FROM item_partner_prices
WHERE item_id = ?
  AND warehouse_id = ?  -- source_warehouse_id
  AND partner_id IS NULL
  AND is_active = true
  AND start_date <= ?  -- 現在日
ORDER BY start_date DESC
LIMIT 1;
```

#### Priority 2: item_prices

```sql
SELECT
    cost_unit_price,        -- または cost_case_price（数量タイプによる）
    tax_exempt_unit_price   -- または tax_exempt_case_price, tax_exempt_carton_price
FROM item_prices
WHERE item_id = ?
  AND is_active = true
  AND start_date <= ?  -- 現在日
ORDER BY start_date DESC
LIMIT 1;
```

## 3. 承認フロー

### 3.1 承認タイミング

`wms_shortages.is_confirmed = true`にセットされたとき、関連する`wms_shortage_allocations`レコードも自動的に承認されます。

### 3.2 承認処理

```php
// wms_shortagesの承認時
DB::table('wms_shortages')
    ->where('id', $shortageId)
    ->update([
        'is_confirmed' => true,
        'is_confirmed_at' => now(),
    ]);

// 関連するallocationsも承認
ConfirmShortageAllocations::execute($shortageId, $userId);
```

### 3.3 承認されるフィールド

- `is_confirmed`: `false` → `true`
- `confirmed_at`: `NULL` → 承認日時
- `confirmed_user_id`: `NULL` → 承認者のユーザーID

## 4. 使用方法

### 4.1 単価取得

```php
use App\Actions\Wms\GetWarehousePriceForAllocation;
use App\Enums\QuantityType;

// 倉庫単価と容器単価を取得
$prices = GetWarehousePriceForAllocation::execute(
    itemId: 123,
    sourceWarehouseId: 5,
    quantityType: QuantityType::CASE,
    asOfDate: '2025-11-22'  // オプション、nullの場合は今日
);

// 結果
// [
//     'purchase_price' => 1500.00,      // 仕入単価
//     'tax_exempt_price' => 100.00,     // 容器単価
// ]
```

### 4.2 代理出荷レコード作成例

```php
use App\Actions\Wms\GetWarehousePriceForAllocation;
use App\Enums\QuantityType;

// 1. 単価を取得
$prices = GetWarehousePriceForAllocation::execute(
    $itemId,
    $sourceWarehouseId,
    QuantityType::CASE
);

// 2. trade_itemsから販売単価を取得
$tradeItem = DB::table('trade_items')
    ->where('id', $tradeItemId)
    ->first();

// 3. 代理出荷レコードを作成
DB::table('wms_shortage_allocations')->insert([
    'wms_shortage_id' => $shortageId,
    'source_warehouse_id' => $sourceWarehouseId,
    'purchase_price' => $prices['purchase_price'],
    'tax_exempt_price' => $prices['tax_exempt_price'],
    'price' => $tradeItem->price,  // 販売単価
    'is_confirmed' => false,
    'created_at' => now(),
    'updated_at' => now(),
]);
```

### 4.3 承認処理

```php
use App\Actions\Wms\ConfirmShortageAllocations;

// 欠品を承認
DB::table('wms_shortages')
    ->where('id', $shortageId)
    ->update([
        'is_confirmed' => true,
        'is_confirmed_at' => now(),
    ]);

// 関連する代理出荷も承認
$confirmedCount = ConfirmShortageAllocations::execute(
    wmsShortageId: $shortageId,
    confirmedUserId: auth()->id()
);

\Log::info("Confirmed {$confirmedCount} allocations");
```

## 5. データフロー図

```
[欠品発生]
    ↓
[wms_shortages作成]
    ↓
[代理出荷検討]
    ↓
[GetWarehousePriceForAllocation] ← item_partner_prices (Priority 1)
    ↓ (見つからない場合)           ← item_prices (Priority 2)
    ↓ (見つからない場合)           ← デフォルト値 0
[単価取得完了]
    ↓
[wms_shortage_allocations作成]
    - source_warehouse_id: 元倉庫ID
    - purchase_price: 仕入単価
    - tax_exempt_price: 容器単価
    - price: 販売単価（trade_itemsから）
    - is_confirmed: false
    ↓
[承認待ち]
    ↓
[wms_shortages.is_confirmed = true]
    ↓
[ConfirmShortageAllocations実行]
    ↓
[wms_shortage_allocations.is_confirmed = true]
    - confirmed_at: 承認日時
    - confirmed_user_id: 承認者ID
```

## 6. 注意事項

### 6.1 単価が0になるケース

以下の場合、単価が0になります：
- 商品マスタに単価が登録されていない
- 倉庫単価も原価単価も設定されていない
- 該当する数量タイプの単価フィールドが存在しない（例: CARTON）

### 6.2 tax_exempt_priceの扱い

- `tax_exempt_price`は容器単価（保証非課税単価）を表します
- 取得できない場合は`0`にセットされます（NULLではない）
- item_pricesとitem_partner_pricesの両方に対応フィールドがあります

### 6.3 販売単価（price）

- 販売単価は`trade_items.price`から取得します
- 欠品発生時の取引の単価を記録することで、損益計算が可能になります
- NULLも許容されます

### 6.4 承認の不可逆性

- 一度承認された代理出荷レコードは、承認を取り消すことはできません
- 承認前に内容を十分に確認してください

## 7. モニタリングクエリ

### 7.1 未承認の代理出荷レコード

```sql
SELECT
    wsa.*,
    ws.shortage_date,
    w.name as source_warehouse_name,
    u.name as confirmed_user_name
FROM wms_shortage_allocations wsa
LEFT JOIN wms_shortages ws ON wsa.wms_shortage_id = ws.id
LEFT JOIN warehouses w ON wsa.source_warehouse_id = w.id
LEFT JOIN users u ON wsa.confirmed_user_id = u.id
WHERE wsa.is_confirmed = false
ORDER BY wsa.created_at DESC;
```

### 7.2 単価が0の代理出荷レコード

```sql
SELECT
    wsa.*,
    i.name as item_name,
    w.name as source_warehouse_name
FROM wms_shortage_allocations wsa
LEFT JOIN wms_shortages ws ON wsa.wms_shortage_id = ws.id
LEFT JOIN items i ON ws.item_id = i.id
LEFT JOIN warehouses w ON wsa.source_warehouse_id = w.id
WHERE wsa.purchase_price = 0
   OR wsa.tax_exempt_price = 0
ORDER BY wsa.created_at DESC;
```

### 7.3 承認済み代理出荷の統計

```sql
SELECT
    DATE(confirmed_at) as confirmed_date,
    COUNT(*) as total_count,
    SUM(purchase_price) as total_purchase_price,
    AVG(purchase_price) as avg_purchase_price
FROM wms_shortage_allocations
WHERE is_confirmed = true
  AND confirmed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(confirmed_at)
ORDER BY confirmed_date DESC;
```

## 8. トラブルシューティング

### 8.1 単価が取得できない

**症状**: `purchase_price`と`tax_exempt_price`が両方0になる

**原因**:
- 商品マスタ（item_prices）に単価が登録されていない
- 倉庫単価（item_partner_prices）が設定されていない
- start_dateが未来日になっている

**対処**:
1. item_pricesテーブルで該当商品の単価を確認
2. item_partner_pricesテーブルで倉庫単価を確認
3. start_dateが正しい日付になっているか確認
4. is_active = trueになっているか確認

### 8.2 承認が反映されない

**症状**: `wms_shortages.is_confirmed = true`にしても、allocationsが承認されない

**原因**:
- ConfirmShortageAllocationsアクションが呼ばれていない
- トランザクションがロールバックされている

**対処**:
1. ログを確認（`WMS shortage allocations confirmed`）
2. ConfirmShortageAllocations::execute()が正しく呼ばれているか確認
3. データベーストランザクションの範囲を確認

### 8.3 CARTONタイプの単価が取得できない

**症状**: CARTON数量タイプで単価が常に0になる

**原因**:
- item_partner_pricesにpurchase_carton_priceフィールドが存在しない
- item_pricesにcost_carton_priceフィールドが存在しない

**対処**:
- CARTONタイプの場合、tax_exempt_carton_priceのみ取得可能
- purchase_priceはitem_pricesのcost_unit_price/cost_case_priceで代替するか、手動設定が必要

## 9. 関連ファイル

- **マイグレーション**: `database/migrations/2025_11_22_002758_add_confirmation_and_pricing_to_wms_shortage_allocations.php`
- **単価取得アクション**: `app/Actions/Wms/GetWarehousePriceForAllocation.php`
- **承認アクション**: `app/Actions/Wms/ConfirmShortageAllocations.php`
