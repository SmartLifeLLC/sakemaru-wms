# wms_item_stock_snapshots参照の排除 — 発注・移動候補への在庫直接保存

- **作成日**: 2026-04-05
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/20260405/20260405-110845-remove-snapshot-dependency/

## 背景・目的

### 現状の問題

1. **`wms_item_stock_snapshots`テーブルの肥大化**: ジョブ実行ごとに`倉庫数×商品数`のレコードがINSERTされ、削除されない。33倉庫×11,500商品で1回約28,000行。日次で年間1,000万行・2.5GB。
2. **不定値の参照**: `ListWmsOrderCandidates`と`ListWmsStockTransferCandidates`のモーダル/リストで`wms_item_stock_snapshots`を`job_control_id`なしで参照しており、どのスナップショット時点の値が返るか不定。
3. **データの二重管理**: 発注・移動候補テーブルには既に`current_effective_stock`等のカラムがあるが、手動作成時やリスト表示の一部でスナップショットを別途参照している。

### 目的

- `/admin/wms-order-candidates`および`/admin/wms-stock-transfer-candidates`から`wms_item_stock_snapshots`への参照を**完全に排除**
- 在庫データは候補レコード自体に保存し、「発注計算時点」の値を確定的に参照できるようにする
- 手動作成時の在庫表示は`wms_v_stock_available`ビュー（リアルタイム）を使用
- `batch_code`の生成ルールを拡張し、倉庫コードを末尾に付与

## 現状の実装

### wms_item_stock_snapshotsの参照箇所（排除対象）

| # | ファイル | 行 | 用途 | 現在のテーブル |
|---|---------|-----|------|--------------|
| 1 | `ListWmsOrderCandidates.php` | :86-93 | 新規作成モーダルの現在庫表示 | `wms_item_stock_snapshots` |
| 2 | `ListWmsStockTransferCandidates.php` | :73-80 | 新規作成モーダルの現在庫表示 | `wms_item_stock_snapshots` |
| 3 | `ListWmsStockTransferCandidates.php` | :365-380 | リスト「倉庫在庫」列（HUB倉庫の在庫） | `wms_item_stock_snapshots` |

### 既存の在庫カラム（候補テーブルに既存）

**wms_order_candidates:**
- `current_effective_stock` (integer, nullable) — 有効在庫
- `incoming_quantity` (integer, nullable) — 入荷予定数
- `safety_stock` (integer, nullable) — 安全在庫
- `calculated_shortage_qty` (integer, nullable) — 算出不足数

**wms_stock_transfer_candidates:**
- `current_effective_stock` (integer, nullable) — 有効在庫
- `incoming_quantity` (integer, nullable) — 入荷予定数
- `calculated_available` (integer, nullable) — 見込在庫
- `shortage_qty` (integer, nullable) — 不足数
- `safety_stock` (integer, nullable) — 安全在庫

### batch_code の現状

- **フォーマット**: `YmdHis`（14文字、char(14)）— 例: `20260405005639`
- **生成**: `WmsAutoOrderJobControl::generateBatchCode()` → `now()->format('YmdHis')`
- **用途**: `wms_auto_order_job_controls`, `wms_order_candidates`, `wms_stock_transfer_candidates`, `wms_order_calculation_logs` で共通使用

### wms_auto_order_job_controls の settlement_status

| 値 | 意味 |
|----|------|
| `PENDING` | 確定待ち（承認前） |
| `CONFIRMED` | 確定済み |
| `CANCELLED` | キャンセル済み |

### 倉庫コード

- 最大3桁（例: `1`, `10`, `91`）
- 3桁ゼロ埋め対応が必要（例: `001`, `010`, `091`）

## 変更内容

### 概要

1. 手動作成モーダルの在庫表示を`wms_v_stock_available`に切り替え（リアルタイム参照）
2. リスト表示のHUB倉庫在庫を候補テーブルのカラムから表示（スナップショット参照を削除）
3. 手動作成時に在庫データを候補レコードに直接保存
4. `batch_code`の生成ルールを拡張（末尾に倉庫コード3桁を付与）

### 詳細設計

#### 1. 手動作成モーダルの在庫表示（リアルタイム）

モーダルで商品を選択した時点の在庫は「参考値」であり、リアルタイムの`wms_v_stock_available`を使うのが正確。

**変更前:**
```php
// ListWmsOrderCandidates.php:86-93
DB::connection('sakemaru')
    ->table('wms_item_stock_snapshots')
    ->where('warehouse_id', $warehouseId)
    ->where('item_id', $itemId)
    ->value('total_effective_piece');
```

**変更後:**
```php
// wms_v_stock_available から直接集計
DB::connection('sakemaru')
    ->table('wms_v_stock_available')
    ->where('warehouse_id', $warehouseId)
    ->where('item_id', $itemId)
    ->sum('available_quantity');
```

同様に`ListWmsStockTransferCandidates.php:73-80`も変更。

#### 2. リスト表示のHUB倉庫在庫（スナップショット排除）

`ListWmsStockTransferCandidates.php:365-380`でHUB倉庫在庫を`wms_item_stock_snapshots`から取得している箇所を、`wms_v_stock_available`に変更。

**変更前:**
```php
->table('wms_item_stock_snapshots')
->whereIn('warehouse_id', $hubWarehouseIds)
->whereIn('item_id', $itemIds)
->select('warehouse_id', 'item_id', 'total_effective_piece')
```

**変更後:**
```php
->table('wms_v_stock_available')
->whereIn('warehouse_id', $hubWarehouseIds)
->whereIn('item_id', $itemIds)
->selectRaw('warehouse_id, item_id, SUM(available_quantity) as total_effective_piece')
->groupBy('warehouse_id', 'item_id')
```

#### 3. 手動作成時の在庫保存

手動作成（モーダル）で候補レコードを作成する際に、その時点の在庫を`current_effective_stock`等に保存する。

**ListWmsOrderCandidates.php の create action callback:**
```php
// 在庫を取得して保存
$stock = DB::connection('sakemaru')
    ->table('wms_v_stock_available')
    ->where('warehouse_id', $warehouseId)
    ->where('item_id', $item['item_id'])
    ->sum('available_quantity');

WmsOrderCandidate::create([
    // ...既存フィールド
    'current_effective_stock' => $stock,
    // incoming_quantity は入荷予定テーブルから集計
]);
```

同様に`ListWmsStockTransferCandidates.php`も変更。

#### 4. batch_code の拡張

**現在**: `YmdHis`（14文字）— 例: `20260405005639`
**新規**: `YmdHis` + 倉庫コード3桁ゼロ埋め（17文字）— 例: `20260405005639002`

| ケース | 末尾 | 例 |
|--------|------|-----|
| 倉庫別実行 | 倉庫コード3桁ゼロ埋め | `20260405005639002` |
| 全体実行 | `000` | `20260405005639000` |

**DB変更**: `batch_code` カラムを `char(14)` → `char(17)` に拡張

**対象テーブル:**
- `wms_auto_order_job_controls`
- `wms_order_candidates`
- `wms_stock_transfer_candidates`
- `wms_order_calculation_logs`

**コード変更:**
```php
// WmsAutoOrderJobControl::generateBatchCode()
public static function generateBatchCode(?int $warehouseId = null): string
{
    $base = now()->format('YmdHis');
    if ($warehouseId) {
        $warehouseCode = Warehouse::where('id', $warehouseId)->value('code');
        return $base . str_pad($warehouseCode, 3, '0', STR_PAD_LEFT);
    }
    return $base . '000';
}
```

#### 5. job_control_id の取得ロジック（手動作成時）

手動作成時、同じシステム日・同じ倉庫で`settlement_status=PENDING`のjob_controlがあれば、そのIDを使用。なければ新規作成。

```php
// 同日・同倉庫のPENDINGジョブを検索
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
    $newJob->markAsSuccess(0); // 手動作成なので即完了
}
```

### 影響範囲

| 機能 | 影響 |
|------|------|
| 発注候補 新規作成モーダル | 在庫表示元の変更、在庫保存追加 |
| 移動候補 新規作成モーダル | 同上 |
| 移動候補 リスト「倉庫在庫」列 | 参照元の変更 |
| batch_code を使用する全箇所 | char(14)→char(17)拡張の影響確認 |
| 自動発注計算サービス | batch_code生成の変更 |
| 発注実行サービス | batch_codeフォーマット変更 |

## 問題点と方向性の確認

### 問題点1: batch_code 拡張の後方互換性

- 既存データのbatch_codeは14文字。新規は17文字。
- **方向性**: 既存データはそのまま（14文字）。新規のみ17文字。`LIKE`検索や前方一致で日時部分を使用している箇所がないか確認が必要。

### 問題点2: 手動作成時のjob_control_id再利用

- 同日・同倉庫のPENDINGジョブに手動作成分を追加すると、`total_records`や`processed_records`が自動計算分のみを反映し、手動分が含まれない。
- **方向性**: `total_records`は参考値として扱い、厳密な整合性は求めない。手動作成時は`origin_type=MANUAL`で区別可能。

### 問題点3: wms_v_stock_available のパフォーマンス

- `real_stocks`(49,496行) LEFT JOIN `real_stock_lots` のビュー。
- 単一`warehouse_id + item_id`での集計は、インデックス`(item_id, warehouse_id)`があるため高速（1ms以下）。
- HUB倉庫在庫の一括取得（ページネーション分の商品数）も、WHERE IN で十分高速。
- **方向性**: パフォーマンス問題なし。

### 問題点4: 手動作成時の incoming_quantity

- `wms_item_stock_snapshots`には`total_incoming_piece`があったが、`wms_v_stock_available`にはない。
- 入荷予定数は`wms_order_incoming_schedules`から別途集計が必要。
- **方向性**: 手動作成時は`incoming_quantity=0`で保存し、必要であれば`wms_order_incoming_schedules`から集計するヘルパーを追加。

## DB変更

### マイグレーション1: batch_code カラム拡張

```php
// 対象テーブル: 4テーブル
Schema::table('wms_auto_order_job_controls', function (Blueprint $table) {
    $table->char('batch_code', 17)->change();
});
Schema::table('wms_order_candidates', function (Blueprint $table) {
    $table->char('batch_code', 17)->change();
});
Schema::table('wms_stock_transfer_candidates', function (Blueprint $table) {
    $table->char('batch_code', 17)->change();
});
Schema::table('wms_order_calculation_logs', function (Blueprint $table) {
    $table->char('batch_code', 17)->change();
});
```

## 制約

- **FK禁止**: 全リレーションはアプリケーションレベルで管理
- **migrate:fresh/refresh禁止**: 共有データベースのため
- **既存データ破壊禁止**: batch_code拡張は既存14文字データに影響しないこと
- **`wms_item_stock_snapshots`テーブル自体は削除しない**: 自動発注計算サービス(`OrderCandidateCalculationService`)で引き続き使用

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_expand_batch_code_to_17_chars.php`

### 既存変更
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` — 在庫参照をwms_v_stock_availableに変更、在庫保存追加、batch_code生成変更
- `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php` — 同上 + HUB倉庫在庫参照変更
- `app/Models/WmsAutoOrderJobControl.php` — `generateBatchCode()`に倉庫コード3桁追加
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — batch_code生成の変更反映
- `app/Services/AutoOrder/StockSnapshotService.php` — batch_code生成の変更反映

### 参照のみ
- `app/Models/WmsOrderCandidate.php` — 既存カラム確認（変更不要）
- `app/Models/WmsStockTransferCandidate.php` — 既存カラム確認（変更不要）
- `app/Models/WmsItemStockSnapshot.php` — 自動発注計算で引き続き使用
- `resources/views/filament/components/order-candidate-create-items.blade.php` — Alpine側は変更不要（メソッド名同じ）
- `resources/views/filament/components/transfer-order-create-items.blade.php` — 同上

## 確認事項

1. **batch_codeの`LIKE`検索**: 既存コードで`batch_code LIKE 'YYYYMMDD%'`のような前方一致検索がある場合、17文字化で問題ないか？問題ない。
倉庫のCODEではなく、IDの３桁をつける。（倉庫コードが大きくても対応できるように)
2. **手動作成の`origin_type`**: 現在全データが`AUTO`。手動作成時は`MANUAL`を設定するが、既存のフィルタやプリセットビューに影響はないか？
これは確認が必要。作成がAUTO / MANUALが混ざるのは同じ job_control_idで問題ないかが確認必要。結局発注はまとめて実施必須
3. **incoming_quantity の扱い**: 手動作成時に入荷予定数を0で良いか、それとも`wms_order_incoming_schedules`から集計すべきか？
集計すべきとモーダルにも表示が必要。また、既存発注データに同じ商品があればそれも表示が必要。（重複生成ではなく、修正になる）
4. **HUB倉庫在庫のリアルタイム表示**: リスト表示でのHUB在庫はリアルタイム（`wms_v_stock_available`）で良いか、それとも候補レコードに保存すべきか？
候補レコードに保存する。wms_vは利用しない。
