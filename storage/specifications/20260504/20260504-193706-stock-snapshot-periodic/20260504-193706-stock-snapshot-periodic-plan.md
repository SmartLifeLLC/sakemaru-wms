# 定期在庫スナップショット 作業計画

## 前提

- 仕様書: `20260504-193706-stock-snapshot-periodic.md`（同ディレクトリ）
- ローカルDB に在庫データあり（real_stocks: 50,566行、real_stock_lots: 50,566行）
- 旧スナップショット機能（wms_item_stock_snapshots + StockSnapshotService）は 2026-04-05 に廃止済み
- 旧テーブルは変更しない。新テーブルで新設計

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | マイグレーション | 3テーブル作成 + 月次パーティション | `php artisan migrate` 成功、テーブル3つ確認 |
| P1 | モデル | Eloquent モデル3つ | モデル作成、tinkerで基本操作確認 |
| P2 | スナップショット取得サービス | capture + 検証ロジック | サービスクラス作成、ユニットレベルのロジック確認 |
| P3 | 取得コマンド + ローカルテスト | Artisanコマンド + 実データ実行 | ローカルDBでスナップショット取得・検証通過 |
| P4 | S3退避サービス + コマンド | CSV出力 + S3アップロード + パーティション削除 + 将来パーティション確保 | ローカルでCSV出力テスト成功、manifest検証成功 |
| P5 | Filament UI | 閲覧用リソース + ドリルダウン | ブラウザでサマリー表示・ロット明細展開確認 |
| P6 | スケジュール + メニュー統合 | cron登録 + メガメニュー追加 | スケジュール登録確認、メニューから遷移確認 |

---

## P0: マイグレーション

### 目的

3つの新テーブルを月次パーティション付きで作成する。

### 作成するテーブル

#### 1. `wms_stock_snapshots`（サマリー）

```sql
CREATE TABLE wms_stock_snapshots (
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning','evening') NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    current_quantity INT NOT NULL DEFAULT 0,
    reserved_quantity INT NOT NULL DEFAULT 0,
    available_quantity INT NOT NULL DEFAULT 0,
    incoming_quantity INT NOT NULL DEFAULT 0,
    stock_count INT UNSIGNED NOT NULL DEFAULT 0,
    captured_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (snapshot_date, snapshot_time, warehouse_id, item_id),
    INDEX idx_ss_item (item_id, snapshot_date),
    INDEX idx_ss_warehouse (warehouse_id, snapshot_date)
) ENGINE=InnoDB;
```

- 複合PK（`id` なし）— クラスタードインデックス直撃
- 月次RANGEパーティション: 当月〜16ヶ月先

#### 2. `wms_stock_snapshot_lots`（ロット明細）

```sql
CREATE TABLE wms_stock_snapshot_lots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning','evening') NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    real_stock_id BIGINT UNSIGNED NOT NULL,
    lot_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    floor_id BIGINT UNSIGNED NULL,
    expiration_date DATE NULL,
    purchase_id BIGINT UNSIGNED NULL,
    current_quantity INT NOT NULL DEFAULT 0,
    reserved_quantity INT NOT NULL DEFAULT 0,
    price DECIMAL(10,2) NULL,
    real_stock_received_at DATETIME NULL,
    lot_created_at DATETIME NULL,
    captured_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (snapshot_date, id),
    UNIQUE KEY uk_ssl_snapshot_lot (snapshot_date, snapshot_time, lot_id),
    INDEX idx_ssl_lookup (snapshot_date, snapshot_time, warehouse_id, item_id),
    INDEX idx_ssl_location (snapshot_date, snapshot_time, location_id),
    INDEX idx_ssl_id (id)
) ENGINE=InnoDB;
```

- PK: `(snapshot_date, id)` — MySQL パーティション制約対応
- 冪等性: UNIQUE `(snapshot_date, snapshot_time, lot_id)`
- 月次RANGEパーティション

#### 3. `wms_stock_snapshot_verifications`（検証結果）

```sql
CREATE TABLE wms_stock_snapshot_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_date DATE NOT NULL,
    snapshot_time ENUM('morning','evening') NOT NULL,
    summary_rows INT NOT NULL,
    lot_rows INT NOT NULL,
    summary_lot_mismatches INT NOT NULL DEFAULT 0,
    realtime_mismatches INT NOT NULL DEFAULT 0,
    realtime_total_diff BIGINT NOT NULL DEFAULT 0,
    row_count_ratio DECIMAL(5,2) NULL,
    is_healthy BOOLEAN NOT NULL DEFAULT TRUE,
    details JSON NULL,
    captured_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (snapshot_date, id),
    UNIQUE KEY uk_sv_date_time (snapshot_date, snapshot_time),
    INDEX idx_sv_id (id)
) ENGINE=InnoDB;
```

### パーティション

3テーブルとも `DB::statement()` で月次RANGEパーティションを作成:

```php
// 当月(2026-05)〜16ヶ月先(2027-09)
$partitions = [];
$start = Carbon::now()->startOfMonth();
for ($i = 0; $i <= 16; $i++) {
    $month = $start->copy()->addMonths($i);
    $next = $month->copy()->addMonth();
    $name = 'p' . $month->format('Ym');
    $partitions[] = "PARTITION {$name} VALUES LESS THAN (TO_DAYS('{$next->format('Y-m-d')}'))";
}
$partitionDDL = implode(",\n", $partitions);

DB::connection('sakemaru')->statement("ALTER TABLE wms_stock_snapshots PARTITION BY RANGE (TO_DAYS(snapshot_date)) ({$partitionDDL})");
```

### 将来パーティション確保

初期作成だけで終わらせない。将来月のパーティション追加漏れを防ぐため、以下を実装する:

- `StockSnapshotService::ensureFuturePartitions(int $monthsAhead = 16)` を作成
- `wms:snapshot-stocks` の capture 開始前に対象日を含むパーティションがあることを確認し、不足があれば先に追加
- `wms:snapshot-archive` の月次実行時にも 16ヶ月先までのパーティションを補充
- `INFORMATION_SCHEMA.PARTITIONS` で既存パーティションを確認し、存在するパーティションは再作成しない
- パーティション追加処理は `GET_LOCK("wms:snapshot:partition-maintenance")` で直列化する
- `ALTER TABLE ... ADD PARTITION` は各テーブル単位で実行し、失敗時はスナップショット取得を開始しない

### 手順

1. `php artisan make:migration create_wms_stock_snapshots_table`
2. `php artisan make:migration create_wms_stock_snapshot_lots_table`
3. `php artisan make:migration create_wms_stock_snapshot_verifications_table`
4. 各マイグレーションに `Schema::connection('sakemaru')` でテーブル作成 + `DB::statement()` でパーティション作成
5. `php artisan migrate` 実行

### 完了条件

- `php artisan migrate` がエラーなく完了
- `SHOW CREATE TABLE wms_stock_snapshots` でパーティション確認
- `SHOW CREATE TABLE wms_stock_snapshot_lots` でパーティション確認
- `SHOW CREATE TABLE wms_stock_snapshot_verifications` でパーティション確認
- `ensureFuturePartitions()` の実装方針がP2/P4に反映されている

---

## P1: モデル

### 目的

3つの Eloquent モデルを作成する。

### 作成するモデル

#### 1. `app/Models/WmsStockSnapshot.php`

```php
class WmsStockSnapshot extends WmsModel
{
    protected $table = 'wms_stock_snapshots';
    public $incrementing = false;
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

    // リレーション: warehouse(), item()
    // スコープ: scopeForDate($date), scopeMorning(), scopeEvening()
}
```

#### 2. `app/Models/WmsStockSnapshotLot.php`

```php
class WmsStockSnapshotLot extends WmsModel
{
    protected $table = 'wms_stock_snapshot_lots';

    protected $fillable = [
        'snapshot_date', 'snapshot_time',
        'warehouse_id', 'item_id',
        'real_stock_id', 'lot_id', 'location_id', 'floor_id',
        'expiration_date', 'purchase_id',
        'current_quantity', 'reserved_quantity', 'price',
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

    // リレーション: warehouse(), item(), location()
}
```

#### 3. `app/Models/WmsStockSnapshotVerification.php`

```php
class WmsStockSnapshotVerification extends WmsModel
{
    protected $table = 'wms_stock_snapshot_verifications';

    protected $fillable = [
        'snapshot_date', 'snapshot_time',
        'summary_rows', 'lot_rows',
        'summary_lot_mismatches', 'realtime_mismatches', 'realtime_total_diff',
        'row_count_ratio', 'is_healthy', 'details',
        'captured_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'is_healthy' => 'boolean',
        'details' => 'array',
        'row_count_ratio' => 'decimal:2',
        'captured_at' => 'datetime',
    ];
}
```

### 完了条件

- 3モデルが作成されている
- `php artisan tinker` で `WmsStockSnapshot::query()->toSql()` 等が正常動作

---

## P2: スナップショット取得サービス

### 目的

`StockSnapshotService` を作成し、スナップショット取得・検証・結果記録のコアロジックを実装する。

### 作成ファイル

`app/Services/StockSnapshotService.php`

### 実装内容

#### capture(string $time = 'morning'): array

1. **将来パーティション確認**: `ensureFuturePartitions()` を実行し、対象日のパーティションがなければ追加
2. **GET_LOCK** で多重実行防止: `GET_LOCK("wms:snapshot:{date}:{time}", 10)`
3. **try/catch/finally 開始**
   - トランザクション開始後に例外が出た場合は必ず `rollBack()`
   - `GET_LOCK` 取得後は、検証中の例外も含めて finally で必ず `RELEASE_LOCK`
   - `commit()` 済みかどうかをフラグで管理し、二重rollbackを避ける
4. **REPEATABLE READ トランザクション開始**（sakemaru 接続）
5. **サマリー INSERT ... SELECT**: 仕様書の SQL をそのまま実装
   - `real_stocks` GROUP BY warehouse_id, item_id
   - `wms_order_incoming_schedules` LEFT JOIN（入荷予定のみの行も含む UNION キーセット）
   - `ON DUPLICATE KEY UPDATE` で冪等性
6. **ロット明細 INSERT ... SELECT**: 仕様書の SQL をそのまま実装
   - `real_stock_lots` INNER JOIN `real_stocks`
   - `WHERE status = 'ACTIVE' AND (current_quantity > 0 OR reserved_quantity > 0)`
   - `ON DUPLICATE KEY UPDATE` で冪等性
7. **トランザクション COMMIT**
8. **対象行数をCOUNTで取得**
   - `DB::statement()` の戻り値は件数として使わない
   - `ON DUPLICATE KEY UPDATE` のaffected rowsも使用しない
   - 実行後に `snapshot_date + snapshot_time` で `wms_stock_snapshots` / `wms_stock_snapshot_lots` を `COUNT(*)`
9. **整合性検証** (トランザクション外)
   - 検証1: サマリー ↔ ロット明細の数量一致
   - 検証2: スナップショット ↔ リアルタイム値の乖離チェック
   - 検証3: 前回スナップショットとの行数比較（±20%）
10. **検証結果を `wms_stock_snapshot_verifications` に upsert**
    - `UNIQUE(snapshot_date, snapshot_time)` があるため単純INSERTは禁止
    - `updateOrInsert()` または `ON DUPLICATE KEY UPDATE` で再実行時も上書き
11. **RELEASE_LOCK**（finally）
12. 結果を返す: `['summary_rows' => int, 'lot_rows' => int, 'verification' => array]`

#### 注意点

- `captured_at` はトランザクション開始前に `now()` で取得し、サマリー・ロット明細の両方に同じ値を使用
- `real_stocks.received_at` が存在しない環境では `real_stock_received_at` はNULLで保存し、FIFO検証は `lot_created_at` を使用する
- INSERT ... SELECT の SQL は `DB::connection('sakemaru')->statement()` で実行（Eloquent ではなく直接SQL）
- `ON DUPLICATE KEY UPDATE` で `VALUES()` 構文を使用（MySQL 8.0 互換）
- 件数表示と戻り値の `summary_rows` / `lot_rows` は実行後COUNTで算出する
- 検証結果も冪等にupsertし、同一 morning/evening の再実行で重複キーエラーを出さない
- 検証1の不一致は基幹システム側の `real_stocks` ↔ `SUM(real_stock_lots)` の乖離も検出する

### 完了条件

- `StockSnapshotService` が作成されている
- capture メソッドのロジックが仕様書の SQL と一致している
- 検証ロジック3種が実装されている
- GET_LOCK / RELEASE_LOCK が実装され、例外時も finally で解放される
- トランザクション例外時に rollback される
- 検証結果が upsert で記録される
- 実行件数が `COUNT(*)` で算出される

---

## P3: 取得コマンド + ローカルテスト

### 目的

Artisan コマンドを作成し、ローカルDBの実データでスナップショットを取得・検証する。

### 作成ファイル

`app/Console/Commands/SnapshotStocksCommand.php`

### コマンド仕様

```bash
php artisan wms:snapshot-stocks                    # morning/evening 自動判定（12時前→morning, 12時以降→evening）
php artisan wms:snapshot-stocks --time=morning     # 朝スナップショット強制
php artisan wms:snapshot-stocks --time=evening     # 夕スナップショット強制
```

### 出力

```
[2026-05-04 19:37:06] Starting stock snapshot (morning)...
[2026-05-04 19:37:08] Summary: 50,566 rows
[2026-05-04 19:37:10] Lot details: 50,566 rows
[2026-05-04 19:37:11] Verification:
  - Summary ↔ Lot: 0 mismatches ✓
  - Realtime drift: 0 mismatches (total diff: 0) ✓
  - Row count ratio: 1.00 (vs previous: N/A) ✓
[2026-05-04 19:37:11] Snapshot completed in 5.2s
```

### ローカルテスト手順

1. `php artisan wms:snapshot-stocks --time=morning` を実行
2. 結果確認:
   - サマリー行数が `real_stocks(current_quantity > 0 OR reserved_quantity > 0)` と `wms_order_incoming_schedules(status IN PENDING/PARTIAL かつ expected_quantity > received_quantity)` の UNION キーセット件数と一致
   - ロット行数が real_stock_lots (ACTIVE, qty > 0) と一致
   - 検証1: 不一致0件
   - 検証2: 乖離が軽微（数秒以内の変動のみ）
   - 検証3: 初回は前回なしでスキップ
3. 2回目実行（冪等性テスト）:
   - `php artisan wms:snapshot-stocks --time=morning` を再実行
   - `ON DUPLICATE KEY UPDATE` で上書き。エラーなし
4. evening も実行:
   - `php artisan wms:snapshot-stocks --time=evening`
   - 別のスナップショットとして記録される
5. tinker で確認:
   ```php
   WmsStockSnapshot::where('snapshot_date', today())->count();
   WmsStockSnapshotLot::where('snapshot_date', today())->count();
   WmsStockSnapshotVerification::where('snapshot_date', today())->get();
   ```

### 完了条件

- コマンドがエラーなく実行完了
- サマリー・ロット明細の行数が実データと整合
- 検証1（サマリー↔ロット）の結果が記録される。基幹側既存不整合がある場合は mismatches > 0 でも詳細が `details` に保存される
- 冪等性テスト（2回目実行）がエラーなし
- 2回目実行で `wms_stock_snapshot_verifications` が重複キーエラーにならず、同一 date/time の検証結果が更新される
- morning / evening 両方が別レコードとして記録される

---

## P4: S3退避サービス + コマンド

### 目的

6ヶ月超のロット明細をCSV出力 → S3アップロード → manifest検証 → パーティション削除する機能を実装する。

### 作成ファイル

- `app/Console/Commands/SnapshotArchiveCommand.php`
- `StockSnapshotService` に `archiveAndCleanup()` メソッドを追加

### コマンド仕様

```bash
php artisan wms:snapshot-archive                   # S3退避 + 検証済みパーティション削除
php artisan wms:snapshot-archive --dry-run          # 対象件数の確認のみ
```

### 実装内容

#### archiveAndCleanup(): array

1. **将来パーティション確保**: `ensureFuturePartitions()` を実行し、16ヶ月先まで補充
2. **対象月の特定**: `snapshot_date < now()->subMonths(6)->startOfMonth()`
3. **月別にCSV出力**:
   - `snapshot_date` でパーティション限定
   - `id` 昇順でチャンク処理（10,000行ずつ）
   - CSV形式: 仕様書記載のヘッダー + データ
4. **gzip圧縮**
5. **manifest作成**:
   - 対象年月、snapshot_date/time、CSV行数、DB対象行数、gzipバイト数、SHA-256 checksum、S3キー、作成時刻をJSONで記録
   - S3パス: `wms-snapshots/lots/{YYYY}/{MM}/manifest_{YYYYMM}.json`
6. **S3アップロード**:
   - CSV: `wms-snapshots/lots/{YYYY}/{MM}/snapshot_lots_{YYYYMMDD}_{morning|evening}.csv.gz`
   - manifest: `wms-snapshots/lots/{YYYY}/{MM}/manifest_{YYYYMM}.json`
7. **S3検証**:
   - アップロード後に `exists()` / `size()` / checksum再計算可能なローカル一時ファイルとの照合を行う
   - CSV行数とDB対象行数が一致しない場合は削除処理に進まない
8. **パーティション削除**:
   - 通常経路は `DROP PARTITION` のみ
   - `DELETE WHERE snapshot_date < ...` は通常運用では禁止。パーティション未対応環境の緊急退避時のみ、明示オプションと小分け `LIMIT` 付きで実行する
9. **15ヶ月超のサマリー削除**: 検証不要の期限切れパーティションを DROP
10. **15ヶ月超の検証結果削除**: 検証不要の期限切れパーティションを DROP

### S3設定

既存の `config/filesystems.php` に `s3` ディスクが定義済みのため、原則として `Storage::disk('s3')` を使用する。新規ディスク追加は不要。

### ローカルテスト

ローカルでは S3 の代わりにローカルディスクに出力してテスト:
```bash
php artisan wms:snapshot-archive --dry-run  # 対象件数確認
php artisan wms:snapshot-archive --disk=local  # ローカル出力テスト（オプション追加を検討）
```

### 完了条件

- `--dry-run` で対象件数が正しく表示される
- CSV出力のフォーマットが仕様書と一致
- gzip圧縮が正常に動作
- manifest JSON が生成され、行数・サイズ・checksumを確認できる
- アップロード先パスが仕様書のS3パス形式に準拠
- S3検証が成功した場合のみ `DROP PARTITION` が実行される
- `ensureFuturePartitions()` で16ヶ月先までのパーティションが補充される

---

## P5: Filament UI

### 目的

スナップショットの閲覧用 Filament リソースを作成する。

### 作成ファイル

- `app/Filament/Resources/WmsStockSnapshotResource.php`
- `app/Filament/Resources/WmsStockSnapshot/Pages/ListWmsStockSnapshots.php`
- `app/Filament/Resources/WmsStockSnapshot/Tables/WmsStockSnapshotTable.php`

### UI仕様

#### 一覧テーブル（サマリー）

- **デフォルト**: 最新の snapshot_date + snapshot_time でフィルタ
- **フィルター**: 日付ピッカー、時間帯（morning/evening）、倉庫、商品CD/名
- **カラム**: 倉庫CD、倉庫名、商品CD、商品名、現在庫数、引当済み数、利用可能数、入荷予定数
- **閲覧専用**: Create/Edit/Delete/Bulk Delete は実装しない。Filament Resource/Page/Table で作成・編集・削除導線を出さない

#### ロット明細ドリルダウン

- サマリー行のレコードアクションからモーダルで表示
- `incoming-detail-modal` クラス、`modalWidth('5xl')`
- ロケーション、賞味期限、数量、仕入単価を表示

#### 検証ステータス

- ページ上部にヘルスバッジ（正常: green / 異常: red）
- 不一致件数のサマリー表示

### デザインルール

- `~/.claude/design-knowledge/modal-design.md` 準拠
- `storage/specifications/old/table-design-specification.md` 準拠
- コード系は「CD」表記、`sticky-actions` クラス、ストライプ行

### 完了条件

- ブラウザで `admin/wms-stock-snapshots` にアクセスできる
- サマリーテーブルが正しくデータ表示される
- フィルター（日付、倉庫、商品）が動作する
- ロット明細モーダルが開き、データが表示される
- 検証ステータスが表示される
- Create/Edit/Delete/Bulk Delete の導線が表示されない

---

## P6: スケジュール + メニュー統合

### 目的

スケジュール登録とメガメニューへの追加を行う。

### 変更ファイル

- `routes/console.php`
- `app/Enums/EMenu.php`

### スケジュール

```php
Schedule::command('wms:snapshot-stocks --time=morning')->dailyAt('06:00');
Schedule::command('wms:snapshot-stocks --time=evening')->dailyAt('18:00');
Schedule::command('wms:snapshot-archive')->monthlyOn(1, '03:00');
```

**リリース判断事項**: 現在 `routes/console.php` の既存スケジュールは全てコメントアウト中。Phase 1では新規スケジュールをコメント状態で追加し、実運用有効化はリリース時に明示判断する。有効化する場合は `withoutOverlapping()` / `onOneServer()` / `appendOutputTo()` を付ける。

### メニュー

`EMenu.php` にスナップショット閲覧のメニュー項目を追加。メガメニュー仕様（`~/.claude/design-knowledge/mega-menu.md`）に準拠。

### 完了条件

- `php artisan schedule:list` でスナップショットコマンドが表示される
- メガメニューからスナップショット画面に遷移できる

---

## 制約（厳守）

1. **FK禁止**: 全リレーションはアプリケーションレベル管理
2. **migrate:fresh/refresh/reset/db:wipe 禁止**: 基幹システム共有DB
3. **`real_stocks` / `real_stock_lots` への書き込み禁止**: SELECT のみ
4. **同一時点性**: サマリーとロット明細は同一 REPEATABLE READ 内で取得
5. **冪等性**: ON DUPLICATE KEY UPDATE で重複実行に対応
6. **多重実行防止**: GET_LOCK で並列実行を防止
7. **旧テーブル変更禁止**: `wms_item_stock_snapshots` は触らない
8. **Filament 4 パターン準拠**: `Filament\Schemas\Components\Section` 等の正しいインポートパス
9. **大量DELETE禁止**: アーカイブ後削除は原則 `DROP PARTITION`。通常運用で数千万行DELETEを実行しない

## 全体完了条件

1. `php artisan wms:snapshot-stocks` でスナップショットが正常取得される
2. 整合性検証3種が実行され、正常/異常と詳細が `wms_stock_snapshot_verifications` に記録される
3. `php artisan wms:snapshot-archive --dry-run` が正常動作する
4. ブラウザでスナップショット一覧 → ロット明細ドリルダウンが動作する
5. メガメニューからスナップショット画面に遷移できる
6. スケジュールが登録されている
