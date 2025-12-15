# Phase 2: 発注先・ロットルール設定

## 目的
発注先との接続設定、ロット・混載ルールを管理する機能を実装する。

---

## 実装タスク

### 1. データベースマイグレーション

#### 1.1 発注先接続設定テーブル
```sql
CREATE TABLE wms_warehouse_contractor_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    contractor_id BIGINT UNSIGNED NOT NULL,

    -- 送信方式
    transmission_type ENUM('JX_FINET', 'MANUAL_CSV', 'FTP') NOT NULL DEFAULT 'MANUAL_CSV',

    -- 接続設定参照
    wms_order_jx_setting_id BIGINT UNSIGNED NULL,
    wms_order_ftp_setting_id BIGINT UNSIGNED NULL,

    -- データ生成クラス
    format_strategy_class VARCHAR(255) NULL COMMENT 'データ生成クラス名',

    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY uk_warehouse_contractor (warehouse_id, contractor_id)
) COMMENT '倉庫×発注先 接続設定';
```

#### 1.2 JX接続設定テーブル
```sql
CREATE TABLE wms_order_jx_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '設定名',

    -- 接続ID
    van_center VARCHAR(50) NULL,
    client_id VARCHAR(50) NULL,
    server_id VARCHAR(50) NULL,
    endpoint_url VARCHAR(255) NULL,

    -- Basic認証
    is_basic_auth TINYINT(1) DEFAULT 0,
    basic_user_id VARCHAR(100) NULL,
    basic_user_pw VARCHAR(255) NULL COMMENT '暗号化して保存',

    -- JXエンベロープ
    jx_from VARCHAR(50) NULL,
    jx_to VARCHAR(50) NULL,

    -- SSL証明書
    ssl_certification_file VARCHAR(255) NULL,

    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) COMMENT 'JX接続設定';
```

#### 1.3 FTP接続設定テーブル
```sql
CREATE TABLE wms_order_ftp_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,

    host VARCHAR(255) NOT NULL,
    port INT DEFAULT 21,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL COMMENT '暗号化して保存',

    -- 接続方式
    protocol ENUM('FTP', 'SFTP', 'FTPS') DEFAULT 'FTP',
    passive_mode TINYINT(1) DEFAULT 1,

    -- アップロード先
    remote_directory VARCHAR(255) DEFAULT '/',
    file_name_pattern VARCHAR(100) DEFAULT 'order_{date}.csv',

    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) COMMENT 'FTP接続設定';
```

#### 1.4 ロット・混載ルールテーブル
```sql
CREATE TABLE wms_warehouse_contractor_order_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    contractor_id BIGINT UNSIGNED NOT NULL,

    -- 発注単位許可
    allows_case TINYINT(1) DEFAULT 1 COMMENT 'ケース発注可否',
    allows_piece TINYINT(1) DEFAULT 0 COMMENT 'バラ発注可否',
    piece_to_case_rounding ENUM('CEIL', 'FLOOR', 'ROUND') DEFAULT 'CEIL' COMMENT 'バラ→ケース変換時の丸め',

    -- 混載設定
    allows_mixed TINYINT(1) DEFAULT 0 COMMENT '混載許可',
    mixed_unit ENUM('CASE', 'PIECE', 'NONE') DEFAULT 'NONE' COMMENT '混載時の単位',
    mixed_limit_qty INT NULL COMMENT '混載時の最低合計数',

    -- ケース発注ルール
    min_case_qty INT DEFAULT 1 COMMENT '最小ケース数',
    case_multiple_qty INT DEFAULT 1 COMMENT 'ケース倍数',

    -- バラ発注ルール
    min_piece_qty INT NULL COMMENT '最小バラ数',
    piece_multiple_qty INT DEFAULT 1 COMMENT 'バラ倍数',

    -- ロット未達時のアクション
    below_lot_action ENUM('ALLOW', 'BLOCK', 'ADD_FEE', 'ADD_SHIPPING', 'NEED_APPROVAL') DEFAULT 'ALLOW',
    handling_fee DECIMAL(10,2) NULL COMMENT '手数料',
    shipping_fee DECIMAL(10,2) NULL COMMENT '送料',

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY uk_warehouse_contractor (warehouse_id, contractor_id)
) COMMENT '発注ロット・混載ルール';
```

#### 1.5 ルール例外テーブル
```sql
CREATE TABLE wms_order_rule_exceptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wms_warehouse_contractor_order_rule_id BIGINT UNSIGNED NOT NULL,

    -- 対象タイプ
    target_type ENUM('ITEM', 'CATEGORY', 'TEMPERATURE', 'BRAND') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL COMMENT '対象ID',

    priority INT DEFAULT 0 COMMENT '優先順位（高いほど優先）',

    -- オーバーライド項目（NULLは基本ルールを継承）
    allows_case TINYINT(1) NULL,
    allows_piece TINYINT(1) NULL,
    min_case_qty INT NULL,
    case_multiple_qty INT NULL,
    min_piece_qty INT NULL,
    piece_multiple_qty INT NULL,
    below_lot_action ENUM('ALLOW', 'BLOCK', 'ADD_FEE', 'ADD_SHIPPING', 'NEED_APPROVAL') NULL,

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    INDEX idx_rule_target (wms_warehouse_contractor_order_rule_id, target_type, target_id)
) COMMENT '発注ルール例外設定';
```

---

### 2. Eloquentモデル作成

- `WmsWarehouseContractorSetting`
- `WmsOrderJxSetting`
- `WmsOrderFtpSetting`
- `WmsWarehouseContractorOrderRule`
- `WmsOrderRuleException`

---

### 3. サービスクラス

#### 3.1 OrderRuleService
```php
namespace App\Services\AutoOrder;

class OrderRuleService
{
    /**
     * 指定倉庫・発注先・商品に適用されるルールを取得
     * 例外ルールがあれば優先適用
     */
    public function getApplicableRule(
        int $warehouseId,
        int $contractorId,
        int $itemId
    ): OrderRuleDTO;

    /**
     * ロット適用後の数量を計算
     */
    public function applyLotRule(
        int $rawQuantity,
        OrderRuleDTO $rule
    ): LotApplicationResult;

    /**
     * 混載条件のチェック
     */
    public function checkMixedLoadCondition(
        array $orderItems,
        OrderRuleDTO $rule
    ): MixedLoadCheckResult;
}
```

---

### 4. Filament管理画面

#### 4.1 JX接続設定リソース（WmsOrderJxSettingResource）
- 接続情報フォーム
- 接続テスト機能

#### 4.2 FTP接続設定リソース（WmsOrderFtpSettingResource）
- 接続情報フォーム
- 接続テスト機能

#### 4.3 発注先接続設定リソース（WmsWarehouseContractorSettingResource）
- 倉庫×発注先の組み合わせ設定
- 送信方式選択
- 接続設定参照

#### 4.4 ロットルール設定リソース（WmsWarehouseContractorOrderRuleResource）
- 基本ルール設定
- 例外ルール設定（RelationManager）
- ルールプレビュー機能

---

### 5. DTO/ValueObjects

```php
class OrderRuleDTO
{
    public bool $allowsCase;
    public bool $allowsPiece;
    public string $pieceToRounding;
    public bool $allowsMixed;
    public ?string $mixedUnit;
    public ?int $mixedLimitQty;
    public int $minCaseQty;
    public int $caseMultipleQty;
    public ?int $minPieceQty;
    public int $pieceMultipleQty;
    public string $belowLotAction;
    public ?float $handlingFee;
    public ?float $shippingFee;
}

class LotApplicationResult
{
    public int $originalQty;
    public int $adjustedQty;
    public string $lotStatus; // RAW, ADJUSTED, BLOCKED, NEED_APPROVAL
    public ?string $adjustmentReason;
    public ?float $feeAmount;
}
```

---

## テスト項目

1. [ ] 基本ルールの取得
2. [ ] 例外ルールの優先適用
3. [ ] ケース数切り上げ計算
4. [ ] 最小数量・倍数チェック
5. [ ] 混載条件チェック
6. [ ] ロット未達時のアクション適用

---

## 次のフェーズ
Phase 3（発注候補計算ロジック）へ進む
