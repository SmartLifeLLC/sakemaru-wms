# 出荷システム仕様書

ウェーブ生成・ピッキング・欠品処理・出荷確定の統合仕様書

**最終更新**: 2026-01-12

---

## 1. 概要

販売管理システム（BoozeCore）と同一DB上で動作する倉庫管理（WMS）出荷機能。
受注データからウェーブを生成し、在庫引当、ピッキング、出荷確定までの一連の出荷業務を管理。

---

## 2. 処理フロー

```
┌─────────────┐
│  受注データ  │  trades / trade_items
└──────┬──────┘
       │
       ▼
┌─────────────┐
│ ウェーブ生成 │  WaveService
│ (wms_waves) │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│  在庫引当   │  StockAllocationService (FEFO→FIFO)
│(wms_reservations)│
└──────┬──────┘
       │
   ┌───┴───┐
   ▼       ▼
在庫あり  在庫不足
   │       │
   │       ▼
   │   ┌─────────────┐
   │   │  欠品処理   │  ShortageDetector / ProxyShipmentService
   │   │ (代理出荷)  │
   │   └──────┬──────┘
   │          │
   └────┬─────┘
        ▼
┌─────────────┐
│  ピッキング  │  ルート最適化（A*アルゴリズム）
│(wms_picking_tasks)│
└──────┬──────┘
       │
       ▼
┌─────────────┐
│  出荷確定   │  在庫差引、売上確定
└─────────────┘
```

---

## 3. ウェーブ生成

### 3.1 概要

受注伝票（earnings）を倉庫×配送コース×時間帯でグループ化し、ウェーブを生成。

### 3.2 コマンド

```bash
php artisan wms:generate-waves
```

### 3.3 スケジュール

| 時刻 | 説明 |
|------|------|
| 06:00, 07:00, 08:00 | 自動生成 |

---

## 4. 在庫引当

### 4.1 FEFO → FIFO 優先順位

1. **FEFO (First Expiry, First Out)**: 賞味期限が近いものから（NULLは最後）
2. **FIFO (First In, First Out)**: 同じ賞味期限なら入庫日が古いものから
3. **Tie-breaker**: real_stock_id 昇順

### 4.2 引当の流れ

```php
// StockAllocationService
$allocations = $service->allocate($earningId, $items);
// wms_reservationsに引当レコード作成
// real_stocks.wms_reserved_qty を増加
```

### 4.3 WMS有効在庫計算

```sql
WMS有効在庫 = current_quantity - wms_reserved_qty - wms_picking_qty
```

---

## 5. 欠品処理

### 5.1 欠品検出

| タイミング | サービス |
|-----------|---------|
| 引当時 | AllocationShortageDetector |
| ピッキング時 | PickingShortageDetector |

### 5.2 代理出荷フロー

```
欠品検出 ─┬─> 他倉庫に在庫あり → 代理出荷割当
          │   (wms_shortage_allocations)
          │
          └─> 在庫なし → 欠品確定
              (wms_shortages.is_confirmed=true)
```

### 5.3 代理出荷（ProxyShipmentService）

```php
// 他倉庫からの代理出荷を検索・割当
$service->findAndAllocateProxy($shortage);
```

---

## 6. ピッキング

### 6.1 ピッキングタスク

| テーブル | 説明 |
|---------|------|
| wms_picking_tasks | タスク管理（伝票単位） |
| wms_picking_logs | 実績ログ |
| wms_picking_item_results | 商品別実績 |

### 6.2 ルート最適化

```
app/Services/Picking/
├── RouteOptimizer.php       # ルート最適化メイン
├── AStarGrid.php            # A*アルゴリズム
├── DistanceCacheService.php # 距離キャッシュ
└── PickRouteService.php     # ルート計算
```

### 6.3 ソート順

```sql
ORDER BY zone_code, walking_order, earning_id
```

---

## 7. 出荷確定

### 7.1 処理内容

1. `real_stocks.current_quantity` 減算
2. `earnings.is_delivered = 1` 更新
3. `wms_reservations` クリア

### 7.2 サービス

```php
// 出荷確定
$service->confirmShipment($waveId);
```

---

## 8. データベース設計

### 8.1 主要テーブル

| テーブル | 説明 |
|---------|------|
| wms_waves | ウェーブ管理 |
| wms_reservations | 在庫引当 |
| wms_picking_tasks | ピッキングタスク |
| wms_picking_logs | ピッキングログ |
| wms_picking_item_results | 商品別実績 |
| wms_shortages | 欠品記録 |
| wms_shortage_allocations | 欠品割当（代理出荷） |
| wms_locations | ロケーション拡張 |

### 8.2 欠品ステータス

| 値 | 説明 |
|----|------|
| DETECTED | 検出 |
| PROXY_ALLOCATED | 代理出荷割当済み |
| CONFIRMED | 欠品確定 |
| RESOLVED | 解消済み |

---

## 9. サービスクラス

```
app/Services/
├── WaveService.php                    # ウェーブ生成・管理
├── StockAllocationService.php         # 在庫引当 (FEFO→FIFO)
│
├── Shortage/
│   ├── AllocationShortageDetector.php # 引当時欠品検出
│   ├── PickingShortageDetector.php    # ピッキング時欠品検出
│   ├── ProxyShipmentService.php       # 代理出荷
│   └── ShortageConfirmationService.php # 欠品確定
│
├── Picking/
│   ├── RouteOptimizer.php             # ルート最適化
│   ├── AStarGrid.php                  # A*アルゴリズム
│   ├── DistanceCacheService.php       # 距離キャッシュ
│   ├── PickRouteService.php           # ピッキングルート
│   └── FrontPointCalculator.php       # 前面ポイント計算
│
└── Print/                             # 帳票出力
```

---

## 10. 運用ルール

### 10.1 排他制御

| 方式 | 説明 |
|------|------|
| `SELECT FOR UPDATE` | 行ロック |
| `wms_lock_version` | 楽観ロック |

### 10.2 冪等制御

`wms_idempotency_keys` テーブルでscope/key_hashを管理

### 10.3 監査ログ

`wms_op_logs` に before/after JSON を記録

---

## 11. Filament UI

### 11.1 ウェーブ管理

- ウェーブ一覧
- ウェーブ生成アクション
- ピッキングタスク割当

### 11.2 ピッキング管理

- タスク一覧
- 実行画面
- 欠品報告

### 11.3 欠品管理

- 欠品一覧
- 代理出荷割当
- 欠品確定

---

## 12. 実装状況

| 機能 | 状況 |
|------|------|
| ウェーブ生成 | ✅ 完了 |
| 在庫引当（FEFO→FIFO） | ✅ 完了 |
| ピッキングタスク管理 | ✅ 完了 |
| ルート最適化（A*） | ✅ 完了 |
| 欠品検出 | ✅ 完了 |
| 代理出荷 | ✅ 完了 |
| 出荷確定 | ✅ 完了 |
| 配送コース変更 | ✅ 完了 |

---

## 13. 倉庫間移動ピッキング

stock_transfers（倉庫間移動伝票）をピッキング対象として統合する機能。

詳細は `stock-transfers-picking-integration.md` を参照。

### 13.1 概要

- 移動伝票もピッキングリストに含める
- 仮想倉庫間移動は対象外（帳簿移動のみ）
- 配送コース単位で波動生成に含める

### 13.2 実装状況

| 機能 | 状況 |
|------|------|
| 仕様書作成 | ✅ 完了 |
| DBマイグレーション | ⬜ 未実装 |
| 波動生成対応 | ⬜ 未実装 |
| API対応 | ⬜ 未実装 |
| 帳票対応 | ⬜ 未実装 |

---

## 14. 旧仕様書

詳細な設計資料は `old/outbound/` に移動:
- `01_*_wave_generation.md` - ウェーブ生成詳細
- `02_picking*.md` - ピッキング詳細
- `03_shortage_reallocation.md` - 欠品処理詳細
- `04_shipment_confirmation.md` - 出荷確定詳細
- `05_location_path_optimization.md` - ルート最適化詳細
- `20251115-shorage-algorithm.md` - 欠品アルゴリズム
