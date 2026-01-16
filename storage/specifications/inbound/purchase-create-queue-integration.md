# 仕入伝票生成キュー連携仕様書

## 概要

WMSから基幹システムに仕入伝票を登録するためのキューベース連携仕様。
`purchase_create_queue` テーブルにレコードをINSERTすることで、基幹システムが自動的に仕入伝票を生成する。

## テーブル構造

### purchase_create_queue テーブル

| カラム名 | 型 | NULL | デフォルト | 説明 |
|---------|-----|------|-----------|------|
| id | bigint | NO | AUTO | 主キー |
| slip_number | varchar | YES | NULL | 生成された伝票番号（基幹で設定） |
| request_user_id | bigint | YES | NULL | リクエストユーザーID |
| request_uuid | varchar | NO | - | WMS側で生成する一意識別子 |
| delivered_date | date | NO | - | 入荷日 |
| items | json | NO | - | 仕入データ（下記参照） |
| status | enum | NO | 'BEFORE' | 処理状態 |
| is_success | boolean | YES | NULL | 処理成功/失敗 |
| error_message | text | YES | NULL | エラーメッセージ |
| note | text | YES | NULL | 備考 |
| next_retry_at | timestamp | YES | NULL | 次回リトライ日時 |
| retry_count | int | NO | 0 | リトライ回数 |
| created_at | timestamp | NO | - | 作成日時 |
| updated_at | timestamp | NO | - | 更新日時 |

### status の状態遷移

```
BEFORE → PROCESSING → FINISHED (is_success: true/false)
   ↑          |
   └──────────┘ (リトライ時)
```

- `BEFORE`: 未処理（WMSがINSERT時に設定）
- `PROCESSING`: 処理中（基幹が処理開始時に変更）
- `FINISHED`: 処理完了（is_successで成功/失敗を判定）

## items JSON構造

`items` カラムには仕入データをJSON形式で格納する。

### 必須/任意フィールド一覧

```json
{
  "process_date": "2024-01-15",       // 必須: 処理日 (Y-m-d形式)
  "delivered_date": "2024-01-15",     // 必須: 入荷日 (Y-m-d形式)
  "account_date": "2024-01-15",       // 必須: 買掛日 (Y-m-d形式)
  "supplier_code": "001",             // 任意: 仕入先コード (※1)
  "note": "備考テキスト",              // 任意: 備考
  "warehouse_code": "10",             // 必須: 倉庫コード
  "delivered_type_code": null,        // 任意: 納品区分コード
  "slip_number": "",                  // 任意: 伝票番号
  "serial_id": null,                  // 任意: 識別ID（既存伝票更新時）
  "is_returned": false,               // 任意: 返品フラグ (true=返品)
  "is_direct_delivery": false,        // 任意: 直送フラグ
  "edi_partner": null,                // 任意: EDI取引先
  "details": [                        // 必須: 明細配列
    {
      "item_code": "10001",           // 必須: 商品コード
      "trade_type_code": null,        // 任意: 取引区分コード
      "quantity": 10,                 // 必須: 数量 (整数)
      "quantity_type": "PIECE",       // 必須: 数量区分 (PIECE/CASE)
      "stock_allocation_code": null,  // 任意: 在庫配分コード
      "price": null,                  // 任意: 単価 (※2)
      "price_category": null,         // 任意: 価格カテゴリ
      "expiration_date": "2026-03-15",// 任意: 賞味期限 (※3)
      "note": ""                      // 任意: 明細備考
    }
  ]
}
```

### 注意事項

**※1 supplier_code について**
- 指定がない場合、商品ごとに `item_contractors` テーブルから仕入先を自動取得
- 同一伝票内で仕入先が異なる場合、仕入先ごとに伝票が分割される

**※2 price について**
- 指定がない場合、`item_prices` テーブルから自動取得
- 指定する場合は数値（小数可）

**※3 expiration_date について**
- 指定がない場合、基幹側で `delivered_date + items.default_expiration_days` から自動計算
- 商品の `default_expiration_days` が `null` の場合は賞味期限なし（nullのまま）
- 指定する場合は `Y-m-d` 形式の日付文字列
- この値は `real_stock_lots.expiration_date` に反映され、FIFO（先入先出）での出庫順序に影響する

### quantity_type の値

| 値 | 説明 |
|----|------|
| PIECE | バラ |
| CASE | ケース |

### is_returned の使い方

| 値 | 説明 |
|----|------|
| false | 通常仕入 |
| true | 返品（マイナス処理） |

## WMS側の実装

### INSERTサンプル（SQL）

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
    'wms-purchase-20240115-001',  -- 一意のUUID
    '2024-01-15',
    '{
        "process_date": "2024-01-15",
        "delivered_date": "2024-01-15",
        "account_date": "2024-01-15",
        "supplier_code": "001",
        "note": "",
        "warehouse_code": "10",
        "is_returned": false,
        "details": [
            {
                "item_code": "10001",
                "quantity": 10,
                "quantity_type": "PIECE",
                "expiration_date": "2026-03-15"
            },
            {
                "item_code": "10002",
                "quantity": 5,
                "quantity_type": "CASE",
                "expiration_date": "2026-04-20"
            }
        ]
    }',
    'BEFORE',
    0,
    NOW(),
    NOW()
);
```

### 処理結果の確認

```sql
-- 特定のリクエストの結果確認
SELECT
    id,
    request_uuid,
    slip_number,      -- 成功時に伝票番号が設定される
    status,
    is_success,
    error_message,
    retry_count
FROM purchase_create_queue
WHERE request_uuid = 'wms-purchase-20240115-001';
```

### 結果の判定

| status | is_success | 意味 |
|--------|------------|------|
| BEFORE | NULL | 未処理（処理待ち） |
| PROCESSING | NULL | 処理中 |
| FINISHED | true | 成功（slip_numberに伝票番号） |
| FINISHED | false | 失敗（error_messageにエラー内容） |

## 売上キュー (earning_create_queue) との比較

| 項目 | 仕入 (purchase) | 売上 (earning) |
|------|----------------|----------------|
| 取引先 | supplier_code (仕入先) | buyer_code (得意先) |
| 日付項目 | account_date (買掛日) | account_date (売掛日) |
| 追加項目 | is_direct_delivery, price_category | delivery_course_code, is_delivered, order_quantity, order_quantity_type |
| 取引方向 | is_returned で判定 | is_returned で判定 |

## 基幹側の処理フロー

1. `queue:work` がポーリング（約3秒間隔）
2. `status='BEFORE'` のレコードを検出
3. `ProcessPurchaseCreateQueue` ジョブをディスパッチ
4. `PostPurchases::executeWithValidation()` で仕入伝票を生成
5. 成功時: `slip_number` を設定し `status='FINISHED', is_success=true`
6. 失敗時: リトライ可能（3回まで）なら1分後に再試行、不可なら `status='FINISHED', is_success=false`

## エラー時の対応

### よくあるエラー

| エラーメッセージ | 原因 | 対処 |
|-----------------|------|------|
| 権限のあるユーザーが見つかりません | システムユーザーが設定されていない | 基幹側で確認 |
| The supplier_code field must be... | 仕入先コードが存在しない | 正しいコードを指定 |
| The warehouse_code field must be... | 倉庫コードが存在しない | 正しいコードを指定 |
| The item_code field must be... | 商品コードが存在しない | 正しいコードを指定 |
| バリデーションエラー | 必須項目不足など | items JSONの内容を確認 |

### 手動リトライ

```sql
-- 失敗したレコードを再処理対象にする
UPDATE purchase_create_queue
SET status = 'BEFORE',
    retry_count = 0,
    next_retry_at = NULL,
    is_success = NULL,
    error_message = NULL,
    updated_at = NOW()
WHERE id = [対象ID]
  AND status = 'FINISHED'
  AND is_success = false;
```

## 関連ファイル（基幹システム）

- モデル: `app/Models/PurchaseCreateQueue.php`
- ジョブ: `app/Jobs/ProcessPurchaseCreateQueue.php`
- API: `app/Actions/API/PostPurchases.php`
- ポーリング: `app/Queue/DatabaseWithCustomQueue.php`
- テスト: `tests/Unit/ProcessPurchaseCreateQueueTest.php`
