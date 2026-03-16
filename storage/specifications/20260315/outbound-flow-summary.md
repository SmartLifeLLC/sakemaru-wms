# 出荷フロー全体像 & API仕様まとめ

> 作成日: 2026-03-15
> 目的: 出荷アプリ開発・修正のための包括的リファレンス

---

## 1. 出荷フロー全体像

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. WAVE生成（WaveService / GenerateWavesCommand）                    │
│    ├─ WaveSettingに基づき自動生成（cron毎分）                          │
│    ├─ 対象: earnings.picking_status='BEFORE' + delivery_course一致    │
│    ├─ フィルタ条件（重要）:                                           │
│    │   - trades.trade_direction = 'NORMAL'（返品/協賛等を除外）        │
│    │   - trade_items.is_active = true                                │
│    │   - trade_items.quantity > 0                                    │
│    └─ wave_no = W{倉庫CD}-C{コースCD}-{YYYYMMDD}-{id}               │
├─────────────────────────────────────────────────────────────────────┤
│ 2. 在庫引当（StockAllocationService）                                │
│    ├─ FEFO優先: expiration_date ASC（NULL最後）                       │
│    ├─ FIFO次点: received_at ASC                                      │
│    ├─ タイブレーカー: real_stock_id ASC                               │
│    ├─ 排他制御: MySQL GET_LOCK + wms_lock_version                    │
│    ├─ バッチ処理: 50件/バッチ × 最大2ページ                           │
│    ├─ WmsReservation (status='RESERVED') 作成                        │
│    └─ 引当欠品検知 → WmsShortage 作成                                │
├─────────────────────────────────────────────────────────────────────┤
│ 3. ピッキングタスク生成                                               │
│    ├─ グルーピング: (倉庫, フロア, ピッキングエリア, 配送コース)         │
│    ├─ WmsPickingTask作成（status='PENDING', picker_id=NULL）          │
│    └─ WmsPickingItemResult作成（walking_order=NULL）                  │
├─────────────────────────────────────────────────────────────────────┤
│ 4. ピッカー割当（AssignPickersToTasksService）                        │
│    ├─ 管理画面から手動実行                                            │
│    ├─ 戦略:                                                          │
│    │   ├─ EQUAL: アイテム数を均等分配（First Fit Decreasing）          │
│    │   └─ SKILL_BASED: スキルレベルで重み付け分配                      │
│    └─ PENDING → PICKING_READY に遷移                                 │
├─────────────────────────────────────────────────────────────────────┤
│ 5. ルート最適化（PickRouteService / RouteOptimizer）                  │
│    ├─ A*アルゴリズム + 2-opt改善                                      │
│    ├─ Nearest Insertion → 2-opt swap                                 │
│    └─ walking_order, distance_from_previous を更新                    │
├─────────────────────────────────────────────────────────────────────┤
│ 6. ピッキング実行（Android端末 → API）                                │
│    ├─ タスク開始: POST /api/picking/tasks/{id}/start                  │
│    ├─ 数量記録:   POST /api/picking/tasks/{itemResultId}/update       │
│    ├─ キャンセル:  POST /api/picking/tasks/{itemResultId}/cancel       │
│    └─ タスク完了: POST /api/picking/tasks/{id}/complete               │
├─────────────────────────────────────────────────────────────────────┤
│ 7. 欠品処理                                                          │
│    ├─ 引当欠品（allocation）: ordered_qty > planned_qty               │
│    ├─ 庫内欠品（picking）:    planned_qty > picked_qty                │
│    ├─ 横持ち出荷（proxy shipment）: 他倉庫からの補充                   │
│    └─ 欠品確認 → is_confirmed=true → 出荷可能                        │
├─────────────────────────────────────────────────────────────────────┤
│ 8. 出荷完了                                                          │
│    ├─ WmsPickingTask.status → COMPLETED → SHIPPED                    │
│    ├─ earnings.picking_status → SHIPPED                              │
│    ├─ 倉庫ミスマッチチェック → 移動伝票自動生成                        │
│    └─ 印刷キュー → ピッキングリストPDF生成                             │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 2. ステータス遷移一覧

### Wave
```
PENDING → PICKING → SHORTAGE → COMPLETED → CLOSED
```

### ピッキングタスク (wms_picking_tasks.status)
```
PENDING → PICKING_READY → PICKING → COMPLETED → SHIPPED
```

### ピッキングアイテム結果 (wms_picking_item_results.status)
```
PENDING → PICKING → COMPLETED
                  → SHORTAGE（欠品時、要手動対応）
```

### 欠品 (wms_shortages.status)
```
OPEN → REALLOCATING → FULFILLED / SHORTAGE / PARTIAL_SHORTAGE / CANCELLED
```

### 欠品割当 (wms_shortage_allocations.status)
```
PENDING → RESERVED → PICKING → FULFILLED / SHORTAGE / CANCELLED
```

### 引当 (wms_reservations.status)
```
RESERVED → CONSUMED / RELEASED / CANCELLED / SHORTAGE
```

### 売上ピッキングステータス (earnings.picking_status)
```
BEFORE → BEFORE_PICKING → PICKING → COMPLETED → SHIPPED
```

### 移動ピッキングステータス (stock_transfers.picking_status)
```
BEFORE → BEFORE_PICKING → PICKING → COMPLETED → SHIPPED
```

---

## 3. API仕様（全19エンドポイント）

### 認証
- **方式**: Sanctum Bearer Token + X-API-Key ヘッダー
- **ログイン**: picker.code + password でトークン取得
- **レスポンス共通フォーマット**:
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {},
    "message": "...",
    "debug_message": null
  }
}
```

### 3.1 認証系（3件）

| # | Method | Path | 説明 |
|---|--------|------|------|
| 1 | POST | `/api/auth/login` | ピッカーログイン（code + password） |
| 2 | POST | `/api/auth/logout` | ログアウト（トークン無効化） |
| 3 | GET | `/api/me` | 認証済みピッカー情報取得 |

**ログインレスポンス例:**
```json
{
  "token": "1|abcdef123456...",
  "picker": {
    "id": 2,
    "code": "TEST001",
    "name": "テストピッカー",
    "default_warehouse_id": 991
  }
}
```

### 3.2 マスタ系（1件）

| # | Method | Path | 説明 |
|---|--------|------|------|
| 4 | GET | `/api/master/warehouses` | 倉庫一覧（id, code, name, kana_name, out_of_stock_option） |

**out_of_stock_option**: `IGNORE_STOCK`（欠品無視）/ `UP_TO_STOCK`（在庫分のみ）

### 3.3 入荷系（6件）

| # | Method | Path | 説明 |
|---|--------|------|------|
| 5 | GET | `/api/incoming/schedules` | 入荷予定一覧（warehouse_id必須, search任意） |
| 6 | GET | `/api/incoming/schedules/{id}` | 入荷予定詳細 |
| 7 | GET | `/api/incoming/locations` | ロケーション検索（code1/code2/code3階層） |
| 8 | POST | `/api/incoming/work-items` | 入荷作業開始 |
| 9 | GET | `/api/incoming/work-items` | 入荷作業一覧（status/日付フィルタ） |
| 10 | PUT | `/api/incoming/work-items/{id}` | 入荷作業更新（数量/日付/ロケーション） |
| 11 | DELETE | `/api/incoming/work-items/{id}` | 入荷作業キャンセル（WORKING時のみ） |
| 12 | POST | `/api/incoming/work-items/{id}/complete` | 入荷確定（real_stocks更新） |

### 3.4 ピッキング系（7件）★出荷アプリのコア

| # | Method | Path | 説明 |
|---|--------|------|------|
| 13 | GET | `/api/picking/tasks` | タスク一覧 |
| 14 | GET | `/api/picking/tasks/{id}` | タスク詳細 |
| 15 | GET | `/api/picking/items/{id}` | アイテム詳細 |
| 16 | POST | `/api/picking/tasks/{id}/start` | タスク開始 |
| 17 | POST | `/api/picking/tasks/{itemResultId}/update` | ピッキング数量更新 |
| 18 | POST | `/api/picking/tasks/{id}/complete` | タスク完了 |
| 19 | POST | `/api/picking/tasks/{itemResultId}/cancel` | アイテムキャンセル |

---

## 4. ピッキングAPI詳細

### GET /api/picking/tasks
**パラメータ:**
| 名前 | 型 | 必須 | 説明 |
|------|-----|------|------|
| warehouse_id | int | Yes | 倉庫ID |
| picker_id | int | No | ピッカーで絞込み |
| picking_area_id | int | No | エリアで絞込み |

**レスポンス構造（グルーピング）:**
```
配送コース (course)
  └─ ピッキングエリア (picking_area)
      └─ 波動 (wave)
          └─ ピッキングリスト (picking_list[])
```

**picking_list各アイテムのフィールド:**
| フィールド | 型 | 説明 |
|-----------|-----|------|
| wms_picking_item_result_id | int | アイテム結果ID（update/cancelで使用） |
| item_id | int | 商品ID |
| item_name | string | 商品名 |
| jan_code | string | 主JANコード |
| jan_code_list | string[] | 全JANコード（複数バーコード対応） |
| volume | string | 容量（例: "720ml"） |
| capacity_case | int | ケース入数 |
| packaging | string | 包装形態（瓶, 缶等） |
| temperature_type | string | 温度帯（常温, 冷蔵, 冷凍） |
| images | string[] | 商品画像URL（最大3件） |
| planned_qty_type | enum | CASE / PIECE |
| planned_qty | decimal | 引当数量 |
| picked_qty | decimal | ピッキング済数量 |
| status | enum | PENDING / PICKING / COMPLETED / SHORTAGE |
| slip_number | int | 伝票番号（earning_id） |

### POST /api/picking/tasks/{itemResultId}/update
**リクエスト:**
```json
{
  "picked_qty": 5,
  "picked_qty_type": "PIECE"
}
```

**自動計算:**
```
shortage_qty = max(0, planned_qty - picked_qty)
```
- 複数回呼び出し可能（上書き方式）
- picked_qty > planned_qty も許容（過剰ピック）

### POST /api/picking/tasks/{id}/complete
**完了処理フロー:**
1. planned_qty=0 のアイテムは自動COMPLETED
2. planned_qty>0 かつ picked_qty=0 のアイテムがあれば **422エラー**
3. shortage_qty=0 → COMPLETED / shortage_qty>0 → SHORTAGE
4. `earning_delivery_queue` に非同期ジョブ登録

**べき等**: 完了済みタスクへの再呼び出しは200 SUCCESS

---

## 5. 主要データベーステーブル

### WMSテーブル（wms_プレフィックス）

| テーブル | 主要カラム | 用途 |
|---------|-----------|------|
| wms_waves | delivery_course_id, status, picking_status | 波動バッチ |
| wms_wave_settings | delivery_course_id, picking_start_time, picking_deadline_time | 波動スケジュール設定 |
| wms_reservations | wave_id, earning_id, item_id, qty, status, wms_lock_version | 在庫引当レコード |
| wms_picking_tasks | wave_id, trade_id, warehouse_id, delivery_course_id, status, picker_id, task_type | ピッキングタスク |
| wms_picking_item_results | picking_task_id, earning_id, stock_transfer_id, source_type, ordered_qty, planned_qty, picked_qty, shortage_qty, walking_order, status | ピッキング明細 |
| wms_shortages | earning_id, item_id, order_qty, planned_qty, picked_qty, allocation_shortage_qty, picking_shortage_qty, status, is_confirmed | 欠品レコード |
| wms_shortage_allocations | shortage_id, from_warehouse_id, assign_qty, status, is_confirmed | 横持ち出荷割当 |
| wms_picking_logs | picking_task_id, picker_id, action, quantity | ピッキング監査ログ |
| wms_pickers | code, name, current_warehouse_id, skill_level, is_active | ピッカーマスタ |
| wms_picking_areas | warehouse_id, code, name | ピッキングエリア |
| wms_picking_assignment_strategies | warehouse_id, strategy_type | 割当戦略設定 |

### 基幹テーブル（参照）

| テーブル | 主要カラム | 結合理由 |
|---------|-----------|---------|
| earnings | warehouse_id, delivery_course_id, picking_status, trade_id | 出荷元データ |
| trades | trade_direction (NORMAL/RETURN/SPONSOR/INVENTORY/ITEM_SET) | 出荷対象フィルタ |
| trade_items | quantity, is_active, quantity_type | 引当元 |
| real_stocks | current_quantity, wms_reserved_qty, wms_picking_qty, wms_lock_version | 在庫数量 |
| real_stock_lots | expiration_date, created_at, status | FEFO/FIFOソート |
| stock_transfers | from_warehouse_id, to_warehouse_id, delivery_course_id, picking_status | 移動ピッキング |
| delivery_courses | warehouse_id | 波動グルーピング単位 |
| warehouses | stock_warehouse_id, is_virtual | 仮想倉庫解決 |

### ビュー

| ビュー | 計算式 | 用途 |
|--------|--------|------|
| wms_v_stock_available | `GREATEST(available_quantity - wms_reserved_qty - wms_picking_qty, 0)` | リアルタイム引当可能在庫 |

---

## 6. 主要サービスクラス

| サービス | ファイル | 責務 |
|---------|---------|------|
| WaveService | `Services/WaveService.php` | 波動作成・管理 |
| StockAllocationService | `Services/StockAllocationService.php` | FEFO/FIFO在庫引当 |
| AssignPickersToTasksService | `Services/Picking/AssignPickersToTasksService.php` | ピッカー割当（EQUAL/SKILL_BASED） |
| RouteOptimizer | `Services/Picking/RouteOptimizer.php` | ルート最適化（A* + 2-opt） |
| PickRouteService | `Services/Picking/PickRouteService.php` | フロアベース経路探索 |
| PickingListService | `Services/PickingList/PickingListService.php` | ピッキングリスト生成（1次/2次/3次） |
| PickingListPdfService | `Services/PickingList/PickingListPdfService.php` | PDF生成 |
| PrintRequestService | `Services/Print/PrintRequestService.php` | 印刷ジョブキュー管理 |
| PickingLogService | `Services/PickingLogService.php` | 監査ログ記録 |
| AllocationShortageDetector | `Services/Shortage/AllocationShortageDetector.php` | 引当時欠品検知 |
| PickingShortageDetector | `Services/Shortage/PickingShortageDetector.php` | 庫内欠品検知 |
| ProxyShipmentService | `Services/Shortage/ProxyShipmentService.php` | 横持ち出荷作成 |
| ShortageConfirmationService | `Services/Shortage/ShortageConfirmationService.php` | 欠品確認処理 |
| ShortageApprovalService | `Services/Shortage/ShortageApprovalService.php` | 欠品承認 |
| EarningDeliveryQueueService | `Services/EarningDeliveryQueueService.php` | 出荷キュー登録 |

---

## 7. APIコントローラー

| コントローラー | ファイル | 担当 |
|--------------|---------|------|
| PickingTaskController | `Http/Controllers/Api/PickingTaskController.php` | ピッキングAPI全般 |
| PickingRouteController | `Http/Controllers/Api/PickingRouteController.php` | ルート可視化API |
| IncomingScheduleController | `Http/Controllers/Api/IncomingScheduleController.php` | 入荷予定API |
| IncomingWorkItemController | `Http/Controllers/Api/IncomingWorkItemController.php` | 入荷作業API |
| IncomingLocationController | `Http/Controllers/Api/IncomingLocationController.php` | ロケーションAPI |
| AuthController | `Http/Controllers/Api/AuthController.php` | 認証API |
| WarehouseController | `Http/Controllers/Api/WarehouseController.php` | 倉庫マスタAPI |

---

## 8. Filament管理画面リソース

| リソース | Model | 主な機能 |
|---------|-------|---------|
| Waves | Wave | 波動一覧/作成、ピッキングリスト印刷（1次・3次） |
| WaveSettings | WaveSetting | 配送コース別ピッキングスケジュール設定 |
| WmsPickingTasks | WmsPickingTask | タスク管理、ピッカー割当、ステータス追跡 |
| ListWmsPickingWaitings | - | 未割当タスク + 一括ピッカー割当 |
| ListWmsCompletedPickingTasks | - | 完了タスクビュー |
| ListWmsPickingItemEdits | - | 数量修正/調整 |
| WmsPickingLogs | WmsPickingLog | 監査ログ |
| WmsPickingAreas | WmsPickingArea | エリア定義 |
| WmsPickers | WmsPicker | ピッカー管理、スキルレベル |
| WmsShortages | WmsShortage | 欠品検知・承認 |
| WmsShortageAllocations | WmsShortageAllocation | 横持ち出荷管理 |
| WmsShortagesWaitingApprovals | - | 承認待ち欠品 |

---

## 9. 未実装/計画中の機能

### 9.1 移動伝票ピッキング統合（stock_transfers）
- `wms_picking_item_results` に `source_type` (EARNING/STOCK_TRANSFER) と `stock_transfer_id` 追加
- 波動生成時に `stock_transfers` も取り込み
- ピッキング完了時に `stock_transfers.picking_status` 更新
- 仮想倉庫間移動は除外

### 9.2 倉庫ミスマッチ移動伝票自動生成
- トリガー: WmsPickingTask.status → SHIPPED
- earning.warehouse_id ≠ delivery_course.warehouse_id（実倉庫ベース）の場合
- `stock_transfer_queue` に自動登録（request_id="wh-mismatch-{earning_id}"）

### 9.3 配送コース自動切替
- テーブル: `wms_buyer_delivery_course_switch_settings`
- 15分間隔でcron実行
- `buyer_details.delivery_course_id` を自動更新
- `last_executed_date` で当日1回のみ実行保証

### 9.4 仮想倉庫対応の強化
- `WarehouseResolver` サービスの実装
  - `resolveRealWarehouseId()`: 仮想→実倉庫ID解決
  - `resolveAllWarehouseIds()`: 実倉庫に紐づく全倉庫ID取得
  - `isSameRealWarehouse()`: 同一実倉庫判定
- 既存クエリの `where('warehouse_id', X)` → `whereIn('warehouse_id', resolveAllWarehouseIds(X))`

### 9.5 印刷キュー統合
- `print_request_queue` に `stock_transfer_ids` カラム追加（基幹側は実装済み）
- WMS側: `wms_picking_item_results` から stock_transfer_id を収集してキューに渡す

---

## 10. 重要なビジネスルール

### フィルタ条件（必須）
```sql
-- 波動生成時の earnings 取得
WHERE earnings.picking_status = 'BEFORE'
  AND earnings.is_delivered = 0
  AND earnings.delivered_date = {対象日}
  AND earnings.delivery_course_id = {コースID}
  AND trades.trade_direction = 'NORMAL'    -- ★必須
  AND trade_items.is_active = true          -- ★必須
  AND trade_items.quantity > 0              -- ★必須
```

### 仮想倉庫ピッキングスキップ
```
IF earning.warehouse(実倉庫) = delivery_course.warehouse(実倉庫)
THEN
  WmsPickingItemResult.status = 'COMPLETED'（自動完了）
  earnings.picking_status = 'COMPLETED'
  物理ピッキング不要
```

### 排他制御
```
1. MySQL GET_LOCK(lockKey, LOCK_TIMEOUT=1sec)
2. wms_lock_version によるオプティミスティックロック
3. wms_idempotency_keys によるべき等性保証
```

### 数量タイプ表示
```
CASE  → "ケース"（NOT "CS"）
PIECE → "バラ"  （NOT "個"）
CARTON → "ボール"
```

---

## 11. ピッキングワークフロー（Androidアプリ側）

```
1. ログイン
   POST /api/auth/login {code, password, device_id}
   → token取得

2. 倉庫選択
   GET /api/master/warehouses
   → 倉庫リスト表示

3. タスク一覧取得
   GET /api/picking/tasks?warehouse_id=991
   → コース別→エリア別→波動別にグループ表示

4. タスク開始
   POST /api/picking/tasks/{id}/start
   → status: PICKING, started_at記録

5. アイテムピッキング（繰り返し）
   POST /api/picking/tasks/{itemResultId}/update
   {picked_qty: N, picked_qty_type: "PIECE"}
   → shortage_qty自動計算

6. 間違い修正（必要時）
   POST /api/picking/tasks/{itemResultId}/cancel
   → PENDING に戻る、picked_qty=0にリセット

7. タスク完了
   POST /api/picking/tasks/{id}/complete
   → COMPLETED or SHORTAGE
   → earning_delivery_queue に非同期ジョブ登録

8. ログアウト
   POST /api/auth/logout
```

### 入荷ワークフロー
```
1. 入荷予定確認
   GET /api/incoming/schedules?warehouse_id=991

2. 作業開始
   POST /api/incoming/work-items
   {incoming_schedule_id, picker_id, warehouse_id}

3. ロケーション検索
   GET /api/incoming/locations?warehouse_id=991&search=A-1

4. 作業内容更新
   PUT /api/incoming/work-items/{id}
   {work_quantity, work_arrival_date, work_expiration_date, location_id}

5. 入荷確定
   POST /api/incoming/work-items/{id}/complete
   → real_stocks に在庫追加
```

---

## 12. ファイルリファレンス

### Models
```
app/Models/Wave.php
app/Models/WaveSetting.php
app/Models/WmsPickingTask.php
app/Models/WmsPickingItemResult.php
app/Models/WmsPickingArea.php
app/Models/WmsPickingLog.php
app/Models/WmsPickingAssignmentStrategy.php
app/Models/WmsPicker.php
app/Models/WmsReservation.php
app/Models/WmsShortage.php
app/Models/WmsShortageAllocation.php
```

### Services
```
app/Services/WaveService.php
app/Services/StockAllocationService.php
app/Services/Picking/AssignPickersToTasksService.php
app/Services/Picking/PickRouteService.php
app/Services/Picking/RouteOptimizer.php
app/Services/PickingList/PickingListService.php
app/Services/PickingList/PickingListPdfService.php
app/Services/Print/PrintRequestService.php
app/Services/PickingLogService.php
app/Services/Shortage/AllocationShortageDetector.php
app/Services/Shortage/PickingShortageDetector.php
app/Services/Shortage/ProxyShipmentService.php
app/Services/Shortage/ShortageConfirmationService.php
app/Services/Shortage/ShortageApprovalService.php
app/Services/EarningDeliveryQueueService.php
```

### Controllers
```
app/Http/Controllers/Api/PickingTaskController.php
app/Http/Controllers/Api/PickingRouteController.php
app/Http/Controllers/Api/IncomingScheduleController.php
app/Http/Controllers/Api/IncomingWorkItemController.php
app/Http/Controllers/Api/IncomingLocationController.php
app/Http/Controllers/Api/AuthController.php
app/Http/Controllers/Api/WarehouseController.php
```

### Console Commands
```
app/Console/Commands/GenerateWavesCommand.php      # 波動生成
app/Console/Commands/OptimizePickingRoute.php       # ルート最適化
app/Console/Commands/GenerateTestShortages.php      # テスト欠品生成
```

### Enums
```
app/Enums/PickerSkillLevel.php
app/Enums/PickingStrategyType.php
app/Enums/EShortageStatus.php
app/Enums/QuantityType.php
app/Enums/EVolumeUnit.php
```

### 仕様書
```
storage/specifications/outbound/                                    # 出荷ロジック
storage/specifications/wave-delivery-course-reform/                 # 波動改革
storage/specifications/20260310/20260310-outbound-logic-specification/  # 出荷ロジック仕様
storage/specifications/20260310/20260310-picking-filter-fix/        # フィルタ修正
storage/specifications/20260311/                                    # モーダルデザイン
storage/api-docs/api-docs.json                                     # OpenAPI仕様
```
