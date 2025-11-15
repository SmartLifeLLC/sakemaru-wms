

# 📘 **欠品管理 & 代理出荷処理 実装指示書（Claude Code 用）**

---

# 0. **目的（ Purpose ）**

本実装の目的は、倉庫出荷における **欠品管理** と **他倉庫からの代理出荷（リカバリ）** を
一元的かつ追跡可能な形で実現することにある。

特に次を満たす：

1. **欠品は2種類ある**

    * **引当欠品（ALLOCATION）**：在庫引当のタイミングで足りない
    * **ピッキング欠品（PICKING）**：引当済みだが現場で実際に取れなかった

2. この2種類を **単一のデータモデル**で管理し、後からどちらの欠品かを明確に区別できる。

3. 欠品は「代理出荷（他倉庫補充）」で解消できる。

    * 元倉庫で足りない場合、ユーザが

      > どの倉庫から、どれだけ出荷するか
      > を自由に指示できる。

4. **ケース受注をバラ在庫で補填する（ケース崩し）は原則禁止**。

    * 元注文が CASE の場合、

        * 引当
        * ピッキング
        * 欠品処理
        * 代理出荷
          の全工程で **CASE 対応ロケーションのみ**を候補とする。

5. 内部ロジックはすべて **PIECE 最小単位**で統一して計算する。

    * 商品ごとに CASE/CARTON の入数が違う
    * 仮に代理出荷で CASE と PIECE が混ざると計算不能
      → 最小単位に統一し計算の一貫性を保つ

6. 欠品 → 代理出荷 → 代理側欠品 → 再代理出荷・・・という
   **連鎖欠品も正しくトレースできる**ようにする。

---

# 1. **高レベル実装方針**

### 1) データモデル中心

* 欠品はすべて `wms_shortages`
* 代理出荷指定は `wms_shortage_allocations`
* ピッキングタスクは既存 `wms_picking_tasks` を `task_type='REALLOCATION'` で利用
* 在庫引当は既存 `real_stocks / wms_real_stocks / wms_reservations` を再利用

### 2) 内部単位はすべて PIECE に統一

* 「何個不足しているか」
* 「何個代理倉庫で確保したか」
* 「欠品残はいくつか」
  これらはすべて PIECE で保持。

### 3) ケース受注 → バラ出荷禁止ルール

* `quantity_type = CASE` の受注は

    * CASE対応ロケーションのみ候補
    * PIECEロケ在庫は候補に含めない
    * 欠品処理 & 代理出荷も CASE のみ

### 4) 欠品 → 再引当 → 代理ピッキング → 欠品連鎖

すべて「不足数 PIECE」で計算し、UI側で CASE/PIECE 表示に変換。

---

# 2. **データモデル（追加テーブル）**

## 2-1. 欠品管理テーブル：`wms_shortages`

```sql
create table wms_shortages (
                               id                   bigint unsigned primary key auto_increment,
                               type                 enum('ALLOCATION','PICKING') not null,   -- 欠品の種類
                               wave_id              bigint unsigned not null,
                               warehouse_id         bigint unsigned not null,                -- 欠品が発生した倉庫
                               item_id              bigint unsigned not null,
                               trade_id             bigint unsigned not null,
                               trade_item_id        bigint unsigned not null,

                               order_qty_each       int not null,                            -- 受注数量(PIECE換算)
                               planned_qty_each     int not null default 0,                  -- 引当数量(PIECE)
                               picked_qty_each      int not null default 0,                  -- ピッキング数量(PIECE)
                               shortage_qty_each    int not null,                            -- 不足(PIECE)

                               qty_type_at_order    enum('CASE','PIECE','CARTON') not null,  -- 受注単位のスナップショット
                               case_size_snap       int not null default 1,                  -- 当時のケース入数

                               source_reservation_id bigint unsigned null,
                               source_pick_result_id bigint unsigned null,

                               parent_shortage_id   bigint unsigned null,                    -- 代理側での再欠品管理

                               status               enum('OPEN','REALLOCATING','FULFILLED','CANCELLED') not null default 'OPEN',
                               reason_code          enum('NONE','NO_STOCK','DAMAGED','MISSING_LOC','OTHER') default 'NONE',
                               note                 varchar(255) null,

                               created_at           timestamp null,
                               updated_at           timestamp null,

                               index idx_shortage_wave (wave_id, status),
                               index idx_shortage_item (item_id, status)
);
```

---

## 2-2. 欠品→代理出荷指示テーブル：`wms_shortage_allocations`

```sql
create table wms_shortage_allocations (
                                          id                  bigint unsigned primary key auto_increment,
                                          shortage_id         bigint unsigned not null,
                                          from_warehouse_id   bigint unsigned not null,    -- 代理出荷倉庫
                                          assign_qty_each     int not null,                -- PIECE換算数量
                                          assign_qty_type     enum('CASE','PIECE','CARTON') not null,

                                          status              enum(
                                              'PENDING',
                                              'RESERVED',
                                              'PICKING',
                                              'FULFILLED',
                                              'SHORTAGE',
                                              'CANCELLED'
                                              ) default 'PENDING',

                                          created_by          bigint unsigned not null default 0,
                                          created_at          timestamp null,
                                          updated_at          timestamp null,

                                          index idx_shortage_alloc (shortage_id, status)
);
```

---

# 3. **段階別実装手順（Claudeへ渡すメイン指示）**

---

# 🔵 **段階1：引当時の欠品生成（ALLOCATION）**

### 1-1. 引当処理終了後、明細ごとに残量を計算

```php
$remaining_each = $order_each - $reserved_each;
if ($remaining_each > 0) {
    WmsShortage::create([...]);
}
```

### 1-2. CASE受注の場合は CASEロケのみ候補

```sql
-- CASE の場合
AND (l.available_quantity_flags & 1) != 0    -- CASE bit
```

### 1-3. 欠品レコード（ALLOCATION）作成

```php
WmsShortage::create([
  'type' => 'ALLOCATION',
  'wave_id' => $waveId,
  'warehouse_id' => $warehouseId,
  'item_id' => $itemId,
  'trade_id' => $tradeId,
  'trade_item_id' => $tradeItemId,
  'order_qty_each' => $orderEach,
  'planned_qty_each' => $reservedEach,
  'picked_qty_each' => 0,
  'shortage_qty_each' => $remaining_each,
  'qty_type_at_order' => $qtyType,
  'case_size_snap' => $caseSize,
  'status' => 'OPEN',
  'reason_code' => 'NO_STOCK'
]);
```

---

# 🔵 **段階2：ピッキング時の欠品生成（PICKING）**

### 2-1. `wms_picking_item_results` 完了時イベント

```php
$short_each = max(0, $planned_each - $picked_each);
if ($short_each > 0) {
  $shortage = WmsShortage::firstOrCreate(
     [ 'type'=>'PICKING', 'wave_id'=>$waveId, 'warehouse_id'=>$warehouseId,
       'item_id'=>$itemId, 'trade_item_id'=>$tradeItemId, 'status'=>'OPEN'],
     [ 'order_qty_each'=>$orderEach, 'planned_qty_each'=>$planned_each,
       'picked_qty_each'=>0,'shortage_qty_each'=>0,'qty_type_at_order'=>$qtyType,
       'case_size_snap'=>$caseSize ]
  );
  $shortage->increment('shortage_qty_each', $short_each);
}
```

---

# 🔵 **段階3：欠品一覧API/UIの提供**

### 3-1. 引当欠品（ALLOCATION一覧）

```sql
SELECT *
FROM wms_shortages
WHERE type='ALLOCATION' AND status IN ('OPEN','REALLOCATING')
ORDER BY wave_id, warehouse_id, item_id;
```

### 3-2. ピッキング欠品（PICKING一覧）

```sql
SELECT *
FROM wms_shortages
WHERE type='PICKING' AND status IN ('OPEN','REALLOCATING')
ORDER BY wave_id, warehouse_id, item_id;
```

---

# 🔵 **段階4：代理出荷指示（ユーザ操作）**

### 4-1. 画面で入力：

* from_warehouse_id
* qty (任意単位：CASE/PIECE/CARTON だが **CASE欠品なら CASEのみ許容**)

### 4-2. 保存処理

```php
// CASE受注で PIECE/CARTON を指定されたら reject
if ($shortage->qty_type_at_order === 'CASE' && $req->qty_type !== 'CASE') {
    throw new Exception('CASE受注に対してバラ/カートン指定はできません');
}

$each = convertToEach($req->qty, $req->qty_type, $shortage->case_size_snap);
$alloc = WmsShortageAllocation::create([
    'shortage_id' => $shortage->id,
    'from_warehouse_id' => $req->warehouse_id,
    'assign_qty_each' => $each,
    'assign_qty_type' => $req->qty_type,
    'status' => 'PENDING',
    'created_by' => auth()->id() ?? 0,
]);

$shortage->update(['status' => 'REALLOCATING']);
```

---

# 🔵 **段階5：代理出荷の実在庫引当（REALLOCATION）**

### 5-1. 代理倉庫で再引当

```php
$reserved_each = $allocator->reserveForShortage($shortage, $alloc);
```

### 5-2. 結果

```php
if ($reserved_each > 0) {
    $alloc->update(['status' => 'RESERVED']);
    // REALLOCATION タスク作成
    WmsPickingTask::create([
        'wave_id' => $shortage->wave_id,
        'warehouse_id' => $req->warehouse_id,
        'task_type' => 'REALLOCATION',
        'status' => 'PENDING',
    ]);
}
```

---

# 🔵 **段階6：代理側ピッキング → 再欠品処理**

### 6-1. ピッキング完了後に欠品計算

```php
$short = max(0, $planned_each - $picked_each);
if ($short > 0) {
    WmsShortage::create([
      'type' => 'PICKING',
      'parent_shortage_id' => $originalShortage->id,
      'wave_id' => $originalShortage->wave_id,
      'warehouse_id' => $proxyWarehouseId,
      'item_id' => $originalShortage->item_id,
      'trade_id' => $originalShortage->trade_id,
      'trade_item_id' => $originalShortage->trade_item_id,
      'order_qty_each' => $originalShortage->order_qty_each,
      'planned_qty_each' => $planned_each,
      'picked_qty_each' => $picked_each,
      'shortage_qty_each' => $short,
      'qty_type_at_order' => $originalShortage->qty_type_at_order,
      'case_size_snap' => $originalShortage->case_size_snap,
      'status' => 'OPEN'
    ]);
}
```

---

# 🔵 **段階7：欠品充足判定（FULFILLED / OPEN 継続）**

### 7-1. 代理倉庫のピッキング実績を集計

```php
$total_picked = WmsShortageAllocation::where('shortage_id',$shortage->id)
   ->where('status','FULFILLED')
   ->sum('assign_qty_each');
```

### 7-2. 残量

```php
$remaining = $shortage->shortage_qty_each - $total_picked;

if ($remaining <= 0) {
    $shortage->update(['status'=>'FULFILLED']);
} else {
    $shortage->update(['status'=>'OPEN']);
}
```

---

# 🔵 **段階8：キャンセル（CANCELLED）**

```php
$shortage->update(['status'=>'CANCELLED']);
```

---

# 🔵 **段階9：支援ユーティリティコード（変換等）**

### 9-1. CASE/PIECE/CARTON → PIECE変換

```php
function convertToEach(int $qty, string $qtyType, int $caseSize): int
{
    return match($qtyType) {
        'CASE'   => $qty * $caseSize,
        'CARTON' => $qty * $caseSize, // CARTON専用入数があるならここで分離
        'PIECE'  => $qty,
        default  => throw new Exception("Invalid qtyType"),
    };
}
```

### 9-2. PIECE → 表示用 CASE 変換

```php
function convertFromEach(int $each, int $caseSize): array
{
    return [
        'case'  => intdiv($each, $caseSize),
        'piece' => $each % $caseSize,
    ];
}
```

---

# 🔵 **段階10：SQL/インデックス（パフォーマンス用）**

```sql
create index idx_shortage_wave     on wms_shortages(wave_id, status);
create index idx_shortage_item     on wms_shortages(item_id, status);
create index idx_shortage_alloc    on wms_shortage_allocations(shortage_id, status);
```

---

