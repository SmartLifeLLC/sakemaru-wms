商品の引き当てのロジックを改善(wave生成時)

1. 在庫は(real_stocks) warehouse_id / stock_allocation_id / floor_id /　location_id / purchase_id　/ item_id / item_management_type / expiration_date の組み合わせでuniqueになる。（データベースの更新が必要）
2. 在庫はexpiration_dateの早い順で引き当てる必要がある。
3. 在庫expiration_dateはitems.uses_expiration_date = trueの場合、賞味期限を入れる必要がある。ただ、NULLの入力は可能
4. locationsは CASE | PIECE | CARTON | UNKNOWN (複数の設定が可能)
5. 引き当て時には受注時のquantity_typeが、考慮されているものを基準に引き当てる必要がある。
6. 在庫の引き当てはリアルタイムで行う　
了解。Claude Code にそのまま貼れるレベルの「在庫引き当てロジック 実装指示書」を出す。余計な説明は排し、要件→I/F→ロック戦略→疑似コード→SQL→例外処理→テストの順で固める。

---

# 在庫引き当て：（`GET_LOCK()`）実装指示

## 0) 前提と目標

* 規模：**300明細 / 1,000アイテム**、完了目標 **≤ 5分**
* 並列：キュー **6〜8ワーカー**
* 排他：**MySQLの名前付きロック**で「倉庫×アイテム」単位の同時実行を抑止
  （外部KVS不使用。接続単位でロックが紐づく点に注意）

---

## 1) ロック設計（必読）

* ロックキー：`alloc:{warehouse_id}:{item_id}`
* 取得：`SELECT GET_LOCK(:key, :timeout_sec)` → 成功=1／失敗=0
  推奨タイムアウト：**1秒**
* 解放：`SELECT RELEASE_LOCK(:key)`
* **必ず同一DB接続**で取得→処理→解放を行う（接続プールで別接続に乗らないようにする）

### ラッパ（PHP/Laravel想定）

```php
final class DbMutex
{
    public static function acquire(string $key, int $timeoutSec = 1): bool
    {
        $pdo = DB::connection()->getPdo();             // 同一接続を保持
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$key, $timeoutSec]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public static function release(string $key): void
    {
        try {
            $pdo = DB::connection()->getPdo();
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$key]);
        } catch (\Throwable $e) {
            // ログのみ（接続断などで既に解放されてるケースを許容）
            logger()->warning('release_lock_failed', ['key'=>$key, 'e'=>$e->getMessage()]);
        }
    }
}
```

---

## 2) 入口I/F（アイテム単位ジョブ）

```php
public function allocateForItem(int $waveId, int $warehouseId, int $itemId): void
{
    $lockKey = "alloc:{$warehouseId}:{$itemId}";
    if (!DbMutex::acquire($lockKey, 1)) {
        // 他ワーカー実行中。今回はスキップ（または短いバックオフで再試行1回）
        return;
    }

    try {
        $this->doAllocate($waveId, $warehouseId, $itemId); // 下記ロジック本体
    } finally {
        DbMutex::release($lockKey);
    }
}
```

---

## 3) 引き当て本体（要点のみ）

* **候補抽出**：期限管理ON→FEFO（NULL最後）、OFF→FIFO
* **単位フィルタ**：bitmask（1=CASE, 2=PIECE, 4=CARTON、8=UNKNOWN）
* **在庫更新**：条件付きUPDATE（楽観）
* **予約**：multi-row INSERT（**≤ 50行/バッチ**）
* **拘束**：`wms_real_stocks.reserved_quantity` を **lock_version** 条件で加算

### 候補取得SQL（期限管理ON）

```sql
SELECT
  rs.id AS real_stock_id,
  rs.location_id, rs.purchase_id, rs.expiration_date, rs.received_at,
  rs.unit_cost, rs.available_quantity,
  wrs.reserved_quantity, wrs.picking_quantity, wrs.lock_version,
  wl.walking_order
FROM real_stocks rs
JOIN wms_real_stocks wrs ON wrs.real_stock_id = rs.id
JOIN wms_locations   wl  ON wl.location_id     = rs.location_id
JOIN locations       l   ON l.id               = rs.location_id
WHERE rs.warehouse_id = :wh
  AND rs.item_id      = :item
  AND rs.available_quantity > 0
  AND (l.available_quantity_flags & :need_flag) != 0
ORDER BY (rs.expiration_date IS NULL), rs.expiration_date, wl.walking_order
LIMIT :limit OFFSET :offset;  -- limit=50, max 2 pages
```

> 非期限管理：`ORDER BY rs.received_at, wl.walking_order`

### 在庫確保（1ロットごと・楽観）

```sql
UPDATE real_stocks
SET available_quantity = available_quantity - :take, updated_at = NOW()
WHERE id = :real_stock_id
  AND available_quantity >= :take;   -- 影響1で確保成功
```

### 拘束＋予約（同一Tx）

```sql
-- reserved_quantity をロット合計で一度に更新
UPDATE wms_real_stocks
SET reserved_quantity = reserved_quantity + :sum_take,
    lock_version      = lock_version + 1
WHERE real_stock_id = :real_stock_id
  AND lock_version  = :prev_lv;      -- 0行→レース→このロットはロールバック/スキップ

-- 予約はまとめてINSERT（≤50行）
INSERT INTO wms_reservations
(warehouse_id, location_id, real_stock_id, item_id, expiry_date, received_at,
 purchase_id, unit_cost, qty_each, qty_type, source_type, source_id, wave_id, status, created_by)
VALUES
  (...), (...), ...;
```

### 冪等キー（重複予約防止）

```sql
create unique index uniq_wres_idem
on wms_reservations(wave_id, item_id, real_stock_id, source_id, status);
```

---

## 4) パラメータ（この基準で固定）

* ワーカー数：**6〜8**
* ロット取得：**50件/回**, **最大2ページ**
* 予約INSERT：**≤50行/バッチ**
* GET_LOCK タイムアウト：**1秒**（失敗時はスキップ or 1回だけ1–3msバックオフ再試行）
* 競合時（UPDATE 0件/lock_version衝突）：**例外にしないで次候補へ**

---

## 5) 単位変換と配分

* 受注明細は **PIECE最小単位**に正規化（CASE/CARTON → ×`item.case_size`）
* `need_total = sum(qty_each)` → 各ロット確保分を `spreadToLines()` で先頭未充足明細から割当
* 残があれば明細ごとに **SHORTAGE** を multi-row で追加

---

## 6) インデックス（作成/確認）

```sql
create index idx_rs_wh_item_exp_loc on real_stocks(warehouse_id, item_id, expiration_date, location_id, purchase_id);
create index idx_wrs_real_lv        on wms_real_stocks(real_stock_id, lock_version);
create index idx_wl_loc             on wms_locations(location_id, walking_order);
create index idx_loc_flags          on locations(id, available_quantity_flags);
```

---

## 7) 例外・境界

* 期限管理ONで `expiration_date IS NULL` → **最後尾**扱い
* `available_quantity_flags = 8(UNKNOWN)` は候補外
* 温度帯/希少エリアの制約は WHERE 条件に追加して常に強制
* タイムアウトでロック取れない場合：**潔くスキップ**（5分以内を優先）

---

## 8) 計測（ログ必須）

* `item_id, need_total, alloc_qty, shortage_qty, elapsed_ms, race_count`
* 合格基準：総時間 **≤ 300秒**, 競合率 **≤ 2%**, 重複予約 **0**

---

## 9) 実装タスク（手順）

1. `DbMutex` ラッパ実装（上記の通り）
2. `allocateForItem()` 入口で `GET_LOCK` を取得→`doAllocate()` 実行→`RELEASE_LOCK`
3. 本体：

    * 候補 50×最大2ページ
    * 在庫 UPDATE（楽観）
    * 同一Txで `wms_real_stocks` 拘束更新（lock_version条件）
    * 予約 multi-row（≤50）
    * 残は SHORTAGE multi-row
4. `wms_reservations` の冪等キー作成
5. ワーカー **6〜8** で本番相当負荷の検証

---

単位フィルタ

バイナリビットマスク方式で可搬性・検索効率を両立できる。
`CASE`, `PIECE`, `CARTON`, `UNKNOWN` の4種類であれば、**int型ビットフラグ**を採用すると高速かつ拡張性が高い。

---

## ✅ 提案構成（bitmask方式）

### 1. ビット割り当て

| 種類      | ビット値 | 10進値 | 説明             |
| ------- | ---- | ---- | -------------- |
| CASE    | 0001 | 1    | ケース単位でピッキング可   |
| PIECE   | 0010 | 2    | バラ単位でピッキング可    |
| CARTON  | 0100 | 4    | カートン単位でピッキング可  |
| UNKNOWN | 1000 | 8    | 未設定（他ビットと併用不可） |

### 2. テーブル定義　（実装済み）

```sql
alter table locations
  add column available_quantity_flags int unsigned not null default 8 comment 'bit flags: 1=CASE, 2=PIECE, 4=CARTON, 8=UNKNOWN';
```

### 3. 登録ルール

* 両対応可：

    * CASE+PIECE = 0001 | 0010 = `3`
    * CASE+CARTON = 0001 | 0100 = `5`
    * PIECE+CARTON = 0010 | 0100 = `6`
    * 全対応 = 0001 | 0010 | 0100 = `7`
* UNKNOWN = `8` 単独のみ。

> ✅ バリデーションルール
> UNKNOWN (8) と他ビットの併用は禁止。

---

## ⚙️ 4. クエリ例

### CASE対応ロケーション抽出

```sql
WHERE (available_quantity_flags & 1) != 0
```

### PIECE対応ロケーション抽出

```sql
WHERE (available_quantity_flags & 2) != 0
```

### CARTON対応ロケーション抽出

```sql
WHERE (available_quantity_flags & 4) != 0
```

### UNKNOWNのみ

```sql
WHERE available_quantity_flags = 8
```

---

## ⚡ 5. パフォーマンス・拡張性

* `int` はインデックス利用可で、`FIND_IN_SET` より高速。
* 追加単位（例：PALLET=16）を柔軟に拡張できる。
* ORM（Laravel/Eloquent）側では、
  ビット演算ラッパーを簡単に用意できる。

---

## 6. Laravel側ユーティリティ例

```php
1. QuantityType　クラスにgetFlag追加

2. Location Model Extend 
class Location 
{
    public function supports(EAvailableUnit $unit): bool
    {
        return ($this->available_quantity_flags & $unit->value) !== 0;
    }

    public function setUnits(array $units): void
    {
        $this->available_quantity_flags = array_reduce(
            $units,
            fn($carry, $u) => $carry | $u->value,
            0
        );
    }
}
```

---

## 📘 7. 比較まとめ

| 方法               | 複数可 | 検索速度    | 移植性   | コメント      |
| ---------------- | --- | ------- | ----- | --------- |
| ENUM             | ✗   | 高速      | 高     | 単一値のみ     |
| SET              | ○   | 中程度     | 低     | MySQL依存   |
| 中間テーブル           | ○   | 中       | 高     | JOINコスト発生 |
| **BITMASK(int)** | ✅   | **最高速** | **高** | 拡張容易・理想的  |

---

### ✅ 最終推奨

→ `available_quantity_flags INT UNSIGNED`（bitmask方式）採用。
Laravel側で enum 定義＋helper関数を付ければメンテナンス性も抜群。

---

希望があれば、
この方式で **マイグレーション + モデル + Seeder例 + 検索クエリ（Filament filter対応）** をまとめて出す。
出す？

