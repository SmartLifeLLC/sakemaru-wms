# wms_item_stock_snapshots 廃止 作業計画

## 前提

- `remove-snapshot-dependency`（発注・移動候補画面からのスナップショット参照排除）が完了済み
- 発注候補・移動候補の手動作成画面は既に`wms_v_stock_available`を使用
- スナップショットの残りの使用箇所は自動発注計算サービスのみ

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | OrderCandidateCalculationService 直接クエリ化 | スナップショット参照→wms_v_stock_available直接読み込み | loadAllDataToMemoryにwms_item_stock_snapshots参照なし |
| P2 | 呼び出し元のスナップショット削除 | Job・Command・Handlerからスナップショット生成呼び出し削除 | StockSnapshotServiceのimportが全呼び出し元から消える |
| P3 | WmsAutoOrderJobControl・管理画面の整理 | findPendingSettlement変更、再生成ボタン削除 | STOCK_SNAPSHOTが新規生成されない |
| P4 | StockSnapshotService・コマンド削除 | 不要になったサービス・コマンドファイルを削除 | ファイルが存在しない |
| P5 | 動作確認・回帰テスト | grep確認・テスト実行 | スナップショット生成への依存が完全排除 |

---

## P1: OrderCandidateCalculationService 直接クエリ化

### 目的

`loadAllDataToMemory()`内のスナップショットテーブルからのSELECTを、`wms_v_stock_available` + `wms_order_incoming_schedules`からの直接クエリに置換する。

### 修正対象ファイル

- `app/Services/AutoOrder/OrderCandidateCalculationService.php`

### 修正内容

#### A. `$snapshotJobId` プロパティ・パラメータの削除

```php
// 削除するプロパティ（:83）
private ?int $snapshotJobId = null;

// calculate() から $snapshotJobId パラメータを削除（:94-99）
// 変更前
public function calculate(?int $snapshotJobId = null, ?int $contractorId = null, bool $transferOnly = false, ?int $warehouseId = null, ?int $createdBy = null): WmsAutoOrderJobControl

// 変更後
public function calculate(?int $contractorId = null, bool $transferOnly = false, ?int $warehouseId = null, ?int $createdBy = null): WmsAutoOrderJobControl

// startJob() 呼び出しから snapshotJobId 削除（:176）
// $this->snapshotJobId = $snapshotJobId; の行も削除（:182）
```

#### B. `loadAllDataToMemory()` 内のスナップショットクエリ置換（:274-316）

**変更前**: `wms_item_stock_snapshots`テーブルからJOIN+SELECTで読み込み

**変更後**: 2つの直接クエリで読み込み

```php
// ① 有効在庫を wms_v_stock_available から直接集計
// 注意: real_stock_id で重複排除が必要（ロット毎に行が複製される）
$effectiveStocks = DB::connection('sakemaru')
    ->selectRaw("
        SELECT warehouse_id, item_id, SUM(stock_qty) as total_effective
        FROM (
            SELECT DISTINCT warehouse_id, item_id, real_stock_id, available_for_wms as stock_qty
            FROM wms_v_stock_available
            WHERE warehouse_id IN ({$warehouseIdsList})
        ) dedup
        GROUP BY warehouse_id, item_id
    ");

// ② 入荷予定数を wms_order_incoming_schedules から集計
$incomingStocks = DB::connection('sakemaru')
    ->table('wms_order_incoming_schedules')
    ->whereIn('warehouse_id', $this->realWarehouseIds)
    ->whereIn('status', ['PENDING', 'PARTIAL'])
    ->selectRaw('warehouse_id, item_id, SUM(expected_quantity - received_quantity) as total_incoming')
    ->groupBy('warehouse_id', 'item_id')
    ->get();

// ③ $this->stockSnapshots に統合
foreach ($effectiveStocks as $s) {
    $this->stockSnapshots[$s->warehouse_id][$s->item_id] = [
        'effective' => (int) $s->total_effective,
        'incoming' => 0,
    ];
}
foreach ($incomingStocks as $s) {
    if (isset($this->stockSnapshots[$s->warehouse_id][$s->item_id])) {
        $this->stockSnapshots[$s->warehouse_id][$s->item_id]['incoming'] = (int) $s->total_incoming;
    } else {
        $this->stockSnapshots[$s->warehouse_id][$s->item_id] = [
            'effective' => 0,
            'incoming' => (int) $s->total_incoming,
        ];
    }
}
```

**重要**: 現在のスナップショットクエリは `item_contractors` とJOINして `is_auto_order=true` のみに絞っているが、新実装では全商品の在庫を読み込む。計算ループ側で既に`$this->itemContractors`（is_auto_order=true）でフィルタしているため問題なし。全商品を読み込む方がシンプルで、将来の拡張にも対応しやすい。

#### C. `$this->stockSnapshots` の使用箇所（変更不要）

`:595-597`（移動候補計算）と`:790-792`（発注候補計算）は`$this->stockSnapshots[warehouse_id][item_id]`を参照しており、データ構造`{effective, incoming}`が同じなので変更不要。

### 手順

1. `OrderCandidateCalculationService.php`を全文読み込み
2. `$snapshotJobId`プロパティ・パラメータを削除
3. `loadAllDataToMemory()`内のスナップショットクエリを直接クエリに置換
4. `startJob()`呼び出しから`snapshotJobId`引数を削除
5. 構文チェック（`php -l`）

### 完了条件

- `OrderCandidateCalculationService.php`に`wms_item_stock_snapshots`参照がない
- `$snapshotJobId`プロパティ・パラメータが削除されている
- `$this->stockSnapshots`のデータ構造が変わらない（`{effective, incoming}`）
- 構文エラーなし

---

## P2: 呼び出し元のスナップショット削除

### 目的

`StockSnapshotService::generateAll()`を呼んでいる全箇所から呼び出しを削除し、`calculate()`の新シグネチャに合わせる。

### 修正対象ファイル

1. `app/Jobs/ProcessOrderCandidateGenerationJob.php` — メインジョブ
2. `app/Console/Commands/AutoOrder/AutoOrderCalculateCommand.php` — CLIコマンド
3. `app/Services/AutoOrder/OrderCreateJobHandler.php` — 発注生成ハンドラ
4. `app/Services/AutoOrder/TransferCreateJobHandler.php` — 移動生成ハンドラ

### 修正内容

各ファイルで以下の変更を実施:

1. `StockSnapshotService`のimport・DI・呼び出しを削除
2. `$snapshotJob = $snapshotService->generateAll(...)` の行を削除
3. `$calculationService->calculate($snapshotJob->id, ...)` → `$calculationService->calculate(...)` に変更
4. 進捗メッセージの「スナップショット」関連テキストを更新

#### ProcessOrderCandidateGenerationJob（メイン）

```php
// 削除
$snapshotService = app(StockSnapshotService::class);
$snapshotJob = $snapshotService->generateAll($this->warehouseId, $this->createdBy);
$results['snapshot'] = $snapshotJob->processed_records;

// 変更
$calcJob = $calculationService->calculate($snapshotJob->id, $this->contractorId, $this->transferOnly, $this->warehouseId, $this->createdBy);
// ↓
$calcJob = $calculationService->calculate($this->contractorId, $this->transferOnly, $this->warehouseId, $this->createdBy);
```

進捗メッセージ: 「スナップショットを準備中...」「スナップショットを生成中...」→ 削除 or 「在庫データを読み込み中...」に変更。進捗パーセントも調整。

### 手順

1. 4ファイルを読み込み
2. 各ファイルのスナップショット呼び出しを削除
3. `calculate()`呼び出しの引数を新シグネチャに合わせる
4. 進捗メッセージを更新
5. 各ファイルの構文チェック

### 完了条件

- 4ファイルに`StockSnapshotService`のimportがない
- `calculate()`呼び出しに`$snapshotJobId`引数がない
- 構文エラーなし

---

## P3: WmsAutoOrderJobControl・管理画面の整理

### 目的

`STOCK_SNAPSHOT`ジョブが新規生成されないようモデルと管理画面を整理する。

### 修正対象ファイル

1. `app/Models/WmsAutoOrderJobControl.php`
2. `app/Filament/Resources/WmsItemStockSnapshots/Pages/ListWmsItemStockSnapshots.php`

### 修正内容

#### WmsAutoOrderJobControl.php

```php
// findPendingSettlement() — STOCK_SNAPSHOT を除外
// 変更前
->whereIn('process_name', [JobProcessName::STOCK_SNAPSHOT, JobProcessName::ORDER_CALC])
// 変更後
->where('process_name', JobProcessName::ORDER_CALC)

// hasPendingSettlement() — 同様に変更

// startJob() — $snapshotJobId パラメータを削除
// $fillable から 'snapshot_job_id' は残す（既存データ保持）
// snapshotJob() リレーションも残す（既存データ参照用）
```

#### ListWmsItemStockSnapshots.php

「スナップショット再生成」ボタン（`generateAll()`呼び出し）を削除。閲覧専用にする。

### 手順

1. `WmsAutoOrderJobControl.php`の`findPendingSettlement()`と`hasPendingSettlement()`を変更
2. `startJob()`の`$snapshotJobId`パラメータを削除
3. `ListWmsItemStockSnapshots.php`の再生成ボタンを削除
4. 構文チェック

### 完了条件

- `findPendingSettlement()`が`STOCK_SNAPSHOT`を含まない
- `startJob()`に`$snapshotJobId`がない
- スナップショット管理画面に再生成ボタンがない
- 構文エラーなし

---

## P4: StockSnapshotService・コマンド削除

### 目的

使用箇所がなくなった`StockSnapshotService`と`SnapshotStocksCommand`を削除する。

### 削除対象ファイル

1. `app/Services/AutoOrder/StockSnapshotService.php`
2. `app/Console/Commands/AutoOrder/SnapshotStocksCommand.php`

### 手順

1. `StockSnapshotService`が他でimportされていないことをgrepで確認
2. `SnapshotStocksCommand`が他で参照されていないことを確認
3. 2ファイルを削除
4. Laravelのコマンドキャッシュ等が影響しないか確認

### 完了条件

- 2ファイルが存在しない
- `grep -rn 'StockSnapshotService' app/` が0件
- `grep -rn 'SnapshotStocksCommand' app/` が0件
- `php artisan` がエラーなく実行可能

---

## P5: 動作確認・回帰テスト

### 目的

全変更の統合確認。スナップショット生成への依存が完全排除されていることを保証。

### 手順

1. **grep確認**:
   ```bash
   grep -rn 'StockSnapshotService' app/
   grep -rn 'SnapshotStocksCommand' app/
   grep -rn 'snapshotJobId' app/
   grep -rn 'snapshot_job_id' app/Services/ app/Jobs/  # モデル以外で参照がないこと
   ```

2. **テスト実行**:
   ```bash
   php artisan test --filter=Order
   php artisan test --filter=Transfer
   php artisan test --filter=Calculation
   ```

3. **コマンド確認**:
   ```bash
   php artisan list | grep wms  # snapshot-stocks が消えていること
   ```

4. **構文チェック**:
   ```bash
   php -l app/Services/AutoOrder/OrderCandidateCalculationService.php
   php -l app/Jobs/ProcessOrderCandidateGenerationJob.php
   # 全変更ファイル
   ```

### 完了条件

- `StockSnapshotService`への参照が`app/`内に0件（参照のみファイル除く）
- `snapshotJobId`がサービス・ジョブファイルに0件
- テスト全パス（または今回の変更と無関係の失敗のみ）
- `php artisan`がエラーなし

---

## 制約（厳守）

1. **`migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` 禁止** — 共有DB
2. **FK禁止** — 全リレーションはアプリケーションレベル
3. **`wms_item_stock_snapshots`テーブルは削除しない** — 既存データ参照用
4. **`STOCK_SNAPSHOT` enumは削除しない** — 既存ジョブレコード表示に必要
5. **`snapshot_job_id`カラムは削除しない** — 既存データ整合性
6. **重複排除必須** — `wms_v_stock_available`からの集計時、`real_stock_id`で重複排除（StockSnapshotServiceのDISTINCTロジックを踏襲）

## 全体完了条件

1. `grep -rn 'StockSnapshotService' app/` → Models・Resources（閲覧用）以外で0件
2. `grep -rn 'snapshotJobId' app/` → 0件
3. `OrderCandidateCalculationService`が`wms_v_stock_available` + `wms_order_incoming_schedules`から直接読み込み
4. `StockSnapshotService.php`と`SnapshotStocksCommand.php`が削除済み
5. スナップショット管理画面が閲覧専用（再生成ボタンなし）
6. テスト全パス
