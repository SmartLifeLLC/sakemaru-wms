# purchase_create_queue 連携ガイド

sakemaru-ai-coreの仕入伝票自動生成キューとの連携仕様書

## 概要

`purchase_create_queue`テーブルにデータをINSERTすると、sakemaru-ai-coreのキューワーカーが自動的に検知し、仕入伝票を生成します。

## テーブル作成SQL

```sql
CREATE TABLE `purchase_create_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slip_number` varchar(255) DEFAULT NULL COMMENT '生成された伝票番号（処理後に自動設定）',
  `request_user_id` bigint unsigned DEFAULT NULL COMMENT 'リクエストユーザーID',
  `request_uuid` varchar(255) NOT NULL COMMENT 'リクエスト識別UUID（重複チェック用）',
  `delivered_date` date NOT NULL COMMENT '納品日',
  `items` json NOT NULL COMMENT '仕入データ（JSON形式）',
  `status` enum('BEFORE','PROCESSING','FINISHED') NOT NULL DEFAULT 'BEFORE',
  `is_success` tinyint(1) DEFAULT NULL COMMENT '処理成功フラグ',
  `error_message` text DEFAULT NULL COMMENT 'エラーメッセージ',
  `note` text DEFAULT NULL,
  `next_retry_at` timestamp NULL DEFAULT NULL COMMENT '次回リトライ日時',
  `retry_count` int NOT NULL DEFAULT '0' COMMENT 'リトライ回数',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_create_queue_status_index` (`status`),
  KEY `purchase_create_queue_next_retry_at_index` (`next_retry_at`),
  KEY `purchase_create_queue_request_uuid_index` (`request_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## データ挿入例

### 基本的なINSERT

```sql
INSERT INTO purchase_create_queue (
  request_uuid,
  delivered_date,
  items,
  status,
  retry_count,
  created_at,
  updated_at
) VALUES (
  'unique-uuid-12345',  -- 一意のUUID
  '2026-01-10',         -- 納品日
  JSON_OBJECT(
    'process_date', '2026-01-10',      -- 計上日
    'delivered_date', '2026-01-10',    -- 納品日
    'account_date', '2026-01-10',      -- 経理日
    'supplier_code', '1',              -- 仕入先コード
    'warehouse_code', '1',             -- 倉庫コード
    'note', '自動発注システム連携',
    'slip_number', '',                 -- 空でOK（自動生成）
    'is_returned', false,              -- 返品フラグ
    'details', JSON_ARRAY(
      JSON_OBJECT(
        'item_code', '10000',          -- 商品コード
        'quantity', 10,                -- 数量
        'quantity_type', 'PIECE',      -- PIECE/CASE/CARTON
        'trade_type_code', '1',        -- 取引区分コード（任意）
        'stock_allocation_code', '',   -- 在庫区分コード（任意）
        'price', 1000,                 -- 単価（任意）
        'note', ''                     -- 明細備考（任意）
      )
    )
  ),
  'BEFORE',
  0,
  NOW(),
  NOW()
);
```

### 複数明細のINSERT

```sql
INSERT INTO purchase_create_queue (
  request_uuid,
  delivered_date,
  items,
  status,
  retry_count,
  created_at,
  updated_at
) VALUES (
  UUID(),               -- MySQL関数でUUID生成
  CURDATE(),            -- 本日
  JSON_OBJECT(
    'process_date', DATE_FORMAT(CURDATE(), '%Y-%m-%d'),
    'delivered_date', DATE_FORMAT(CURDATE(), '%Y-%m-%d'),
    'account_date', DATE_FORMAT(CURDATE(), '%Y-%m-%d'),
    'supplier_code', '1',
    'warehouse_code', '1',
    'note', '自動発注',
    'details', JSON_ARRAY(
      JSON_OBJECT(
        'item_code', '10000',
        'quantity', 10,
        'quantity_type', 'PIECE'
      ),
      JSON_OBJECT(
        'item_code', '10001',
        'quantity', 5,
        'quantity_type', 'CASE'
      ),
      JSON_OBJECT(
        'item_code', '10002',
        'quantity', 2,
        'quantity_type', 'CARTON'
      )
    )
  ),
  'BEFORE',
  0,
  NOW(),
  NOW()
);
```

## JSON構造仕様

### items（ルート）

| フィールド | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `process_date` | string | ○ | 計上日 (YYYY-MM-DD) |
| `delivered_date` | string | ○ | 納品日 (YYYY-MM-DD) |
| `account_date` | string | ○ | 経理日 (YYYY-MM-DD) |
| `supplier_code` | string | △ | 仕入先コード |
| `warehouse_code` | string | ○ | 倉庫コード |
| `delivered_type_code` | string | - | 納品区分コード |
| `note` | string | - | 伝票備考 |
| `slip_number` | string | - | 伝票番号（空でOK、自動生成される） |
| `serial_id` | string | - | シリアルID |
| `is_returned` | boolean | - | 返品フラグ (default: false) |
| `is_direct_delivery` | boolean | - | 直送フラグ (default: false) |
| `edi_partner` | string | - | EDI取引先 |
| `details` | array | ○ | 明細配列 |

### details（明細）

| フィールド | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `item_code` | string | ○ | 商品コード |
| `quantity` | integer | ○ | 数量 |
| `quantity_type` | string | ○ | 数量タイプ: `PIECE`, `CASE`, `CARTON` |
| `trade_type_code` | string | - | 取引区分コード |
| `stock_allocation_code` | string | - | 在庫区分コード |
| `price` | number | - | 単価（未指定時は商品マスタから取得） |
| `price_category` | integer | - | 価格区分 |
| `note` | string | - | 明細備考 |

## ステータス遷移

```
BEFORE → PROCESSING → FINISHED
           ↓
        (エラー時)
           ↓
        BEFORE (リトライ、最大3回)
           ↓
        FINISHED (is_success=false)
```

| status | 説明 |
|--------|------|
| `BEFORE` | 処理待ち |
| `PROCESSING` | 処理中 |
| `FINISHED` | 処理完了（成功/失敗は`is_success`で判定） |

## 処理結果確認

### 特定のリクエスト確認

```sql
SELECT
  id,
  request_uuid,
  status,
  is_success,
  slip_number,
  error_message,
  retry_count,
  created_at,
  updated_at
FROM purchase_create_queue
WHERE request_uuid = 'unique-uuid-12345';
```

### 未処理件数確認

```sql
SELECT COUNT(*) as pending_count
FROM purchase_create_queue
WHERE status = 'BEFORE';
```

### エラー件数確認

```sql
SELECT
  id,
  request_uuid,
  error_message,
  retry_count,
  created_at
FROM purchase_create_queue
WHERE status = 'FINISHED' AND is_success = false
ORDER BY created_at DESC
LIMIT 10;
```

### 本日の処理状況

```sql
SELECT
  status,
  is_success,
  COUNT(*) as count
FROM purchase_create_queue
WHERE DATE(created_at) = CURDATE()
GROUP BY status, is_success;
```

## PHPでの挿入例

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 仕入データを作成
$purchaseData = [
    'process_date' => now()->format('Y-m-d'),
    'delivered_date' => now()->format('Y-m-d'),
    'account_date' => now()->format('Y-m-d'),
    'supplier_code' => '1',
    'warehouse_code' => '1',
    'note' => '自動発注システム連携',
    'details' => [
        [
            'item_code' => '10000',
            'quantity' => 10,
            'quantity_type' => 'PIECE',
        ],
        [
            'item_code' => '10001',
            'quantity' => 5,
            'quantity_type' => 'CASE',
        ],
    ],
];

// キューに挿入
DB::table('purchase_create_queue')->insert([
    'request_uuid' => Str::uuid()->toString(),
    'delivered_date' => now()->format('Y-m-d'),
    'items' => json_encode($purchaseData),
    'status' => 'BEFORE',
    'retry_count' => 0,
    'created_at' => now(),
    'updated_at' => now(),
]);
```

## 注意事項

1. **request_uuid**: 一意である必要があります。重複チェックに使用されます
2. **status**: 必ず`BEFORE`で挿入してください（キューワーカーが自動処理）
3. **slip_number**: 処理成功後に生成された伝票番号が自動設定されます
4. **リトライ**: エラー時は最大3回まで自動リトライされます（1分間隔）
5. **マスタデータ**: `supplier_code`, `warehouse_code`, `item_code`は事前にマスタ登録が必要です
6. **キューワーカー**: sakemaru-ai-core側で`php artisan queue:work`が実行されている必要があります

## トラブルシューティング

### 処理されない場合

1. キューワーカーが起動しているか確認
   ```bash
   php artisan queue:work
   ```

2. statusが`BEFORE`になっているか確認
   ```sql
   SELECT status FROM purchase_create_queue WHERE id = ?;
   ```

### エラーが発生する場合

1. `error_message`を確認
   ```sql
   SELECT error_message FROM purchase_create_queue WHERE id = ?;
   ```

2. よくあるエラー:
   - `商品が見つかりません`: item_codeがマスタに存在しない
   - `倉庫が見つかりません`: warehouse_codeがマスタに存在しない
   - `仕入先が見つかりません`: supplier_codeがマスタに存在しない
   - `権限のあるユーザーが見つかりません`: システムユーザーが設定されていない
