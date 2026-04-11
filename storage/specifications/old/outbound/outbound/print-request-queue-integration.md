# PrintRequestQueue 統合設計書

## 概要

WMSシステムから印刷リクエストを送信し、sakemaru-ai-core側で伝票を出力する仕組みの設計について。
現状は売上伝票のみ対応しているが、倉庫移動リストも一緒に印刷する必要がある。

---

## 1. 現在の印刷の仕組み

### 1.1 PrintRequestQueue テーブル（現状）

```sql
-- 現在の構造（推定）
CREATE TABLE print_request_queue (
    id BIGINT PRIMARY KEY,
    client_id INT NOT NULL,
    earning_ids JSON,                    -- 売上IDの配列
    print_type VARCHAR(50),              -- 'CLIENT_SLIP_PRINTER' など
    group_by_delivery_course BOOLEAN,    -- 配送コース別にグルーピング
    warehouse_id INT,                    -- 出荷元倉庫
    printer_driver_id INT,               -- プリンタードライバー
    status VARCHAR(20),                  -- 'pending', 'processing', 'completed', 'failed'
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### 1.2 WMS側からのリクエスト例

```php
PrintRequestQueue::create([
    'client_id' => $clientId,
    'earning_ids' => $earningIds,           // 対象売上IDの配列
    'print_type' => 'CLIENT_SLIP_PRINTER',  // プリンター直接印刷
    'group_by_delivery_course' => true,
    'warehouse_id' => $warehouseId,
    'printer_driver_id' => $printerDriverId,
    'status' => 'pending',
]);
```

### 1.3 sakemaru-ai-core側の処理フロー

```
1. ポーリングで print_request_queue をチェック
2. status='pending' のレコードを取得
3. PdfExport::dispatch() を呼び出し
4. 配送コース別にグルーピングして PDF 生成
   - 表紙 (GenerateCoverPage)
   - 横持出荷リスト (GenerateCrossDockingList)
   - 倉庫移動リスト (GenerateStockTransferList) ← 現在は earning から日付を取得
   - 売上伝票 (CLIENT_SLIP)
5. S3 にアップロード
6. プリンター出力キューに登録
```

---

## 2. 現在の問題点

### 2.1 倉庫移動が印刷されないケース

| ケース | 売上 | 倉庫移動 | 結果 |
|--------|------|----------|------|
| A | あり | あり | 売上伝票のみ印刷（倉庫移動は売上の日付から自動取得） |
| B | あり | なし | 売上伝票のみ印刷 |
| C | なし | あり | **印刷されない（問題）** |
| D | なし | なし | 印刷なし |

### 2.2 問題の原因

1. `earning_ids` が空の場合、`PdfExport` が処理をスキップしていた
2. 倉庫移動リストは売上の `delivered_date` を基に取得していた
3. 売上がない場合、日付情報がなく倉庫移動を取得できなかった

### 2.3 sakemaru-ai-core側の対応状況

`PdfExport` に以下のパラメータを追加済み：
- `delivered_date`: 出荷日（売上がない場合の倉庫移動取得用）
- `delivery_course_id`: 配送コースID

これにより、売上がなくても倉庫移動のみで印刷可能になった。

---

## 3. 提案: PrintRequestQueue の拡張

### 3.1 方法A: delivered_date を追加（推奨）

**テーブル変更:**
```sql
ALTER TABLE print_request_queue
ADD COLUMN delivered_date DATE NULL AFTER earning_ids;
```

**WMS側のリクエスト:**
```php
PrintRequestQueue::create([
    'client_id' => $clientId,
    'earning_ids' => $earningIds,           // 空でも可
    'delivered_date' => '2026-01-16',       // 追加
    'print_type' => 'CLIENT_SLIP_PRINTER',
    'group_by_delivery_course' => true,
    'warehouse_id' => $warehouseId,
    'printer_driver_id' => $printerDriverId,
    'status' => 'pending',
]);
```

**メリット:**
- シンプルな変更
- sakemaru-ai-core側は既に対応済み
- 売上がなくても倉庫移動を自動取得可能

**デメリット:**
- 倉庫移動の明示的な指定ができない

---

### 3.2 方法B: stock_transfer_ids を追加

**テーブル変更:**
```sql
ALTER TABLE print_request_queue
ADD COLUMN stock_transfer_ids JSON NULL AFTER earning_ids;
```

**WMS側のリクエスト:**
```php
PrintRequestQueue::create([
    'client_id' => $clientId,
    'earning_ids' => $earningIds,
    'stock_transfer_ids' => $stockTransferIds,  // 追加
    'print_type' => 'CLIENT_SLIP_PRINTER',
    'group_by_delivery_course' => true,
    'warehouse_id' => $warehouseId,
    'printer_driver_id' => $printerDriverId,
    'status' => 'pending',
]);
```

**メリット:**
- 倉庫移動を明示的に指定可能
- 細かい制御が可能

**デメリット:**
- WMS側で倉庫移動IDを取得・管理する必要あり
- sakemaru-ai-core側の追加実装が必要

---

### 3.3 方法C: delivered_date + delivery_course_id を追加

**テーブル変更:**
```sql
ALTER TABLE print_request_queue
ADD COLUMN delivered_date DATE NULL AFTER earning_ids,
ADD COLUMN delivery_course_id INT NULL AFTER delivered_date;
```

**WMS側のリクエスト:**
```php
PrintRequestQueue::create([
    'client_id' => $clientId,
    'earning_ids' => [],                        // 空でも可
    'delivered_date' => '2026-01-16',           // 追加
    'delivery_course_id' => $deliveryCourseId,  // 追加（オプション）
    'print_type' => 'CLIENT_SLIP_PRINTER',
    'group_by_delivery_course' => true,
    'warehouse_id' => $warehouseId,
    'printer_driver_id' => $printerDriverId,
    'status' => 'pending',
]);
```

**メリット:**
- 配送コース単位で印刷可能
- 売上・倉庫移動を自動取得
- 柔軟性が高い

**デメリット:**
- やや複雑

---

## 4. 処理フロー（提案後）

### 4.1 方法A採用時のフロー

```
WMS側:
1. ピッキング完了時に PrintRequestQueue にレコード作成
   - earning_ids: 対象売上ID（あれば）
   - delivered_date: 出荷日
   - warehouse_id: 出荷元倉庫
   - printer_driver_id: 対象プリンター

sakemaru-ai-core側:
1. ポーリングで print_request_queue をチェック
2. status='pending' のレコードを取得
3. PdfExport::dispatch() を呼び出し
   - earning_ids が空の場合は delivered_date で倉庫移動を取得
   - earning_ids がある場合は売上 + 倉庫移動を出力
4. 配送コース別にPDF生成
5. プリンター出力
```

### 4.2 出力物

| 売上あり | 倉庫移動あり | 出力物 |
|----------|--------------|--------|
| Yes | Yes | 表紙 + 横持出荷リスト + 倉庫移動リスト + 売上伝票 |
| Yes | No | 表紙 + 横持出荷リスト + 売上伝票 |
| No | Yes | 倉庫移動リスト のみ |
| No | No | 出力なし |

---

## 5. WMS側の実装ポイント

### 5.1 印刷リクエストのタイミング

**オプション1: ピッキング完了時**
```
配送コースのピッキングが全て完了 → PrintRequestQueue に登録
```

**オプション2: 出荷確定時**
```
出荷確定処理時 → PrintRequestQueue に登録
```

**オプション3: 手動トリガー**
```
WMS画面から「伝票印刷」ボタン → PrintRequestQueue に登録
```

### 5.2 考慮事項

1. **重複印刷の防止**
   - 同じ売上/倉庫移動が複数回印刷されないよう制御が必要
   - `earning.slip_requested_count` をチェックするなど

2. **配送コースの特定**
   - 売上がない場合、どの配送コースで印刷するか
   - `delivery_course_id` を明示的に指定するか、倉庫移動から取得するか

3. **プリンターの決定**
   - 配送コースに紐づくプリンター
   - 倉庫に紐づくデフォルトプリンター
   - 明示的に指定

---

## 6. 推奨案（旧）

~~**方法A（delivered_date追加）を推奨**~~

※ 以下は旧案。最終案は「8. 最終決定案」を参照。

---

## 7. 関連ファイル

### sakemaru-ai-core

- `app/Actions/Log/PdfExport.php` - PDF生成処理（delivered_date対応済み）
- `app/Actions/Print/GenerateStockTransferList.php` - 倉庫移動リスト生成
- `app/Actions/Print/GenerateCrossDockingList.php` - 横持出荷リスト生成
- `app/Actions/Print/GenerateCoverPage.php` - 表紙生成
- `app/Livewire/ShipmentManagement.php` - 出荷管理画面（手動印刷）

### WMS（関連予定）

- 印刷リクエスト生成処理
- ピッキング完了処理

---

## 8. 最終決定案: stock_transfer_ids を明示的に指定

**更新日**: 2026-01-16

### 8.1 背景と決定理由

WMSの波動生成では、既に `earnings` と `stock_transfers` の両方をピッキング対象として統合済み。
`wms_picking_item_results` には以下のフィールドが存在する：

```
source_type: 'EARNING' | 'STOCK_TRANSFER'
earning_id: NULL または 売上ID
stock_transfer_id: NULL または 倉庫移動ID
```

**決定理由:**

1. **ピッキング対象と伝票の完全一致**
   - `delivered_date` による自動取得では、ピッキングしていない倉庫移動まで含まれる可能性がある
   - 明示的に `stock_transfer_ids` を指定することで、実際にピッキングしたものだけを印刷

2. **配送コース不一致問題の回避**
   - `earning_ids` に含まれる売上の `delivery_course_id` が異なる可能性がある
   - `stock_transfer_ids` を明示指定すれば、この問題を回避できる

3. **シンプルで確実**
   - WMS側で既に `pickingItemResults` から両方のIDを取得可能
   - 追加の日付計算やフィルタリングロジックが不要

---

### 8.2 テーブル変更（基幹システム側で実施）

```sql
ALTER TABLE print_request_queue
ADD COLUMN stock_transfer_ids JSON NULL AFTER earning_ids;
```

**変更後のテーブル構造:**

```sql
CREATE TABLE print_request_queue (
    id BIGINT PRIMARY KEY,
    client_id INT NOT NULL,
    earning_ids JSON,                    -- 売上IDの配列
    stock_transfer_ids JSON,             -- 【追加】倉庫移動IDの配列
    print_type VARCHAR(50),
    group_by_delivery_course BOOLEAN,
    warehouse_id INT,
    printer_driver_id INT,
    status VARCHAR(20),
    error_message TEXT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

### 8.3 WMS側の実装変更

**PrintRequestService.php の変更:**

```php
public function createPrintRequest(int $deliveryCourseId, string $shipmentDate, int $warehouseId, ?int $waveId = null): array
{
    // ... プリンター設定取得 ...

    // 対象のピッキングタスクを取得
    $query = WmsPickingTask::where('delivery_course_id', $deliveryCourseId)
        ->where('shipment_date', $shipmentDate);

    if ($waveId) {
        $query->where('wave_id', $waveId);
    }

    $tasks = $query->with(['pickingItemResults'])->get();

    if ($tasks->isEmpty()) {
        return ['success' => false, 'message' => '対象のピッキングタスクが見つかりません。'];
    }

    // earning_id と stock_transfer_id を収集
    $earningIds = [];
    $stockTransferIds = [];

    foreach ($tasks as $task) {
        foreach ($task->pickingItemResults as $itemResult) {
            if ($itemResult->earning_id && !in_array($itemResult->earning_id, $earningIds)) {
                $earningIds[] = $itemResult->earning_id;
            }
            if ($itemResult->stock_transfer_id && !in_array($itemResult->stock_transfer_id, $stockTransferIds)) {
                $stockTransferIds[] = $itemResult->stock_transfer_id;
            }
        }
    }

    // 売上も倉庫移動もない場合はエラー
    if (empty($earningIds) && empty($stockTransferIds)) {
        return ['success' => false, 'message' => '印刷対象の売上・倉庫移動が見つかりません。'];
    }

    // client_id を取得（売上または倉庫移動から）
    $clientId = $this->resolveClientId($earningIds, $stockTransferIds);

    // print_request_queue にレコード作成
    $queue = PrintRequestQueue::create([
        'client_id' => $clientId,
        'earning_ids' => $earningIds,
        'stock_transfer_ids' => $stockTransferIds,  // 【追加】
        'print_type' => PrintRequestQueue::PRINT_TYPE_CLIENT_SLIP_PRINTER,
        'group_by_delivery_course' => true,
        'warehouse_id' => $warehouseId,
        'printer_driver_id' => $printerDriverId,
        'status' => PrintRequestQueue::STATUS_PENDING,
    ]);

    return [
        'success' => true,
        'message' => '印刷依頼を作成しました。',
        'queue_id' => $queue->id,
        'earning_count' => count($earningIds),
        'stock_transfer_count' => count($stockTransferIds),
    ];
}
```

---

### 8.4 sakemaru-ai-core側の変更 (実装済み)

**PdfExport::execute() の新しいシグネチャ:**

```php
public static function execute(
    string $file_path,
    int $log_id,
    array $target_ids,           // earning_ids
    array $print_types,
    ?string $start_month,
    ?string $end_month,
    ?int $warehouse_id,
    ?int $item_category_1_id,
    ?ItemTypes $item_type,
    bool $is_debug = false,
    ?User $user = null,
    ?int $printer_driver_id = null,
    bool $group_by_delivery_course = false,
    bool $warehouse_auto_print = false,
    ?string $delivered_date = null,
    ?int $delivery_course_id = null,
    array $stock_transfer_ids = [],  // 【新規追加】
): ActionResult
```

**ポーリング処理の変更例:**

```php
// print_request_queue から取得時
$request = PrintRequestQueue::where('status', 'pending')->first();

// PdfExport::dispatch() を呼び出し
PdfExport::dispatch(
    $filePath,
    $logId,
    $request->earning_ids ?? [],           // earning_ids
    [PrintType::CLIENT_SLIP_PRINTER->value],
    null, null,                             // start_month, end_month
    $request->warehouse_id,
    null, null,                             // item_category_1_id, item_type
    false,                                  // is_debug
    $user,
    $request->printer_driver_id,
    $request->group_by_delivery_course ?? true,
    false,                                  // warehouse_auto_print
    null,                                   // delivered_date (stock_transfer_idsがあれば不要)
    null,                                   // delivery_course_id
    $request->stock_transfer_ids ?? [],     // 【追加】stock_transfer_ids
);
```

**GenerateStockTransferList::generateByIds() 追加:**

```php
// 指定されたstock_transfer_idsから直接PDFを生成
public static function generateByIds(array $stockTransferIds, ?LaravelMpdf $pdf = null): ?LaravelMpdf
```

**処理フロー:**

1. `stock_transfer_ids` が指定されている場合:
   - 配送コース別にグルーピング
   - 各配送コースの倉庫移動リストを `generateByIds()` で生成
2. `stock_transfer_ids` が空で `delivered_date` が指定されている場合:
   - 従来の日付ベース取得（フォールバック）

---

### 8.5 期待される動作

| ピッキング内容 | earning_ids | stock_transfer_ids | 印刷出力 |
|---------------|-------------|-------------------|----------|
| 売上5件 + 移動2件 | [1,2,3,4,5] | [101,102] | 売上伝票5件 + 倉庫移動リスト(2件) |
| 売上0件 + 移動3件 | [] | [101,102,103] | 倉庫移動リストのみ(3件) |
| 売上4件 + 移動0件 | [1,2,3,4] | [] | 売上伝票4件のみ |
| 売上0件 + 移動0件 | [] | [] | エラー（印刷対象なし） |

---

### 8.6 実装担当

| 項目 | 担当 | 状況 |
|------|------|------|
| print_request_queue スキーマ変更 | 基幹システム | **完了** |
| sakemaru-ai-core ポーリング修正 (ProcessPrintRequest) | 基幹システム | **完了** |
| PdfExport stock_transfer_ids 対応 | 基幹システム | **完了** |
| GenerateStockTransferList generateByIds 追加 | 基幹システム | **完了** |
| PrintRequestQueue モデル更新 (cast追加) | 基幹システム | **完了** |
| PrintRequestQueue モデル更新 | WMS | 未着手 |
| PrintRequestService 修正 | WMS | 未着手 |

---

### 8.7 移行時の注意

1. **後方互換性**
   - `stock_transfer_ids` が NULL の場合は従来通り `delivered_date` ベースで取得
   - 段階的な移行が可能

2. **テスト項目**
   - 売上のみの印刷（従来動作）
   - 倉庫移動のみの印刷
   - 売上 + 倉庫移動の混在印刷
   - 空リクエストのエラーハンドリング

---

## 9. 基幹システム側 実施済み内容 (2026-01-16)

### 9.1 マイグレーション

**ファイル:** `database/migrations/2026_01_16_184514_add_stock_transfer_ids_to_print_request_queue_table.php`

```php
Schema::table('print_request_queue', function (Blueprint $table) {
    $table->json('stock_transfer_ids')->nullable()->after('earning_ids')->comment('倉庫移動ID配列');
});
```

### 9.2 PrintRequestQueue モデル更新

**ファイル:** `app/Models/PrintRequestQueue.php`

```php
protected $casts = [
    'earning_ids' => 'array',
    'stock_transfer_ids' => 'array',  // 追加
    'group_by_delivery_course' => 'boolean',
    'processed_at' => 'datetime',
];
```

### 9.3 ProcessPrintRequest ジョブ更新

**ファイル:** `app/Jobs/ProcessPrintRequest.php`

```php
PdfExport::dispatch(
    $log->path,
    $log->id,
    $request->earning_ids ?? [],
    [$print_type->value],
    null, // start_month
    null, // end_month
    $request->warehouse_id,
    null, // item_category_1_id
    null, // item_type
    false, // is_debug
    $user,
    $request->printer_driver_id,
    $request->group_by_delivery_course,
    false, // warehouse_auto_print
    null, // delivered_date
    null, // delivery_course_id
    $request->stock_transfer_ids ?? [] // 追加: stock_transfer_ids
);
```

### 9.4 PdfExport 更新

**ファイル:** `app/Actions/Log/PdfExport.php`

- `stock_transfer_ids` パラメータを追加
- `handleGroupedByDeliveryCourse()`: 配送コース別に `stock_transfer_ids` をフィルタ
- `handleStockTransfersOnly()`: `stock_transfer_ids` から直接取得（日付ベースはフォールバック）

### 9.5 GenerateStockTransferList 更新

**ファイル:** `app/Actions/Print/GenerateStockTransferList.php`

- `generateByIds()` メソッドを追加: stock_transfer_ids から直接PDFを生成

---

## 10. WMS側 作業内容

### 10.1 PrintRequestQueue モデル更新

**ファイル:** `app/Models/PrintRequestQueue.php`

```php
protected $casts = [
    'earning_ids' => 'array',
    'stock_transfer_ids' => 'array',  // 追加
    'group_by_delivery_course' => 'boolean',
    'processed_at' => 'datetime',
];
```

### 10.2 PrintRequestService 修正

**ファイル:** `app/Services/PrintRequestService.php` (または該当ファイル)

ピッキング結果から `earning_id` と `stock_transfer_id` を収集して `PrintRequestQueue` に渡す:

```php
public function createPrintRequest(int $deliveryCourseId, string $shipmentDate, int $warehouseId, ?int $waveId = null): array
{
    // 対象のピッキングタスクを取得
    $query = WmsPickingTask::where('delivery_course_id', $deliveryCourseId)
        ->where('shipment_date', $shipmentDate);

    if ($waveId) {
        $query->where('wave_id', $waveId);
    }

    $tasks = $query->with(['pickingItemResults'])->get();

    if ($tasks->isEmpty()) {
        return ['success' => false, 'message' => '対象のピッキングタスクが見つかりません。'];
    }

    // earning_id と stock_transfer_id を収集
    $earningIds = [];
    $stockTransferIds = [];

    foreach ($tasks as $task) {
        foreach ($task->pickingItemResults as $itemResult) {
            if ($itemResult->earning_id && !in_array($itemResult->earning_id, $earningIds)) {
                $earningIds[] = $itemResult->earning_id;
            }
            if ($itemResult->stock_transfer_id && !in_array($itemResult->stock_transfer_id, $stockTransferIds)) {
                $stockTransferIds[] = $itemResult->stock_transfer_id;
            }
        }
    }

    // 売上も倉庫移動もない場合はエラー
    if (empty($earningIds) && empty($stockTransferIds)) {
        return ['success' => false, 'message' => '印刷対象の売上・倉庫移動が見つかりません。'];
    }

    // print_request_queue にレコード作成
    $queue = PrintRequestQueue::create([
        'client_id' => $this->resolveClientId($earningIds, $stockTransferIds),
        'earning_ids' => $earningIds,
        'stock_transfer_ids' => $stockTransferIds,  // 追加
        'print_type' => 'CLIENT_SLIP',
        'group_by_delivery_course' => true,
        'warehouse_id' => $warehouseId,
        'printer_driver_id' => $printerDriverId,
        'status' => 'pending',
    ]);

    return [
        'success' => true,
        'message' => '印刷依頼を作成しました。',
        'queue_id' => $queue->id,
        'earning_count' => count($earningIds),
        'stock_transfer_count' => count($stockTransferIds),
    ];
}
```

### 10.3 確認事項

1. **wms_picking_item_results テーブル**
   - `stock_transfer_id` カラムが存在すること
   - ピッキング時に `stock_transfer_id` が正しく設定されていること

2. **テスト**
   - 売上のみの印刷リクエスト
   - 倉庫移動のみの印刷リクエスト
   - 売上 + 倉庫移動の混在印刷リクエスト
