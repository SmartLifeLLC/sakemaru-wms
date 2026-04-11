# real_stocks テーブル リファクタリング対応仕様書

## 概要

sakemaru-ai-core側で`real_stocks`テーブルから`floor_id`と`location_id`カラムが削除され、`real_stock_lots`テーブルに移動されました。WMS側でもこの変更に対応する必要があります。

## 変更日
2026-01-14

## データベース変更内容

### real_stocks テーブル（変更後）
以下のカラムが**削除**されました：
- `floor_id`
- `location_id`
- `purchase_id`
- `trade_item_id`
- `price`
- `content_amount`
- `container_amount`
- `expiration_date`

新しいユニーク制約：
```sql
UNIQUE KEY `unique_item_warehouse_allocation` (`item_id`, `warehouse_id`, `stock_allocation_id`)
```

### real_stock_lots テーブル（変更後）
以下のカラムが**追加**されました：
- `floor_id` - フロアID
- `location_id` - ロケーションID

## WMS側で確認・修正が必要な箇所

### 1. RealStockモデル
`floor()`および`location()`リレーションが使用されている場合、`active_lots`経由で取得するように変更が必要。

**変更前：**
```php
$realStock->location_id
$realStock->floor_id
$realStock->location
$realStock->floor
```

**変更後：**
```php
$realStock->active_lots->first()?->location_id
$realStock->active_lots->first()?->floor_id
$realStock->active_lots->first()?->location
$realStock->active_lots->first()?->floor
```

### 2. SQLクエリ・JOIN
`real_stocks.location_id`や`real_stocks.floor_id`を使用したJOINは、`real_stock_lots`経由に変更が必要。

**変更前：**
```sql
LEFT JOIN locations ON real_stocks.location_id = locations.id
```

**変更後：**
```sql
LEFT JOIN real_stock_lots ON real_stocks.id = real_stock_lots.real_stock_id
    AND real_stock_lots.remaining_quantity > 0
LEFT JOIN locations ON real_stock_lots.location_id = locations.id
```

### 3. INSERT/UPDATE処理
`real_stocks`への`floor_id`/`location_id`の挿入・更新は不要になりました。
代わりに`real_stock_lots`に保存する必要があります。

### 4. フィルタリング処理
`floor_id`や`location_id`でのフィルタリングは`whereHas`を使用：

**変更前：**
```php
RealStock::where('floor_id', $floorId)->get();
```

**変更後：**
```php
RealStock::whereHas('active_lots', function ($q) use ($floorId) {
    $q->where('floor_id', $floorId);
})->get();
```

## 検索対象パターン

WMSプロジェクト内で以下のパターンを検索してください：

```
real_stock->location_id
real_stock->floor_id
real_stocks.location_id
real_stocks.floor_id
->location()  (RealStockモデル内)
->floor()     (RealStockモデル内)
'location_id' => (real_stocksへのINSERT/UPDATE)
'floor_id' =>    (real_stocksへのINSERT/UPDATE)
```

## RealStockLotモデルに必要なリレーション

```php
public function floor(): BelongsTo
{
    return $this->belongsTo(Floor::class);
}

public function location(): BelongsTo
{
    return $this->belongsTo(Location::class);
}
```

## 注意事項

1. `active_lots`リレーションは`remaining_quantity > 0`または`status = 'ACTIVE'`でフィルタされたロットを返す
2. 複数のロットが存在する場合、最初のロット（FIFO順）のlocationを使用
3. ロットが存在しない場合は`null`が返る可能性があるため、`?->` null safe operatorを使用

## ロットとロケーションの関係

**重要：** 同じ倉庫・同じ商品でも、棚番（ロケーション）が異なる場合は別ロットとして管理されます。

- 1つの`real_stock` = 1つの (warehouse_id, item_id, stock_allocation_id) 組み合わせ
- 1つの`real_stock`に対して、複数の`real_stock_lot`が存在可能（ロケーション別）
- T3在庫からは合計数量のみ取得されるため、移行時は最初のロットに全数量が割り当てられる

## 関連ファイル（sakemaru-ai-core側の変更済みファイル）

参考として、sakemaru-ai-core側で修正されたファイル一覧：

- `app/Models/RealStock.php` - floor/locationリレーション削除
- `app/Models/RealStockLot.php` - floor/locationリレーション追加
- `app/Services/LotAllocationService.php` - createLotにfloor_id/location_id追加
- `app/Actions/Trades/UpdateTradeItems.php` - ロット作成時にlocation渡す
- `app/Actions/Updaters/UpdateRealStocks.php` - lots経由でlocation更新
- `app/Actions/Inventory/StartInventory.php` - lots経由でフィルタ
- `app/Actions/API/PostRealStocks.php` - floor_id/location_id削除
- `app/Actions/Print/CreatePickingList.php` - lots経由でlocation取得
- `app/Actions/Print/CreateStockTransferChecklist.php` - lots経由でJOIN
- `app/Livewire/RealStockForm.php` - lots経由でlocation取得
- `app/Livewire/RealStockDetail.php` - lots経由でlocation取得
- `resources/views/print/arrival-schedule-list.blade.php` - lots経由でlocation取得
