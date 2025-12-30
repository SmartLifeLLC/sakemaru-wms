# Phase 0: 事前準備・マスタ設定

## 目的
自動発注機能の基盤となるテーブル作成とマスタデータ設定機能を実装する。

---

## 実装タスク

### 1. データベースマイグレーション

#### 1.1 ジョブ管理テーブル
```sql
CREATE TABLE wms_auto_order_job_controls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    process_name VARCHAR(50) NOT NULL COMMENT 'SATELLITE_CALC, HUB_CALC, ORDER_TRANSMISSION等',
    batch_code CHAR(14) NOT NULL COMMENT 'バッチ実行ID (YYYYMMDDHHMMSS)',
    status ENUM('PENDING', 'RUNNING', 'SUCCESS', 'FAILED') NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    target_scope JSON NULL COMMENT '対象倉庫や期間などのパラメータ',
    total_records INT NULL,
    processed_records INT NULL,
    error_details TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_batch_code (batch_code),
    INDEX idx_status (status)
);
```

#### 1.2 在庫スナップショットテーブル
```sql
CREATE TABLE wms_warehouse_item_total_stocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    snapshot_at DATETIME NOT NULL COMMENT '集計日時',
    total_effective_piece INT NOT NULL COMMENT '有効在庫合計バラ数',
    total_non_effective_piece INT NOT NULL DEFAULT 0 COMMENT '無効在庫合計バラ数',
    total_incoming_piece INT NOT NULL DEFAULT 0 COMMENT '入荷予定合計バラ数',
    last_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY uk_warehouse_item (warehouse_id, item_id),
    INDEX idx_snapshot (snapshot_at)
);
```

#### 1.3 クライアント設定テーブル（自動発注用）
```sql
CREATE TABLE wms_client_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calc_logic_type VARCHAR(50) DEFAULT 'STANDARD' COMMENT '計算ロジックタイプ',
    satellite_calc_time TIME DEFAULT '10:00:00' COMMENT '非拠点計算開始時刻',
    hub_calc_time TIME DEFAULT '10:30:00' COMMENT '拠点計算開始時刻',
    execution_time TIME DEFAULT '12:00:00' COMMENT '発注実行時刻',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

#### 1.4 倉庫設定テーブル（Hub/Satellite判定用）
```sql
-- warehousesテーブルへのカラム追加、または新規テーブル作成を検討
ALTER TABLE warehouses ADD COLUMN warehouse_type ENUM('HUB', 'SATELLITE') DEFAULT 'SATELLITE' COMMENT '倉庫タイプ';
ALTER TABLE warehouses ADD COLUMN hub_warehouse_id BIGINT UNSIGNED NULL COMMENT '拠点倉庫ID (Satellite倉庫の場合)';
ALTER TABLE warehouses ADD COLUMN exclude_sunday_arrival TINYINT(1) DEFAULT 1 COMMENT '日曜入荷除外フラグ';
```

#### 1.5 商品発注設定テーブル（item_contractors拡張確認）
```sql
-- 既存テーブルの確認が必要
-- 以下のカラムが存在しない場合は追加
ALTER TABLE item_contractors ADD COLUMN safety_stock INT DEFAULT 0 COMMENT '安全在庫数';
ALTER TABLE item_contractors ADD COLUMN max_stock INT NULL COMMENT '最大在庫数';
ALTER TABLE item_contractors ADD COLUMN is_auto_order TINYINT(1) DEFAULT 1 COMMENT '自動発注フラグ';
ALTER TABLE item_contractors ADD COLUMN lead_time_days INT DEFAULT 1 COMMENT 'リードタイム日数';
ALTER TABLE item_contractors ADD COLUMN is_holiday_delivery_available TINYINT(1) DEFAULT 0 COMMENT '休日配送可否';
```

---

### 2. Eloquentモデル作成

- `WmsAutoOrderJobControl`
- `WmsWarehouseItemTotalStock`
- `WmsClientSetting`

---

### 3. Filament管理画面

#### 3.1 クライアント設定画面
- 計算ロジックタイプ選択
- 各フェーズの実行時刻設定

#### 3.2 倉庫設定画面の拡張
- 倉庫タイプ（Hub/Satellite）選択
- 拠点倉庫の選択（Satellite倉庫の場合）
- 日曜入荷除外フラグ

---

### 4. 在庫スナップショット生成コマンド

```bash
php artisan wms:snapshot-stocks
```

- 全倉庫の全商品について在庫数を集計
- `wms_warehouse_item_total_stocks`へ保存
- 発注計算バッチの前に実行

---

## 確認事項（実装前に解決が必要）

1. [ ] `item_contractors`テーブルの現在の構造を確認
2. [ ] `warehouses`テーブルへのカラム追加可否（基幹システムとの整合性）
3. [ ] Hub/Satellite倉庫の判定ロジックの最終確認

---

## 次のフェーズ
Phase 1（休日管理）へ進む
