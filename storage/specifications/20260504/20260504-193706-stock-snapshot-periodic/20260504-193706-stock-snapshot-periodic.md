# 定期在庫スナップショット機能

- **作成日**: 2026-05-04
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/20260504/20260504-193706-stock-snapshot-periodic/

## 背景・目的

### 目的

特定時点の在庫状態を調査可能にする。「あの日の朝、在庫はどうなっていたか？」に回答できるデータ基盤を構築する。

### ユースケース

- 在庫差異の原因調査（いつ・どこで数量がずれたか → ロット明細で特定）
- 出荷判断の妥当性検証（判断時点の在庫状態を再現）
- 在庫推移の分析（日次・週次のトレンド → サマリーで集計）
- 自動発注計算の再現検証（当時の有効在庫・入荷予定を再構築）

### スケジュール

1日2回: **6:00**（朝、出荷作業前）、**18:00**（夕方、出荷作業後）

## 現状の実装

### 旧スナップショット機能（廃止済み）

`wms_item_stock_snapshots` テーブルと `StockSnapshotService` は **2026-04-05に廃止**。

- **旧用途**: 自動発注計算の一時キャッシュ（ジョブ実行ごとに生成→参照→不要化）
- **廃止理由**: 一時テーブルと同じ役割で永続化の意味がなく、テーブル肥大化（年間10M行・2.5GB）が問題
- **現状**: テーブルは残存（データ0件）、`StockSnapshotService` は削除済み
- **詳細**: `storage/specifications/20260405/20260405-135655-abolish-stock-snapshot/`

### 在庫データの現在の構造

#### `real_stocks` テーブル（基幹システム）

倉庫×商品 単位の在庫マスタ。

| カラム | 型 | 用途 |
|--------|-----|------|
| `id` | BIGINT PK | real_stock_id |
| `client_id` | BIGINT | クライアント |
| `warehouse_id` | BIGINT | 倉庫 |
| `stock_allocation_id` | BIGINT | 在庫配賦 |
| `item_id` | BIGINT | 商品 |
| `current_quantity` | INT | 現在庫数 |
| `reserved_quantity` | INT | 引当済み数 |
| `available_quantity` | INT (GENERATED) | = current - reserved |
| `wms_lock_version` | INT | 楽観ロック |
| `received_at` | DATETIME | 入庫日時 |

#### `real_stock_lots` テーブル（基幹システム）

ロット単位の明細。1つの `real_stocks` に対して複数のロットが存在しうる。

| カラム | 型 | 用途 |
|--------|-----|------|
| `id` | BIGINT PK | ロットID |
| `real_stock_id` | BIGINT | 親在庫 |
| `location_id` | BIGINT | ロケーション（**フロアプランで使用**）|
| `floor_id` | BIGINT | フロア |
| `expiration_date` | DATE | 賞味期限（**FEFO基準**）|
| `price` | DECIMAL(2) | 仕入単価 |
| `content_amount` | DECIMAL(4) | 内容量 |
| `container_amount` | DECIMAL(4) | 容器量 |
| `purchase_id` | BIGINT | 仕入PO |
| `initial_quantity` | INT | 初期数量 |
| `current_quantity` | INT | 現在数量 |
| `reserved_quantity` | INT | ロット引当数 |
| `status` | ENUM | ACTIVE / DEPLETED / EXPIRED |

#### `wms_v_stock_available` ビュー

`real_stocks LEFT JOIN real_stock_lots (ACTIVE)` のビュー。自動発注とフロアプランの両方が参照。

#### `wms_order_incoming_schedules` テーブル

入荷予定。自動発注で `incoming_quantity` として参照。

| 参照カラム | 計算 |
|-----------|------|
| `warehouse_id`, `item_id` | GROUP BY キー |
| `expected_quantity - received_quantity` | SUM → 入荷予定残数 |
| `status IN ('PENDING', 'PARTIAL')` | フィルタ条件 |

### システムでの在庫参照パターン

| 機能 | 参照レベル | 使用カラム |
|------|-----------|-----------|
| **自動発注計算** | 倉庫×商品（集約） | `available_for_wms`(SUM, real_stock_id重複排除) + incoming |
| **フロアプラン** | ロケーション×商品（ロット） | `location_id`, `current_quantity`, `expiration_date`, `item_code/name` |
| **在庫一覧(RealStocksTable)** | 倉庫×商品（集約） | `current_quantity`, `available_quantity`（サブクエリ集計） |
| **FEFO引当** | ロット単位 | `expiration_date` ASC → `real_stock_lots.created_at` ASC → `real_stock_lots.id` ASC |

### データ規模（LOCAL実測）

- 倉庫数: **32**（在庫ありは20倉庫）
- 商品数: **51,197**
- `real_stocks` 在庫あり行数: **50,566行**
- `real_stock_lots` (ACTIVE): **50,566行**（LOCAL環境では1:1）
- **本番想定**: ロットは1在庫あたり1〜3ロット → **5万〜15万行/回**

## 変更内容

### 概要

2層構造のスナップショットを構築する:

1. **サマリーテーブル** `wms_stock_snapshots` — 倉庫×商品の集約。15ヶ月保持
2. **ロット明細テーブル** `wms_stock_snapshot_lots` — ロケーション・賞味期限別。6ヶ月DB保持 → S3にCSV退避

取得後に**整合性検証**を実施し、サマリーとロット明細の数量一致を確認する。

サマリーとロット明細は同一トランザクションの consistent read から取得し、`captured_at` を共通値として保存する。これにより、別ステートメント実行中の在庫変動によるスナップショット内不整合を避ける。

### 詳細設計

#### DB変更

##### テーブル1: `wms_stock_snapshots`（サマリー）

自動発注計算と同じ粒度。「倉庫Aに商品Bが合計何個あったか」を記録。

```sql
CREATE TABLE wms_stock_snapshots (
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning','evening') NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    current_quantity INT NOT NULL DEFAULT 0,       -- SUM(real_stocks.current_quantity)
    reserved_quantity INT NOT NULL DEFAULT 0,      -- SUM(real_stocks.reserved_quantity)
    available_quantity INT NOT NULL DEFAULT 0,     -- SUM(real_stocks.available_quantity)
    incoming_quantity INT NOT NULL DEFAULT 0,      -- SUM(入荷予定残数)
    stock_count INT UNSIGNED NOT NULL DEFAULT 0,     -- real_stock レコード数（検証用。入荷予定のみは0）
    captured_at DATETIME(6) NOT NULL,              -- 実取得時刻
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (snapshot_date, snapshot_time, warehouse_id, item_id),
    INDEX idx_ss_item (item_id, snapshot_date),
    INDEX idx_ss_warehouse (warehouse_id, snapshot_date)
) ENGINE=InnoDB;
```

**設計判断 — PKにauto-increment `id` を使わない理由:**

- クエリは常に `(snapshot_date, snapshot_time, warehouse_id, item_id)` の組み合わせでアクセスする
- InnoDB のクラスタードインデックス = PK なので、複合PKにすることでクエリが直接PKスキャンになる
- 別途 `id` を持つと、全クエリがセカンダリインデックス → PKルックアップの2段階になり非効率
- 冪等性も PK の `DUPLICATE KEY` で自然に保証される
- パーティション（`snapshot_date`）との相性も良い（PKの先頭がパーティションキー）

##### テーブル2: `wms_stock_snapshot_lots`（ロット明細）

フロアプランと同じ粒度。「ロケーションCに賞味期限Dの商品が何個あったか」を記録。

```sql
CREATE TABLE wms_stock_snapshot_lots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning','evening') NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    real_stock_id BIGINT UNSIGNED NOT NULL,        -- トレーサビリティ用
    lot_id BIGINT UNSIGNED NOT NULL,               -- real_stock_lots.id
    location_id BIGINT UNSIGNED NULL,              -- ロケーション
    floor_id BIGINT UNSIGNED NULL,                 -- 当時のフロア
    expiration_date DATE NULL,                     -- 賞味期限
    purchase_id BIGINT UNSIGNED NULL,              -- 仕入PO
    current_quantity INT NOT NULL DEFAULT 0,       -- ロット現在庫数
    reserved_quantity INT NOT NULL DEFAULT 0,      -- ロット引当数
    price DECIMAL(10,2) NULL,                      -- 仕入単価
    real_stock_received_at DATETIME NULL,          -- real_stocks.received_at
    lot_created_at DATETIME NULL,                  -- real_stock_lots.created_at（FIFO検証用）
    captured_at DATETIME(6) NOT NULL,              -- 実取得時刻
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (snapshot_date, id),
    UNIQUE KEY uk_ssl_snapshot_lot (snapshot_date, snapshot_time, lot_id),
    INDEX idx_ssl_lookup (snapshot_date, snapshot_time, warehouse_id, item_id),
    INDEX idx_ssl_location (snapshot_date, snapshot_time, location_id),
    INDEX idx_ssl_id (id)
) ENGINE=InnoDB;
```

**設計判断 — ロット明細にauto-increment `id` を使う理由:**

- 自然キーが広い（snapshot_date + snapshot_time + lot_id で一意だが、lot_idが再利用される可能性がゼロとは言えない）
- CSV退避・S3アップロード時のページネーション（`WHERE id > :last_id`）に便利
- MySQLパーティション制約に対応するため、PKは `(snapshot_date, id)` とする
- 冪等性は `UNIQUE(snapshot_date, snapshot_time, lot_id)` で保証する
- アーカイブ時は `snapshot_date` で対象パーティションを絞り、パーティション内を `id` 順に処理する

**ロット明細に含めるカラムの根拠:**

| カラム | 含める理由 |
|--------|-----------|
| `real_stock_id` | 基幹在庫へのトレーサビリティ。差異発生時に元レコードを特定 |
| `lot_id` | ロット単位の追跡。フロアプランの表示と対応 |
| `location_id` | フロアプランの「どのロケーションに何があったか」を再現 |
| `floor_id` | ロケーションマスタ変更後も当時のフロアを再現 |
| `expiration_date` | FEFO引当の妥当性検証。賞味期限切れ調査 |
| `purchase_id` | 仕入・入荷由来の調査で元POを特定 |
| `current_quantity` | ロット単位の数量。サマリーとの整合性検証に使用 |
| `reserved_quantity` | ロット単位の引当。引当差異の調査 |
| `price` | 在庫金額の算出（棚卸評価）|
| `real_stock_received_at` | 基幹在庫の入庫日時を保持 |
| `lot_created_at` | 現行FEFO/FIFO実装の同一期限内ソート順を再現 |

**含めないカラムとその理由:**

| カラム | 除外理由 |
|--------|---------|
| `content_amount`, `container_amount` | 商品マスタ属性。時点で変化しないため `items` テーブルから取得可能 |
| `initial_quantity` | 初期値は変化しないため、`real_stock_lots` から直接取得可能 |
| `stock_allocation_id`, `client_id` | マスタ属性。時点で変化しない |
| `wms_lock_version` | WMS内部制御値。調査用途では不要 |

##### データ量見積もり（確定版）

**サマリーテーブル（15ヶ月保持）:**

| 項目 | 値 |
|------|-----|
| 1回あたり | ~50,000行（LOCAL実測）|
| 1日 | ~100,000行 |
| 15ヶ月 | **約4,600万行 / ~5GB（idx込）** |

**ロット明細テーブル（6ヶ月DB保持）:**

| 項目 | LOCAL (1:1) | 本番想定 (1:2) |
|------|------------|---------------|
| 1回あたり | 50,000行 | 100,000行 |
| 1日 | 100,000行 | 200,000行 |
| 6ヶ月 | 1,800万行 / 3.1GB | **3,700万行 / 6.2GB** |

**S3退避分（6ヶ月超〜15ヶ月のロット明細）:**

| 項目 | 値 |
|------|-----|
| 月別CSV (生) | ~870MB |
| 月別CSV (gzip) | **~130MB** |
| 9ヶ月分合計 (gzip) | **~1.2GB** |

**DB合計（最大同時保持）: ~11GB**

##### データ量対策

- **月次パーティション**: 両テーブルとも `snapshot_date` で RANGE パーティション
- **MySQL制約**: パーティションキー `snapshot_date` は全PK/UNIQUEキーに含める
- **サマリー**: 15ヶ月超のパーティションを `DROP`
- **ロット明細**: 6ヶ月超をCSV出力 → S3アップロード → パーティション `DROP`

**パーティション作成方針:**

- Laravel Schema Builder だけで完結させず、`DB::statement()` で月次RANGEパーティションDDLを発行する
- 初期作成時に当月〜16ヶ月先までのパーティションを作成する
- 月次アーカイブ処理で将来月パーティションを追加し、保持期限超過パーティションを削除する
- `DROP PARTITION` 前にS3退避済みであることを検証結果またはアーカイブログで確認する

#### 整合性検証

スナップショット取得後に以下の検証を実施。不一致があればログに記録しアラート。

- 検証1はサマリー/ロット明細INSERT後、同一取得処理内で実行する
- 検証2は取得トランザクション完了後に実行する
- 検証詳細は件数と代表例（最大100件）を `details` JSON に保存し、全件詳細は必要時にSQLで再調査する

##### 検証1: サマリー ↔ ロット明細の数量一致

```sql
-- ロット明細を warehouse_id × item_id で集計し、サマリーと比較
SELECT
    s.warehouse_id, s.item_id,
    s.current_quantity AS summary_current,
    COALESCE(l.lot_current, 0) AS lot_current,
    s.current_quantity - COALESCE(l.lot_current, 0) AS diff
FROM wms_stock_snapshots s
LEFT JOIN (
    SELECT snapshot_date, snapshot_time, warehouse_id, item_id,
           SUM(current_quantity) AS lot_current
    FROM wms_stock_snapshot_lots
    WHERE snapshot_date = :date AND snapshot_time = :time
    GROUP BY snapshot_date, snapshot_time, warehouse_id, item_id
) l ON s.snapshot_date = l.snapshot_date
    AND s.snapshot_time = l.snapshot_time
    AND s.warehouse_id = l.warehouse_id
    AND s.item_id = l.item_id
WHERE s.snapshot_date = :date AND s.snapshot_time = :time
HAVING diff != 0;
```

**不一致の原因**: `real_stocks.current_quantity` と `SUM(real_stock_lots.current_quantity)` がそもそも一致しないケースがある（基幹システム側の整合性問題）。この検証でその乖離も検出できる。

##### 検証2: スナップショット ↔ リアルタイムの乖離チェック

取得直後（取得トランザクション完了後、数秒以内）に再度 `real_stocks` を集計し、スナップショットとの差分を確認。大きな乖離があれば「取得直後に在庫変動があった」ことを示す。

```sql
-- 取得直後のリアルタイム値との差分
SELECT COUNT(*) AS mismatch_count,
       SUM(ABS(diff)) AS total_diff
FROM (
    SELECT s.warehouse_id, s.item_id,
           MAX(s.current_quantity) - COALESCE(SUM(rs.current_quantity), 0) AS diff
    FROM wms_stock_snapshots s
    LEFT JOIN real_stocks rs ON s.warehouse_id = rs.warehouse_id AND s.item_id = rs.item_id
    WHERE s.snapshot_date = :date AND s.snapshot_time = :time
    GROUP BY s.warehouse_id, s.item_id
) sub
WHERE diff != 0;
```

##### 検証3: 行数の妥当性チェック

```php
// 前回スナップショットとの行数比較（±20%を超えたら警告）
$currentCount = $summaryInserted;
$previousCount = WmsStockSnapshot::where('snapshot_date', $previousDate)
    ->where('snapshot_time', $time)->count();

if ($previousCount > 0) {
    $ratio = $currentCount / $previousCount;
    if ($ratio < 0.8 || $ratio > 1.2) {
        Log::warning("Snapshot row count anomaly: {$currentCount} vs previous {$previousCount}");
    }
}
```

##### 検証結果の記録

`wms_stock_snapshot_verifications` テーブルに記録:

```sql
CREATE TABLE wms_stock_snapshot_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning','evening') NOT NULL,
    summary_rows INT NOT NULL,
    lot_rows INT NOT NULL,
    summary_lot_mismatches INT NOT NULL DEFAULT 0,     -- 検証1: 不一致件数
    realtime_mismatches INT NOT NULL DEFAULT 0,        -- 検証2: リアルタイム乖離件数
    realtime_total_diff BIGINT NOT NULL DEFAULT 0,     -- 検証2: 乖離合計
    row_count_ratio DECIMAL(5,2) NULL,                 -- 検証3: 前回比
    is_healthy BOOLEAN NOT NULL DEFAULT TRUE,
    details JSON NULL,                                 -- 不一致の詳細（warehouse_id, item_id, diff）
    captured_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (snapshot_date, id),
    UNIQUE KEY uk_sv_date_time (snapshot_date, snapshot_time),
    INDEX idx_sv_id (id)
) ENGINE=InnoDB;
```

#### モデル変更

##### 新規: `app/Models/WmsStockSnapshot.php`

```php
class WmsStockSnapshot extends WmsModel
{
    protected $table = 'wms_stock_snapshots';
    public $incrementing = false; // 複合PK
    protected $primaryKey = null;

    protected $fillable = [
        'snapshot_date', 'snapshot_time',
        'warehouse_id', 'item_id',
        'current_quantity', 'reserved_quantity',
        'available_quantity', 'incoming_quantity',
        'stock_count', 'captured_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'captured_at' => 'datetime',
    ];
}
```

##### 新規: `app/Models/WmsStockSnapshotLot.php`

```php
class WmsStockSnapshotLot extends WmsModel
{
    protected $table = 'wms_stock_snapshot_lots';

    protected $fillable = [
        'snapshot_date', 'snapshot_time',
        'warehouse_id', 'item_id',
        'real_stock_id', 'lot_id', 'location_id', 'floor_id',
        'expiration_date', 'purchase_id',
        'current_quantity', 'reserved_quantity',
        'price',
        'real_stock_received_at', 'lot_created_at', 'captured_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'expiration_date' => 'date',
        'price' => 'decimal:2',
        'real_stock_received_at' => 'datetime',
        'lot_created_at' => 'datetime',
        'captured_at' => 'datetime',
    ];
}
```

#### サービス変更

##### 新規: `app/Services/StockSnapshotService.php`

```php
class StockSnapshotService
{
    public function capture(string $time = 'morning'): array
    {
        $date = now()->toDateString();
        $capturedAt = now();

        // 冪等性:
        // - サマリー: 複合PKで ON DUPLICATE KEY UPDATE
        // - ロット明細: UNIQUE(snapshot_date, snapshot_time, lot_id) で ON DUPLICATE KEY UPDATE
        //
        // 同一時点性:
        // - sakemaru接続で REPEATABLE READ トランザクションを開始
        // - サマリーとロット明細を同一 consistent read から INSERT ... SELECT
        // - 長時間化を避けるため、アプリ側で行を逐次取得せずDB内一括INSERTに限定
        //
        // 多重実行防止:
        // - GET_LOCK("wms:snapshot:{date}:{time}") を取得し、同一枠の並列実行を防止

        // 1. サマリー INSERT ... SELECT (real_stocks集計 + incomingのみ行も含める)
        // 2. ロット明細 INSERT ... SELECT (real_stock_lots WHERE status = 'ACTIVE')
        // 3. 整合性検証
        // 4. 検証結果を wms_stock_snapshot_verifications に記録

        return [
            'summary_rows' => $summaryCount,
            'lot_rows' => $lotCount,
            'verification' => $verificationResult,
        ];
    }

    public function archiveAndCleanup(): array
    {
        // 6ヶ月超のロット明細 → CSV出力 → S3アップロード → DB削除
        // 15ヶ月超のサマリー → パーティション DROP
        // 15ヶ月超の検証結果 → 削除
    }
}
```

**サマリー INSERT SQL:**

```sql
INSERT INTO wms_stock_snapshots
    (snapshot_date, snapshot_time, warehouse_id, item_id,
     current_quantity, reserved_quantity, available_quantity,
     incoming_quantity, stock_count, captured_at, created_at)
SELECT
    :date,
    :time,
    k.warehouse_id,
    k.item_id,
    COALESCE(stock.current_quantity, 0),
    COALESCE(stock.reserved_quantity, 0),
    COALESCE(stock.available_quantity, 0),
    COALESCE(inc.total_incoming, 0),
    COALESCE(stock.stock_count, 0),
    :captured_at,
    NOW()
FROM (
    SELECT warehouse_id, item_id
    FROM real_stocks
    WHERE current_quantity > 0 OR reserved_quantity > 0
    GROUP BY warehouse_id, item_id
    UNION
    SELECT warehouse_id, item_id
    FROM wms_order_incoming_schedules
    WHERE status IN ('PENDING', 'PARTIAL')
      AND expected_quantity > received_quantity
    GROUP BY warehouse_id, item_id
) k
LEFT JOIN (
    SELECT warehouse_id, item_id,
           SUM(current_quantity) AS current_quantity,
           SUM(reserved_quantity) AS reserved_quantity,
           SUM(available_quantity) AS available_quantity,
           COUNT(id) AS stock_count
    FROM real_stocks
    WHERE current_quantity > 0 OR reserved_quantity > 0
    GROUP BY warehouse_id, item_id
) stock ON stock.warehouse_id = k.warehouse_id
    AND stock.item_id = k.item_id
LEFT JOIN (
    SELECT warehouse_id, item_id,
           SUM(expected_quantity - received_quantity) AS total_incoming
    FROM wms_order_incoming_schedules
    WHERE status IN ('PENDING', 'PARTIAL')
      AND expected_quantity > received_quantity
    GROUP BY warehouse_id, item_id
) inc ON inc.warehouse_id = k.warehouse_id
    AND inc.item_id = k.item_id
WHERE COALESCE(stock.current_quantity, 0) > 0
   OR COALESCE(stock.reserved_quantity, 0) > 0
   OR COALESCE(inc.total_incoming, 0) > 0
ON DUPLICATE KEY UPDATE
    current_quantity = VALUES(current_quantity),
    reserved_quantity = VALUES(reserved_quantity),
    available_quantity = VALUES(available_quantity),
    incoming_quantity = VALUES(incoming_quantity),
    stock_count = VALUES(stock_count),
    captured_at = VALUES(captured_at);
```

**ロット明細 INSERT SQL:**

```sql
INSERT INTO wms_stock_snapshot_lots
    (snapshot_date, snapshot_time, warehouse_id, item_id,
     real_stock_id, lot_id, location_id, floor_id, expiration_date, purchase_id,
     current_quantity, reserved_quantity, price,
     real_stock_received_at, lot_created_at, captured_at, created_at)
SELECT
    :date, :time, rs.warehouse_id, rs.item_id,
    rs.id, rsl.id, rsl.location_id, rsl.floor_id, rsl.expiration_date, rsl.purchase_id,
    rsl.current_quantity, rsl.reserved_quantity, rsl.price,
    rs.received_at, rsl.created_at, :captured_at,
    NOW()
FROM real_stock_lots rsl
INNER JOIN real_stocks rs ON rs.id = rsl.real_stock_id
WHERE rsl.status = 'ACTIVE'
  AND (rsl.current_quantity > 0 OR rsl.reserved_quantity > 0)
ON DUPLICATE KEY UPDATE
    warehouse_id = VALUES(warehouse_id),
    item_id = VALUES(item_id),
    real_stock_id = VALUES(real_stock_id),
    location_id = VALUES(location_id),
    floor_id = VALUES(floor_id),
    expiration_date = VALUES(expiration_date),
    purchase_id = VALUES(purchase_id),
    current_quantity = VALUES(current_quantity),
    reserved_quantity = VALUES(reserved_quantity),
    price = VALUES(price),
    real_stock_received_at = VALUES(real_stock_received_at),
    lot_created_at = VALUES(lot_created_at),
    captured_at = VALUES(captured_at);
```

#### S3退避

##### CSV形式

```
snapshot_date,snapshot_time,warehouse_id,item_id,real_stock_id,lot_id,location_id,floor_id,expiration_date,purchase_id,current_quantity,reserved_quantity,price,real_stock_received_at,lot_created_at,captured_at
2026-05-04,morning,1,12345,99001,88001,5001,30,2026-08-15,77001,100,20,1500.00,2026-04-20 09:15:00,2026-04-20 09:16:00,2026-05-04 06:00:01.123456
```

##### S3パス

```
s3://{bucket}/wms-snapshots/lots/{YYYY}/{MM}/snapshot_lots_{YYYYMMDD}_{morning|evening}.csv.gz
```

##### 退避コマンド

```bash
php artisan wms:snapshot-archive          # 6ヶ月超のロット明細をS3退避 + DB削除
php artisan wms:snapshot-archive --dry-run # 対象件数の確認のみ
```

月次実行（月初に前月分を対象にはしない。6ヶ月超を対象）。対象抽出は `snapshot_date` で月次パーティションを限定し、パーティション内を `id` 昇順で分割出力する。

#### コマンド

##### 新規: `app/Console/Commands/SnapshotStocksCommand.php`

```bash
php artisan wms:snapshot-stocks                    # morning/evening 自動判定
php artisan wms:snapshot-stocks --time=morning     # 朝スナップショット
php artisan wms:snapshot-stocks --time=evening     # 夕スナップショット
```

##### 新規: `app/Console/Commands/SnapshotArchiveCommand.php`

```bash
php artisan wms:snapshot-archive                   # S3退避 + 古いデータ削除
php artisan wms:snapshot-archive --dry-run          # 確認のみ
```

##### スケジュール登録: `routes/console.php`

```php
Schedule::command('wms:snapshot-stocks --time=morning')->dailyAt('06:00');
Schedule::command('wms:snapshot-stocks --time=evening')->dailyAt('18:00');
Schedule::command('wms:snapshot-archive')->monthlyOn(1, '03:00');  // 毎月1日 3:00
```

**注意**: 現在 `routes/console.php` の既存スケジュールは全てコメントアウト中。Phase 1ではこの3つだけを有効化するのか、スケジューラ全体の再開タイミングに合わせるのかをリリース前に決める。

#### UI変更

##### 既存リソースの拡張または新規リソース

`WmsStockSnapshotResource`（閲覧専用）:

- **フィルター**: 日付、時間帯（morning/evening）、倉庫、商品CD/名
- **サマリー表示**: 倉庫CD、倉庫名、商品CD、商品名、現在庫数、引当済み数、利用可能数、入荷予定数
- **ロット明細展開**: サマリー行からドリルダウンでロット明細を表示（ロケーション、賞味期限、数量）
- **検証結果表示**: ヘルスステータス（正常/異常）、不一致件数
- **比較機能（Phase 2候補）**: 2つの時点を選択して差分を表示

### 影響範囲

| 機能 | 影響 |
|------|------|
| 既存テーブル | なし（旧 `wms_item_stock_snapshots` は変更しない）|
| スケジューラ | `routes/console.php` にスケジュール追加 |
| メガメニュー | スナップショット閲覧画面をメニューに追加 |
| パフォーマンス | INSERT ... SELECT で一括処理。6:00/18:00に1回ずつ（ピーク時間を避けている）|
| S3 | 新規バケット/パス使用。IAM権限設定が必要 |
| DBパーティション | 新規テーブルは月次RANGEパーティション前提。PK/UNIQUEに`snapshot_date`を含める |

## 制約

- **FK禁止**: `warehouse_id`, `item_id`, `location_id` 等は参照のみ（外部キー制約なし）
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 共有DB
- **冪等性必須**: サマリーは複合PK、ロット明細は `UNIQUE(snapshot_date, snapshot_time, lot_id)` で `ON DUPLICATE KEY UPDATE`
- **同一時点性必須**: サマリーとロット明細は同一 `REPEATABLE READ` トランザクション内の consistent read から取得
- **`real_stocks` テーブルへの書き込み禁止**: SELECT のみ
- **旧テーブル `wms_item_stock_snapshots` は変更しない**: 別目的の別テーブル

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_create_wms_stock_snapshots_table.php` — サマリーテーブル
- `database/migrations/XXXX_create_wms_stock_snapshot_lots_table.php` — ロット明細テーブル
- `database/migrations/XXXX_create_wms_stock_snapshot_verifications_table.php` — 検証結果テーブル
- `app/Models/WmsStockSnapshot.php` — サマリーモデル
- `app/Models/WmsStockSnapshotLot.php` — ロット明細モデル
- `app/Models/WmsStockSnapshotVerification.php` — 検証結果モデル
- `app/Services/StockSnapshotService.php` — 取得・検証・退避サービス
- `app/Console/Commands/SnapshotStocksCommand.php` — スナップショット取得コマンド
- `app/Console/Commands/SnapshotArchiveCommand.php` — S3退避コマンド
- `app/Filament/Resources/WmsStockSnapshotResource.php` — 閲覧用リソース
- `app/Filament/Resources/WmsStockSnapshot/Pages/ListWmsStockSnapshots.php` — 一覧ページ
- `app/Filament/Resources/WmsStockSnapshot/Tables/WmsStockSnapshotTable.php` — テーブル定義

### 既存変更
- `routes/console.php` — スケジュール追加
- `app/Enums/EMenu.php` — メニュー項目追加（必要に応じて）
- `config/filesystems.php` — S3ディスク設定（未設定の場合）

### 参照のみ
- `app/Models/WmsItemStockSnapshot.php` — 旧スナップショットモデル（参考のみ）
- `app/Models/Sakemaru/RealStock.php` — 在庫データ参照元
- `app/Models/Sakemaru/RealStockLot.php` — ロット参照元
- `database/migrations/2026_01_13_*_update_wms_v_stock_available_view_*.php` — ビュー定義参考

## 確認事項

1. ~~**保持期間**~~ → **決定**: サマリー15ヶ月、ロット明細6ヶ月DB + S3退避
2. **S3バケット/パス**: 既存のS3設定があるか？ 新規バケット作成が必要か？
3. **入荷予定の粒度**: `incoming_quantity` はサマリーに倉庫×商品の合計値で含める。仕入先別の内訳が必要な場合はPhase 2
4. ~~**旧テーブルのクリーンアップ**~~ → **決定**: 今回は `wms_item_stock_snapshots` を変更しない
5. **比較機能の優先度**: 2時点比較のUIはPhase 1に含めるか、Phase 2にするか
6. **スケジュール実行の前提**: `routes/console.php` のスケジュールは現在全てコメントアウト中。スケジューラ自体が有効化されている必要あり
7. **検証アラートの通知先**: 整合性不一致時の通知方法（ログのみ？ Slack？ メール？）
