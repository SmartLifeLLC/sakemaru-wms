# Phase 3: 発注候補計算ロジック

## 目的
Satellite倉庫の移動候補、Hub倉庫の発注候補を計算するバッチ処理を実装する。

---

## 実装タスク

### 1. データベースマイグレーション

#### 1.1 移動候補テーブル（Satellite → Hub）
```sql
CREATE TABLE wms_stock_transfer_candidates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_code CHAR(14) NOT NULL COMMENT 'バッチ実行ID',

    -- 対象情報
    satellite_warehouse_id BIGINT UNSIGNED NOT NULL COMMENT '移動先倉庫（Satellite）',
    hub_warehouse_id BIGINT UNSIGNED NOT NULL COMMENT '移動元倉庫（Hub）',
    item_id BIGINT UNSIGNED NOT NULL,
    contractor_id BIGINT UNSIGNED NULL COMMENT '発注先',

    -- 計算値
    suggested_quantity INT NOT NULL COMMENT '理論必要数（バラ）',
    transfer_quantity INT NOT NULL COMMENT '移動数量（ロット適用後）',
    quantity_type ENUM('PIECE', 'CASE') DEFAULT 'CASE',

    -- 入荷予定日
    expected_arrival_date DATE NULL,
    original_arrival_date DATE NULL COMMENT '休日シフト前の日付',

    -- ステータス
    status ENUM('PENDING', 'APPROVED', 'EXCLUDED', 'EXECUTED') DEFAULT 'PENDING',

    -- ロット適用状態
    lot_status ENUM('RAW', 'ADJUSTED', 'BLOCKED', 'NEED_APPROVAL') DEFAULT 'RAW',
    lot_rule_id BIGINT UNSIGNED NULL,
    lot_exception_id BIGINT UNSIGNED NULL,
    lot_before_qty INT NULL COMMENT 'ロット適用前の数量',
    lot_after_qty INT NULL COMMENT 'ロット適用後の数量',

    -- 手数料
    lot_fee_type VARCHAR(50) NULL,
    lot_fee_amount DECIMAL(10,2) NULL,

    -- 手動修正フラグ
    is_manually_modified TINYINT(1) DEFAULT 0,
    modified_by BIGINT UNSIGNED NULL,
    modified_at DATETIME NULL,

    -- 除外理由
    exclusion_reason VARCHAR(255) NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_batch (batch_code),
    INDEX idx_status (status),
    INDEX idx_satellite (satellite_warehouse_id, item_id),
    INDEX idx_hub (hub_warehouse_id, item_id)
) COMMENT '倉庫間移動候補';
```

#### 1.2 発注候補テーブル（Hub → 外部発注先）
```sql
CREATE TABLE wms_order_candidates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_code CHAR(14) NOT NULL,

    -- 対象情報
    warehouse_id BIGINT UNSIGNED NOT NULL COMMENT '発注倉庫（Hub）',
    item_id BIGINT UNSIGNED NOT NULL,
    contractor_id BIGINT UNSIGNED NOT NULL COMMENT '発注先',

    -- 計算値
    self_shortage_qty INT NOT NULL DEFAULT 0 COMMENT '自倉庫の不足数',
    satellite_demand_qty INT NOT NULL DEFAULT 0 COMMENT 'Satellite倉庫からの需要',
    suggested_quantity INT NOT NULL COMMENT '理論合計必要数',
    order_quantity INT NOT NULL COMMENT '発注数量（ロット適用後）',
    quantity_type ENUM('PIECE', 'CASE') DEFAULT 'CASE',

    -- 入荷予定日
    expected_arrival_date DATE NULL,
    original_arrival_date DATE NULL,

    -- ステータス
    status ENUM('PENDING', 'APPROVED', 'EXCLUDED', 'EXECUTED') DEFAULT 'PENDING',

    -- ロット適用状態
    lot_status ENUM('RAW', 'ADJUSTED', 'BLOCKED', 'NEED_APPROVAL') DEFAULT 'RAW',
    lot_rule_id BIGINT UNSIGNED NULL,
    lot_exception_id BIGINT UNSIGNED NULL,
    lot_before_qty INT NULL,
    lot_after_qty INT NULL,
    lot_fee_type VARCHAR(50) NULL,
    lot_fee_amount DECIMAL(10,2) NULL,

    -- 手動修正
    is_manually_modified TINYINT(1) DEFAULT 0,
    modified_by BIGINT UNSIGNED NULL,
    modified_at DATETIME NULL,
    exclusion_reason VARCHAR(255) NULL,

    -- 送信情報
    transmission_status ENUM('PENDING', 'SENT', 'FAILED') DEFAULT 'PENDING',
    transmitted_at DATETIME NULL,
    wms_order_jx_document_id BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_batch (batch_code),
    INDEX idx_status (status),
    INDEX idx_warehouse_item (warehouse_id, item_id),
    INDEX idx_contractor (contractor_id)
) COMMENT '発注候補';
```

#### 1.3 計算ログテーブル
```sql
CREATE TABLE wms_order_calculation_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_code CHAR(14) NOT NULL,

    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    calculation_type ENUM('SATELLITE', 'HUB') NOT NULL,

    -- 計算入力値
    current_effective_stock INT NOT NULL COMMENT '有効在庫',
    incoming_quantity INT NOT NULL DEFAULT 0 COMMENT '入荷予定数',
    safety_stock_setting INT NOT NULL COMMENT '安全在庫設定値',
    lead_time_days INT NOT NULL,

    -- 計算結果
    calculated_shortage_qty INT NOT NULL COMMENT '不足数',
    calculated_order_quantity INT NOT NULL COMMENT '発注推奨数',

    -- 詳細情報（JSON）
    calculation_details JSON NULL COMMENT '計算詳細',

    created_at TIMESTAMP NULL,

    INDEX idx_batch (batch_code),
    INDEX idx_warehouse_item (warehouse_id, item_id)
) COMMENT '発注計算ログ';
```

---

### 2. Eloquentモデル作成

- `WmsStockTransferCandidate`
- `WmsOrderCandidate`
- `WmsOrderCalculationLog`

---

### 3. 計算ロジック実装

#### 3.1 発注必要数の計算式
```
Required Qty = (Safety Stock + Consumption during LT) - (Effective Inventory + Incoming Orders)
```

#### 3.2 SatelliteCalculationService
```php
namespace App\Services\AutoOrder;

class SatelliteCalculationService
{
    /**
     * Satellite倉庫の移動候補を計算
     * Phase 1: 10:00に実行
     */
    public function calculateAll(string $batchCode): void
    {
        // 1. 対象Satellite倉庫を取得
        // 2. 各倉庫×商品ごとに不足数を計算
        // 3. ロットルール適用
        // 4. wms_stock_transfer_candidatesへ保存
        // 5. 計算ログを記録
    }

    /**
     * 単一倉庫×商品の計算
     */
    public function calculateForItem(
        int $warehouseId,
        int $itemId,
        string $batchCode
    ): ?WmsStockTransferCandidate;
}
```

#### 3.3 HubCalculationService
```php
namespace App\Services\AutoOrder;

class HubCalculationService
{
    /**
     * Hub倉庫の発注候補を計算
     * Phase 2: 10:30に実行
     */
    public function calculateAll(string $batchCode): void
    {
        // 1. 対象Hub倉庫を取得
        // 2. 自倉庫の不足数を計算
        // 3. Satellite倉庫からの需要（wms_stock_transfer_candidates）を集計
        // 4. 合計必要量を算出
        // 5. ロットルール適用
        // 6. wms_order_candidatesへ保存
        // 7. 計算ログを記録
    }

    /**
     * Satellite倉庫からの需要を集計
     */
    public function aggregateSatelliteDemand(
        int $hubWarehouseId,
        int $itemId,
        string $batchCode
    ): int;
}
```

#### 3.4 入荷予定日算出ロジック
```php
/**
 * 入荷予定日を算出（休日考慮）
 */
public function calculateArrivalDate(
    int $warehouseId,
    int $leadTimeDays,
    Carbon $baseDate
): ArrivalDateResult
{
    $tempDate = $baseDate->copy()->addDays($leadTimeDays);
    $originalDate = $tempDate->copy();

    // 休日チェック（キャッシュから）
    while ($this->calendarCache->isHoliday($warehouseId, $tempDate)) {
        $tempDate->addDay();
    }

    $shiftedDays = $originalDate->diffInDays($tempDate);

    return new ArrivalDateResult(
        arrivalDate: $tempDate,
        originalDate: $originalDate,
        shiftedDays: $shiftedDays
    );
}
```

---

### 4. Artisanコマンド

#### 4.1 Satellite計算バッチ
```bash
php artisan wms:calculate-satellite-orders {--warehouse=}
```

#### 4.2 Hub計算バッチ
```bash
php artisan wms:calculate-hub-orders {--warehouse=}
```

#### 4.3 統合実行コマンド
```bash
php artisan wms:auto-order-calculate {--phase=all|satellite|hub}
```

---

### 5. スケジューラ設定

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Phase 0: 在庫スナップショット (09:55)
    $schedule->command('wms:snapshot-stocks')
        ->dailyAt('09:55');

    // Phase 1: Satellite計算 (10:00)
    $schedule->command('wms:calculate-satellite-orders')
        ->dailyAt('10:00');

    // Phase 2: Hub計算 (10:30)
    $schedule->command('wms:calculate-hub-orders')
        ->dailyAt('10:30');
}
```

---

### 6. DTO/ValueObjects

```php
class ArrivalDateResult
{
    public Carbon $arrivalDate;
    public Carbon $originalDate;
    public int $shiftedDays;
}

class CalculationInput
{
    public int $effectiveStock;
    public int $incomingQuantity;
    public int $safetyStock;
    public int $leadTimeDays;
    public ?int $consumptionRate; // 日次消費予測
}

class CalculationResult
{
    public int $shortageQty;
    public int $orderQty;
    public array $details;
}
```

---

## テスト項目

1. [ ] 不足数計算の正確性
2. [ ] 入荷予定日の休日シフト
3. [ ] シフト日数分の消費予測加算
4. [ ] Satellite需要のHub集計
5. [ ] ロットルール適用
6. [ ] 計算ログの記録

---

## 次のフェーズ
Phase 4（候補確認・修正UI）へ進む
