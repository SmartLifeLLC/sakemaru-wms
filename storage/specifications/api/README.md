# API仕様書

Android出荷作業端末向けAPI、外部連携の統合仕様書

**最終更新**: 2026-01-12

---

## 1. 概要

WMSの外部連携APIおよびモバイルデバイス向けAPIの仕様。
倉庫出荷作業（ピッキング）に必要なログイン、倉庫選択、タスク取得、ピック実績登録、タスク完了、ログアウトを提供。

### 1.1 基本仕様

| 項目 | 内容 |
|------|------|
| 認証方式 | Bearer Token (JWT) |
| URL Prefix | `/api` |
| ドキュメント | L5-Swagger |
| 認証子 | `wms_pickers.code` + パスワード（メールアドレス不使用） |

---

## 2. 関連テーブル

### 2.1 wms_pickers

```
id, code(Unique), name, password(hashed), default_warehouse_id, is_active, timestamps
```

### 2.2 wms_picking_tasks

```
id, wave_id, wms_picking_area_id, warehouse_id, warehouse_code, delivery_course_id,
delivery_course_code, shipment_date, trade_id, status(PENDING|PICKING|COMPLETED),
task_type(WAVE|REALLOCATION), picker_id, started_at, completed_at, timestamps
```

- インデックス: `(wave_id, wms_picking_area_id, status)`, `(wave_id, status)`, `(warehouse_code)`, `(delivery_course_code)`
- `earning_id` → `wms_picking_item_results` に移動
- `status` から `SHORTAGE` 削除（欠品は item_results の `has_shortage` で管理）

### 2.3 wms_picking_item_results

```
id, picking_task_id, trade_item_id, item_id, real_stock_id, location_id, wms_picking_area_id,
walking_order, ordered_qty, ordered_qty_type, planned_qty, planned_qty_type, picked_qty,
picked_qty_type, shortage_qty, has_physical_shortage(generated),
status(PICKING|COMPLETED|SHORTAGE), picked_at, picker_id, timestamps
```

- 並び順: `wms_picking_area_id, walking_order, item_id`（歩行順最適化）

---

## 3. 認証API

### 3.1 ログイン

```
POST /auth/login
```

**リクエスト:**
```json
{ "code": "PICKER001", "password": "****", "device_id": "optional" }
```

**レスポンス（成功）:**
```json
{ "token": "jwt...", "picker": { "id": 1, "code": "PICKER001", "name": "田中", "default_warehouse_id": 1 } }
```

**エラー:** 401（コード/パスワード不一致、`is_active=0`）

### 3.2 ログアウト

```
POST /auth/logout
```

**レスポンス:** 204 No Content

### 3.3 自分情報

```
GET /me
```

**レスポンス:**
```json
{ "id": 1, "code": "PICKER001", "name": "田中", "default_warehouse_id": 1 }
```

---

## 4. マスタAPI

### 4.1 倉庫一覧

```
GET /warehouses
```

**レスポンス:**
```json
[{ "id": 1, "code": "W001", "name": "本社倉庫" }]
```

### 4.2 ピッキングエリア一覧

```
GET /picking-areas?warehouse_id=1
```

**レスポンス:**
```json
[{ "id": 1, "code": "PA1", "name": "常温エリア", "warehouse_id": 1 }]
```

---

## 5. ピッキングタスクAPI

### 5.1 タスク一覧取得

```
GET /picking/tasks?warehouse_id=1&picker_id=&picking_area_id=
```

**クエリパラメータ:**
- `warehouse_id` (required)
- `picker_id` (optional)
- `picking_area_id` (optional)

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": [
      {
        "course": { "code": "333", "name": "テストコース" },
        "picking_area": { "code": "123", "name": "PA-1" },
        "wave": { "wms_picking_task_id": 1, "wms_wave_id": 5 },
        "picking_list": [
          {
            "wms_picking_item_result_id": 1,
            "item_id": 111110,
            "item_name": "白鶴特撰 本醸造生貯蔵酒720ml",
            "jan_code": "4901681115008",
            "jan_code_list": ["4901681115008", "4901681115015"],
            "volume": "720ml",
            "capacity_case": 12,
            "packaging": "瓶",
            "temperature_type": "常温",
            "images": ["https://example.com/items/111110/image1.jpg"],
            "planned_qty_type": "CASE",
            "planned_qty": "2.00",
            "picked_qty": "0.00",
            "status": "PENDING",
            "slip_number": 1
          }
        ]
      }
    ]
  }
}
```

### 5.2 単一タスク取得

```
GET /picking/tasks/{wms_picking_task_id}
```

**レスポンス:** タスク一覧の1件分と同構造

### 5.3 単一アイテム取得

```
GET /picking/items/{wms_picking_item_result_id}
```

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {
      "wms_picking_item_result_id": 178,
      "item_id": 158655,
      "item_name": "ワインメーカーズ ノート シャルドネ 750ml",
      "jan_code": "9326817002732",
      "jan_code_list": ["9326817002732"],
      "volume": "750ml",
      "capacity_case": 12,
      "packaging": "750.0x12.0",
      "temperature_type": "定温",
      "images": [],
      "planned_qty_type": "CASE",
      "planned_qty": 6,
      "picked_qty": 0,
      "status": "PENDING",
      "slip_number": 39
    }
  }
}
```

---

## 6. ピッキング操作API

### 6.1 タスク開始

```
POST /picking/tasks/{wms_picking_task_id}/start
```

**作用:** `wms_picking_tasks.status` を `PICKING` に変更

**レスポンス:**
```json
{ "is_success": true, "result": { "data": { "id": 1, "status": "PICKING", "started_at": "..." } } }
```

### 6.2 ピッキング数量登録

```
POST /picking/tasks/{wms_picking_item_result_id}/update
```

**リクエスト:**
```json
{ "picked_qty": 2, "picked_qty_type": "CASE" }
```

**作用:**
- `wms_picking_item_results` を更新（status は常に `PICKING`）
- `shortage_qty = planned_qty - picked_qty` を計算
- **在庫更新はここでは行わない**（complete時に実施）

**レスポンス:**
```json
{ "is_success": true, "result": { "data": { "id": 1, "picked_qty": 2, "shortage_qty": 0, "status": "PICKING" } } }
```

### 6.3 ピッキングキャンセル

```
POST /picking/tasks/{wms_picking_item_result_id}/cancel
```

**作用:**
- `status` を `PENDING` にリセット
- `picked_qty = 0`, `shortage_qty = 0` にリセット

**制約:** `COMPLETED`/`SHORTAGE` 状態はキャンセル不可（422）

### 6.4 タスク完了

```
POST /picking/tasks/{wms_picking_task_id}/complete
```

**前提条件:** 全 `wms_picking_item_results.status` が `PENDING`/`PICKING` ではないこと

**作用:**
- 各アイテムの `status` を更新:
  - `shortage_qty > 0`: `SHORTAGE`
  - `shortage_qty = 0`: `COMPLETED`
- タスクの `status` を更新:
  - 1件でも `SHORTAGE` あり: `SHORTAGE`
  - 全て `COMPLETED`: `COMPLETED`
- **在庫更新を実施**: `real_stocks` の `current_quantity`, `available_quantity`, `wms_reserved_qty` を減算

**レスポンス:**
```json
{ "is_success": true, "result": { "data": { "id": 1, "status": "COMPLETED", "completed_at": "..." } } }
```

---

## 7. ステータス遷移

### 7.1 アイテムステータス (wms_picking_item_results)

| status | 設定タイミング | 意味 |
|--------|--------------|------|
| PENDING | 初期状態 / cancel後 | 未着手 |
| PICKING | update呼び出し時 | ピッキング中 |
| COMPLETED | complete時（shortage_qty = 0） | 完了 |
| SHORTAGE | complete時（shortage_qty > 0） | 欠品あり |

### 7.2 遷移フロー

```
PENDING → PICKING（update）→ COMPLETED/SHORTAGE（complete）
    ↑         |
    +---------+（cancel）
```

**重要:**
- update時は常に `PICKING`（`COMPLETED`/`SHORTAGE`にはならない）
- complete時に全アイテムの最終判定
- `PENDING`/`PICKING` が残っている状態では complete 不可

---

## 8. バリデーションルール

| 対象 | ルール |
|------|--------|
| ログイン | `code` 必須 |
| タスク取得 | `warehouse_id` 必須 |
| ピック登録 | `picked_qty > 0`（整数）、`picked_qty_type` は `planned_qty_type` と一致 |
| 完了 | `PICKING` 残存時は422、競合時は409 |

---

## 9. エラーレスポンス

| コード | 説明 |
|--------|------|
| 400 | バリデーションエラー: `{ message, errors: { field: [msg] } }` |
| 401 | 未認証 / 無効トークン |
| 403 | 権限不足 |
| 404 | 未検出 |
| 409 | 競合（割当済/並行更新/在庫予約の衝突） |
| 422 | 業務ルール違反（過剰/型不一致/未達完了禁止） |
| 500 | サーバエラー |

---

## 10. コントローラ

```
app/Http/Controllers/Api/
├── PickingTaskController.php      # ピッキングタスク
├── PickingRouteController.php     # ピッキングルート
├── MasterDataController.php       # マスタデータ
└── FloorPlanController.php        # フロアプラン
```

---

## 11. 今後の追加予定

| 機能 | 説明 |
|------|------|
| 入荷検品API | 入庫確定用API |
| 棚卸しAPI | 棚卸し作業用API |
| ロケーション管理API | ロケーション操作用API |

---

## 12. 旧仕様書

詳細な設計資料は `old/api/` に移動:
- `1.android-handy-api.md` - Androidハンディターミナル向けAPI詳細仕様
