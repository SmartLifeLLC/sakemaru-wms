# 発注テストデータ生成機能 仕様書

作成日: 2025-12-24
更新日: 2025-12-24

---

## 1. システム概要

### 1.1 自動発注システムの構成

```
┌─────────────────────────────────────────────────────────────────┐
│                    自動発注システム全体像                          │
└─────────────────────────────────────────────────────────────────┘

┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  基幹システム      │     │    WMS設定        │     │   計算エンジン     │
│  (マスタデータ)    │ ──→ │  (発注パラメータ)  │ ──→ │  (Multi-Echelon)  │
└──────────────────┘     └──────────────────┘     └──────────────────┘
       │                        │                        │
       ▼                        ▼                        ▼
  - warehouses            - wms_warehouse_         - wms_order_
  - items                   auto_order_settings      candidates
  - contractors           - wms_warehouse_         - wms_stock_transfer_
  - item_contractors        holiday_settings          candidates
  - lead_times            - wms_warehouse_         - wms_order_
                            calendars                calculation_logs
                          - wms_item_supply_
                            settings
                          - wms_national_holidays
                          - wms_contractor_holidays
```

### 1.2 主要サービスクラス

| サービス | ファイルパス | 役割 |
|---------|-------------|------|
| MultiEchelonCalculationService | `app/Services/AutoOrder/MultiEchelonCalculationService.php` | Multi-Echelon計算エンジン |
| StockSnapshotService | `app/Services/AutoOrder/StockSnapshotService.php` | 在庫スナップショット生成 |
| CalendarGenerationService | `app/Services/AutoOrder/CalendarGenerationService.php` | 営業日カレンダー生成 |
| ContractorLeadTimeService | `app/Services/AutoOrder/ContractorLeadTimeService.php` | 発注先リードタイム計算 |

### 1.3 関連Artisanコマンド

| コマンド | 説明 |
|---------|------|
| `php artisan wms:auto-order-calculate` | 発注計算実行（スナップショット + Multi-Echelon計算） |
| `php artisan wms:snapshot-stocks` | 在庫スナップショットのみ生成 |
| `php artisan wms:generate-calendars` | 営業日カレンダー生成 |
| `php artisan wms:import-holidays` | 祝日マスタインポート |

---

## 2. データ構造

### 2.1 マスタデータ（基幹システム - 既存前提）

これらのデータは基幹システムに既に存在することを前提とする。

#### warehouses（倉庫マスタ）
```sql
CREATE TABLE warehouses (
    id BIGINT PRIMARY KEY,
    code VARCHAR(50),
    name VARCHAR(255),
    is_active BOOLEAN DEFAULT true,
    ...
);
```

#### items（商品マスタ）
```sql
CREATE TABLE items (
    id BIGINT PRIMARY KEY,
    item_code VARCHAR(50),
    item_name VARCHAR(255),
    type VARCHAR(50),  -- 'ALCOHOL', etc.
    temperature_type VARCHAR(50),
    is_active BOOLEAN DEFAULT true,
    ...
);
```

#### contractors（発注先マスタ）
```sql
CREATE TABLE contractors (
    id BIGINT PRIMARY KEY,
    code VARCHAR(50),
    name VARCHAR(255),
    ...
);
```

#### item_contractors（商品発注契約）
```sql
CREATE TABLE item_contractors (
    id BIGINT PRIMARY KEY,
    client_id BIGINT,
    item_id BIGINT,
    warehouse_id BIGINT,
    contractor_id BIGINT,
    supplier_id BIGINT,
    safety_stock INT DEFAULT 0,      -- 安全在庫
    max_stock INT,                   -- 最大在庫
    is_auto_order BOOLEAN DEFAULT true,
    ...
);
```

#### lead_times（リードタイム - 曜日別）
```sql
CREATE TABLE lead_times (
    id BIGINT PRIMARY KEY,
    contractor_id BIGINT,
    warehouse_id BIGINT,
    lead_time_mon INT,
    lead_time_tue INT,
    lead_time_wed INT,
    lead_time_thu INT,
    lead_time_fri INT,
    lead_time_sat INT,
    lead_time_sun INT,
    ...
);
```

### 2.2 WMS設定データ（生成対象）

#### wms_warehouse_auto_order_settings（倉庫発注設定）
```sql
CREATE TABLE wms_warehouse_auto_order_settings (
    id BIGINT PRIMARY KEY,
    warehouse_id BIGINT UNIQUE,
    is_auto_order_enabled BOOLEAN DEFAULT true,
    exclude_sunday_arrival BOOLEAN DEFAULT false,
    exclude_holiday_arrival BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### wms_warehouse_holiday_settings（倉庫休日設定）
```sql
CREATE TABLE wms_warehouse_holiday_settings (
    id BIGINT PRIMARY KEY,
    warehouse_id BIGINT UNIQUE,
    regular_holiday_days JSON,        -- [0, 6] = 日曜・土曜
    is_national_holiday_closed BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### wms_national_holidays（祝日マスタ）
```sql
CREATE TABLE wms_national_holidays (
    id BIGINT PRIMARY KEY,
    holiday_date DATE UNIQUE,
    holiday_name VARCHAR(100),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### wms_warehouse_calendars（営業日カレンダー）
```sql
CREATE TABLE wms_warehouse_calendars (
    id BIGINT PRIMARY KEY,
    warehouse_id BIGINT,
    target_date DATE,
    is_holiday BOOLEAN DEFAULT false,
    holiday_reason VARCHAR(255),
    is_manual_override BOOLEAN DEFAULT false,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE (warehouse_id, target_date)
);
```

#### wms_item_supply_settings（商品供給設定）
```sql
CREATE TABLE wms_item_supply_settings (
    id BIGINT PRIMARY KEY,
    warehouse_id BIGINT,
    item_id BIGINT,
    supply_type VARCHAR(20) DEFAULT 'EXTERNAL',  -- 'INTERNAL' or 'EXTERNAL'
    source_warehouse_id BIGINT,                   -- INTERNAL時の供給元
    item_contractor_id BIGINT,                    -- EXTERNAL時の発注契約
    lead_time_days INT DEFAULT 1,
    daily_consumption_qty INT DEFAULT 0,
    hierarchy_level INT DEFAULT 0,                -- 0=最下流
    is_enabled BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE (warehouse_id, item_id)
);
```

#### wms_contractor_holidays（発注先臨時休業）
```sql
CREATE TABLE wms_contractor_holidays (
    id BIGINT PRIMARY KEY,
    contractor_id BIGINT,
    holiday_date DATE,
    reason VARCHAR(100),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE (contractor_id, holiday_date)
);
```

---

## 3. テストデータ生成機能の要件

### 3.1 生成するデータ

| 優先度 | テーブル | 生成内容 |
|--------|---------|----------|
| **必須** | wms_warehouse_auto_order_settings | 指定倉庫の発注有効化 |
| **必須** | wms_warehouse_holiday_settings | 定休日設定（土日など） |
| **必須** | wms_warehouse_calendars | 営業日カレンダー（3-12ヶ月分） |
| **必須** | wms_item_supply_settings | 商品×倉庫の供給設定 |
| 推奨 | wms_national_holidays | 祝日マスタ（日本の祝日） |
| 任意 | wms_contractor_holidays | 発注先臨時休業 |

### 3.2 前提条件チェック

テストデータ生成前に以下を確認：

1. **倉庫マスタ**: `warehouses` に有効な倉庫が存在
2. **商品マスタ**: `items` に有効な商品が存在
3. **発注契約**: `item_contractors` に商品×倉庫×発注先の関係が存在
4. **在庫データ**: `real_stocks` に在庫が存在（計算テスト用）

### 3.3 生成パターン

#### パターン1: 単一倉庫・外部発注のみ
```
Hub倉庫 ← 外部発注（EXTERNAL）
```
- 最もシンプルなテストケース
- hierarchy_level = 0

#### パターン2: Hub-Satellite構成（Multi-Echelon）
```
Satellite倉庫 ← 内部移動（INTERNAL） ← Hub倉庫 ← 外部発注（EXTERNAL）
     Level 0                              Level 1
```
- Multi-Echelon計算のテスト
- 需要伝播の検証

---

## 4. 実装仕様

### 4.1 新規タブの追加

TestDataGeneratorに「発注テスト用」タブを追加する。

**ファイル**: `app/Filament/Pages/TestDataGenerator.php`

```php
// 発注テスト用アクション名
public function getAutoOrderActionNames(): array
{
    return [
        'generateAutoOrderSettings',
        'generateNationalHolidays',
        'generateWarehouseCalendars',
        'generateItemSupplySettings',
        'resetAutoOrderTestData',
    ];
}
```

**Bladeテンプレート追加**: `resources/views/filament/pages/test-data-generator.blade.php`

### 4.2 アクション詳細

#### アクション1: generateAutoOrderSettings（倉庫発注設定生成）

**目的**: 倉庫の自動発注設定と休日設定を一括生成

**入力パラメータ**:
| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|-----------|------|
| warehouse_ids | array | ○ | - | 対象倉庫ID（複数選択可） |
| regular_holiday_days | array | - | [0, 6] | 定休日の曜日（0=日曜〜6=土曜） |
| is_national_holiday_closed | bool | - | true | 祝日を休業とするか |
| exclude_sunday_arrival | bool | - | false | 日曜入荷を除外 |
| exclude_holiday_arrival | bool | - | false | 祝日入荷を除外 |

**処理内容**:
1. 各倉庫に対して `wms_warehouse_auto_order_settings` を UPSERT
2. 各倉庫に対して `wms_warehouse_holiday_settings` を UPSERT

#### アクション2: generateNationalHolidays（祝日マスタ生成）

**目的**: 日本の祝日をマスタに登録

**入力パラメータ**:
| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|-----------|------|
| year | int | - | 現在年 | 対象年 |
| include_next_year | bool | - | true | 翌年も含める |

**処理内容**:
1. `WmsNationalHoliday::generateJapaneseHolidays($year)` を呼び出し
2. 既存データは維持（重複スキップ）

#### アクション3: generateWarehouseCalendars（営業日カレンダー生成）

**目的**: 倉庫ごとの営業日カレンダーを生成

**入力パラメータ**:
| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|-----------|------|
| warehouse_ids | array | ○ | - | 対象倉庫ID（複数選択可） |
| months | int | - | 6 | 生成月数（1-12） |

**処理内容**:
1. `CalendarGenerationService::generateCalendar($warehouseId, $months)` を呼び出し
2. 既存の `is_manual_override = true` は維持

#### アクション4: generateItemSupplySettings（商品供給設定生成）

**目的**: 商品×倉庫の供給設定を一括生成

**入力パラメータ**:
| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|-----------|------|
| warehouse_id | int | ○ | - | 対象倉庫ID |
| supply_type | string | ○ | 'EXTERNAL' | 供給タイプ |
| source_warehouse_id | int | △ | - | 供給元倉庫（INTERNAL時必須） |
| item_limit | int | - | 100 | 生成する商品数の上限 |
| lead_time_days | int | - | 2 | リードタイム |
| daily_consumption_qty | int | - | 10 | 日販予測 |
| use_item_contractors | bool | - | true | item_contractorsを参照して生成 |

**処理内容**:
1. `item_contractors` から対象倉庫の商品×発注先を取得
2. 各商品に対して `wms_item_supply_settings` を UPSERT
3. `WmsItemSupplySetting::recalculateHierarchyLevels()` で階層レベル再計算

#### アクション5: resetAutoOrderTestData（発注テストデータリセット）

**目的**: 発注関連のWMSテーブルをクリア

**入力パラメータ**:
| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|-----------|------|
| reset_settings | bool | - | true | 設定テーブルをリセット |
| reset_calendars | bool | - | true | カレンダーをリセット |
| reset_candidates | bool | - | true | 発注候補をリセット |
| warehouse_ids | array | - | null | 対象倉庫（nullで全倉庫） |

**処理内容**:
1. `wms_order_candidates` を DELETE（バッチで安全に）
2. `wms_stock_transfer_candidates` を DELETE
3. `wms_order_calculation_logs` を DELETE
4. オプションで設定テーブルも DELETE

---

## 5. UI設計

### 5.1 タブ構成

```
┌──────────────────┬──────────────────┬──────────────────┐
│   出荷テスト用    │  倉庫テストデータ  │   発注テスト用    │  ← 新規追加
└──────────────────┴──────────────────┴──────────────────┘
```

### 5.2 発注テスト用タブの構成

```
┌─────────────────────────────────────────────────────────────────┐
│ 発注テストの流れ                                                   │
├─────────────────────────────────────────────────────────────────┤
│ 1. 倉庫発注設定 - 倉庫の自動発注を有効化                           │
│ 2. 祝日マスタ生成 - 日本の祝日を登録                               │
│ 3. カレンダー生成 - 営業日カレンダーを計算                          │
│ 4. 供給設定生成 - 商品×倉庫の供給方式を設定                         │
│ 5. 計算実行 - php artisan wms:auto-order-calculate                │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ アクションボタン                                                   │
├─────────────────────────────────────────────────────────────────┤
│ [倉庫発注設定] [祝日生成] [カレンダー生成] [供給設定] [リセット]      │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ 説明カード（3カラム）                                              │
├─────────────────────────────────────────────────────────────────┤
│ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐          │
│ │ 倉庫発注設定   │ │ カレンダー生成 │ │ 供給設定生成   │          │
│ │ ・自動発注有効 │ │ ・営業日計算   │ │ ・EXTERNAL/   │          │
│ │ ・定休日設定   │ │ ・祝日反映    │ │  INTERNAL    │          │
│ │ ・祝日休業    │ │ ・3-12ヶ月分  │ │ ・階層レベル  │          │
│ └───────────────┘ └───────────────┘ └───────────────┘          │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. 作業指示

### 6.1 実装順序

| 順序 | 作業内容 | ファイル | 優先度 |
|------|---------|----------|--------|
| 1 | タブ追加・アクション名定義 | `TestDataGenerator.php` | 必須 |
| 2 | Bladeテンプレート更新 | `test-data-generator.blade.php` | 必須 |
| 3 | generateAutoOrderSettingsアクション実装 | `TestDataGenerator.php` | 必須 |
| 4 | generateNationalHolidaysアクション実装 | `TestDataGenerator.php` | 必須 |
| 5 | generateWarehouseCalendarsアクション実装 | `TestDataGenerator.php` | 必須 |
| 6 | generateItemSupplySettingsアクション実装 | `TestDataGenerator.php` | 必須 |
| 7 | resetAutoOrderTestDataアクション実装 | `TestDataGenerator.php` | 必須 |

### 6.2 実装時の注意事項

1. **DB削除禁止**: `migrate:fresh`, `migrate:refresh` は絶対禁止
2. **UPSERT使用**: 既存データは上書き、新規は追加
3. **トランザクション**: 各アクションはトランザクションで囲む
4. **エラーハンドリング**: 前提条件チェックでエラー時は明確なメッセージ
5. **通知**: 処理完了時に `Notification::make()` で結果表示

### 6.3 テスト手順

1. 倉庫発注設定を生成
2. 祝日マスタを生成
3. カレンダーを生成
4. 供給設定を生成
5. `php artisan wms:auto-order-calculate` を実行
6. 発注候補一覧で結果確認

---

## 7. 参考情報

### 7.1 関連ファイル

| カテゴリ | ファイルパス |
|---------|-------------|
| テストデータ生成ページ | `app/Filament/Pages/TestDataGenerator.php` |
| テストデータ生成ビュー | `resources/views/filament/pages/test-data-generator.blade.php` |
| カレンダー生成サービス | `app/Services/AutoOrder/CalendarGenerationService.php` |
| 計算サービス | `app/Services/AutoOrder/MultiEchelonCalculationService.php` |
| 祝日モデル | `app/Models/WmsNationalHoliday.php` |
| 供給設定モデル | `app/Models/WmsItemSupplySetting.php` |
| 発注ガイドページ | `app/Filament/Pages/AutoOrderGuide.php` |

### 7.2 既存実装パターン例

```php
// アクション定義例（generatePickersより）
public function generatePickersAction(): Action
{
    return Action::make('generatePickers')
        ->label('ピッカー生成')
        ->icon('heroicon-o-user-plus')
        ->color('warning')
        ->requiresConfirmation()
        ->modalHeading('ピッカーを生成')
        ->form([
            Select::make('warehouse_id')
                ->label('倉庫')
                ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                ->required(),
        ])
        ->action(function (array $data): void {
            // 処理
            Notification::make()
                ->title('ピッカー生成完了')
                ->success()
                ->send();
        });
}
```

---

## 8. 変更履歴

| 日付 | 変更内容 | 担当 |
|------|---------|------|
| 2025-12-24 | 初版作成 | - |
