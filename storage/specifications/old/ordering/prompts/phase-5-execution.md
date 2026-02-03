# Phase 5: 発注実行・送信

## 目的
承認済み候補データを確定し、実際の発注処理（JX送信/CSV生成/倉庫間移動）を実行する。

---

## 実装タスク

### 1. データベースマイグレーション

#### 1.1 JXドキュメントテーブル
```sql
CREATE TABLE wms_order_jx_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wms_order_jx_setting_id BIGINT UNSIGNED NOT NULL,
    batch_code CHAR(14) NOT NULL,

    -- 対象情報
    warehouse_id BIGINT UNSIGNED NOT NULL,
    contractor_id BIGINT UNSIGNED NOT NULL,

    -- ドキュメント情報
    document_type VARCHAR(50) DEFAULT 'ORDER',
    document_number VARCHAR(50) NULL COMMENT 'JX伝票番号',

    -- ステータス
    status ENUM('READY', 'SENT', 'FAILED', 'CANCELLED') DEFAULT 'READY',

    -- 送信情報
    sent_at DATETIME NULL,
    retry_count INT DEFAULT 0,
    last_error TEXT NULL,

    -- ファイル情報
    file_path VARCHAR(255) NULL COMMENT '生成されたXMLファイルパス',
    file_url VARCHAR(255) NULL,

    -- レスポンス
    response_code VARCHAR(50) NULL,
    response_message TEXT NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_batch (batch_code),
    INDEX idx_status (status),
    INDEX idx_setting (wms_order_jx_setting_id)
) COMMENT 'JX送信ドキュメント';
```

#### 1.2 発注実行履歴テーブル
```sql
CREATE TABLE wms_order_execution_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_code CHAR(14) NOT NULL,

    execution_type ENUM('TRANSFER', 'JX', 'CSV', 'FTP') NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    contractor_id BIGINT UNSIGNED NULL,

    -- 実行結果
    status ENUM('SUCCESS', 'PARTIAL', 'FAILED') NOT NULL,
    total_candidates INT NOT NULL,
    success_count INT NOT NULL,
    failed_count INT NOT NULL,

    -- 詳細
    details JSON NULL,
    error_details TEXT NULL,

    executed_by BIGINT UNSIGNED NULL,
    executed_at DATETIME NOT NULL,

    INDEX idx_batch (batch_code)
) COMMENT '発注実行履歴';
```

---

### 2. サービスクラス

#### 2.1 OrderExecutionService
```php
namespace App\Services\AutoOrder;

class OrderExecutionService
{
    /**
     * 承認済み候補の発注を実行
     * Phase 4: 12:00に実行
     */
    public function executeAll(string $batchCode): ExecutionResult
    {
        $results = [];

        // 1. 移動候補の実行（Satellite分）
        $transferResult = $this->executeStockTransfers($batchCode);
        $results['transfers'] = $transferResult;

        // 2. 発注候補の実行（Hub分）
        $orderResult = $this->executeOrders($batchCode);
        $results['orders'] = $orderResult;

        return new ExecutionResult($results);
    }

    /**
     * 移動候補の実行
     */
    public function executeStockTransfers(string $batchCode): TransferExecutionResult
    {
        $candidates = WmsStockTransferCandidate::where('batch_code', $batchCode)
            ->where('status', 'APPROVED')
            ->whereNotIn('lot_status', ['BLOCKED'])
            ->get();

        foreach ($candidates as $candidate) {
            try {
                // stock_transfer_queueを作成
                $this->createStockTransferQueue($candidate);

                $candidate->update([
                    'status' => 'EXECUTED',
                ]);
            } catch (\Exception $e) {
                // エラーログ記録
            }
        }

        return new TransferExecutionResult(...);
    }

    /**
     * stock_transfer_queueを作成
     */
    private function createStockTransferQueue(WmsStockTransferCandidate $candidate): int
    {
        return DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
            'client_id' => config('app.client_id'),
            'slip_number' => null, // 自動採番
            'process_date' => $candidate->expected_arrival_date,
            'delivered_date' => $candidate->expected_arrival_date,
            'note' => '自動発注による移動',
            'items' => json_encode([
                [
                    'item_code' => $candidate->item->code,
                    'quantity' => $candidate->transfer_quantity,
                    'quantity_type' => $candidate->quantity_type,
                    'stock_allocation_code' => '1',
                    'note' => "移動候補ID: {$candidate->id}",
                ],
            ], JSON_UNESCAPED_UNICODE),
            'from_warehouse_code' => $candidate->hubWarehouse->code,
            'to_warehouse_code' => $candidate->satelliteWarehouse->code,
            'request_id' => "order-{$candidate->id}",
            'status' => 'BEFORE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
```

#### 2.2 JXTransmissionService
```php
namespace App\Services\AutoOrder;

class JXTransmissionService
{
    /**
     * JX発注データを送信
     */
    public function transmit(WmsOrderJxDocument $document): TransmissionResult
    {
        $setting = $document->jxSetting;

        try {
            // 1. XMLドキュメント生成
            $xml = $this->generateXml($document);

            // 2. SSL接続設定
            $client = $this->createHttpClient($setting);

            // 3. 送信
            $response = $client->post($setting->endpoint_url, [
                'body' => $xml,
                'headers' => [
                    'Content-Type' => 'application/xml',
                ],
            ]);

            // 4. レスポンス解析
            $result = $this->parseResponse($response);

            $document->update([
                'status' => 'SENT',
                'sent_at' => now(),
                'response_code' => $result->code,
                'response_message' => $result->message,
            ]);

            return new TransmissionResult(true, $result);

        } catch (\Exception $e) {
            $document->update([
                'status' => 'FAILED',
                'retry_count' => $document->retry_count + 1,
                'last_error' => $e->getMessage(),
            ]);

            return new TransmissionResult(false, null, $e->getMessage());
        }
    }

    /**
     * JX形式のXMLを生成
     */
    private function generateXml(WmsOrderJxDocument $document): string;
}
```

#### 2.3 CsvExportService
```php
namespace App\Services\AutoOrder;

class CsvExportService
{
    /**
     * 発注CSVを生成
     */
    public function exportOrderCsv(string $batchCode, int $warehouseId, int $contractorId): string
    {
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('warehouse_id', $warehouseId)
            ->where('contractor_id', $contractorId)
            ->where('status', 'APPROVED')
            ->get();

        $setting = WmsWarehouseContractorSetting::where('warehouse_id', $warehouseId)
            ->where('contractor_id', $contractorId)
            ->first();

        // フォーマット戦略クラスを使用
        $formatter = app($setting->format_strategy_class);
        $csv = $formatter->format($candidates);

        // ファイル保存
        $fileName = "order_{$batchCode}_{$warehouseId}_{$contractorId}.csv";
        $path = Storage::disk('orders')->put($fileName, $csv);

        return $path;
    }
}
```

#### 2.4 FtpUploadService
```php
namespace App\Services\AutoOrder;

class FtpUploadService
{
    /**
     * CSVファイルをFTPアップロード
     */
    public function upload(string $localPath, WmsOrderFtpSetting $setting): UploadResult
    {
        $connection = $this->createConnection($setting);

        try {
            $remotePath = $setting->remote_directory . '/' . basename($localPath);
            $connection->put($remotePath, $localPath);

            return new UploadResult(true, $remotePath);
        } catch (\Exception $e) {
            return new UploadResult(false, null, $e->getMessage());
        }
    }
}
```

---

### 3. Artisanコマンド

#### 3.1 発注実行コマンド
```bash
php artisan wms:execute-orders {batchCode?} {--warehouse=} {--contractor=}
```

#### 3.2 JX送信コマンド
```bash
php artisan wms:transmit-jx-orders {batchCode?} {--retry-failed}
```

#### 3.3 CSV出力コマンド
```bash
php artisan wms:export-order-csv {batchCode?} {--warehouse=} {--contractor=}
```

---

### 4. スケジューラ設定（追加）

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // ... Phase 1-2のスケジュール ...

    // Phase 4: 発注実行 (12:00)
    $schedule->command('wms:execute-orders')
        ->dailyAt('12:00');

    // JXリトライ (12:30, 13:00)
    $schedule->command('wms:transmit-jx-orders --retry-failed')
        ->dailyAt('12:30');
}
```

---

### 5. Filament管理画面（追加）

#### 5.1 JXドキュメント一覧（WmsOrderJxDocumentResource）

**一覧テーブル:**
- バッチコード
- 倉庫
- 発注先
- ステータス
- 送信日時
- レスポンス

**アクション:**
- 再送信
- XMLプレビュー
- キャンセル

#### 5.2 実行履歴一覧

**表示内容:**
- バッチごとの実行サマリー
- 成功/失敗件数
- エラー詳細

---

### 6. フォーマット戦略パターン

```php
interface OrderFormatStrategy
{
    public function format(Collection $candidates): string;
}

class DefaultCsvFormatter implements OrderFormatStrategy
{
    public function format(Collection $candidates): string
    {
        // デフォルトのCSVフォーマット
    }
}

class ContractorACsvFormatter implements OrderFormatStrategy
{
    public function format(Collection $candidates): string
    {
        // 発注先A専用フォーマット
    }
}
```

---

## エラーハンドリング

### リトライ戦略
- JX送信失敗時: 最大3回リトライ（指数バックオフ）
- FTPアップロード失敗時: 最大3回リトライ

### 部分失敗時の処理
- 失敗した候補のみ `FAILED` マーク
- 成功した候補は `EXECUTED`
- 後から失敗分のみ再実行可能

---

## テスト項目

1. [ ] stock_transfer_queue生成
2. [ ] JX XML生成
3. [ ] JX送信（モック）
4. [ ] CSV生成（各フォーマット）
5. [ ] FTPアップロード（モック）
6. [ ] リトライ処理
7. [ ] 部分失敗時の処理

---

## 次のフェーズ
Phase 6（運用・監視）へ進む
