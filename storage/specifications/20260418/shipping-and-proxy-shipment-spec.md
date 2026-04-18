# 出荷・横持ち出荷 ロジック仕様書

**作成日:** 2026-04-18
**対象:** WMS出荷関連の全体フロー整理 + 横持ち出荷API仕様検討用

---

## 1. 概要

WMSの出荷処理は大きく3つのパスがある:

| パス | 説明 | source_type |
|------|------|-------------|
| **通常出荷** | 売上伝票(earning)に基づくピッキング→出荷 | `EARNING` |
| **倉庫間移動** | stock_transferに基づくピッキング→移動 | `STOCK_TRANSFER` |
| **横持ち出荷** | 欠品発生→他倉庫からの代理出荷 | (shortage_allocationベース) |

---

## 2. 通常出荷フロー

### 2.1 全体フロー

```
Wave生成 (wms:generate-waves)
  → 在庫引当 (StockAllocationService)
  → ピッキングタスク作成 (wms_picking_tasks)
  → ピッキングアイテム作成 (wms_picking_item_results)
    ↓
【Androidアプリ】
  → タスク取得 (GET /api/picking/tasks)
  → タスク開始 (POST /api/picking/tasks/{id}/start)
  → アイテム更新 (POST /api/picking/tasks/{itemResultId}/update)
  → タスク完了 (POST /api/picking/tasks/{id}/complete)
    ↓
出荷確認
  → EarningDeliveryQueueService.registerFromPickingTask()
  → earning_delivery_queue (PENDING)
    ↓ (非同期Job)
  → ProcessEarningDeliveryQueue
  → LotAllocationService.confirmDelivery()
  → real_stock_lots 数量減算
```

### 2.2 ピッキングタスクのステータス遷移

```
PENDING → PICKING_READY → PICKING → COMPLETED
                                   → SHORTAGE → (欠品処理後) → COMPLETED
                                                              → SHIPPED (管理画面から)
```

### 2.3 ピッキングアイテムのステータス遷移

```
PENDING → PICKING → COMPLETED (picked_qty >= planned_qty)
                   → SHORTAGE  (shortage_qty > 0)
```

---

## 3. 既存API一覧（Picking関連）

### 3.1 認証

| Method | Path | 説明 |
|--------|------|------|
| POST | `/api/auth/login` | ログイン（picker code + password） |
| POST | `/api/auth/logout` | ログアウト（トークン無効化） |
| GET | `/api/me` | 現在のピッカー情報取得 |

**認証方式:** API Key (ヘッダー `X-API-Key`) + Sanctum Token (Bearer)

### 3.2 マスタデータ

| Method | Path | 説明 |
|--------|------|------|
| GET | `/api/master/warehouses` | 倉庫一覧 |

### 3.3 ピッキング

| Method | Path | 説明 |
|--------|------|------|
| GET | `/api/picking/tasks` | タスク一覧（warehouse_id必須） |
| GET | `/api/picking/tasks/{id}` | タスク詳細 |
| GET | `/api/picking/items/{id}` | アイテム詳細 |
| POST | `/api/picking/tasks/{id}/start` | タスク開始 |
| POST | `/api/picking/tasks/{itemResultId}/update` | アイテム数量更新 |
| POST | `/api/picking/tasks/{itemResultId}/cancel` | アイテムキャンセル（PENDING戻し） |
| POST | `/api/picking/tasks/{id}/complete` | タスク完了 |

### 3.4 入荷

| Method | Path | 説明 |
|--------|------|------|
| GET | `/api/incoming/schedules` | 入荷予定一覧 |
| GET | `/api/incoming/schedules/{id}` | 入荷予定詳細 |
| GET | `/api/incoming/work-items` | 入荷作業アイテム |
| POST | `/api/incoming/work-items` | 入荷作業開始 |
| PUT | `/api/incoming/work-items/{id}` | 入荷作業更新 |
| POST | `/api/incoming/work-items/{id}/complete` | 入荷作業完了 |
| DELETE | `/api/incoming/work-items/{id}` | 入荷作業キャンセル |
| GET | `/api/incoming/locations` | ロケーション検索 |

### 3.5 共通レスポンス形式

```json
{
    "is_success": true,
    "code": "SUCCESS",
    "result": {
        "data": { ... },
        "message": "成功メッセージ",
        "debug_message": null
    }
}
```

エラー時:
```json
{
    "is_success": false,
    "code": "VALIDATION_ERROR",
    "result": {
        "data": null,
        "error_message": "エラーメッセージ",
        "errors": { ... }
    }
}
```

---

## 4. タスク完了API 詳細 (`POST /api/picking/tasks/{id}/complete`)

### 4.1 処理フロー

```php
// PickingTaskController::complete()

1. タスク取得・バリデーション
   - isTaskEditable() チェック
   - SHIPPED or 欠品処理中(status != BEFORE) → 編集不可

2. 自動完了
   - planned_qty = 0 のアイテムを自動COMPLETED

3. 未完了チェック
   - planned_qty > 0 AND picked_qty = 0 AND shortage_qty = 0 → エラー

4. アイテムステータス更新
   - shortage_qty > 0 → SHORTAGE + PickingShortageDetector.detectAndRecord()
   - shortage_qty = 0 → COMPLETED + 未処理欠品レコード削除

5. タスクステータス更新
   - 欠品あり → SHORTAGE
   - 欠品なし → COMPLETED

6. stock_transfers更新（source_type=STOCK_TRANSFERの場合）
   - stock_transfers.picking_status を COMPLETED or SHORTAGE に更新

7. 出荷キュー登録
   - EarningDeliveryQueueService.registerFromPickingTask()
   - earning_delivery_queue にPENDINGレコード作成
```

### 4.2 レスポンス

```json
{
    "is_success": true,
    "code": "SUCCESS",
    "result": {
        "data": {
            "wms_picking_task_id": 1,
            "id": 1,
            "status": "COMPLETED",
            "completed_at": "2026-04-18 10:00:00"
        },
        "message": "Picking task completed"
    }
}
```

---

## 5. 欠品→横持ち出荷フロー

### 5.1 通常出荷との違い

| 項目 | 通常出荷 | 横持ち出荷 |
|------|---------|-----------|
| 在庫引当 | Wave生成時に自倉庫から | 管理者が他倉庫を手動指定 |
| ピッキング元 | 自倉庫 | 指定された他倉庫 |
| 出荷トリガー | Wave → 自動 | 欠品 → 管理者指示 → 手動 |
| 伝票種別 | earning | shortage_allocation |
| 在庫移動 | なし | stock_transfer_queue作成 |
| 単価 | 通常仕入単価 | 出荷元倉庫の仕入単価 |

### 5.2 全体フロー

```
【段階1】ピッキング時の欠品検出
  ピッキングタスク完了 (POST /api/picking/tasks/{id}/complete)
  → shortage_qty > 0 のアイテム検出
  → PickingShortageDetector.detectAndRecord()
  → wms_shortages 作成 (status=BEFORE)

【段階2】管理画面での横持ち出荷指示（Filament UI）
  → ProxyShipmentService.createProxyShipment()
  → wms_shortage_allocations 作成 (status=PENDING)
  → wms_shortages.status → REALLOCATING

【段階3】欠品対応確定（管理画面）
  → ShortageConfirmationService.confirm()
  → wms_picking_item_results 更新:
    - shortage_allocated_qty = 合計横持ち数量
    - is_ready_to_shipment = true
    - shipment_ready_at = now()
  → wms_shortages.status → SHORTAGE or PARTIAL_SHORTAGE
  → wms_shortages.is_confirmed = true
  → ConfirmShortageAllocations 実行

【段階4】横持ち出荷の実際のピッキング
  ★ 現在ここにAPI対応がない ★
  → 出荷元倉庫でのピッキング作業
  → allocation.picked_qty の更新

【段階5】横持ち出荷完了
  → StockTransferQueueService.createStockTransferQueue()
  → stock_transfer_queue 作成 (status=BEFORE)
  → from_warehouse = 横持ち出荷倉庫
  → to_warehouse = 実倉庫ベースで判定
```

### 5.3 関連テーブル

```
wms_shortages
├── id
├── wave_id, warehouse_id, item_id
├── trade_id, earning_id, trade_item_id
├── delivery_course_id, shipment_date
├── order_qty        ← 受注数量
├── planned_qty      ← 引当数量
├── picked_qty       ← ピッキング実績
├── shortage_qty     ← 総欠品数 (= order_qty - picked_qty)
├── allocation_shortage_qty  ← 引当欠品 (= order_qty - planned_qty)
├── picking_shortage_qty     ← ピッキング欠品 (= planned_qty - picked_qty)
├── qty_type_at_order        ← 受注時の数量単位 (CASE/PIECE/CARTON)
├── case_size_snap           ← 受注時のケースサイズ
├── source_pick_result_id    ← 元のpicking_item_result.id
├── parent_shortage_id       ← 親欠品ID（階層管理）
├── status           ← BEFORE / REALLOCATING / SHORTAGE / PARTIAL_SHORTAGE
├── is_confirmed     ← 確定済みフラグ
├── is_synced        ← Sakemaru同期フラグ
└── updater_id

wms_shortage_allocations
├── id
├── shortage_id
├── shipment_date, delivery_course_id
├── target_warehouse_id   ← 出荷元倉庫（横持ち出荷倉庫）
├── source_warehouse_id   ← 元倉庫（欠品発生倉庫）
├── assign_qty            ← 指示数量（受注単位）
├── assign_qty_type       ← CASE / PIECE / CARTON
├── picked_qty            ← 実績数量
├── purchase_price        ← 仕入単価（出荷元倉庫ベース）
├── tax_exempt_price      ← 税抜単価
├── price                 ← 販売単価（元注文ベース）
├── status    ← PENDING / RESERVED / PICKING / FULFILLED / SHORTAGE
├── is_confirmed          ← 確定済み
├── is_finished           ← 完了済み
└── created_by, updated_by
```

### 5.4 関連サービス一覧

| サービス | ファイルパス | 役割 |
|---------|-------------|------|
| `ProxyShipmentService` | `app/Services/Shortage/ProxyShipmentService.php` | 横持ち出荷指示のCRUD |
| `ShortageConfirmationService` | `app/Services/Shortage/ShortageConfirmationService.php` | 欠品対応確定 |
| `PickingShortageDetector` | `app/Services/Shortage/PickingShortageDetector.php` | ピッキング時の欠品検出・記録 |
| `AllocationShortageDetector` | `app/Services/Shortage/AllocationShortageDetector.php` | 引当時の欠品検出 |
| `StockTransferQueueService` | `app/Services/Shortage/StockTransferQueueService.php` | 横持ち完了→倉庫移動伝票キュー作成 |
| `EarningDeliveryQueueService` | `app/Services/EarningDeliveryQueueService.php` | 出荷完了→ロット在庫更新キュー |
| `LotAllocationService` | `app/Services/LotAllocationService.php` | ロット単位の在庫確定 |
| `StockAllocationService` | `app/Services/StockAllocationService.php` | FEFO→FIFO在庫引当 |

---

## 6. 横持ち出荷とWave生成の関係

### 6.1 Wave生成の対象範囲

`GenerateWavesCommand`（`wms:generate-waves`）のWave生成対象は以下の2種類**のみ**:

| 対象 | テーブル | 条件 |
|------|---------|------|
| 売上伝票 | `earnings` | `picking_status = 'BEFORE'`, `is_delivered = 0`, 配送コース一致 |
| 倉庫間移動 | `stock_transfers` | `picking_status = 'BEFORE'`, `is_active = true`, 配送コース一致 |

**横持ち出荷（`wms_shortage_allocations`）はWave生成の対象に含まれない。**

```
通常出荷:     Wave生成 → picking_task → picking_item_result → アプリでピッキング → 完了
倉庫間移動:   Wave生成 → picking_task → picking_item_result → アプリでピッキング → 完了
横持ち出荷:   欠品検出 → 管理者指示 → ★ピッキング手段なし★ → 完了
```

Wave生成はスケジューラー（`schedule:run`）により時刻ベースで実行され、`wms_wave_settings.picking_start_time` を超えた配送コースのみ処理する。横持ち出荷は欠品が確定した時点で初めて発生するため、Wave生成のタイミングとは無関係である。

### 6.2 横持ち出荷に必要なデータ取得モデル

横持ち出荷はWave（波動出荷）ではなく、**入荷予定と同様の常時取得モデル**が適切:

| 比較項目 | Wave出荷（通常） | 横持ち出荷（必要な設計） | 入荷予定（参考） |
|---------|----------------|----------------------|----------------|
| データ発生タイミング | Wave生成コマンド実行時 | 欠品確定→管理者指示時 | 発注確定時 |
| 時刻依存性 | あり（picking_start_time） | なし（常時発生しうる） | なし |
| API取得方式 | Wave内のタスク一覧 | **常時取得可能** | 常時取得可能 |
| フィルタ条件 | warehouse_id | warehouse_id + date + delivery_course_id | warehouse_id + search |
| 一覧表示 | 配送コース×エリア別 | **倉庫別×日付別×配送コース別** | 商品別 |

**設計原則:**
- 横持ち出荷データは **`is_confirmed = true` かつ `status = PENDING`** の `wms_shortage_allocations` が対象
- 管理者が横持ち出荷を確定した時点でアプリから即座に取得可能になる
- Wave生成のタイミングに依存しない

---

## 7. 横持ち出荷API — 現状のギャップと必要な対応

### 7.1 現在APIが存在しないフェーズ

**段階4「横持ち出荷の実際のピッキング」** に対応するAPIがない。

現在の通常ピッキングAPIは以下の前提で設計されている:
- `wms_picking_tasks` → `wms_picking_item_results` のペア
- Wave生成時に自動作成されたタスクを処理
- `source_type = EARNING | STOCK_TRANSFER`

横持ち出荷は:
- Waveとは独立（Wave生成を経由しない）
- `wms_shortage_allocations` が出荷指示の元データ
- 出荷元倉庫（`target_warehouse_id`）でピッキングする
- ピッキング完了時に `picked_qty` を allocation に反映
- 完了後に `StockTransferQueueService.createStockTransferQueue()` を呼ぶ必要がある

### 7.2 横持ち出荷APIの要件

#### データ取得要件

横持ち出荷はWave出荷と異なり、**常時APIから取得可能**でなければならない（入荷予定と同じモデル）。

- 管理者が横持ち出荷を確定した瞬間から、出荷元倉庫のアプリに表示される
- Wave生成コマンドの実行タイミングに依存しない
- 以下のフィルタでピッキング対象を絞り込める必要がある:
  - **倉庫別**: `target_warehouse_id`（出荷元倉庫＝ピッカーの所属倉庫）
  - **日付別**: `shipment_date`（出荷予定日）
  - **配送コース別**: `delivery_course_id`（元注文の配送コース）

#### 取得対象の条件

```sql
SELECT sa.*
FROM wms_shortage_allocations sa
JOIN wms_shortages s ON sa.shortage_id = s.id
WHERE sa.is_confirmed = true          -- 管理者確定済み
  AND sa.status IN ('PENDING', 'PICKING')  -- 未完了
  AND sa.target_warehouse_id = ?      -- 出荷元倉庫（ピッカーの倉庫）
  AND sa.shipment_date = ?            -- 出荷予定日（オプション）
  AND sa.delivery_course_id = ?       -- 配送コース（オプション）
```

### 7.3 横持ち出荷専用API仕様

通常ピッキングAPIとは独立した専用エンドポイントとする。

#### エンドポイント一覧

| Method | Path | 説明 |
|--------|------|------|
| GET | `/api/proxy-shipments` | 横持ち出荷一覧（倉庫別・日付別・配送コース別） |
| GET | `/api/proxy-shipments/{id}` | 横持ち出荷詳細 |
| POST | `/api/proxy-shipments/{id}/start` | ピッキング開始 |
| POST | `/api/proxy-shipments/{id}/update` | 数量更新 |
| POST | `/api/proxy-shipments/{id}/complete` | ピッキング完了 |

#### 7.3.1 横持ち出荷一覧

```
GET /api/proxy-shipments?warehouse_id={id}&date={YYYY-MM-DD}&delivery_course_id={id}
```

**パラメータ:**

| パラメータ | 必須 | 説明 |
|-----------|------|------|
| `warehouse_id` | 必須 | 出荷元倉庫ID（ピッカーの所属倉庫） |
| `date` | 任意 | 出荷予定日（未指定時は当日以降） |
| `delivery_course_id` | 任意 | 配送コースで絞り込み |

**レスポンス:**
```json
{
    "is_success": true,
    "code": "SUCCESS",
    "result": {
        "data": [
            {
                "allocation_id": 123,
                "shortage_id": 456,
                "shipment_date": "2026-04-18",
                "status": "PENDING",
                "source_warehouse": {
                    "id": 991,
                    "code": "1",
                    "name": "酒丸蔵 本社"
                },
                "target_warehouse": {
                    "id": 992,
                    "code": "2",
                    "name": "酒丸蔵 第2倉庫"
                },
                "delivery_course": {
                    "id": 100,
                    "code": "910072",
                    "name": "佐藤 尚紀"
                },
                "item": {
                    "id": 100,
                    "code": "111048",
                    "name": "商品A",
                    "jan_codes": ["4901234567890"],
                    "volume": "720ml",
                    "capacity_case": 12,
                    "temperature_type": "常温",
                    "images": []
                },
                "assign_qty": 10,
                "assign_qty_type": "CASE",
                "picked_qty": 0,
                "customer": {
                    "code": "C001",
                    "name": "得意先A"
                },
                "original_earning_id": 789,
                "slip_number": 789
            }
        ],
        "summary": {
            "total_count": 15,
            "by_date": {
                "2026-04-18": 10,
                "2026-04-19": 5
            },
            "by_delivery_course": [
                { "code": "910072", "name": "佐藤 尚紀", "count": 8 },
                { "code": "910073", "name": "田中 太郎", "count": 7 }
            ]
        }
    }
}
```

#### 7.3.2 横持ち出荷詳細

```
GET /api/proxy-shipments/{allocation_id}
```

**レスポンス:** 一覧の1件と同じ構造 + 追加情報:
```json
{
    "result": {
        "data": {
            "allocation_id": 123,
            "...": "（一覧と同じフィールド）",
            "shortage_detail": {
                "order_qty": 20,
                "planned_qty": 15,
                "picked_qty": 10,
                "shortage_qty": 10,
                "qty_type_at_order": "CASE"
            },
            "location": {
                "code": "A-01-02",
                "name": null
            }
        }
    }
}
```

#### 7.3.3 ピッキング開始

```
POST /api/proxy-shipments/{allocation_id}/start
```

**後処理:**
```
1. wms_shortage_allocations.status → PICKING
2. started_at = now()（要カラム追加検討）
```

#### 7.3.4 数量更新

```
POST /api/proxy-shipments/{allocation_id}/update
```

**リクエスト:**
```json
{
    "picked_qty": 8
}
```

**後処理:**
```
1. wms_shortage_allocations.picked_qty 更新
2. ステータスは PICKING のまま（最終確定は complete で）
```

#### 7.3.5 ピッキング完了

```
POST /api/proxy-shipments/{allocation_id}/complete
```

**リクエスト:**
```json
{
    "picked_qty": 10
}
```

**後処理フロー:**
```
1. wms_shortage_allocations.picked_qty 更新
2. wms_shortage_allocations.status → FULFILLED (picked_qty > 0) or SHORTAGE (picked_qty = 0)
3. wms_shortage_allocations.is_finished = true, finished_at = now()
4. StockTransferQueueService.createStockTransferQueue() 呼び出し
   → stock_transfer_queue 作成（from=出荷元倉庫, to=実倉庫判定）
5. ProxyShipmentService.updateFulfillmentStatus() で親shortage更新
```

**レスポンス:**
```json
{
    "is_success": true,
    "code": "SUCCESS",
    "result": {
        "data": {
            "allocation_id": 123,
            "picked_qty": 10,
            "status": "FULFILLED",
            "stock_transfer_queue_id": 456
        },
        "message": "横持ち出荷が完了しました"
    }
}
```

### 7.4 通常ピッキングAPIとの比較

```
【通常ピッキング（Wave出荷）】
GET  /api/picking/tasks?warehouse_id=991          ← Wave内タスク一覧
GET  /api/picking/tasks/{id}                       ← タスク詳細
POST /api/picking/tasks/{id}/start                 ← タスク開始
POST /api/picking/tasks/{itemResultId}/update       ← アイテム数量更新
POST /api/picking/tasks/{id}/complete              ← タスク完了

【横持ち出荷（常時取得）】
GET  /api/proxy-shipments?warehouse_id=991&date=2026-04-18  ← 確定済み横持ち一覧
GET  /api/proxy-shipments/{allocation_id}                    ← 横持ち詳細
POST /api/proxy-shipments/{allocation_id}/start              ← ピッキング開始
POST /api/proxy-shipments/{allocation_id}/update             ← 数量更新
POST /api/proxy-shipments/{allocation_id}/complete           ← ピッキング完了

【入荷（参考: 常時取得モデル）】
GET  /api/incoming/schedules?warehouse_id=991      ← 入荷予定一覧
POST /api/incoming/work-items                      ← 入荷作業開始
PUT  /api/incoming/work-items/{id}                 ← 入荷作業更新
POST /api/incoming/work-items/{id}/complete         ← 入荷作業完了
```

**共通点:**
- 認証: 全て API Key + Sanctum Token
- レスポンス形式: 全て `{ is_success, code, result: { data, message } }`
- 倉庫別フィルタ: 全て `warehouse_id` パラメータ

**横持ち出荷の特徴:**
- Wave（波動）に依存しない常時取得モデル
- `wms_shortage_allocations` が直接の操作対象（picking_taskを経由しない）
- 完了時に `stock_transfer_queue` を自動作成

---

## 8. Handy Webモック（APIテスト環境）

### 8.1 概要

WMS APIのテスト・デモ用にブラウザベースのAndroidアプリモックを実装している。
実際のWMS APIを直接呼び出すため、API仕様の検証に利用できる。

**2つの実装が存在:**

| 版 | エントリURL | 技術構成 | 特徴 |
|----|------------|---------|------|
| **V1** | `/handy/login` | Alpine.js + Blade（ページ単位） | シンプル、画面遷移がURL |
| **V2** | `/handy-v2/` | Alpine.js + ES6 modules + Store（SPA） | タブ切替、PWA、バーコード対応 |

**対象デバイス:** Baratron BHT-M60（3.2インチ 480x800 Android端末）

### 8.2 ルーティング

```
# V1（ページ単位）
GET /handy/login           → HandyController::login()           ログイン
GET /handy/home            → HandyController::home()            倉庫選択+メニュー
GET /handy/incoming        → HandyIncomingController::index()   入荷SPA
GET /handy/outgoing        → HandyController::outgoing()        出荷（ピッキング）SPA

# V2（統合SPA）
GET /handy-v2/{any?}       → HandyV2Controller::index()         全画面を1つのSPAで管理
```

### 8.3 API通信方式

**重要: HandyモックはLaravelコントローラーを経由せず、WMS APIを直接呼び出す。**

```
ブラウザ（480x800表示）
  ↓ fetch()
  ├─ Header: X-API-Key: {設定値}
  ├─ Header: Authorization: Bearer {Sanctumトークン}
  └─ Content-Type: application/json
  ↓
WMS API（/api/*）
  ├─ /api/auth/login          → 認証
  ├─ /api/master/warehouses   → 倉庫一覧
  ├─ /api/picking/tasks       → ピッキングタスク
  ├─ /api/incoming/schedules  → 入荷予定
  └─ （横持ち出荷APIを追加予定）
```

これにより:
- 実際のAndroidアプリと同じAPIを検証できる
- APIの仕様不備をブラウザ上で発見できる
- 複数ユーザーの同時操作をテスト可能

### 8.4 認証フロー

```
1. ピッカーコード + パスワード入力
2. POST /api/auth/login（X-API-Keyヘッダー付き）
3. レスポンス: { token, picker: { id, code, name, default_warehouse_id } }
4. トークンをlocalStorageに保存
5. 以降の全APIリクエストに Bearer {token} を付与
```

V1: トークンをURLパラメータ（`?auth_key=xxx`）で画面間受け渡し
V2: localStorageのみで管理（`wms_v2_token`, `wms_v2_picker`）

### 8.5 V1 画面構成

#### 出荷（ピッキング）ワークフロー — `/handy/outgoing`

```
タスク一覧 → ピッキング → 完了確認 → 結果
```

| 画面 | 表示内容 | API |
|------|---------|-----|
| タスク一覧 | 配送コース×エリア別、進捗バー | `GET /api/picking/tasks?warehouse_id={id}` |
| ピッキング | 商品画像、JAN、数量入力（+/-） | `POST /api/picking/tasks/{itemResultId}/update` |
| 完了確認 | 完了件数、欠品件数サマリー | `POST /api/picking/tasks/{id}/complete` |
| 結果 | 成功メッセージ | — |

#### 入荷ワークフロー — `/handy/incoming`

```
ログイン → 倉庫選択 → 商品一覧 → 入荷処理 → 結果 → 履歴
```

| 画面 | 表示内容 | API |
|------|---------|-----|
| 商品一覧 | 検索可能、無限スクロール（50件） | `GET /api/incoming/schedules?warehouse_id={id}` |
| 入荷処理 | 数量、ロケーション、入荷日、賞味期限 | `POST /api/incoming/work-items` |
| 完了 | 確認 | `POST /api/incoming/work-items/{id}/complete` |
| 履歴 | 今日/全件、削除可能 | `GET /api/incoming/work-items` |

### 8.6 V2 画面構成

V2はタブベースSPAで、入荷と出荷をタブ切り替えで操作:

```
LOGIN → HOME → [入荷タブ | 出荷タブ | 設定タブ]
```

#### タブ構成

| タブ | 画面遷移 |
|-----|---------|
| 入荷 | `INCOMING_LIST → INCOMING_WORK → INCOMING_RESULT → INCOMING_HISTORY` |
| 出荷 | `PICKING_TASKS → PICKING_ITEM → PICKING_COMPLETE → PICKING_RESULT` |
| 設定 | `SETTINGS`（倉庫変更等） |

#### V2固有の機能

- **バーコードスキャン**: 物理バーコードリーダー対応、JAN一致判定（match/mismatch表示）
- **PWA**: manifest.json + Service Worker登録、オフライン検知
- **モジュール構成**: Store（状態管理）+ Service（API通信）の分離設計

### 8.7 V2 フロントエンドアーキテクチャ

```
resources/js/handy-v2/
├── app.js                    メインコンポーネント（画面ルーティング・イベント統合）
├── stores/
│   ├── auth.js              認証ストア（token, picker, login/logout）
│   ├── warehouse.js         倉庫ストア（一覧, 選択, localStorage永続化）
│   ├── incoming.js          入荷ストア（予定, 作業, 履歴, フォーム状態）
│   ├── picking.js           出荷ストア（タスク, アイテム, バーコード, 数量）
│   └── notification.js      通知ストア（トースト表示）
└── services/
    ├── api-client.js        HTTP通信基盤（X-API-Key + Bearer自動付与）
    ├── auth-service.js      POST /api/auth/login, logout, GET /api/me
    ├── master-service.js    GET /api/master/warehouses
    ├── incoming-service.js  入荷API呼び出し（CRUD）
    └── picking-service.js   出荷API呼び出し（タスク取得・更新・完了）
```

### 8.8 横持ち出荷の開発計画

横持ち出荷機能はこのHandyモック上に追加開発する想定:

```
【現状】
  入荷タブ: GET /api/incoming/*
  出荷タブ: GET /api/picking/*
  
【追加予定】
  横持ちタブ: GET /api/proxy-shipments/*（新規API）
```

**V2への追加が適切な理由:**
- タブベースSPAに新タブ追加が容易
- Store + Service の分離設計により `proxy-shipment-service.js` + `proxy-shipment.js`（store）を追加するだけ
- バーコードスキャン・PWA等の基盤をそのまま利用可能
- 入荷と同じ「常時取得モデル」のため、入荷タブの実装パターンを踏襲可能

**追加が必要なファイル（想定）:**

| ファイル | 内容 |
|---------|------|
| `resources/js/handy-v2/stores/proxy-shipment.js` | 横持ちストア（一覧、フィルタ、ピッキング状態） |
| `resources/js/handy-v2/services/proxy-shipment-service.js` | 横持ちAPI呼び出し |
| `resources/views/handy-v2/partials/proxy-shipment/*.blade.php` | 横持ち画面テンプレート |
| `app/Http/Controllers/Api/ProxyShipmentController.php` | 横持ちAPIコントローラー |

### 8.9 関連ファイルパス

#### コントローラー（Handy）
- `app/Http/Controllers/Handy/HandyController.php` — V1 ログイン/ホーム/出荷
- `app/Http/Controllers/Handy/HandyIncomingController.php` — V1 入荷
- `app/Http/Controllers/Handy/HandyV2Controller.php` — V2 SPAエントリ

#### Bladeテンプレート
- `resources/views/handy/login.blade.php` — V1 ログイン
- `resources/views/handy/home.blade.php` — V1 ホーム
- `resources/views/handy/outgoing.blade.php` — V1 出荷
- `resources/views/handy/incoming.blade.php` — V1 入荷
- `resources/views/handy-v2/app.blade.php` — V2 メインSPA
- `resources/views/handy-v2/partials/picking/*.blade.php` — V2 出荷画面群
- `resources/views/handy-v2/partials/incoming/*.blade.php` — V2 入荷画面群

#### JavaScript
- `resources/js/handy/outgoing-app.js` — V1 出荷ロジック
- `resources/js/handy/incoming-app.js` — V1 入荷ロジック
- `resources/js/handy-v2/app.js` — V2 メインコンポーネント
- `resources/js/handy-v2/stores/*.js` — V2 状態管理
- `resources/js/handy-v2/services/*.js` — V2 API通信層

---

## 9. 関連ファイルパス一覧（全体）

### APIコントローラー
- `app/Http/Controllers/Api/PickingTaskController.php` — ピッキングAPI
- `app/Http/Controllers/Api/IncomingController.php` — 入荷API
- `app/Http/Controllers/Api/AuthController.php` — 認証API
- `app/Http/Controllers/Api/MasterDataController.php` — マスタAPI

### サービス
- `app/Services/EarningDeliveryQueueService.php` — 出荷キュー登録
- `app/Services/LotAllocationService.php` — ロット在庫確定
- `app/Services/StockAllocationService.php` — 在庫引当(FEFO→FIFO)
- `app/Services/Shortage/ProxyShipmentService.php` — 横持ち出荷CRUD
- `app/Services/Shortage/ShortageConfirmationService.php` — 欠品確定
- `app/Services/Shortage/PickingShortageDetector.php` — ピッキング欠品検出
- `app/Services/Shortage/AllocationShortageDetector.php` — 引当欠品検出
- `app/Services/Shortage/StockTransferQueueService.php` — 倉庫移動伝票キュー

### モデル
- `app/Models/WmsPickingTask.php`
- `app/Models/WmsPickingItemResult.php`
- `app/Models/WmsShortage.php`
- `app/Models/WmsShortageAllocation.php`
- `app/Models/Sakemaru/EarningDeliveryQueue.php`
- `app/Models/Sakemaru/Earning.php`
- `app/Models/Sakemaru/StockTransfer.php`

### ジョブ
- `app/Jobs/ProcessEarningDeliveryQueue.php` — 出荷キュー非同期処理

### コマンド
- `app/Console/Commands/GenerateWavesCommand.php` — Wave生成

### ルート
- `routes/api.php` — API定義
- `routes/web.php` — Handyモックルート

### API仕様
- `storage/api-docs/api-docs.json` — OpenAPI 3.0 仕様
