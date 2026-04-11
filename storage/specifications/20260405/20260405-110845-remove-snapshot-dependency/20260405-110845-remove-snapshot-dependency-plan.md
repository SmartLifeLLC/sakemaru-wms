# wms_item_stock_snapshots参照排除 作業計画

## 前提

- 発注候補・移動候補テーブルには`current_effective_stock`, `incoming_quantity`等の在庫カラムが既存
- `wms_item_stock_snapshots`は自動発注計算(`OrderCandidateCalculationService`)では引き続き使用
- `origin_type`は既存enum: `AUTO/USER/DIST`（`USER`を手動作成に使用）
- `batch_code`は現在`char(14)`、`LIKE`検索なし（SelectFilterのdistinct取得のみ）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | batch_code拡張（DB + モデル） | char(14)→char(17)マイグレーション + generateBatchCode変更 | マイグレーション成功、既存データ影響なし |
| P2 | 発注候補のスナップショット排除 | ListWmsOrderCandidates手動作成モーダル改修 | スナップショット参照0、在庫・入荷予定数が保存される |
| P3 | 移動候補のスナップショット排除 | ListWmsStockTransferCandidates手動作成モーダル + HUB在庫列改修 | スナップショット参照0、HUB在庫が候補レコードから表示 |
| P4 | 自動発注サービスのbatch_code対応 | OrderCandidateCalculationService等のbatch_code生成を変更 | 倉庫ID付きbatch_codeが生成される |
| P5 | 動作確認・回帰テスト | 全画面の動作確認、grepでスナップショット参照残りなし確認 | 発注候補・移動候補からのスナップショット参照が完全排除 |

---

## P1: batch_code拡張（DB + モデル）

### 目的

`batch_code`を14文字→17文字に拡張し、末尾に倉庫ID（3桁ゼロ埋め）を付与可能にする。

### 修正対象ファイル

1. `database/migrations/XXXX_expand_batch_code_to_17_chars.php` — **新規作成**
2. `app/Models/WmsAutoOrderJobControl.php` — `generateBatchCode()`メソッド変更

### 修正内容

#### マイグレーション

```php
// 4テーブルの batch_code を char(14) → char(17) に拡張
// 既存データ（14文字）はそのまま保持される（char型はスペースパディング）
Schema::table('wms_auto_order_job_controls', fn (Blueprint $table) =>
    $table->char('batch_code', 17)->change()
);
Schema::table('wms_order_candidates', fn (Blueprint $table) =>
    $table->char('batch_code', 17)->nullable()->change()
);
Schema::table('wms_stock_transfer_candidates', fn (Blueprint $table) =>
    $table->char('batch_code', 17)->nullable()->change()
);
Schema::table('wms_order_calculation_logs', fn (Blueprint $table) =>
    $table->char('batch_code', 17)->nullable()->change()
);
```

**注意**: `nullable()`は既存のカラム定義に合わせる。事前に各テーブルのカラム定義を確認すること。

#### generateBatchCode 変更

```php
// WmsAutoOrderJobControl.php
public static function generateBatchCode(?int $warehouseId = null): string
{
    $base = now()->format('YmdHis');
    $suffix = $warehouseId
        ? str_pad((string) $warehouseId, 3, '0', STR_PAD_LEFT)
        : '000';
    return $base . $suffix;
}
```

### 手順

1. 4テーブルの`batch_code`カラム定義を確認（`nullable`か否か）
2. マイグレーションファイルを作成
3. `php artisan migrate` を実行
4. `WmsAutoOrderJobControl::generateBatchCode()`を変更
5. 既存テストがあれば実行して通ることを確認

### 完了条件

- マイグレーション成功
- `SHOW COLUMNS FROM wms_auto_order_job_controls LIKE 'batch_code'`で`char(17)`確認
- 既存batch_codeデータが破壊されていない（`SELECT batch_code FROM wms_auto_order_job_controls LIMIT 5`で確認）
- `generateBatchCode(2)` → `20260405XXXXXX002` 形式

---

## P2: 発注候補のスナップショット排除

### 目的

`ListWmsOrderCandidates.php`の手動作成モーダルから`wms_item_stock_snapshots`参照を排除し、在庫をリアルタイム取得・候補レコードに保存する。入荷予定数も集計して表示・保存する。

### 修正対象ファイル

1. `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php`

### 修正内容

#### A. 在庫表示メソッドの変更（:88-93付近）

**変更前:**
```php
DB::connection('sakemaru')
    ->table('wms_item_stock_snapshots')
    ->where('warehouse_id', $warehouseId)
    ->where('item_id', $itemId)
    ->value('total_effective_piece');
```

**変更後:**
```php
DB::connection('sakemaru')
    ->table('wms_v_stock_available')
    ->where('warehouse_id', $warehouseId)
    ->where('item_id', $itemId)
    ->sum('available_quantity');
```

#### B. 入荷予定数の取得・表示

手動作成モーダルで商品選択時に入荷予定数も表示する。

```php
// 入荷予定数の取得
$incomingQty = DB::connection('sakemaru')
    ->table('wms_order_incoming_schedules')
    ->where('warehouse_id', $warehouseId)
    ->where('item_id', $itemId)
    ->whereIn('status', ['PENDING', 'PARTIAL'])
    ->selectRaw('SUM(expected_quantity - received_quantity) as total_incoming')
    ->value('total_incoming') ?? 0;
```

#### C. 既存発注データの確認・表示

同じ倉庫・同じ商品のPENDING発注候補がある場合、重複ではなく修正とする旨を表示。

```php
// 同倉庫・同商品のPENDING候補を確認
$existingCandidate = WmsOrderCandidate::where('warehouse_id', $warehouseId)
    ->where('item_id', $itemId)
    ->whereHas('jobControl', fn ($q) => $q->where('settlement_status', 'PENDING'))
    ->first();
```

#### D. 候補レコード作成時の在庫保存

```php
WmsOrderCandidate::create([
    // ...既存フィールド
    'current_effective_stock' => $stock,
    'incoming_quantity' => $incomingQty,
    // safety_stock, calculated_shortage_qty は必要に応じて
]);
```

#### E. batch_code / job_control_id の取得（手動作成時）

仕様書の「5. job_control_id の取得ロジック」に従い、同日・同倉庫のPENDINGジョブを再利用。

```php
$existingJob = WmsAutoOrderJobControl::where('process_name', JobProcessName::ORDER_CALC)
    ->where('settlement_status', SettlementStatus::PENDING)
    ->where('warehouse_id', $warehouseId)
    ->whereDate('started_at', today())
    ->orderByDesc('id')
    ->first();

if ($existingJob) {
    $batchCode = $existingJob->batch_code;
} else {
    $newJob = WmsAutoOrderJobControl::startJob(
        processName: JobProcessName::ORDER_CALC,
        createdBy: auth()->id(),
        warehouseId: $warehouseId,
    );
    $batchCode = $newJob->batch_code;
    $newJob->markAsSuccess(0);
}
```

### 手順

1. `ListWmsOrderCandidates.php`を読み、手動作成モーダルの全体構造を把握
2. `wms_item_stock_snapshots`参照箇所を`wms_v_stock_available`に変更
3. 入荷予定数の集計ロジックを追加（取得メソッド + Blade表示）
4. 既存発注候補の重複チェックを追加
5. 候補レコード作成時に在庫・入荷予定数を保存
6. job_control_id取得ロジックを仕様通りに変更
7. Blade側に入荷予定数・既存候補情報の表示を追加（必要な場合）
8. 画面上で手動作成を実行して在庫が表示・保存されることを確認

### 完了条件

- `ListWmsOrderCandidates.php`に`wms_item_stock_snapshots`への参照がない
- 手動作成モーダルで在庫が`wms_v_stock_available`から表示される
- 入荷予定数が`wms_order_incoming_schedules`から集計・表示される
- 候補レコードに`current_effective_stock`、`incoming_quantity`が保存される
- 同商品のPENDING候補がある場合に情報が表示される

---

## P3: 移動候補のスナップショット排除

### 目的

`ListWmsStockTransferCandidates.php`の手動作成モーダルとHUB倉庫在庫列から`wms_item_stock_snapshots`参照を排除する。

### 修正対象ファイル

1. `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php`

### 修正内容

#### A. 手動作成モーダルの在庫表示（:75-79付近）

P2と同様に`wms_v_stock_available`に変更。入荷予定数の取得・表示も追加。

#### B. HUB倉庫在庫列（:367-373付近）

**変更前:**
```php
$hubStocks = DB::connection('sakemaru')
    ->table('wms_item_stock_snapshots')
    ->whereIn('warehouse_id', $hubWarehouseIds)
    ->whereIn('item_id', $itemIds)
    ->select('warehouse_id', 'item_id', 'total_effective_piece')
    ->get()
    ->keyBy(fn ($row) => "{$row->warehouse_id}_{$row->item_id}");
```

**変更後:** 候補レコードに保存された値から表示（スナップショットもビューも使わない）。

仕様書の確認事項4の回答:「候補レコードに保存する。wms_vは利用しない。」

→ 自動計算時にすでに保存されている`current_effective_stock`を使用。HUB倉庫在庫の表示ロジックを候補レコードの保存値ベースに変更する。

**実装方針**: 
- HUB倉庫在庫は、同じ`batch_code`で作成された移動候補レコードのうち、HUB倉庫のレコードの`current_effective_stock`を参照
- または、移動候補テーブルにHUB倉庫在庫用のカラムを追加（要確認）

→ 実装時に現在のHUB倉庫在庫列の表示ロジックを詳細に読んで最適な方法を決定する。

#### C. 手動作成時の在庫保存・入荷予定数

P2と同様のロジック。移動候補固有のカラム（`calculated_available`, `shortage_qty`）も適切に設定。

#### D. job_control_id / batch_code

P2と同様、同日・同倉庫のPENDINGジョブを再利用。移動候補の場合の`process_name`を確認すること。

### 手順

1. `ListWmsStockTransferCandidates.php`を読み、全体構造を把握
2. 手動作成モーダルの在庫表示を変更
3. 入荷予定数の集計・表示を追加
4. HUB倉庫在庫列の表示ロジックを変更（スナップショット→候補レコード）
5. 候補レコード作成時の在庫保存を追加
6. 画面上で確認

### 完了条件

- `ListWmsStockTransferCandidates.php`に`wms_item_stock_snapshots`への参照がない
- HUB倉庫在庫が候補レコードの保存値から表示される
- 手動作成で在庫・入荷予定数が表示・保存される

---

## P4: 自動発注サービスのbatch_code対応

### 目的

自動発注計算サービスが`generateBatchCode()`を呼ぶ際に倉庫IDを渡すよう変更。

### 修正対象ファイル

1. `app/Services/AutoOrder/OrderCandidateCalculationService.php`
2. `app/Services/AutoOrder/StockSnapshotService.php`（必要であれば）

### 修正内容

#### generateBatchCode 呼び出し箇所の確認と変更

`generateBatchCode()`の全呼び出し箇所を確認し、倉庫IDを渡すよう変更。

```php
// 変更前
$batchCode = WmsAutoOrderJobControl::generateBatchCode();

// 変更後（倉庫別実行の場合）
$batchCode = WmsAutoOrderJobControl::generateBatchCode($warehouseId);

// 全体実行の場合は従来通り
$batchCode = WmsAutoOrderJobControl::generateBatchCode(); // → '...000'
```

### 手順

1. `generateBatchCode()`の全呼び出し箇所を`grep`で特定
2. 各呼び出し箇所で倉庫IDが利用可能か確認
3. 倉庫IDを渡すよう変更
4. `startJob()`メソッドに`warehouseId`パラメータがあるか確認、なければ追加

### 完了条件

- 全ての`generateBatchCode()`呼び出しで適切な倉庫IDが渡される
- 全体実行時は末尾`000`、倉庫別実行時は倉庫ID 3桁が付与される

---

## P5: 動作確認・回帰テスト

### 目的

全変更の統合確認。スナップショット参照が発注・移動候補から完全排除されていることを保証。

### 手順

1. **grep確認**: 発注候補・移動候補関連ファイルに`wms_item_stock_snapshots`参照がないことを確認
   ```bash
   grep -rn 'wms_item_stock_snapshots' app/Filament/Resources/WmsOrderCandidates/
   grep -rn 'wms_item_stock_snapshots' app/Filament/Resources/WmsStockTransferCandidates/
   ```
2. **画面確認（発注候補）**:
   - `/admin/wms-order-candidates` にアクセス
   - 手動作成モーダルを開き、商品選択時に在庫と入荷予定数が表示されること
   - 候補を作成し、`current_effective_stock`と`incoming_quantity`が保存されていること
   - batch_codeが17文字形式であること
3. **画面確認（移動候補）**:
   - `/admin/wms-stock-transfer-candidates` にアクセス
   - 手動作成モーダルで同様の確認
   - HUB倉庫在庫列が候補レコードの値を表示していること
4. **既存テスト実行**:
   ```bash
   php artisan test --filter=Order
   php artisan test --filter=Transfer
   ```
5. **既存データ確認**: 既存の14文字batch_codeデータが正常に表示されること

### 完了条件

- 発注候補・移動候補からの`wms_item_stock_snapshots`参照が0件
- 手動作成モーダルで在庫・入荷予定数が正しく表示・保存される
- HUB倉庫在庫列が候補レコードから表示される
- batch_codeが17文字形式で生成される
- 既存データ・既存機能に回帰がない

---

## 制約（厳守）

1. **`migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe` 禁止** — 共有DB
2. **FK禁止** — 全リレーションはアプリケーションレベル
3. **`wms_item_stock_snapshots`テーブルは削除しない** — 自動発注計算で引き続き使用
4. **既存batch_code（14文字）データを破壊しない**
5. **`origin_type`は既存enum（`AUTO/USER/DIST`）に従う** — `MANUAL`は使用しない
6. **倉庫コードではなく倉庫ID（3桁ゼロ埋め）をbatch_code末尾に付与**

## 全体完了条件

1. `grep -rn 'wms_item_stock_snapshots' app/Filament/Resources/WmsOrderCandidates/` → 結果なし
2. `grep -rn 'wms_item_stock_snapshots' app/Filament/Resources/WmsStockTransferCandidates/` → 結果なし
3. 手動作成で在庫（`wms_v_stock_available`）と入荷予定数（`wms_order_incoming_schedules`）が表示・保存される
4. HUB倉庫在庫が候補レコードの保存値から表示される
5. `batch_code`が17文字形式（末尾に倉庫ID 3桁 or 000）
6. 既存データ・既存自動発注処理に影響なし
