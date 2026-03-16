# 自動発注計算ロジック仕様書

> AI prompt用リファレンス。発注候補生成の実装箇所・計算式・データフローを網羅する。

---

## 1. 全体フロー

```
スケジューラ (5分毎)
  │
  ▼
[wms:auto-order-scheduled]  ← 発注先ごとの自動生成時刻チェック
  │
  ├─ StockSnapshotService.generateAll()
  │    → wms_item_stock_snapshots に全倉庫の在庫スナップショットを保存
  │
  ▼
[OrderCandidateCalculationService.calculate()]
  │
  ├─ Step 1: loadAllDataToMemory()     ← 全マスタ・設定をメモリにロード
  ├─ Step 2: createInternalTransferCandidatesBulk()  ← INTERNAL移動候補
  ├─ Step 3: loadTransferCandidatesToMemory()        ← 移動候補を集計
  ├─ Step 4: createExternalOrderCandidatesBulk()     ← EXTERNAL発注候補
  ├─ Step 5: insertCalculationLogs()                 ← 計算ログ保存
  └─ Step 6: buildResultData()                       ← 結果サマリー構築
```

### ステータス遷移

```
PENDING（未承認）→ APPROVED（承認済）→ CONFIRMED（発注確定）→ EXECUTED（送信済）
                                                  ↑
                                            EXCLUDED（除外）
```

---

## 2. データソースと前提条件

### 2.1 対象商品のフィルタ条件

| 条件 | カラム | テーブル | 値 |
|------|--------|----------|------|
| 自動発注フラグ | `is_auto_order` | `item_contractors` | `true` |
| 発注点 | `safety_stock` | `item_contractors` | `>= 0` |
| 販売終了区分 | `end_of_sale_type` | `items` | `'NORMAL'` |
| 自動発注有効倉庫 | `is_auto_order_enabled` | `wms_warehouse_auto_order_settings` | `true` |
| 実倉庫のみ | `is_virtual` | `warehouses` | `false` |

**実装箇所:**
- スナップショット読込: `OrderCandidateCalculationService.php:245-253`
- INTERNAL候補取得: `OrderCandidateCalculationService.php:517-526`
- EXTERNAL候補取得: `OrderCandidateCalculationService.php:714-730`

### 2.2 メモリロード対象データ

| データ | テーブル | キー構造 | 実装行 |
|--------|----------|----------|--------|
| 在庫スナップショット | `wms_item_stock_snapshots` | `[warehouse_id][item_id]` | L245-284 |
| INTERNAL設定 | `wms_contractor_settings` | `[contractor_id] → supply_warehouse_id` | L288-296 |
| 商品マスタ | `items` | `[item_id]` | L300-312 |
| 仕入先別単価 | `item_partner_prices` | `[item_id][supplier_id]` | L316-353 |
| 倉庫マスタ | `warehouses` | `[warehouse_id]` | L357-368 |
| 発注先マスタ | `contractors` | `[contractor_id]` | L372-383 |
| 仕入先マスタ | `suppliers + partners` | `[supplier_id]` | L387-401 |
| 移動配送コース | `warehouse_stock_transfer_delivery_courses` | `[from][to]` | L405-416 |
| リードタイム | `contractors + lead_times` | `[contractor_id]` | L420-429 |
| 納品可能曜日 | `wms_contractor_warehouse_delivery_days` | `[contractor_id][warehouse_id]` | L433-451 |
| 倉庫休日 | `wms_warehouse_calendars` | `[warehouse_id][date]` | L455-470 |
| 発注コード | `item_search_information` | `[item_id]` | L474-485 |

---

## 3. INTERNAL移動候補の計算ロジック

**実装箇所:** `OrderCandidateCalculationService::createInternalTransferCandidatesBulk()` (L503-667)

### 3.1 不足数の計算式

```
不足数 = 発注点(safety_stock) - (有効在庫 + 入庫予定数)
```

```php
$shortageQty = $ic->safety_stock - ($effectiveStock + $incomingStock);
```

### 3.2 候補生成の判定条件

```php
// 不足なし → スキップ
if ($shortageQty < 0) continue;

// 発注点0 かつ 在庫0 → 最低1個を発注（在庫切れ補充）
if ($shortageQty === 0 && (int) $ic->safety_stock === 0) {
    if ($effectiveStock > 0 || $incomingStock > 0) continue;
    $shortageQty = 1;
} elseif ($shortageQty === 0) {
    continue;
}
```

**発注点0の特別ルール:**
- `is_auto_order = true`（○マーク）かつ `safety_stock = 0` の場合
- 有効在庫 = 0 かつ 入庫予定 = 0 のときのみ候補を生成
- 不足数は最低1個として扱う

### 3.3 発注数量の決定

```php
// 移動候補はバラ発注可能（仕入れ単位の切り上げなし）
$orderQty = $shortageQty;
```

**重要:** INTERNAL移動候補では `roundUpToUnit()` を適用しない。バラ数量をそのまま使用する。

### 3.4 その他のスキップ条件

- INTERNAL発注先設定がない（`supply_warehouse_id` 未設定）
- 依頼倉庫と供給元倉庫が同一

### 3.5 出力先テーブル

`wms_stock_transfer_candidates` に INSERT。主なカラム:

| カラム | 内容 |
|--------|------|
| `satellite_warehouse_id` | 依頼先倉庫（在庫不足の倉庫） |
| `hub_warehouse_id` | 供給元倉庫（横持ち出荷元） |
| `suggested_quantity` | 提案数量 = 不足数そのまま |
| `transfer_quantity` | 移動数量 = 提案数量と同値 |
| `shortage_qty` | 計算された不足数 |
| `safety_stock` | 発注点 |
| `purchase_unit` | 仕入れ単位（参考情報、切り上げには使用しない） |

---

## 4. EXTERNAL発注候補の計算ロジック

**実装箇所:** `OrderCandidateCalculationService::createExternalOrderCandidatesBulk()` (L711-913)

### 4.1 不足数の計算式

```
計算用在庫 = 有効在庫 + 入庫予定 + 移動入庫予定 - 移動出庫予定
不足数    = 発注点(safety_stock) - 計算用在庫
```

```php
$calculatedStock = $effectiveStock + $incomingStock + $incomingFromTransfer - $outgoingToTransfer;
$shortageQty = $ic->safety_stock - $calculatedStock;
```

**INTERNAL移動候補との違い:**
- 移動候補の影響（入庫/出庫）を在庫に加味する
- INTERNAL移動で在庫が減る倉庫は、その分EXTERNAL発注で補填される

### 4.2 候補生成の判定条件

INTERNALと同様の発注点0ルールを適用:

```php
if ($shortageQty < 0) continue;

if ($shortageQty === 0 && (int) $ic->safety_stock === 0) {
    if ($calculatedStock > 0) continue;
    $shortageQty = 1;
} elseif ($shortageQty === 0) {
    continue;
}
```

### 4.3 発注数量の決定（仕入れ単位切り上げあり）

```php
$purchaseUnit = max(1, (int) ($ic->purchase_unit ?? 1));
$orderQty = $this->roundUpToUnit($shortageQty, $purchaseUnit);
```

**切り上げロジック:**
```php
private function roundUpToUnit(int $quantity, int $unit): int
{
    if ($unit <= 1) return $quantity;
    return (int) ceil($quantity / $unit) * $unit;
}
```

例: 不足数50、仕入れ単位12 → `⌈50/12⌉ × 12 = 60`

### 4.4 需要内訳の構築

EXTERNAL発注候補には需要の出所を記録:

```
自倉庫不足分   = max(0, 不足数 - サテライト需要)
サテライト需要 = min(移動出庫予定, 不足数)
```

`demand_breakdown` JSON に倉庫別の需要内訳を保存。

### 4.5 出力先テーブル

`wms_order_candidates` に INSERT。主なカラム:

| カラム | 内容 |
|--------|------|
| `warehouse_id` | 発注倉庫 |
| `contractor_id` | 発注先 |
| `supplier_id` | 仕入先 |
| `suggested_quantity` | 提案数量（仕入れ単位切り上げ済み） |
| `order_quantity` | 発注数量 = 提案数量と同値 |
| `self_shortage_qty` | 自倉庫不足分 |
| `satellite_demand_qty` | サテライト倉庫からの需要 |
| `demand_breakdown` | JSON: 倉庫別需要内訳 |
| `ordering_code` | 発注コード（13桁ゼロパディング） |
| `purchase_unit_price` | 仕入単価 |

---

## 5. 到着予定日の計算

**実装箇所:** `OrderCandidateCalculationService::calculateArrivalDate()` (L941-996)

```
仮到着日 = 発注日 + リードタイム日数
   ↓
納品可能曜日チェック → 最大14日先送り
   ↓
倉庫休日チェック → 最大14日先送り
   ↓
確定到着日
```

| ステップ | データソース | 実装行 |
|----------|-------------|--------|
| リードタイム | `contractors.lead_time_id → lead_times.lead_time_mon` | L948-949 |
| 納品可能曜日 | `wms_contractor_warehouse_delivery_days` | L957-973 |
| 倉庫休日 | `wms_warehouse_calendars` | L977-989 |

---

## 6. 計算ログ

**出力先:** `wms_order_calculation_logs`

各候補ごとに以下を記録:

| カラム | 内容 |
|--------|------|
| `calculation_type` | `INTERNAL` / `EXTERNAL` |
| `current_effective_stock` | 計算時の有効在庫 |
| `incoming_quantity` | 入庫予定数 |
| `safety_stock_setting` | 発注点設定値 |
| `calculated_shortage_qty` | 計算された不足数 |
| `calculated_order_quantity` | 最終発注数量 |
| `calculation_details` | JSON: 計算過程の詳細（日本語） |

---

## 7. 関連ファイル一覧

### サービス層

| ファイル | 役割 |
|----------|------|
| `app/Services/AutoOrder/OrderCandidateCalculationService.php` | **発注候補計算（本ドキュメントの主題）** |
| `app/Services/AutoOrder/StockSnapshotService.php` | 在庫スナップショット生成 |
| `app/Services/AutoOrder/OrderExecutionService.php` | 候補確定 → 入荷予定作成 |
| `app/Services/AutoOrder/TransferCandidateExecutionService.php` | 移動候補確定 → 横持ちキュー作成 |
| `app/Services/AutoOrder/OrderTransmissionService.php` | JX API送信 |
| `app/Services/AutoOrder/OrderCancellationService.php` | 入荷予定キャンセル |
| `app/Services/AutoOrder/DemandDistributionJobHandler.php` | 需要分配ジョブハンドラ |
| `app/Services/AutoOrder/OrderDataFileService.php` | 発注データファイル管理 |
| `app/Services/AutoOrder/OrderValidationService.php` | 送信前バリデーション |
| `app/Services/AutoOrder/CalendarGenerationService.php` | 倉庫カレンダー生成 |
| `app/Services/AutoOrder/ContractorLeadTimeService.php` | リードタイム管理 |

### コマンド

| コマンド | スケジュール | 役割 |
|----------|-------------|------|
| `wms:auto-order-scheduled` | 5分毎 | 発注先ごとの自動候補生成 |
| `wms:auto-order-transmit` | 5分毎 | 確定済み候補の自動送信 |
| `wms:auto-order-calculate` | 手動 | 全仕入先一括の候補計算 |
| `wms:snapshot-stocks` | 手動 | 在庫スナップショット単体生成 |
| `wms:sync-monthly-safety-stocks` | 月末 01:30 | 月次安全在庫同期 |
| `wms:generate-calendars` | 月初 01:00 | 倉庫カレンダー生成 |

### Enum

| Enum | 値 |
|------|------|
| `CandidateStatus` | PENDING / APPROVED / CONFIRMED / EXECUTED / EXCLUDED |
| `CalculationType` | INTERNAL / EXTERNAL |
| `JobProcessName` | STOCK_SNAPSHOT / ORDER_CALC / ORDER_EXECUTION / ORDER_TRANSMISSION / TRANSFER_APPROVAL 等 |
| `TransmissionType` | INTERNAL / EXTERNAL |
| `OriginType` | AUTO / MANUAL |
| `LotStatus` | RAW / ADJUSTED / SPLIT / MERGED |

### モデル

| モデル | テーブル |
|--------|----------|
| `WmsOrderCandidate` | `wms_order_candidates` |
| `WmsStockTransferCandidate` | `wms_stock_transfer_candidates` |
| `WmsAutoOrderJobControl` | `wms_auto_order_job_controls` |
| `WmsOrderCalculationLog` | `wms_order_calculation_logs` |
| `WmsContractorSetting` | `wms_contractor_settings` |
| `WmsWarehouseAutoOrderSetting` | `wms_warehouse_auto_order_settings` |
| `WmsItemStockSnapshot` | `wms_item_stock_snapshots` |
| `WmsOrderIncomingSchedule` | `wms_order_incoming_schedules` |

---

## 8. INTERNAL vs EXTERNAL 比較表

| 項目 | INTERNAL（移動候補） | EXTERNAL（発注候補） |
|------|---------------------|---------------------|
| 出力テーブル | `wms_stock_transfer_candidates` | `wms_order_candidates` |
| 不足数計算 | `発注点 - (有効在庫 + 入庫予定)` | `発注点 - (有効在庫 + 入庫予定 + 移動入庫 - 移動出庫)` |
| 仕入れ単位切り上げ | **なし（バラ発注可能）** | **あり（`roundUpToUnit`）** |
| 発注点0対応 | 在庫0で最低1個 | 在庫0で最低1個（→仕入れ単位に切り上げ） |
| 需要内訳 | なし | `demand_breakdown` JSON |
| 発注コード | なし | `ordering_code`（13桁） |
| 仕入単価 | なし | `purchase_unit_price` |
| 仕入先指定フィルタ | 常に全件処理 | `targetContractorIds` で絞り込み可 |

---

## 9. 排他制御

**実装箇所:** `OrderCandidateCalculationService::calculate()` (L92-145)

### パターンA: 仕入先指定あり

- 対象仕入先（親+子）に PENDING/APPROVED の候補があればエラー

### パターンB: 仕入先指定なし

- APPROVED の候補が存在すればエラー（「先に確定を行ってください」）

### 実行中チェック

```php
WmsAutoOrderJobControl::hasRunningJob(JobProcessName::ORDER_CALC)
```

---

## 10. 設計上の注意点

1. **計算順序が重要**: INTERNAL → EXTERNAL の順。EXTERNAL計算時にINTERNAL移動の影響を加味する
2. **冪等性**: 同じ `batch_code` で再実行すると既存候補を削除してから再生成
3. **メモリ最適化**: 全データをメモリにプリロードし、N+1クエリを回避
4. **バルクインサート**: 1000件ずつチャンク処理でメモリ使用量を制御
5. **ジョブ進捗**: `WmsAutoOrderJobControl.updateProgress(step, total)` で4段階の進捗を記録
