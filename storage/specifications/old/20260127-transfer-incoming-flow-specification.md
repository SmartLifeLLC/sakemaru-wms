# 移動候補 → 入荷検品フロー 仕様書

## 概要

移動候補（WmsStockTransferCandidate）の確定から入荷検品完了までのフローを定義する。
在庫移動は入荷検品完了時に実行し、物理的な物の流れと在庫データの整合性を確保する。

## 現状の問題点

1. 移動候補確定時に `stock_transfer_queue` が作成され、即座に在庫が移動する
2. Satellite倉庫での入荷検品フローがない
3. Hub倉庫のピッキング完了前に在庫が移動してしまう
4. **当日ピッキング・翌日出荷の場合、波動生成でピッキング対象として抽出されない**

---

## 決定事項

| No | 決定内容 |
|----|---------|
| 1 | stock_transfers に picking_date を追加 |
| 2 | warehouse_stock_transfer_delivery_courses に picking_lead_days を追加 |
| 3 | 配送コース未設定の場合はデフォルト値を使用（config: default_picking_lead_days = 0） |
| 4 | 倉庫間移動の波動生成は picking_date を参照（通常出荷は従来通り） |
| 5 | stock_transfer作成時にHub在庫を減算（予約は使用しない） |
| 6 | ピッキング完了時にstock_transfersの数量を実績に更新 |
| 7 | stock_transfer_queue に action_type を追加（CREATE / UPDATE / DELIVER） |
| 8 | 入荷検品完了時に stock_transfer_queue (action_type=DELIVER) を作成 |
| 9 | sakemaru-ai-coreが action_type=DELIVER を処理してSatellite在庫を加算 + is_delivered を更新 |
| 10 | 基幹システム管理画面からの移動は従来通り（即時反映、WMS起点のみ新フロー適用） |

> **Note**: 在庫予約（wms_reserved_quantity）は使用しない。移動候補確定からstock_transfer作成までの間に在庫が売れる可能性があるが、許容する。

---

## 関連ドキュメント

- **Core実装仕様**: [20260127-transfer-incoming-flow-core-implementation.md](./20260127-transfer-incoming-flow-core-implementation.md)
  - データベースマイグレーション
  - ProcessStockTransfer.php の変更（CREATE/UPDATE/DELIVER処理）
  - UpdateStockTransfer.php の変更

---

## フロー概要

```
【移動候補確定】(WMS)
    ↓
stock_transfer_queue 作成 (action_type=CREATE)
WmsOrderIncomingSchedule 作成 (Satellite入荷予定)
    ↓
【stock_transfer_queue処理】(sakemaru-ai-core)
※ action_type=CREATE の場合
    ↓
stock_transfers 作成 (picking_date 算出)
Hub在庫を減算 (current_quantity 減算)
※ Satellite在庫はまだ変更しない
※ is_delivered = false
    ↓
【Hub倉庫 - ピッキング日】
波動生成 (picking_date = today で抽出)
ピッキング実施
    ↓
【ピッキング完了】(WMS)
stock_transfer_queue 作成 (action_type=UPDATE)
WmsOrderIncomingSchedule.expected_quantity を更新
    ↓
【Hub倉庫 - 出荷日】
トラックに積み込み、Satelliteへ発送
    ↓
【Satellite倉庫 - 入荷日】
入荷検品確定 (WMS)
    ↓
stock_transfer_queue 作成 (action_type=DELIVER)
    ↓
【stock_transfer_queue処理】(sakemaru-ai-core)
※ action_type=DELIVER の場合
    ↓
Satellite在庫を加算
is_delivered = true に更新
```

### 基幹システム管理画面からの移動

基幹システムの管理画面から在庫移動を実施した場合は**従来通り即時反映**：
- `stock_transfers` 作成時に両倉庫の在庫を即時更新
- WMS起点の移動のみ新フロー（入荷検品経由）を適用

---

## 日付とキーフィールドの定義

| データ | フィールド | 内容 | 例 |
|--------|-----------|------|-----|
| `warehouse_stock_transfer_delivery_courses` | `picking_lead_days` | ピッキングリードタイム（日数）**【新規】** | 1 |
| `stock_transfer_queue` | `action_type` | 処理タイプ **【新規】** | CREATE / UPDATE / DELIVER |
| `stock_transfer_queue` | `delivered_date` | Hub出荷日 | 2026-01-28 (土) |
| `stock_transfers` | `picking_date` | Hubピッキング日 **【新規】** | 2026-01-27 (金) |
| `stock_transfers` | `delivered_date` | Hub出荷日 | 2026-01-28 (土) |
| `stock_transfers` | `is_delivered` | 入荷完了フラグ **【用途変更】** | false → true |
| `WmsOrderIncomingSchedule` | `expected_arrival_date` | Satellite入荷予定日 | 2026-01-28 (土) |
| `WmsStockTransferCandidate` | `shipment_date` | Hub出荷日 | 2026-01-28 (土) |
| `WmsStockTransferCandidate` | `expected_arrival_date` | Satellite入荷予定日 | 2026-01-28 (土) |

### picking_date の算出

```
picking_date = delivered_date - picking_lead_days

picking_lead_days の取得優先順位:
1. warehouse_stock_transfer_delivery_courses.picking_lead_days（Hub→Satellite設定）
2. config('wms.default_picking_lead_days', 0)（デフォルト）

例: delivered_date = 2026-01-28, picking_lead_days = 1
    → picking_date = 2026-01-27
```

---

## 在庫の動き

### タイムライン

```
【1/26 移動候補確定】
stock_transfer_queue:
  - action_type: CREATE
WmsOrderIncomingSchedule:
  - status: PENDING
Hub倉庫:
  - 変更なし（stock_transfer作成まで在庫は減らない）
Satellite倉庫:
  - 変更なし

【1/27 stock_transfer作成】(sakemaru-ai-core処理 action_type=CREATE)
Hub倉庫:
  - current_quantity: -100 (実在庫減算)
Satellite倉庫:
  - 変更なし (まだ届いていない)
stock_transfers:
  - is_delivered: false

【1/27 ピッキング完了】
※ 実績数量が98だった場合
stock_transfer_queue:
  - action_type: UPDATE
WmsOrderIncomingSchedule:
  - expected_quantity: 100 → 98 に更新

【1/27 stock_transfer更新】(sakemaru-ai-core処理 action_type=UPDATE)
stock_transfers:
  - 数量: 100 → 98 に更新
Hub倉庫:
  - current_quantity: +2 (差分を戻す)

【1/28 出荷日】
Hub倉庫: トラックに積み込み
  - 在庫変更なし

【1/28 入荷検品確定】(WMS)
stock_transfer_queue:
  - action_type: DELIVER
  - stock_transfer_id: 123
  - items.received_quantity: 95

【1/28 stock_transfer_queue処理】(sakemaru-ai-core処理 action_type=DELIVER)
Satellite倉庫:
  - current_quantity: +95 (実在庫加算、received_quantity ベース)
stock_transfers:
  - is_delivered: true
```

### 理論在庫と実在庫

| タイミング | Hub実在庫 | Satellite実在庫 | 備考 |
|-----------|----------|----------------|------|
| 移動候補確定前 | 100 | 0 | - |
| 移動候補確定後 | 100 | 0 | stock_transfer作成まで在庫は減らない |
| stock_transfer作成後 | 0 | 0 | Hub在庫減算、Satellite未加算 |
| ピッキング完了後（差異あり） | 2 | 0 | 差分戻し |
| 入荷検品完了後 | 2 | 95 | Satellite在庫加算（received_quantity） |

> **Note**: 予約を使用しないため、移動候補確定〜stock_transfer作成の間に在庫が売れる可能性がある。stock_transfer作成時に在庫不足の場合はエラーとなる。

---

## sakemaru-wms 実装仕様

### 1. OrderSource Enum の拡張

**ファイル**: `app/Enums/AutoOrder/OrderSource.php`

```php
<?php

namespace App\Enums\AutoOrder;

enum OrderSource: string
{
    case AUTO = 'AUTO';
    case MANUAL = 'MANUAL';
    case TRANSFER = 'TRANSFER';  // 追加

    public function label(): string
    {
        return match ($this) {
            self::AUTO => '自動発注',
            self::MANUAL => '手動発注',
            self::TRANSFER => '倉庫間移動',
        };
    }
}
```

### 2. WmsOrderIncomingSchedule テーブル拡張

**マイグレーション**: `add_transfer_columns_to_wms_order_incoming_schedules_table.php`

```php
Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
    $table->unsignedBigInteger('transfer_candidate_id')->nullable()->after('order_candidate_id')
        ->comment('移動候補ID (order_source=TRANSFERの場合)');
    $table->unsignedBigInteger('source_warehouse_id')->nullable()->after('transfer_candidate_id')
        ->comment('移動元倉庫ID (order_source=TRANSFERの場合)');
    $table->unsignedBigInteger('stock_transfer_id')->nullable()->after('source_warehouse_id')
        ->comment('stock_transfers.id (sakemaru-ai-core)');

    $table->index('transfer_candidate_id');
    $table->index('source_warehouse_id');
    $table->index('stock_transfer_id');
});

// order_source enum に TRANSFER を追加
DB::connection('sakemaru')->statement("
    ALTER TABLE wms_order_incoming_schedules
    MODIFY COLUMN order_source ENUM('AUTO', 'MANUAL', 'TRANSFER') DEFAULT 'MANUAL'
");
```

### 3. WmsStockTransferCandidate テーブル拡張

**マイグレーション**: `add_shipment_date_to_wms_stock_transfer_candidates_table.php`

```php
Schema::connection('sakemaru')->table('wms_stock_transfer_candidates', function (Blueprint $table) {
    $table->date('shipment_date')->nullable()->after('expected_arrival_date')
        ->comment('Hub出荷日');
});

// 既存データの shipment_date を expected_arrival_date と同じ値で更新
DB::connection('sakemaru')->statement('
    UPDATE wms_stock_transfer_candidates
    SET shipment_date = expected_arrival_date
    WHERE shipment_date IS NULL
');
```

### 4. TransferCandidateExecutionService の変更

**ファイル**: `app/Services/AutoOrder/TransferCandidateExecutionService.php`

**目的**:
- WmsOrderIncomingSchedule を作成
- stock_transfer_queue に action_type=CREATE を設定
- ※ Hub在庫の予約は行わない（stock_transfer作成時に減算）

```php
<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Enums\AutoOrder\OrderSource;
use App\Models\WmsOrderIncomingSchedule;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransferCandidateExecutionService
{
    /**
     * 移動候補を確定
     * - stock_transfer_queue を作成 (action_type=CREATE)
     * - WmsOrderIncomingSchedule を作成（Satellite入荷予定）
     * ※ Hub在庫の予約は行わない（stock_transfer作成時に減算）
     */
    public function executeCandidate(WmsStockTransferCandidate $candidate, int $executedBy): array
    {
        if ($candidate->status !== CandidateStatus::APPROVED) {
            throw new \RuntimeException(
                "Candidate {$candidate->id} must be APPROVED before execution."
            );
        }

        return DB::connection('sakemaru')->transaction(function () use ($candidate, $executedBy) {
            // 1. stock_transfer_queue を作成 (action_type=CREATE)
            $queueId = $this->createStockTransferQueue($candidate);

            // 2. WmsOrderIncomingSchedule を作成（Satellite入荷予定）
            $schedule = $this->createIncomingSchedule($candidate);

            // 3. 移動候補のステータスを更新
            $candidate->update([
                'status' => CandidateStatus::EXECUTED,
                'modified_by' => $executedBy,
                'modified_at' => now(),
            ]);

            Log::info('Transfer candidate executed', [
                'candidate_id' => $candidate->id,
                'queue_id' => $queueId,
                'incoming_schedule_id' => $schedule->id,
            ]);

            return [
                'queue_id' => $queueId,
                'incoming_schedule_id' => $schedule->id,
            ];
        });
    }

    /**
     * stock_transfer_queue を作成 (action_type=CREATE)
     */
    private function createStockTransferQueue(WmsStockTransferCandidate $candidate): int
    {
        $hubWarehouse = $candidate->hubWarehouse;
        $satelliteWarehouse = $candidate->satelliteWarehouse;
        $item = $candidate->item;

        $items = [[
            'item_code' => $item->code,
            'quantity' => $candidate->transfer_quantity,
            'quantity_type' => $candidate->quantity_type->value,
            'stock_allocation_code' => '1',
            'note' => "移動候補ID: {$candidate->id}",
        ]];

        $requestId = "transfer-create-{$candidate->id}";

        return DB::connection('sakemaru')->table('stock_transfer_queue')->insertGetId([
            'client_id' => config('app.client_id'),
            'request_id' => $requestId,
            'slip_number' => null,
            'process_date' => $candidate->shipment_date?->format('Y-m-d') ?? $candidate->expected_arrival_date->format('Y-m-d'),
            'delivered_date' => $candidate->shipment_date?->format('Y-m-d') ?? $candidate->expected_arrival_date->format('Y-m-d'),
            'from_warehouse_code' => $hubWarehouse->code,
            'to_warehouse_code' => $satelliteWarehouse->code,
            'delivery_course_id' => $candidate->delivery_course_id,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'note' => "自動発注移動 バッチ:{$candidate->batch_code}",
            'status' => 'BEFORE',
            'action_type' => 'CREATE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * WmsOrderIncomingSchedule を作成（Satellite入荷予定）
     */
    private function createIncomingSchedule(WmsStockTransferCandidate $candidate): WmsOrderIncomingSchedule
    {
        return WmsOrderIncomingSchedule::create([
            'warehouse_id' => $candidate->satellite_warehouse_id,
            'item_id' => $candidate->item_id,
            'search_code' => $this->getSearchCodeForItem($candidate->item_id),
            'contractor_id' => $candidate->contractor_id,
            'supplier_id' => null,
            'order_candidate_id' => null,
            'transfer_candidate_id' => $candidate->id,
            'source_warehouse_id' => $candidate->hub_warehouse_id,
            'stock_transfer_id' => null,  // 後で同期
            'order_source' => OrderSource::TRANSFER,
            'expected_quantity' => $candidate->transfer_quantity,
            'received_quantity' => 0,
            'quantity_type' => $candidate->quantity_type,
            'order_date' => now()->format('Y-m-d'),
            'expected_arrival_date' => $candidate->expected_arrival_date,
            'status' => IncomingScheduleStatus::PENDING,
        ]);
    }

    /**
     * 商品の検索コードを取得
     */
    private function getSearchCodeForItem(int $itemId): ?string
    {
        return DB::connection('sakemaru')
            ->table('item_search_information')
            ->where('item_id', $itemId)
            ->where('is_used_for_ordering', true)
            ->where('is_active', true)
            ->value('search_string');
    }
}
```

### 5. IncomingConfirmationService の変更

**ファイル**: `app/Services/AutoOrder/IncomingConfirmationService.php`

**目的**: TRANSFER確定時に stock_transfer_queue (action_type=DELIVER) を作成

```php
public function confirmIncoming(
    WmsOrderIncomingSchedule $schedule,
    int $confirmedBy,
    ?int $receivedQuantity = null,
    ?string $actualDate = null,
    ?string $expirationDate = null
): WmsOrderIncomingSchedule {
    // ... existing validation ...

    return DB::connection('sakemaru')->transaction(function () use ($schedule, $confirmedBy, $receivedQuantity, $actualDate, $expirationDate) {
        // ... existing logic ...

        // TRANSFER タイプの場合、納品確定キューを作成
        if ($schedule->order_source === OrderSource::TRANSFER) {
            $this->createDeliverQueue($schedule, $receivedQuantity);
        }

        return $schedule->fresh();
    });
}

/**
 * stock_transfer_queue (action_type=DELIVER) を作成
 */
private function createDeliverQueue(
    WmsOrderIncomingSchedule $schedule,
    ?int $receivedQuantity
): void {
    // stock_transfer_id が未設定の場合、動的に取得
    if (!$schedule->stock_transfer_id) {
        $this->syncStockTransferId($schedule);
    }

    if (!$schedule->stock_transfer_id) {
        throw new \RuntimeException(
            "Stock transfer ID not found for schedule {$schedule->id}"
        );
    }

    $requestId = "transfer-deliver-{$schedule->id}-" . now()->format('YmdHis');

    DB::connection('sakemaru')->table('stock_transfer_queue')->insert([
        'client_id' => config('app.client_id'),
        'request_id' => $requestId,
        'stock_transfer_id' => $schedule->stock_transfer_id,
        'delivered_date' => now()->format('Y-m-d'),
        'items' => json_encode([
            'schedule_id' => $schedule->id,
            'received_quantity' => $receivedQuantity ?? $schedule->expected_quantity,
            'quantity_type' => $schedule->quantity_type->value,
        ], JSON_UNESCAPED_UNICODE),
        'note' => "入荷検品確定 Schedule ID: {$schedule->id}",
        'status' => 'BEFORE',
        'action_type' => 'DELIVER',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Log::info('Deliver queue created', [
        'schedule_id' => $schedule->id,
        'stock_transfer_id' => $schedule->stock_transfer_id,
        'request_id' => $requestId,
    ]);
}

/**
 * stock_transfer_id を動的に取得・設定
 */
private function syncStockTransferId(WmsOrderIncomingSchedule $schedule): void
{
    $queue = DB::connection('sakemaru')
        ->table('stock_transfer_queue')
        ->where('request_id', "transfer-create-{$schedule->transfer_candidate_id}")
        ->where('status', 'FINISHED')
        ->where('is_success', true)
        ->first();

    if ($queue && $queue->stock_transfer_id) {
        $schedule->update(['stock_transfer_id' => $queue->stock_transfer_id]);
        $schedule->refresh();
    }
}
```

### 6. PickingCompleteService（新規）

**ファイル**: `app/Services/AutoOrder/PickingCompleteService.php`

**目的**: ピッキング完了時に UPDATE queue を作成し、WmsOrderIncomingSchedule.expected_quantity を更新

```php
<?php

namespace App\Services\AutoOrder;

use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PickingCompleteService
{
    /**
     * ピッキング完了時の処理
     *
     * @param int $stockTransferId stock_transfers.id
     * @param int $pickedQuantity 実績数量
     * @param int $originalQuantity 元の予定数量
     * @param string $quantityType UNIT/CASE/CARTON
     * @param int $incomingScheduleId WmsOrderIncomingSchedule.id
     */
    public function handlePickingComplete(
        int $stockTransferId,
        int $pickedQuantity,
        int $originalQuantity,
        string $quantityType,
        int $incomingScheduleId
    ): void {
        DB::connection('sakemaru')->transaction(function () use (
            $stockTransferId, $pickedQuantity, $originalQuantity, $quantityType, $incomingScheduleId
        ) {
            // 1. stock_transfer_queue (UPDATE) を作成（差異がある場合のみ）
            if ($pickedQuantity !== $originalQuantity) {
                $this->createUpdateQueue(
                    $stockTransferId,
                    $pickedQuantity,
                    $originalQuantity,
                    $quantityType
                );
            }

            // 2. WmsOrderIncomingSchedule.expected_quantity を更新
            WmsOrderIncomingSchedule::where('id', $incomingScheduleId)
                ->update(['expected_quantity' => $pickedQuantity]);

            Log::info('Picking complete processed', [
                'stock_transfer_id' => $stockTransferId,
                'original_quantity' => $originalQuantity,
                'picked_quantity' => $pickedQuantity,
                'incoming_schedule_id' => $incomingScheduleId,
            ]);
        });
    }

    /**
     * stock_transfer_queue (UPDATE) を作成
     */
    private function createUpdateQueue(
        int $stockTransferId,
        int $pickedQuantity,
        int $originalQuantity,
        string $quantityType
    ): void {
        $requestId = "transfer-update-{$stockTransferId}-" . now()->format('YmdHis');

        DB::connection('sakemaru')->table('stock_transfer_queue')->insert([
            'client_id' => config('app.client_id'),
            'request_id' => $requestId,
            'stock_transfer_id' => $stockTransferId,
            'items' => json_encode([
                'picked_quantity' => $pickedQuantity,
                'original_quantity' => $originalQuantity,
                'quantity_type' => $quantityType,
            ], JSON_UNESCAPED_UNICODE),
            'note' => "ピッキング完了 数量更新",
            'status' => 'BEFORE',
            'action_type' => 'UPDATE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Update queue created', [
            'stock_transfer_id' => $stockTransferId,
            'request_id' => $requestId,
        ]);
    }
}
```

**呼び出し元**: 波動のピッキング完了処理（該当箇所は要特定）

### 7. 波動生成ロジックの変更

**ファイル**: `app/Console/Commands/GenerateWavesCommand.php`

**メソッド**: `getEligibleStockTransfersQuery()` (670-697行目)

```php
// 変更前（679行目）
->where('st.delivered_date', $shippingDate)

// 変更後
->where('st.picking_date', $shippingDate)
```

> **Note**: earnings（通常出荷）は従来通り `delivered_date` を使用（71行目）。stock_transfers のみ `picking_date` に変更。

### 8. WmsOrderIncomingSchedule モデルの変更

**ファイル**: `app/Models/WmsOrderIncomingSchedule.php`

```php
protected $fillable = [
    // ... existing fields ...
    'transfer_candidate_id',  // 追加
    'source_warehouse_id',    // 追加
    'stock_transfer_id',      // 追加
];

// リレーション追加
public function transferCandidate(): BelongsTo
{
    return $this->belongsTo(WmsStockTransferCandidate::class, 'transfer_candidate_id');
}

public function sourceWarehouse(): BelongsTo
{
    return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
}
```

### 9. WmsStockTransferCandidate モデルの変更

**ファイル**: `app/Models/WmsStockTransferCandidate.php`

```php
protected $fillable = [
    // ... existing fields ...
    'shipment_date',  // 追加
];

protected $casts = [
    // ... existing casts ...
    'shipment_date' => 'date',  // 追加
];
```

---

## 変更ファイル一覧 (sakemaru-wms)

| ファイル | 変更種別 | 内容 |
|---------|---------|------|
| `app/Enums/AutoOrder/OrderSource.php` | 修正 | `TRANSFER` case 追加 |
| `database/migrations/..._add_transfer_columns_to_wms_order_incoming_schedules.php` | 新規 | テーブル拡張 |
| `database/migrations/..._add_shipment_date_to_wms_stock_transfer_candidates.php` | 新規 | shipment_date 追加 |
| `app/Models/WmsOrderIncomingSchedule.php` | 修正 | フィールド・リレーション追加 |
| `app/Models/WmsStockTransferCandidate.php` | 修正 | `shipment_date` 追加 |
| `app/Services/AutoOrder/TransferCandidateExecutionService.php` | 修正 | 入荷予定作成、action_type=CREATE（予約なし） |
| `app/Services/AutoOrder/IncomingConfirmationService.php` | 修正 | TRANSFER確定時のqueue作成 (action_type=DELIVER) |
| `app/Services/AutoOrder/PickingCompleteService.php` | 新規 | ピッキング完了時のUPDATE queue作成、expected_quantity更新 |
| `app/Console/Commands/GenerateWavesCommand.php` | 修正 | picking_date での抽出（倉庫間移動のみ） |

---

## シーケンス図

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    WMS      │     │Queue Worker │     │    Core     │     │  Database   │
│  (User)     │     │(sakemaru)   │     │  (Job)      │     │             │
└──────┬──────┘     └──────┬──────┘     └──────┬──────┘     └──────┬──────┘
       │                   │                   │                   │
       │ 移動候補確定      │                   │                   │
       │──────────────────────────────────────────────────────────>│
       │                   │                   │ stock_transfer_   │
       │                   │                   │ queue INSERT      │
       │                   │                   │ (action_type=     │
       │                   │                   │  CREATE)          │
       │                   │                   │                   │
       │                   │                   │ wms_order_        │
       │                   │                   │ incoming_         │
       │                   │                   │ schedules INSERT  │
       │                   │                   │                   │
       │                   │ poll queue        │                   │
       │                   │<──────────────────────────────────────│
       │                   │                   │                   │
       │                   │ ProcessStockTransfer (CREATE)         │
       │                   │──────────────────>│                   │
       │                   │                   │                   │
       │                   │                   │ stock_transfers   │
       │                   │                   │ INSERT            │
       │                   │                   │ (picking_date算出)│
       │                   │                   │ (is_delivered=F)  │
       │                   │                   │──────────────────>│
       │                   │                   │                   │
       │                   │                   │ Hub在庫減算       │
       │                   │                   │ (current -100)    │
       │                   │                   │──────────────────>│
       │                   │                   │                   │
       │ [Hub] 波動生成    │                   │                   │
       │ (picking_date=today で抽出)           │                   │
       │──────────────────────────────────────────────────────────>│
       │                   │                   │                   │
       │ [Hub] ピッキング完了                  │                   │
       │ (実績: 98)        │                   │                   │
       │──────────────────────────────────────────────────────────>│
       │                   │                   │ stock_transfer_   │
       │                   │                   │ queue INSERT      │
       │                   │                   │ (action_type=     │
       │                   │                   │  UPDATE)          │
       │                   │                   │                   │
       │                   │ poll queue        │                   │
       │                   │<──────────────────────────────────────│
       │                   │                   │                   │
       │                   │ ProcessStockTransfer (UPDATE)         │
       │                   │──────────────────>│                   │
       │                   │                   │ stock_transfers   │
       │                   │                   │ 数量更新 (100→98) │
       │                   │                   │ Hub在庫 +2        │
       │                   │                   │                   │
       │ [Hub] 出荷        │                   │                   │
       │ (トラック積み込み)│                   │                   │
       │                   │                   │                   │
       │ [Satellite]       │                   │                   │
       │ 入荷検品確定      │                   │                   │
       │──────────────────────────────────────────────────────────>│
       │                   │                   │ stock_transfer_   │
       │                   │                   │ queue INSERT      │
       │                   │                   │ (action_type=     │
       │                   │                   │  DELIVER)         │
       │                   │                   │                   │
       │                   │ poll queue        │                   │
       │                   │<──────────────────────────────────────│
       │                   │                   │                   │
       │                   │ ProcessStockTransfer (DELIVER)        │
       │                   │──────────────────>│                   │
       │                   │                   │                   │
       │                   │                   │ Satellite在庫加算 │
       │                   │                   │ (current +95)     │
       │                   │                   │──────────────────>│
       │                   │                   │                   │
       │                   │                   │ is_delivered=true │
       │                   │                   │──────────────────>│
       │                   │                   │                   │
```

---

## テスト項目

### 正常系（WMS側）

1. [ ] 移動候補確定時に `stock_transfer_queue` (action_type=CREATE) が作成される
2. [ ] 移動候補確定時に `WmsOrderIncomingSchedule` (TRANSFER) が作成される
3. [ ] Hub倉庫の波動生成で `picking_date = today` の移動分が取り込まれる
4. [ ] ピッキング完了時に `stock_transfer_queue` (action_type=UPDATE) が作成される
5. [ ] ピッキング完了時に `WmsOrderIncomingSchedule.expected_quantity` が更新される
6. [ ] Satellite倉庫の入荷予定画面に表示される
7. [ ] 入荷検品確定時に `stock_transfer_queue` (action_type=DELIVER) が作成される
8. [ ] 当日ピッキング・翌日出荷のケースで正しく動作する

### 正常系（Core側）

> Core側のテスト項目は [Core実装仕様](./20260127-transfer-incoming-flow-core-implementation.md#8-テスト項目core側) を参照

### 異常系

1. [ ] 既に `is_delivered = true` の場合、再度確定できない（冪等性）
2. [ ] `stock_transfer_id` が未設定の場合、動的に取得を試みる
3. [ ] Hub在庫が不足している場合、stock_transfer作成がエラーになる
4. [ ] `stock_transfer_queue` (DELIVER) 処理失敗時、エラーステータスが記録される
5. [ ] `request_id` 重複時、INSERT がエラーになる（ユニーク制約）

---

## 今後の課題

| 項目 | 優先度 | 備考 |
|-----|--------|------|
| 部分入荷対応 | 中 | 入荷数量が予定数量と異なる場合の処理 |
| キャンセル対応 | 中 | 移動候補・入荷予定のキャンセルフロー |
| 配送コースのリードタイム管理画面 | 低 | picking_lead_days の設定UI |
| リトライ機構 | 低 | queue処理失敗時の自動リトライ |
| 差異報告 | 低 | 入荷数量と予定数量の差異がある場合の報告・調整フロー |
| エラー通知 | 低 | Phase 1 はログのみ、メール・画面通知は Phase 2 以降 |

---

## エラー時の動作（Phase 1）

### 在庫不足エラー時

- `stock_transfer_queue.status = 'ERROR'` で記録
- WmsOrderIncomingSchedule は `PENDING` のまま
- 管理画面でエラー状態を確認可能（stock_transfer_queue 一覧）
- リカバリーは手動対応（移動候補の再作成 or キャンセル）

### ユーザーへのエラー通知

- Phase 1: ログのみ（メール・画面通知は Phase 2 以降で検討）

### タイミングウィンドウ

- ポーリング間隔が短ければ（1-5分）、実運用上のリスクは低い
- 在庫が売れた場合はエラーとして手動対応
- 運用開始後に問題が多発すれば予約機能を再検討
