# wms_item_stock_snapshots 廃止 — 自動発注計算の直接クエリ化

- **作成日**: 2026-04-05
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/20260405/20260405-135655-abolish-stock-snapshot/
- **前提**: `remove-snapshot-dependency`（発注・移動候補画面からのスナップショット参照排除）が完了済み

## 背景・目的

### 現状の問題

1. **テーブル肥大化**: `wms_item_stock_snapshots`にジョブ実行ごとに`倉庫数×商品数`のレコードがINSERT。33倉庫×11,500商品で1回約28,000行。日次で年間1,000万行・2.5GB。削除ロジックなし。
2. **2段階ジョブの冗長性**: 自動発注は「スナップショット生成」→「候補計算」の2段階。スナップショットは一時テーブルと同じ役割で、永続化する意味が薄い。
3. **一貫性の幻想**: スナップショットは「計算開始時点の在庫固定」が目的だが、日次夜間実行が基本であり、計算中の在庫変動はほぼ発生しない。

### 目的

- `StockSnapshotService`を廃止し、`OrderCandidateCalculationService`が`wms_v_stock_available` + `wms_order_incoming_schedules`から直接メモリに読み込むよう変更
- `STOCK_SNAPSHOT`ジョブプロセスを廃止
- 2段階ジョブ → 1段階に簡素化
- `wms_item_stock_snapshots`テーブルのデータは段階的にクリーンアップ（テーブル削除は次フェーズ）

## 現状の実装

### 自��発注計算フロー（現在）

```
ProcessOrderCandidateGenerationJob
  ├── Step 1: StockSnapshotService::generateAll()
  │     └── INSERT INTO wms_item_stock_snapshots SELECT FROM wms_v_stock_available + wms_order_incoming_schedules
  │     └── WmsAutoOrderJobControl (process_name=STOCK_SNAPSHOT) を作成
  │
  └── Step 2: OrderCandidateCalculationService::calculate($snapshotJobId)
        └── loadAllDataToMemory()
              └── SELECT FROM wms_item_stock_snapshots WHERE job_control_id = $snapshotJobId
              └── → $this->stockSnapshots[warehouse_id][item_id] = {effective, incoming}
        └── createInternalTransferCandidatesBulk() — $this->stockSnapshots を参照
        ��── createExternalOrderCandidatesBulk() — $this->stockSnapshots を参照
```

### StockSnapshotService の生成SQL要約

`wms_v_stock_available`から有効在庫を集計 + `wms_order_incoming_schedules`から入荷予定数を集計し、`wms_item_stock_snapshots`にINSERT。

**データ内容:**
- `warehouse_id`, `item_id`
- `total_effective_piece`: 有効在庫（`available_for_wms`の合計、`real_stock_id`で重複排除）
- `total_incoming_piece`: 入荷予定残数（PENDING/PARTIALの `expected - received` 合計）

### StockSnapshotService の呼び出し箇所

| # | ファイル | 行 | 用途 |
|---|---------|-----|------|
| 1 | `ProcessOrderCandidateGenerationJob.php` | :119-120 | 自動発注ジョブ（メイン） |
| 2 | `AutoOrderCalculateCommand.php` | :63 | CLIコマンド `wms:auto-order-calculate` |
| 3 | `OrderCreateJobHandler.php` | :157 | 発注生成ジ���ブハンドラ |
| 4 | `TransferCreateJobHandler.php` | :157 | 移動生成ジョブハンドラ |
| 5 | `ListWmsItemStockSnapshots.php` | :50-51 | 管理画面「スナップショット再生成」ボタン |

### OrderCandidateCalculationService のスナップショット参照

`loadAllDataToMemory()`内（:274-316）:
```php
$snapshotQuery = DB::connection('sakemaru')
    ->table('wms_item_stock_snapshots as s')
    ->join('item_contractors as ic', ...)
    ->whereIn('s.warehouse_id', $this->realWarehouseIds)
    ->where('ic.is_auto_order', true)
    ->where('ic.safety_stock', '>=', 0);

if ($this->snapshotJobId) {
    $snapshotQuery->where('s.job_control_id', $this->snapshotJobId);
} else {
    // 最新のSTOCK_SNAPSHOT��ョブを使用
}

// → $this->stockSnapshots[warehouse_id][item_id] = {effective, incoming}
```

使用箇所:
- `:595-597` — 移動候補計算（INTERNAL）
- `:790-792` — 発注候補計算（EXTERNAL）

## 変更内容

### 概要

1. `OrderCandidateCalculationService`がスナップショットテーブルではなく`wms_v_stock_available` + `wms_order_incoming_schedules`から直接メモリに読み込む
2. `StockSnapshotService`の呼び出しを全箇所から削除
3. `calculate()`の`$snapshotJobId`パラメータを廃止
4. `STOCK_SNAPSHOT`ジョブプロセスを廃止（管理画面・enum）

### 詳細設計

#### 1. OrderCandidateCalculationService の変更

`loadAllDataToMemory()`内のスナップショットクエリを、`StockSnapshotService.generateSnapshots()`と同等のSELECTクエリに置換。

**変更前（:274-316）:**
```php
// wms_item_stock_snapshots から読み込み
$snapshotQuery = DB::connection('sakemaru')
    ->table('wms_item_stock_snapshots as s')
    ->join('item_contractors as ic', ...)
    ->where('s.job_control_id', $this->snapshotJobId);
```

**変更後:**
```php
// wms_v_stock_available から有効在庫を直接集計
$effectiveStocks = DB::connection('sakemaru')
    ->table('wms_v_stock_available')
    ->whereIn('warehouse_id', $this->realWarehouseIds)
    ->selectRaw('warehouse_id, item_id, SUM(available_for_wms) as total_effective')
    ->groupBy('warehouse_id', 'item_id')
    ->get();

// wms_order_incoming_schedules から入荷予定数を集計
$incomingStocks = DB::connection('sakemaru')
    ->table('wms_order_incoming_schedules')
    ->whereIn('warehouse_id', $this->realWarehouseIds)
    ->whereIn('status', ['PENDING', 'PARTIAL'])
    ->selectRaw('warehouse_id, item_id, SUM(expected_quantity - received_quantity) as total_incoming')
    ->groupBy('warehouse_id', 'item_id')
    ->get();

// item_contractors の is_auto_order フィルタは既存の $this->itemContractors で処理
// （stockSnapshots に格納する段階では全商品を含め、計算時に is_auto_order でフィルタ）
```

**注意**: 現在のスナップショットクエリは `item_contractors` と JOIN して `is_auto_order=true` のみに絞っている。新実装で���全商品の在庫を読み込み、計算ループ側で `item_contractors` フィルタを適用する（既に`$this->itemContractors`でフィルタ済みなので影響なし）。

#### 2. calculate() シグネチャの変更

```php
// 変更前
public function calculate(?int $snapshotJobId = null, ?int $contractorId = null, ...): WmsAutoOrderJobControl

// 変更後
public function calculate(?int $contractorId = null, ...): WmsAutoOrderJobControl
```

`$snapshotJobId` パラメータと `$this->snapshotJobId` プロパティを削除。

#### 3. ProcessOrderCandidateGenerationJob の変更

```php
// 変更前
$snapshotService = app(StockSnapshotService::class);
$snapshotJob = $snapshotService->generateAll($this->warehouseId, $this->createdBy);
$calcJob = $calculationService->calculate($snapshotJob->id, ...);

// 変更後
$calcJob = $calculationService->calculate($this->contractorId, $this->transferOnly, $this->warehouseId, $this->createdBy);
```

スナップショット生成ステップを削除。進捗メッセージも更新。

#### 4. AutoOrderCalculateCommand の変更

```php
// 変更前
$job = $snapshotService->generateAll();
$calcJob = $calculationService->calculate($job->id);

// 変更後
$calcJob = $calculationService->calculate();
```

#### 5. OrderCreateJobHandler / TransferCreateJobHandler の変更

同様にスナップ���ョット呼び出しを削除。

#### 6. WmsAutoOrderJobControl の変更

- `startJob()`の`$snapshotJobId`パラメータを削除（または残すが使用しない）
- `snapshot_job_id`カラムは削除しない（既存データ保持）
- `findPendingSettlement()`から`STOCK_SNAPSHOT`を除外

```php
// 変更前
->whereIn('process_name', [JobProcessName::STOCK_SNAPSHOT, JobProcessName::ORDER_CALC])

// 変更後
->where('process_name', JobProcessName::ORDER_CALC)
```

#### 7. 管理画面（段階的対応）

- `WmsItemStockSnapshotResource` — 閲覧専用として残す（既存データ参照用）。「スナップショット再生成」ボタンは削除。
- `WmsAutoOrderJobControlsTable` — `STOCK_SNAPSHOT`ジョブは表示するが、新規生成はされなくなる。

### 影響範囲

| 機能 | 影響 |
|------|------|
| 自動発注計算ジョブ | スナップショットステップ削除、直接クエリに変更 |
| CLIコマンド `wms:auto-order-calculate` | スナップショット呼び出し削除 |
| `wms:snapshot-stocks` コマ���ド | 廃止 |
| 発注生成ジョブハンドラ | スナップショット呼び出し削除 |
| 移動生成ジョブハンドラ | スナップショット呼び出し削除 |
| スナップショット管理画面 | 再生成ボタン削除、閲覧のみ |
| ジョブ管理画面 | STOCK_SNAPSHOTジョブは既存のみ表示 |

## 制約

- **FK禁止**: 全リレーションはアプリケーションレベル管理
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 共有DB
- **`wms_item_stock_snapshots`テーブルは削除しない**: 既存データの参照用に残す
- **`snapshot_job_id`カラムは削除しない**: 既存データの整合性保持
- **`STOCK_SNAPSHOT` enumは削除しない**: 既存ジョブレコードの表示に必要
- **パフォーマンス**: `wms_v_stock_available`からの集計は`real_stock_id`による重複排除が必要（StockSnapshotServiceと同じロジック）

## 対象ファイル

### 既存変更
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — スナップショット参照を直接クエリに変更、$snapshotJobId削除
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` — スナップショットステップ削除
- `app/Console/Commands/AutoOrder/AutoOrderCalculateCommand.php` — スナップショット呼び出し削除
- `app/Services/AutoOrder/OrderCreateJobHandler.php` — スナップショット呼び出し削除
- `app/Services/AutoOrder/TransferCreateJobHandler.php` — スナップショット呼び出し削除
- `app/Models/WmsAutoOrderJobControl.php` — findPendingSettlement()からSTOCK_SNAPSHOT除外、startJob()のsnapshotJobId削除
- `app/Filament/Resources/WmsItemStockSnapshots/Pages/ListWmsItemStockSnapshots.php` — 再生成ボタン削除

### 削除候補（使用箇所がなくなるファイル）
- `app/Services/AutoOrder/StockSnapshotService.php` — 全機能が不要に
- `app/Console/Commands/AutoOrder/SnapshotStocksCommand.php` — CLIコマンド廃止

### 参照のみ
- `app/Models/WmsItemStockSnapshot.php` — 既存データ参照用に残す
- `app/Filament/Resources/WmsItemStockSnapshots/` — 閲覧用に残す
- `app/Enums/AutoOrder/JobProcessName.php` — STOCK_SNAPSHOT enumは残す
- `app/Enums/EMenu.php` — スナップショットメニューは残す

## 確認事項

1. **パフォーマンス**: `wms_v_stock_available`から全倉庫×全商品を一括SELECTする場合のクエリ時間は？ 現在のスナップショットINSERT+SELECTの合計時間と比較して短縮されるか？
   → スナップショットINSERT（書き込み）がなくなるため、必ず短縮される。SELECTのみで済む。

2. **`real_stock_id`の重複排除**: `wms_v_stock_available`はロット毎に行が複製されるため、`available_for_wms`を単純SUMすると在庫が多重計上される。`StockSnapshotService`は`DISTINCT real_stock_id`で排除している。新実装でも同じ排除が必要。
   → `SELECT DISTINCT warehouse_id, item_id, real_stock_id, available_for_wms` → サブクエリで `GROUP BY warehouse_id, item_id, SUM(...)` が正確。

3. **既存スナップショットデータの扱い**: テーブルは残すが、古いデータのクリーンアップ（TRUNCATEまたはバッチ削除）は別タスクか？
   → 別タスクとする。まずは新規INSERTを停止し、テーブル肥大化の進行を止める。
