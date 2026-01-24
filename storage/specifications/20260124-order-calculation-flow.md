# 発注計算フロー仕様書

作成日: 2026-01-24

## 概要

在庫スナップショットの作成から発注候補の作成までの全体フローを定義する。

---

## 1. 処理フロー全体図

```
┌─────────────────────────────────────────────────────────────────────┐
│                         事前処理（日次バッチ）                        │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Step 0: 月別発注点の同期（月初のみ）                                 │
│                                                                     │
│  wms:sync-monthly-safety-stocks                                     │
│  wms_monthly_safety_stocks → item_contractors.safety_stock          │
│                                                                     │
│  ※ use_safety_stock_auto_update = true の商品のみ更新              │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         発注計算処理                                 │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Step 1: 在庫スナップショット作成                                    │
│                                                                     │
│  StockSnapshotService::generateAll()                                │
│                                                                     │
│  入力:                                                              │
│    - wms_v_stock_available（現在の有効在庫）                         │
│    - wms_order_incoming_schedules（入荷予定）                        │
│                                                                     │
│  出力:                                                              │
│    - wms_item_stock_snapshots                                       │
│      ├─ total_effective_piece（有効在庫）                            │
│      └─ total_incoming_piece（入荷予定数）                           │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Step 2: 発注候補計算                                                │
│                                                                     │
│  OrderCandidateCalculationService::calculate()                      │
│                                                                     │
│  2-1. データプリロード                                               │
│    - 在庫スナップショット                                            │
│    - 発注先リードタイム（contractors.lead_time_id → lead_times）     │
│    - 納品可能曜日（wms_contractor_warehouse_delivery_days）          │
│    - 倉庫休日（wms_warehouse_calendars）                            │
│                                                                     │
│  2-2. INTERNAL移動候補計算                                           │
│    - 内部移動が必要な商品を抽出                                      │
│    → wms_stock_transfer_candidates                                  │
│                                                                     │
│  2-3. EXTERNAL発注候補計算                                           │
│    - 外部発注が必要な商品を抽出                                      │
│    - 到着予定日を計算（リードタイム + 曜日調整 + 休日調整）           │
│    → wms_order_candidates                                           │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Step 3: 計算ログ保存                                                │
│                                                                     │
│  → wms_order_calculation_logs                                       │
│    計算過程の詳細（デバッグ・監査用）                                 │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 2. 各ステップの詳細

### Step 0: 月別発注点の同期

**実行タイミング**: 月初（1日）
**コマンド**: `php artisan wms:sync-monthly-safety-stocks`

```
wms_monthly_safety_stocks
    │
    ▼ (month = 現在月)
item_contractors.safety_stock を更新
    │
    ▼ (条件: use_safety_stock_auto_update = true)
発注計算で使用する発注点が最新化される
```

**ポイント**:
- 発注計算時には既に`item_contractors.safety_stock`が最新化されている前提
- 発注計算処理内では月別発注点を参照しない

---

### Step 1: 在庫スナップショット作成

**サービス**: `StockSnapshotService::generateAll()`
**テーブル**: `wms_item_stock_snapshots`

#### 入力データ

| データソース | 内容 |
|-------------|------|
| `wms_v_stock_available` | 現在の有効在庫（available_for_wms） |
| `wms_order_incoming_schedules` | 入荷予定（PENDING/PARTIAL の残数量） |

#### 出力データ

```sql
wms_item_stock_snapshots (
    warehouse_id,
    item_id,
    snapshot_at,
    total_effective_piece,   -- 現在の有効在庫
    total_incoming_piece     -- 入荷予定数
)
```

#### 処理内容

```sql
-- 有効在庫を集計
SELECT warehouse_id, item_id, SUM(available_for_wms) as total_effective
FROM wms_v_stock_available
GROUP BY warehouse_id, item_id

-- 入荷予定を集計
SELECT warehouse_id, item_id, SUM(expected_quantity - received_quantity) as total_incoming
FROM wms_order_incoming_schedules
WHERE status IN ('PENDING', 'PARTIAL')
GROUP BY warehouse_id, item_id
```

---

### Step 2: 発注候補計算

**サービス**: `OrderCandidateCalculationService::calculate()`

#### 2-1. データプリロード

| データ | 参照テーブル | メモリ構造 |
|--------|-------------|-----------|
| 在庫スナップショット | `wms_item_stock_snapshots` | `[warehouse_id][item_id] => {effective, incoming}` |
| 発注先リードタイム | `contractors` → `lead_times` | `[contractor_id] => lead_time_days` |
| 納品可能曜日 | `wms_contractor_warehouse_delivery_days` | `[contractor_id][warehouse_id] => setting` |
| 倉庫休日 | `wms_warehouse_calendars` | `[warehouse_id][date] => true` |

#### 2-2. 不足数量計算

```
不足数 = 発注点 - (有効在庫 + 入荷予定 + 移動入庫予定 - 移動出庫予定)

発注点: item_contractors.safety_stock（月別発注点で更新済み）
有効在庫: wms_item_stock_snapshots.total_effective_piece
入荷予定: wms_item_stock_snapshots.total_incoming_piece
```

#### 2-3. 到着予定日計算

```
┌───────────────────────────────────────────────────────────┐
│                      発注日（今日）                        │
└─────────────────────────┬─────────────────────────────────┘
                          ▼
┌───────────────────────────────────────────────────────────┐
│  + リードタイム（発注先単位）                               │
│    contractors.lead_time_id → lead_times.lead_time_xxx    │
│    ※未設定: INTERNAL=1日, EXTERNAL=3日                    │
└─────────────────────────┬─────────────────────────────────┘
                          ▼
┌───────────────────────────────────────────────────────────┐
│  納品可能曜日チェック（発注先×倉庫）                        │
│    wms_contractor_warehouse_delivery_days                 │
│    → 不可曜日なら次の可能日へスキップ                      │
└─────────────────────────┬─────────────────────────────────┘
                          ▼
┌───────────────────────────────────────────────────────────┐
│  倉庫休日チェック                                          │
│    wms_warehouse_calendars                                │
│    → 休日なら次の営業日へスキップ                          │
└─────────────────────────┬─────────────────────────────────┘
                          ▼
┌───────────────────────────────────────────────────────────┐
│                    到着予定日（確定）                       │
└───────────────────────────────────────────────────────────┘
```

---

## 3. データフロー図

```
┌─────────────────────────────────────────────────────────────────────┐
│                           マスタデータ                               │
├─────────────────────────────────────────────────────────────────────┤
│ item_contractors          発注点、仕入単位、自動発注フラグ            │
│   └─ safety_stock        ← wms_monthly_safety_stocks から同期       │
│                                                                     │
│ contractors               発注先マスタ                               │
│   └─ lead_time_id        → lead_times（リードタイム）               │
│                                                                     │
│ wms_contractor_warehouse_delivery_days  納品可能曜日（発注先×倉庫）  │
│ wms_warehouse_calendars                 倉庫休日                     │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                           在庫データ                                 │
├─────────────────────────────────────────────────────────────────────┤
│ real_stocks               実在庫（lot単位）                          │
│   └─ wms_v_stock_available  有効在庫ビュー                           │
│                                                                     │
│ wms_order_incoming_schedules  入荷予定                               │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       スナップショット                               │
├─────────────────────────────────────────────────────────────────────┤
│ wms_item_stock_snapshots                                            │
│   ├─ total_effective_piece   有効在庫                                │
│   └─ total_incoming_piece    入荷予定数                              │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                          発注候補                                    │
├─────────────────────────────────────────────────────────────────────┤
│ wms_stock_transfer_candidates   内部移動候補（INTERNAL発注先）       │
│ wms_order_candidates            外部発注候補（EXTERNAL発注先）       │
│ wms_order_calculation_logs      計算ログ                             │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 4. 関連テーブル一覧

### マスタ系

| テーブル | 用途 |
|---------|------|
| `item_contractors` | 商品×倉庫×発注先の設定（発注点、仕入単位） |
| `contractors` | 発注先マスタ（lead_time_id） |
| `lead_times` | リードタイム設定（曜日別） |
| `wms_contractor_warehouse_delivery_days` | 納品可能曜日（発注先×倉庫） |
| `wms_warehouse_calendars` | 倉庫カレンダー（休日設定） |
| `wms_monthly_safety_stocks` | 月別発注点 |

### トランザクション系

| テーブル | 用途 |
|---------|------|
| `wms_item_stock_snapshots` | 在庫スナップショット |
| `wms_order_incoming_schedules` | 入荷予定 |
| `wms_stock_transfer_candidates` | 内部移動候補 |
| `wms_order_candidates` | 外部発注候補 |
| `wms_order_calculation_logs` | 計算ログ |

---

## 5. バッチ実行順序

### 月初（1日）

```bash
# 1. 月別発注点を同期
php artisan wms:sync-monthly-safety-stocks

# 2. 通常の発注計算を実行
# (以下のJob or Commandで)
```

### 日次

```bash
# 1. 在庫スナップショット作成
# 2. 発注候補計算
# (発注計算Jobが両方を実行)
```

---

## 6. 計算例

### 前提条件

| 項目 | 値 |
|------|-----|
| 商品 | item_id=100 |
| 倉庫 | warehouse_id=1 |
| 発注先 | contractor_id=1126（カナカン日配） |
| 発注日 | 2026-01-24（金） |
| 発注点 | 100（月別発注点で更新済み） |
| 有効在庫 | 30 |
| 入荷予定 | 20 |
| リードタイム | 2日 |
| 納品可能曜日 | 火・金のみ |

### 計算

```
1. 不足数計算
   不足数 = 発注点 - (有効在庫 + 入荷予定)
         = 100 - (30 + 20)
         = 50

2. 到着予定日計算
   発注日: 2026-01-24（金）
   + リードタイム: 2日
   = 2026-01-26（日）← 仮到着日

   日曜: 納品不可 → 2026-01-27（月）
   月曜: 納品不可 → 2026-01-28（火）
   火曜: 納品可能 → 確定

   到着予定日: 2026-01-28（火）

3. 発注候補データ
   {
     warehouse_id: 1,
     item_id: 100,
     contractor_id: 1126,
     order_quantity: 50,
     expected_arrival_date: "2026-01-28"
   }
```

---

## 7. 実装タスク

| # | タスク | 状況 |
|---|--------|------|
| 1 | `lead_times`にOracle「納品予定」を移行 | ✅ 完了 |
| 2 | `contractors.lead_time_id`を紐付け | ✅ 完了 |
| 3 | `OrderCandidateCalculationService`にリードタイム参照を追加 | 未実施 |
| 4 | `OrderCandidateCalculationService`に納品曜日チェックを追加 | 未実施 |
| 5 | `OrderCandidateCalculationService`に倉庫休日チェックを追加 | 未実施 |
| 6 | `wms_warehouse_calendars`に休日データ登録 | 未実施 |
