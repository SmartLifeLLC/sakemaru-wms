# 入庫予定管理機能 実装進捗

## 作成日: 2026-01-10

## 背景・目的

自動発注システムにおいて、以下のギャップを特定し対応:

1. 発注確定後の入庫予定データが存在しなかった
2. 入庫確定→仕入れデータ作成のフローがなかった
3. 在庫スナップショットに入荷予定数が反映されていなかった（`total_incoming_piece` が常に0）

### 要件
- 発注は自動発注だけでなく**手動発注**もある
- 入庫確定時に `purchase_create_queue` に登録して仕入れデータを作成

---

## 実装完了項目

### 1. データベース

#### マイグレーション
- **ファイル**: `database/migrations/2026_01_10_015202_create_wms_order_incoming_schedules_table.php`
- **テーブル**: `wms_order_incoming_schedules`

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

**ステータス**: 実行済み

---

### 2. Enum

#### IncomingScheduleStatus
- **ファイル**: `app/Enums/AutoOrder/IncomingScheduleStatus.php`
- **値**: PENDING(未入庫), PARTIAL(一部入庫), CONFIRMED(入庫完了), CANCELLED(キャンセル)

#### OrderSource
- **ファイル**: `app/Enums/AutoOrder/OrderSource.php`
- **値**: AUTO(自動発注), MANUAL(手動発注)

---

### 3. モデル

#### WmsOrderIncomingSchedule
- **ファイル**: `app/Models/WmsOrderIncomingSchedule.php`

**リレーション**:
- warehouse(), item(), contractor(), supplier()
- orderCandidate() - WmsOrderCandidate
- confirmedByUser() - User

**スコープ**:
- pending(), partial(), confirmed(), notCompleted()
- forWarehouse(), expectedBefore()
- fromAutoOrder(), fromManualOrder()

**アクセサ**:
- remaining_quantity - 残り入庫数量
- is_fully_received - 入庫完了フラグ

**メソッド**:
- addReceivedQuantity() - 入庫数量追加
- confirm() - 入庫確定
- cancel() - キャンセル

---

### 4. サービス

#### OrderExecutionService
- **ファイル**: `app/Services/AutoOrder/OrderExecutionService.php`
- **役割**: 発注確定 → 入庫予定作成

| メソッド | 説明 |
|----------|------|
| executeCandidate() | 発注候補を確定し入庫予定を作成 |
| executeBatch() | バッチ単位で一括確定 |
| createManualIncomingSchedule() | 手動発注から入庫予定を作成 |

#### IncomingConfirmationService
- **ファイル**: `app/Services/AutoOrder/IncomingConfirmationService.php`
- **役割**: 入庫確定 → 仕入れデータ作成

| メソッド | 説明 |
|----------|------|
| confirmIncoming() | 入庫確定、purchase_create_queue登録 |
| recordPartialIncoming() | 一部入庫を記録 |
| confirmMultiple() | 複数入庫予定を一括確定 |
| cancelIncoming() | 入庫予定をキャンセル |

---

### 5. StockSnapshotService 更新

- **ファイル**: `app/Services/AutoOrder/StockSnapshotService.php`
- **変更内容**:
  - `generateSnapshots()` メソッドを更新
  - `wms_order_incoming_schedules` から PENDING/PARTIAL の残数量を集計
  - `total_incoming_piece` に入荷予定数を反映
  - `getIncomingStocks()` メソッドを実装

---

## データフロー図

```
┌─────────────────┐     ┌──────────────────────────┐
│  発注候補       │     │  手動発注                 │
│ (APPROVED)      │     │                          │
└────────┬────────┘     └────────────┬─────────────┘
         │                           │
         │ OrderExecutionService     │ OrderExecutionService
         │ .executeCandidate()       │ .createManualIncomingSchedule()
         │                           │
         └───────────┬───────────────┘
                     ▼
         ┌───────────────────────────┐
         │ wms_order_incoming_schedules │
         │ status: PENDING           │
         └───────────┬───────────────┘
                     │
                     │ 入庫作業
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
                     │ バッチ処理
                     ▼
         ┌───────────────────────────┐
         │ 仕入れデータ作成           │
         │ (purchases テーブル)      │
         └───────────────────────────┘
```

---

## 未実装・今後の課題

### 優先度高
1. [ ] Filament リソース作成（入庫予定管理画面）
2. [ ] 入庫予定一覧・確定画面の実装
3. [ ] 手動発注入力画面の実装

### 優先度中
4. [ ] 季節別発注点の動的調整（Python分析ツールとの連携）
5. [ ] リードタイム（2日）を考慮した将来予測
6. [ ] purchase_create_queue 処理結果の反映（purchase_slip_number更新）

### 優先度低
7. [ ] 入庫予定のメール/通知機能
8. [ ] 入庫予定レポート

---

## 関連ファイル一覧

```
database/migrations/
└── 2026_01_10_015202_create_wms_order_incoming_schedules_table.php

app/Enums/AutoOrder/
├── IncomingScheduleStatus.php
└── OrderSource.php

app/Models/
└── WmsOrderIncomingSchedule.php

app/Services/AutoOrder/
├── OrderExecutionService.php
├── IncomingConfirmationService.php
└── StockSnapshotService.php (更新)
```

---

## 参考資料

- `storage/specifications/auto-ordering/PURCHASE_CREATE_QUEUE_GUIDE.md` - 仕入れキュー仕様
- `/Users/jungsinyu/PycharmProjects/HanaDBTransfer/data-analysis/hana/seasonal_order_point_analysis/` - 季節別発注点分析ツール
