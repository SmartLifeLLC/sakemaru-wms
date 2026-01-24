# 発注計算ロジック仕様書（再設計版）

作成日: 2026-01-24

## 概要

発注候補の到着予定日を計算するロジック。以下の3要素を考慮する：

1. **リードタイム**（仕入先単位）
2. **納品可能曜日**（発注先×倉庫単位）
3. **倉庫休日**

---

## 1. 関連テーブル

| テーブル | 単位 | 用途 | データ状況 |
|---------|------|------|-----------|
| `lead_times` | 発注先 | リードタイム日数 | 移行予定（Oracle「納品予定」） |
| `contractors.lead_time_id` | 発注先 | lead_times への参照 | **既存カラム**（現在未使用） |
| `wms_contractor_warehouse_delivery_days` | 発注先×倉庫 | 納品可能曜日 | 51件登録済み |
| `wms_warehouse_calendars` | 倉庫×日付 | 倉庫休日 | 0件（今後登録） |

### 参照経路

```
item_contractors
  ├─ contractor_id → contractors.lead_time_id → lead_times（リードタイム）
  ├─ contractor_id ─┬→ wms_contractor_warehouse_delivery_days（納品可能曜日）
  └─ warehouse_id ──┘
                    └→ wms_warehouse_calendars（倉庫休日）
```

---

## 2. 到着予定日計算ロジック

### 2.1 計算フロー図

```
┌─────────────────────────────────────────────────────────┐
│                      発注日                              │
│                  (例: 2026-01-24 金曜)                   │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  Step 1: リードタイム取得（発注先単位）                    │
│                                                         │
│  item_contractors.contractor_id                         │
│    → contractors.lead_time_id                           │
│    → lead_times.lead_time_xxx                           │
│                                                         │
│  ※未設定の場合: INTERNAL=1日、EXTERNAL=3日              │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  Step 2: 仮到着予定日を計算                               │
│                                                         │
│  発注日 + リードタイム日数 = 仮到着予定日                  │
│  (例: 2026-01-24 + 2日 = 2026-01-26 日曜)               │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  Step 3: 納品可能曜日チェック（発注先×倉庫単位）           │
│                                                         │
│  wms_contractor_warehouse_delivery_days を参照           │
│  納品不可曜日なら → 次の納品可能曜日までスキップ           │
│                                                         │
│  (例: 日曜不可 → 月曜不可 → 火曜OK → 2026-01-28)         │
│                                                         │
│  ※設定なしの場合: 全曜日納品可能として扱う                │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│  Step 4: 倉庫休日チェック                                 │
│                                                         │
│  wms_warehouse_calendars を参照                          │
│  休日なら → 次の営業日までスキップ                        │
│                                                         │
│  ※設定なしの場合: 営業日として扱う                        │
└────────────────────────┬────────────────────────────────┘
                         ▼
┌─────────────────────────────────────────────────────────┐
│                   到着予定日（確定）                       │
│                    2026-01-28 火曜                       │
└─────────────────────────────────────────────────────────┘
```

### 2.2 計算例

**前提条件:**
- 発注日: 2026-01-24（金曜）
- 商品: item_id=100
- 倉庫: warehouse_id=1
- 発注先: contractor_id=1126（カナカン日配）
- 発注先リードタイム: contractors.lead_time_id → lead_times.納品予定=2日
- 納品可能曜日: 火・金のみ

**計算:**
```
Step 1: リードタイム = 2日（仕入先1126の設定）
Step 2: 2026-01-24（金） + 2日 = 2026-01-26（日）
Step 3: 日曜NG → 月曜NG → 火曜OK = 2026-01-28（火）
Step 4: 倉庫休日なし = 2026-01-28（火）確定
```

---

## 3. 実装設計

### 3.1 データプリロード

パフォーマンスのため、計算前に全データをメモリにロード。

```php
class OrderCandidateCalculationService
{
    /** @var array [contractor_id] => lead_time_days */
    private array $contractorLeadTimes = [];

    /** @var array [contractor_id][warehouse_id] => WmsContractorWarehouseDeliveryDay */
    private array $deliveryDaySettings = [];

    /** @var array [warehouse_id][date_string] => true */
    private array $warehouseHolidays = [];

    private function loadAllDataToMemory(): void
    {
        // 1. 発注先リードタイム（発注日の曜日は現時点では全曜日同じ値）
        $contractors = DB::connection('sakemaru')
            ->table('contractors as c')
            ->join('lead_times as lt', 'c.lead_time_id', '=', 'lt.id')
            ->select('c.id as contractor_id', 'lt.lead_time_mon as lead_time')
            ->get();
        foreach ($contractors as $c) {
            $this->contractorLeadTimes[$c->contractor_id] = $c->lead_time;
        }
        Log::info('発注先リードタイムをロード', ['count' => count($this->contractorLeadTimes)]);

        // 2. 納品可能曜日（発注先×倉庫）
        $deliveryDays = WmsContractorWarehouseDeliveryDay::all();
        foreach ($deliveryDays as $dd) {
            $this->deliveryDaySettings[$dd->contractor_id][$dd->warehouse_id] = $dd;
        }
        Log::info('納品曜日設定をロード', ['count' => count($deliveryDays)]);

        // 3. 倉庫休日（今後30日分）
        $startDate = now();
        $endDate = now()->addDays(30);
        $warehouseHolidays = DB::connection('sakemaru')
            ->table('wms_warehouse_calendars')
            ->where('is_holiday', true)
            ->whereBetween('target_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->get();
        foreach ($warehouseHolidays as $h) {
            $this->warehouseHolidays[$h->warehouse_id][$h->target_date] = true;
        }
        Log::info('倉庫休日をロード', ['count' => count($warehouseHolidays)]);
    }
}
```

### 3.2 到着予定日計算メソッド

```php
/**
 * 到着予定日を計算
 *
 * @param int $contractorId 発注先ID（リードタイム・納品曜日取得用）
 * @param int $warehouseId 倉庫ID（納品曜日・休日取得用）
 * @param Carbon $orderDate 発注日
 * @param bool $isInternal 内部移動かどうか
 * @return array{
 *     arrival_date: Carbon,
 *     lead_time_days: int,
 *     shifted_days: int,
 *     shift_reasons: array<string>
 * }
 */
private function calculateArrivalDate(
    int $contractorId,
    int $warehouseId,
    Carbon $orderDate,
    bool $isInternal = false
): array {
    // Step 1: リードタイム取得（発注先単位）
    $leadTimeDays = $this->contractorLeadTimes[$contractorId]
        ?? ($isInternal ? 1 : 3);  // デフォルト値

    // Step 2: 仮到着予定日
    $arrivalDate = $orderDate->copy()->addDays($leadTimeDays);
    $shiftedDays = 0;
    $shiftReasons = [];

    // Step 3: 納品可能曜日チェック（最大14日）
    $deliverySetting = $this->deliveryDaySettings[$contractorId][$warehouseId] ?? null;
    if ($deliverySetting) {
        $deliveryDays = $deliverySetting->getDeliveryDays();
        if (!empty($deliveryDays)) {
            $deliveryShift = 0;
            for ($i = 0; $i < 14; $i++) {
                if ($deliverySetting->canDeliverOn($arrivalDate->dayOfWeek)) {
                    break;
                }
                $arrivalDate->addDay();
                $deliveryShift++;
            }
            if ($deliveryShift > 0) {
                $shiftedDays += $deliveryShift;
                $shiftReasons[] = "納品可能曜日調整(+{$deliveryShift}日)";
            }
        }
    }

    // Step 4: 倉庫休日チェック（最大14日）
    $warehouseShift = 0;
    for ($i = 0; $i < 14; $i++) {
        $dateStr = $arrivalDate->format('Y-m-d');
        if (!isset($this->warehouseHolidays[$warehouseId][$dateStr])) {
            break;
        }
        $arrivalDate->addDay();
        $warehouseShift++;
    }
    if ($warehouseShift > 0) {
        $shiftedDays += $warehouseShift;
        $shiftReasons[] = "倉庫休日(+{$warehouseShift}日)";
    }

    return [
        'arrival_date' => $arrivalDate,
        'lead_time_days' => $leadTimeDays,
        'shifted_days' => $shiftedDays,
        'shift_reasons' => $shiftReasons,
    ];
}
```

### 3.3 発注候補作成時の呼び出し

```php
// EXTERNAL発注候補作成
foreach ($itemContractors as $ic) {
    // 到着予定日を計算
    $arrivalInfo = $this->calculateArrivalDate(
        $ic->contractor_id,
        $ic->warehouse_id,
        $now,
        isInternal: false
    );

    $insertData[] = [
        // ... 既存フィールド ...
        'expected_arrival_date' => $arrivalInfo['arrival_date']->format('Y-m-d'),
        'original_arrival_date' => $now->copy()->addDays($arrivalInfo['lead_time_days'])->format('Y-m-d'),
        // ...
    ];

    // 計算ログに詳細を記録
    $this->calculationLogs[] = [
        // ... 既存フィールド ...
        'lead_time_days' => $arrivalInfo['lead_time_days'],
        'calculation_details' => json_encode([
            // ... 既存フィールド ...
            'リードタイム' => $arrivalInfo['lead_time_days'],
            '到着日調整' => $arrivalInfo['shifted_days'],
            '調整理由' => implode(', ', $arrivalInfo['shift_reasons']),
        ], JSON_UNESCAPED_UNICODE),
    ];
}
```

---

## 4. データ要件

### 4.1 必須データ

| データ | 必須 | 未設定時の動作 |
|--------|------|---------------|
| 仕入先リードタイム | いいえ | INTERNAL=1日、EXTERNAL=3日 |
| 納品可能曜日 | いいえ | 全曜日納品可能 |
| 倉庫休日 | いいえ | 全日営業 |

### 4.2 データ移行タスク

| # | タスク | 担当 | 状況 |
|---|--------|------|------|
| 1 | `lead_times`にOracle「納品予定」を移行 | Python | ✅ 完了（1,048件） |
| 2 | `contractors.lead_time_id`を`lead_times`に紐付け | Python/SQL | ✅ 完了（1,048件） |
| 3 | `wms_warehouse_calendars`に休日データ登録 | 手動/バッチ | 未実施 |

**注**: `suppliers`への変更は不要。既存の`contractors.lead_time_id`を使用。

---

## 5. 整合性チェックSQL

### 5.1 リードタイム未設定の発注先

```sql
SELECT DISTINCT
    c.code AS contractor_code,
    c.name AS contractor_name,
    COUNT(DISTINCT ic.item_id) AS item_count
FROM item_contractors ic
JOIN contractors c ON ic.contractor_id = c.id
WHERE ic.is_auto_order = 1
  AND ic.safety_stock > 0
  AND c.lead_time_id IS NULL
GROUP BY c.code, c.name
ORDER BY item_count DESC;
```

### 5.2 納品曜日未設定の発注先×倉庫

```sql
SELECT DISTINCT
    c.code AS contractor_code,
    c.name AS contractor_name,
    w.code AS warehouse_code,
    COUNT(DISTINCT ic.item_id) AS item_count
FROM item_contractors ic
JOIN contractors c ON ic.contractor_id = c.id
JOIN warehouses w ON ic.warehouse_id = w.id
LEFT JOIN wms_contractor_warehouse_delivery_days d
    ON d.contractor_id = ic.contractor_id
    AND d.warehouse_id = ic.warehouse_id
WHERE ic.is_auto_order = 1
  AND ic.safety_stock > 0
  AND d.id IS NULL
GROUP BY c.code, c.name, w.code
ORDER BY item_count DESC;
```

---

## 6. 計算ログ出力例

```json
{
  "商品コード": "12345",
  "商品名": "アサヒ生ビール500ml",
  "発注先コード": 1126,
  "発注先名": "カナカン日配",
  "倉庫コード": 1,
  "発注日": "2026-01-24",
  "リードタイム": 2,
  "仮到着予定日": "2026-01-26",
  "到着日調整": 2,
  "調整理由": "納品可能曜日調整(+2日)",
  "確定到着予定日": "2026-01-28"
}
```

---

## 7. 注意事項

1. **リードタイムの単位**
   - 発注先単位（contractors.lead_time_id → lead_times）
   - 現時点では全曜日同じ値
   - 将来的に曜日別が必要な場合は`lead_times.lead_time_xxx`を活用

2. **納品可能曜日の単位**
   - 発注先×倉庫単位
   - 発注先単位のリードタイムと組み合わせて使用

3. **無限ループ防止**
   - 最大14日までスキップ
   - 14日超えた場合は警告ログを出力

4. **パフォーマンス**
   - 全データをメモリにプリロード
   - 計算ループ内でDBアクセスしない
