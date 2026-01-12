# Phase 1: 休日管理機能

## 目的
各倉庫の休日・営業日を管理し、発注計算時の入荷予定日算出に使用する。

---

## 実装タスク

### 1. データベースマイグレーション

#### 1.1 休日ルール設定テーブル
```sql
CREATE TABLE wms_warehouse_holiday_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,

    -- 定休日設定 (JSON形式で曜日を保持)
    -- 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat
    regular_holiday_days JSON NULL COMMENT '定休日の曜日配列 (例: [0, 6])',

    -- 祝日設定
    is_national_holiday_closed TINYINT(1) DEFAULT 1 NOT NULL COMMENT '祝日を休業とするか',

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY uk_warehouse (warehouse_id)
) COMMENT '倉庫別休日生成ルール';
```

#### 1.2 展開済みカレンダーテーブル
```sql
CREATE TABLE wms_warehouse_calendars (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,

    target_date DATE NOT NULL COMMENT '対象日付',
    is_holiday TINYINT(1) DEFAULT 0 NOT NULL COMMENT '休日フラグ (0:営業日, 1:休日)',
    holiday_reason VARCHAR(255) NULL COMMENT '休日理由',
    is_manual_override TINYINT(1) DEFAULT 0 NOT NULL COMMENT '手動変更フラグ',

    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY uk_warehouse_date (warehouse_id, target_date),
    INDEX idx_calc_lookup (warehouse_id, target_date, is_holiday)
) COMMENT '計算用・倉庫別営業日カレンダー';
```

#### 1.3 祝日マスタテーブル（必要に応じて）
```sql
CREATE TABLE wms_national_holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY uk_date (holiday_date)
) COMMENT '祝日マスタ';
```

---

### 2. Eloquentモデル作成

- `WmsWarehouseHolidaySetting`
- `WmsWarehouseCalendar`
- `WmsNationalHoliday`

---

### 3. サービスクラス

#### 3.1 CalendarGenerationService
```php
namespace App\Services\AutoOrder;

class CalendarGenerationService
{
    /**
     * 指定倉庫のカレンダーを生成（向こう12ヶ月）
     */
    public function generateCalendar(int $warehouseId): void;

    /**
     * 全倉庫のカレンダーを再生成
     */
    public function regenerateAllCalendars(): void;

    /**
     * 特定日が休日かどうかを判定
     */
    public function isHoliday(int $warehouseId, Carbon $date): bool;

    /**
     * 次の営業日を取得
     */
    public function getNextBusinessDay(int $warehouseId, Carbon $fromDate): Carbon;
}
```

#### 3.2 CalendarCacheService
```php
namespace App\Services\AutoOrder;

class CalendarCacheService
{
    /**
     * バッチ開始時に対象倉庫のカレンダーをメモリにプリロード
     */
    public function preloadCalendars(array $warehouseIds, Carbon $startDate, Carbon $endDate): void;

    /**
     * キャッシュから休日判定
     */
    public function isHolidayFromCache(int $warehouseId, string $dateString): bool;
}
```

---

### 4. Artisanコマンド

#### 4.1 カレンダー生成コマンド
```bash
php artisan wms:generate-calendars {--warehouse=} {--months=12}
```

#### 4.2 祝日インポートコマンド
```bash
php artisan wms:import-holidays {year}
```
- 日本の祝日APIまたはCSVからインポート

---

### 5. Filament管理画面

#### 5.1 休日設定リソース（WmsWarehouseHolidaySettingResource）

**フォーム要素:**
- 倉庫選択
- 定休日チェックボックス（月〜日）
- 祝日休業フラグ

**保存時の処理:**
- カレンダー再生成をトリガー

#### 5.2 カレンダー表示・編集画面

**機能:**
- 月別カレンダー表示
- 営業日/休日の色分け表示
- 日付クリックで休日⇔営業日の切り替え（臨時休業/臨時営業）
- 手動変更した日付は視覚的に区別

---

### 6. 発注計算での利用ロジック

```php
// バッチ冒頭で実行
$calendarCache = WmsWarehouseCalendar::whereIn('warehouse_id', $targetWarehouseIds)
    ->whereBetween('target_date', [$today, $maxLeadTimeDate])
    ->get()
    ->groupBy('warehouse_id')
    ->map(fn($calendars) => $calendars->keyBy('target_date'));

// 入荷予定日の決定
$tempDate = $today->addDays($leadTimeDays);
while ($calendarCache[$warehouseId][$tempDate->toDateString()]->is_holiday ?? false) {
    $tempDate->addDay();
}
$finalArrivalDate = $tempDate;
```

---

## テスト項目

1. [ ] 定休日設定による休日判定
2. [ ] 祝日による休日判定
3. [ ] 臨時休業/臨時営業の手動設定
4. [ ] カレンダー再生成時の手動変更保持
5. [ ] 次の営業日算出ロジック

---

## 次のフェーズ
Phase 2（発注先・ロットルール設定）へ進む
