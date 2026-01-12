# 入荷システム仕様書

入荷予定管理・入庫確定・仕入れデータ連携の統合仕様書

**最終更新**: 2026-01-12

---

## 1. 概要

発注確定後の入庫予定を管理し、入庫確定時に仕入れデータを自動生成するシステム。
自動発注・手動発注の両方に対応。

---

## 2. データフロー

```
┌─────────────────┐     ┌──────────────────────────┐
│  発注候補       │     │  手動発注                 │
│ (APPROVED)      │     │                          │
└────────┬────────┘     └────────────┬─────────────┘
         │                           │
         │ OrderExecutionService     │ createManualIncomingSchedule()
         │ .executeCandidate()       │
         │                           │
         └───────────┬───────────────┘
                     ▼
         ┌───────────────────────────┐
         │ wms_order_incoming_schedules │
         │ status: PENDING           │
         └───────────┬───────────────┘
                     │
         ┌───────────┴───────────────┐
         │                           │
         ▼                           ▼
    在庫計算に反映              入庫作業
    (StockSnapshotService)           │
                                     ▼
                     ┌───────────────────────────┐
                     │ IncomingConfirmationService │
                     │ .confirmIncoming()         │
                     │ .recordPartialIncoming()   │
                     └───────────┬───────────────┘
                                 │
                                 ▼
                     ┌───────────────────────────┐
                     │ purchase_create_queue     │
                     │ status: BEFORE            │
                     └───────────┬───────────────┘
                                 │
                                 │ sakemaru-ai-core
                                 ▼
                     ┌───────────────────────────┐
                     │ 仕入れデータ作成           │
                     │ (purchases テーブル)      │
                     └───────────────────────────┘
```

---

## 3. データベース設計

### 3.1 wms_order_incoming_schedules

入庫予定を管理するメインテーブル

| カラム | 型 | 説明 |
|--------|------|------|
| warehouse_id | bigint | 入庫倉庫 |
| item_id | bigint | 商品ID |
| contractor_id | bigint | 発注先ID |
| supplier_id | bigint? | 仕入先ID |
| order_candidate_id | bigint? | 自動発注: wms_order_candidates.id |
| manual_order_number | varchar(50)? | 手動発注: 発注番号 |
| order_source | enum | AUTO / MANUAL |
| expected_quantity | int | 予定数量 |
| received_quantity | int | 入庫済み数量 |
| quantity_type | enum | PIECE / CASE / CARTON |
| order_date | date | 発注日 |
| expected_arrival_date | date | 入庫予定日 |
| actual_arrival_date | date? | 実際の入庫日 |
| status | enum | PENDING / PARTIAL / CONFIRMED / CANCELLED |
| confirmed_at | datetime? | 入庫確定日時 |
| confirmed_by | bigint? | 入庫確定者ID |
| purchase_queue_id | bigint? | purchase_create_queue.id |
| purchase_slip_number | varchar(50)? | 生成された仕入伝票番号 |
| note | text? | 備考 |

### 3.2 ステータス遷移

```
PENDING ──┬──> PARTIAL ──> CONFIRMED
          │       │
          │       └────────> CANCELLED
          │
          └──> CONFIRMED
          │
          └──> CANCELLED
```

| ステータス | 説明 |
|-----------|------|
| PENDING | 未入庫 |
| PARTIAL | 一部入庫 |
| CONFIRMED | 入庫完了 |
| CANCELLED | キャンセル |

### 3.3 発注元（OrderSource）

| 値 | 説明 |
|----|------|
| AUTO | 自動発注（wms_order_candidatesから） |
| MANUAL | 手動発注 |

---

## 4. サービスクラス

### 4.1 OrderExecutionService

発注確定時に入庫予定を作成

```php
// 自動発注からの入庫予定作成
$service->executeCandidate($candidate, $executedBy);

// 手動発注からの入庫予定作成
$service->createManualIncomingSchedule([
    'warehouse_id' => 1,
    'item_id' => 100,
    'contractor_id' => 10,
    'expected_quantity' => 50,
    'expected_arrival_date' => '2026-01-15',
], $createdBy);

// バッチ単位で一括確定
$service->executeBatch($batchCode, $executedBy);
```

### 4.2 IncomingConfirmationService

入庫確定時に仕入れデータを作成

```php
// 入庫確定（全量）
$service->confirmIncoming($schedule, $confirmedBy);

// 入庫確定（数量指定）
$service->confirmIncoming($schedule, $confirmedBy, $receivedQuantity, $actualDate);

// 一部入庫
$service->recordPartialIncoming($schedule, $receivedQuantity, $confirmedBy);

// 複数一括確定
$service->confirmMultiple($scheduleIds, $confirmedBy);

// キャンセル
$service->cancelIncoming($schedule, $cancelledBy, $reason);
```

### 4.3 StockSnapshotService

入荷予定数を在庫計算に反映

```php
// スナップショット生成時に入荷予定数を集計
// PENDING/PARTIAL ステータスの残数量を total_incoming_piece に反映
$service->generateSnapshots($warehouseIds);

// 入荷予定数を取得
$incomingStocks = $service->getIncomingStocks($warehouseIds);
// ['warehouse_id-item_id' => quantity]
```

---

## 5. purchase_create_queue 連携

### 5.1 概要

`purchase_create_queue`テーブルにデータをINSERTすると、sakemaru-ai-coreのキューワーカーが自動的に仕入伝票を生成。

### 5.2 JSON構造

```json
{
  "process_date": "2026-01-10",
  "delivered_date": "2026-01-10",
  "account_date": "2026-01-10",
  "supplier_code": "1",
  "warehouse_code": "1",
  "note": "自動発注システム連携",
  "details": [
    {
      "item_code": "10000",
      "quantity": 10,
      "quantity_type": "PIECE"
    }
  ]
}
```

### 5.3 ステータス遷移

```
BEFORE → PROCESSING → FINISHED (is_success=true/false)
```

---

## 6. モデル（WmsOrderIncomingSchedule）

### 6.1 リレーション

```php
$schedule->warehouse     // 倉庫
$schedule->item          // 商品
$schedule->contractor    // 発注先
$schedule->supplier      // 仕入先
$schedule->orderCandidate // 元の発注候補
$schedule->confirmedByUser // 確定者
```

### 6.2 スコープ

```php
WmsOrderIncomingSchedule::pending()      // PENDING
WmsOrderIncomingSchedule::partial()      // PARTIAL
WmsOrderIncomingSchedule::confirmed()    // CONFIRMED
WmsOrderIncomingSchedule::notCompleted() // PENDING or PARTIAL
WmsOrderIncomingSchedule::forWarehouse($id)
WmsOrderIncomingSchedule::expectedBefore($date)
WmsOrderIncomingSchedule::fromAutoOrder()
WmsOrderIncomingSchedule::fromManualOrder()
```

### 6.3 アクセサ

```php
$schedule->remaining_quantity  // 残り入庫数量
$schedule->is_fully_received   // 入庫完了フラグ
```

---

## 7. 実装状況

| 機能 | 状況 | 備考 |
|------|------|------|
| 入庫予定テーブル | ✅ 完了 | マイグレーション済み |
| OrderExecutionService | ✅ 完了 | 発注確定→入庫予定作成 |
| IncomingConfirmationService | ✅ 完了 | 入庫確定→仕入れキュー |
| StockSnapshotService | ✅ 完了 | 入荷予定数反映 |
| Filament入庫予定リソース | ⬜ 未実装 | 一覧・確定画面 |
| 手動発注入力画面 | ⬜ 未実装 | 手動入庫予定作成UI |
| purchase_slip_number反映 | ⬜ 未実装 | キュー処理後の更新 |

---

## 8. 今後の課題

1. **Filament入庫予定管理画面**
   - 入庫予定一覧
   - 入庫確定アクション
   - 一部入庫対応
   - 手動発注入力

2. **purchase_create_queue結果反映**
   - 処理成功時のpurchase_slip_number更新
   - エラー時の通知

3. **入庫通知機能**
   - 入庫予定日のリマインダー
   - 遅延アラート

---

## 9. 旧仕様書

詳細な設計資料は `old/incoming/` に移動:
- `2026-01-10-incoming-schedule-progress.md` - 実装進捗
- `PURCHASE_CREATE_QUEUE_GUIDE.md` - キュー連携詳細
