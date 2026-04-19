# 横持ち出荷 API 仕様書（Android / クライアント向け）

- 作成日: 2026-04-19
- バージョン: 1.0
- ベースURL: `/api`

---

## 1. 概要

横持ち出荷（代理出荷）のピッキング作業を行うためのAPI。
通常出荷（`/api/picking/*`）とは独立したエンドポイント群。

### 1.1 業務フロー

```
管理画面で欠品対応確定
  → wms_shortage_allocations.status = RESERVED
  → Android: 一覧取得（GET /proxy-shipments）
  → Android: 詳細取得（GET /proxy-shipments/{id}）
  → Android: 開始（POST /proxy-shipments/{id}/start）
  → Android: 数量更新（POST /proxy-shipments/{id}/update）  ※任意・複数回可
  → Android: 完了（POST /proxy-shipments/{id}/complete）
  → 倉庫移動伝票（stock_transfer_queue）自動作成
```

### 1.2 認証

既存の出荷API（`/api/picking/*`）と同一。

| ヘッダー | 値 | 備考 |
|---------|---|------|
| `X-API-Key` | APIキー | 全APIで必須 |
| `Authorization` | `Bearer {token}` | `/api/auth/login` で取得したトークン |
| `Accept` | `application/json` | |

### 1.3 レスポンス共通形式

既存 Android 共通 `ApiEnvelope<T>` に準拠。

成功時:
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": { "...": "endpoint-specific payload" },
    "message": "操作メッセージ",
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
    "errors": {
      "field": ["フィールドエラー"]
    },
    "debug_message": null
  }
}
```

> **重要**: endpoint 固有のフィールドは必ず `result.data` 配下に収める。`result` 直下に独自フィールドを追加しない。

### 1.4 用語マッピング

| DB カラム | API フィールド | 意味 |
|----------|--------------|------|
| `target_warehouse_id` | `pickup_warehouse` | **出荷元**（在庫がある倉庫、ピッキングする倉庫） |
| `source_warehouse_id` | `destination_warehouse` | **届け先**（欠品発生倉庫、商品を届ける倉庫） |

> Android側は `pickup_warehouse` = 今いる倉庫（`warehouse_id` で指定した倉庫）、`destination_warehouse` = 届け先 と理解してよい。

---

## 2. エンドポイント一覧

| # | Method | Path | 機能 |
|---|--------|------|------|
| 1 | GET | `/api/proxy-shipments` | 一覧取得 |
| 2 | GET | `/api/proxy-shipments/{id}` | 詳細取得（候補ロケーション付き） |
| 3 | POST | `/api/proxy-shipments/{id}/start` | ピッキング開始 |
| 4 | POST | `/api/proxy-shipments/{id}/update` | ピック数更新 |
| 5 | POST | `/api/proxy-shipments/{id}/complete` | ピッキング完了 |

---

## 3. API 詳細

### 3.1 一覧取得

`GET /api/proxy-shipments`

倉庫作業者が担当する横持ち出荷の一覧を取得する。初回表示時は `shipment_date` に当日日付を指定する。

#### リクエストパラメータ（Query String）

| パラメータ | 型 | 必須 | 説明 |
|----------|---|------|------|
| `warehouse_id` | integer | **必須** | 出荷元倉庫ID（ログイン後に選択した倉庫） |
| `shipment_date` | string (YYYY-MM-DD) | 任意 | 出荷日フィルタ。初回は `meta.business_date` を使用 |
| `delivery_course_id` | integer | 任意 | 配送コース絞り込み |

#### リクエスト例

```
GET /api/proxy-shipments?warehouse_id=1&shipment_date=2026-04-19
```

#### レスポンス

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {
      "items": [
        {
          "allocation_id": 123,
          "shortage_id": 456,
          "shipment_date": "2026-04-19",
          "status": "RESERVED",
          "pickup_warehouse": {
            "id": 1,
            "code": "1",
            "name": "本店"
          },
          "destination_warehouse": {
            "id": 91,
            "code": "91",
            "name": "華むすびの蔵センター"
          },
          "delivery_course": {
            "id": 100,
            "code": "910072",
            "name": "佐藤 尚紀"
          },
          "item": {
            "id": 100,
            "code": "111048",
            "name": "白鶴特撰 本醸造生貯蔵酒720ml",
            "jan_codes": ["4901234567890"],
            "volume": "720ml",
            "capacity_case": 12,
            "temperature_type": "常温",
            "images": ["https://example.com/image1.jpg"]
          },
          "assign_qty": 10,
          "assign_qty_type": "CASE",
          "picked_qty": 0,
          "remaining_qty": 10,
          "customer": {
            "code": "C001",
            "name": "得意先A"
          },
          "slip_number": 789,
          "is_editable": true
        }
      ],
      "summary": {
        "total_count": 15,
        "by_delivery_course": [
          { "id": 100, "code": "910072", "name": "佐藤 尚紀", "count": 8 },
          { "id": 101, "code": "910073", "name": "田中 太郎", "count": 7 }
        ]
      },
      "meta": {
        "business_date": "2026-04-19"
      }
    },
    "message": "横持ち出荷一覧を取得しました",
    "debug_message": null
  }
}
```

#### data.items[] フィールド説明

| フィールド | 型 | 説明 |
|----------|---|------|
| `allocation_id` | integer | 横持ち出荷ID（各APIの `{id}` に使用） |
| `shortage_id` | integer | 元の欠品ID |
| `shipment_date` | string | 出荷日 (YYYY-MM-DD) |
| `status` | string | `RESERVED` / `PICKING` |
| `pickup_warehouse` | object | 出荷元倉庫 `{ id, code, name }` |
| `destination_warehouse` | object | 送り先倉庫 `{ id, code, name }` |
| `delivery_course` | object\|null | 配送コース `{ id, code, name }` |
| `item` | object | 商品情報（下記参照） |
| `assign_qty` | integer | 指示数量 |
| `assign_qty_type` | string | 数量単位: `CASE` / `PIECE` / `CARTON` |
| `picked_qty` | integer | 現在のピック済み数量 |
| `remaining_qty` | integer | 残数 (`assign_qty - picked_qty`) |
| `customer` | object\|null | 得意先 `{ code, name }` |
| `slip_number` | integer\|null | 伝票番号（trade_id） |
| `is_editable` | boolean | 編集可能かどうか |

#### item フィールド

| フィールド | 型 | 説明 |
|----------|---|------|
| `id` | integer | 商品ID |
| `code` | string | 商品コード |
| `name` | string | 商品名 |
| `jan_codes` | string[] | JANコード一覧（更新日時降順） |
| `volume` | string\|null | 容量（例: "720ml"） |
| `capacity_case` | integer\|null | ケース入数 |
| `temperature_type` | string\|null | 温度帯（"常温", "冷蔵", "冷凍"） |
| `images` | string[] | 商品画像URL一覧 |

#### summary フィールド

| フィールド | 型 | 説明 |
|----------|---|------|
| `total_count` | integer | 総件数 |
| `by_delivery_course` | array | 配送コース別件数。フィルタUIに使用 |

#### meta フィールド

| フィールド | 型 | 説明 |
|----------|---|------|
| `business_date` | string | 営業日（初回の日付フィルタ初期値に使用） |

---

### 3.2 詳細取得

`GET /api/proxy-shipments/{id}`

一覧で選択した横持ち出荷の詳細を取得する。候補ロケーション一覧と欠品詳細が追加で返る。

#### リクエストパラメータ

| パラメータ | 型 | 必須 | 場所 | 説明 |
|----------|---|------|------|------|
| `id` | integer | **必須** | Path | `allocation_id` |
| `warehouse_id` | integer | **必須** | Query | 出荷元倉庫ID |

#### リクエスト例

```
GET /api/proxy-shipments/123?warehouse_id=1
```

#### レスポンス

一覧の `data[]` の1件と同じフィールドに加え、以下が追加される。

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {
      "allocation_id": 123,
      "shortage_id": 456,
      "shipment_date": "2026-04-19",
      "status": "RESERVED",
      "pickup_warehouse": { "id": 1, "code": "1", "name": "本店" },
      "destination_warehouse": { "id": 91, "code": "91", "name": "華むすびの蔵センター" },
      "delivery_course": { "id": 100, "code": "910072", "name": "佐藤 尚紀" },
      "item": { "..." : "（一覧と同じ）" },
      "assign_qty": 10,
      "assign_qty_type": "CASE",
      "picked_qty": 0,
      "remaining_qty": 10,
      "customer": { "code": "C001", "name": "得意先A" },
      "slip_number": 789,
      "is_editable": true,
      "shortage_detail": {
        "order_qty": 20,
        "planned_qty": 15,
        "picked_qty": 10,
        "shortage_qty": 10,
        "qty_type_at_order": "CASE"
      },
      "candidate_locations": [
        { "location_id": 1, "code": "A-01-02", "available_qty": 6 },
        { "location_id": 2, "code": "A-02-01", "available_qty": 8 }
      ]
    },
    "message": "横持ち出荷詳細を取得しました",
    "debug_message": null
  }
}
```

#### 追加フィールド: shortage_detail

| フィールド | 型 | 説明 |
|----------|---|------|
| `order_qty` | integer | 受注数量 |
| `planned_qty` | integer | 引当予定数量 |
| `picked_qty` | integer | 通常出荷でのピック済み数量 |
| `shortage_qty` | integer | 欠品数量 |
| `qty_type_at_order` | string | 受注時の数量単位 |

#### 追加フィールド: candidate_locations[]

候補ロケーション。FEFO（賞味期限順）→ FIFO（入庫日順）でソート済み。

| フィールド | 型 | 説明 |
|----------|---|------|
| `location_id` | integer | ロケーションID |
| `code` | string | ロケーションコード（例: "A-01-02"） |
| `available_qty` | integer | 利用可能在庫数 |

> **注意**: 候補ロケーションは参考情報であり、在庫予約は行われない。表示後に他の出荷で在庫が減る可能性がある。

---

### 3.3 ピッキング開始

`POST /api/proxy-shipments/{id}/start`

横持ち出荷のピッキングを開始する。ステータスが `RESERVED` → `PICKING` に遷移する。

#### リクエスト

```json
{
  "warehouse_id": 1
}
```

| パラメータ | 型 | 必須 | 説明 |
|----------|---|------|------|
| `warehouse_id` | integer | **必須** | 出荷元倉庫ID |

#### レスポンス

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": { "（allocation オブジェクト）": "..." },
    "message": "横持ち出荷を開始しました",
    "debug_message": null
  }
}
```

#### ステータス遷移

| 現在の status | 結果 |
|-------------|------|
| `RESERVED` | → `PICKING` に更新。`started_at`, `started_picker_id` を記録 |
| `PICKING` | 成功扱い（再送対応）。状態変更なし |
| その他 | 422エラー |

> **再送安全**: `PICKING` への再送は正常レスポンスを返す。Android側で通信リトライしても問題ない。

---

### 3.4 ピック数更新

`POST /api/proxy-shipments/{id}/update`

ピック中の数量を途中保存する。完了前に画面を離れる場合や、段階的にピックする場合に使用。

#### リクエスト

```json
{
  "warehouse_id": 1,
  "picked_qty": 8
}
```

| パラメータ | 型 | 必須 | 説明 |
|----------|---|------|------|
| `warehouse_id` | integer | **必須** | 出荷元倉庫ID |
| `picked_qty` | integer | **必須** | ピック済み数量。`0 <= picked_qty <= assign_qty` |

#### レスポンス

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": { "（allocation オブジェクト）": "..." },
    "message": "ピック数を更新しました",
    "debug_message": null
  }
}
```

#### 動作

- `RESERVED` 状態で呼び出された場合、暗黙的に `PICKING` に遷移する（startを明示的に呼ばなくてもよい）
- `picked_qty > assign_qty` の場合は 422 エラー

---

### 3.5 ピッキング完了

`POST /api/proxy-shipments/{id}/complete`

横持ち出荷を完了する。ステータス判定、倉庫移動伝票の作成、親欠品の集計更新を行う。

#### リクエスト

```json
{
  "warehouse_id": 1,
  "picked_qty": 10
}
```

| パラメータ | 型 | 必須 | 説明 |
|----------|---|------|------|
| `warehouse_id` | integer | **必須** | 出荷元倉庫ID |
| `picked_qty` | integer | 任意 | 最終ピック数。指定時は完了直前に上書き。省略時は現在の `picked_qty` を使用 |

#### レスポンス

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {
      "allocation_id": 123,
      "status": "FULFILLED",
      "picked_qty": 10,
      "is_editable": false,
      "stock_transfer_queue_id": 456
    },
    "message": "横持ち出荷を完了しました",
    "debug_message": null
  }
}
```

#### 完了ステータス判定

| 条件 | 結果 status | 説明 |
|-----|-----------|------|
| `picked_qty >= assign_qty` | `FULFILLED` | 全量ピック完了 |
| `0 < picked_qty < assign_qty` | `SHORTAGE` | 一部欠品 |
| `picked_qty = 0` | `SHORTAGE` | 全量欠品 |

#### 後処理

1. `is_finished = true`, `finished_at` を記録
2. `picked_qty > 0` の場合 → `stock_transfer_queue` を1件作成
3. `picked_qty = 0` の場合 → `stock_transfer_queue` は作成しない
4. 親 shortage の集計状態を再計算

#### data.stock_transfer_queue_id

| 値 | 説明 |
|----|------|
| integer | 作成された倉庫移動伝票キューのID |
| null | `picked_qty = 0` のため作成なし |

#### べき等性（重要）

**完了APIは何度呼んでも安全。** Android端末の通信リトライ・再送に対応。

| 状況 | 動作 |
|-----|------|
| 初回呼び出し | 通常の完了処理を実行 |
| 2回目以降（`is_finished = true`） | 200を返す。`stock_transfer_queue` は重複作成しない |

---

## 4. エラー一覧

全エラーは共通 Envelope の `error_message` 形式で返る。

| HTTP | code | 発生条件 | `error_message` 例 |
|------|------|---------|-------------------|
| 404 | `NOT_FOUND` | 指定IDの横持ち出荷が存在しない | `横持ち出荷が見つかりません` |
| 422 | `VALIDATION_ERROR` | `warehouse_id` 未指定 | `The warehouse id field is required.` |
| 422 | `VALIDATION_ERROR` | 倉庫ID不一致（ログイン倉庫と異なる） | `指定された倉庫と一致しません` |
| 422 | `VALIDATION_ERROR` | 未確定（`is_confirmed = false`） | `この横持ち出荷はまだ確定されていません` |
| 422 | `VALIDATION_ERROR` | 操作不可ステータス | `この横持ち出荷は操作できません（ステータス: ...）` |
| 422 | `VALIDATION_ERROR` | `picked_qty > assign_qty` | `ピック数(N)が指示数(M)を超えています` |
| 401 | - | 認証トークンなし/無効 | - |
| 500 | `ERROR` | `stock_transfer_queue` 作成失敗 | - |

#### エラーレスポンス例（422）

```json
{
  "is_success": false,
  "code": "VALIDATION_ERROR",
  "result": {
    "data": null,
    "error_message": "指定された倉庫と一致しません",
    "errors": [],
    "debug_message": null
  }
}
```

#### エラーレスポンス例（404）

```json
{
  "is_success": false,
  "code": "NOT_FOUND",
  "result": {
    "data": null,
    "error_message": "横持ち出荷が見つかりません",
    "debug_message": null
  }
}
```

---

## 5. ステータス遷移図

```
                  ┌─────────┐
  管理画面で確定 → │ RESERVED │
                  └────┬────┘
                       │ start / update
                       ▼
                  ┌─────────┐
                  │ PICKING  │ ← update（数量更新）
                  └────┬────┘
                       │ complete
                       ▼
              ┌────────────────────┐
              │                    │
         ┌────▼────┐         ┌────▼────┐
         │FULFILLED│         │SHORTAGE │
         │(全量)   │         │(欠品)   │
         └─────────┘         └─────────┘
```

### Android側で表示すべきステータス

| status | 表示テキスト | バッジ色 |
|--------|-----------|---------|
| `RESERVED` | 未着手 | 青 |
| `PICKING` | ピッキング中 | オレンジ |
| `FULFILLED` | 完了 | 緑（一覧には表示されない） |
| `SHORTAGE` | 欠品 | 赤（一覧には表示されない） |

---

## 6. 数量単位

| `assign_qty_type` | 表示 |
|-------------------|------|
| `CASE` | ケース |
| `PIECE` | バラ |
| `CARTON` | ボール |

---

## 7. 推奨実装フロー（Android）

### 7.1 一覧画面

1. `GET /proxy-shipments?warehouse_id={id}&shipment_date={result.data.meta.business_date}` で一覧取得
2. `result.data.summary.by_delivery_course` で配送コースフィルタを構築
3. 日付変更・配送コース変更で再取得
4. カードタップで詳細画面へ遷移

### 7.2 ピッキング画面

1. `GET /proxy-shipments/{id}?warehouse_id={id}` で詳細取得
2. `candidate_locations` を参考にロケーションへ移動
3. バーコードスキャン → `item.jan_codes` と照合
4. 数量入力 → `POST /proxy-shipments/{id}/update` で途中保存
5. 「完了」ボタン → `POST /proxy-shipments/{id}/complete`

### 7.3 完了画面

1. complete レスポンスの `result.data.status` で FULFILLED/SHORTAGE を表示
2. `result.data.stock_transfer_queue_id` を表示（存在する場合）
3. 「一覧へ戻る」で一覧画面に遷移

### 7.4 通信エラー時

- **start/update/complete は再送安全**。通信エラー時はリトライ可能
- 特に complete は完全にべき等（同じレスポンスが返る、queue が重複しない）

---

## 8. 通常出荷APIとの違い

| 項目 | 通常出荷 (`/api/picking/*`) | 横持ち出荷 (`/api/proxy-shipments/*`) |
|-----|--------------------------|-------------------------------------|
| 元データ | `wms_picking_tasks` + `wms_picking_item_results` | `wms_shortage_allocations` |
| ID | `wms_picking_task_id` | `allocation_id` |
| 取得タイミング | Wave生成後のみ | 常時（確定済みは即時表示） |
| 1リクエスト = | 1タスク（複数商品） | 1 allocation（1商品） |
| 完了後の後処理 | `earning_delivery_queue` | `stock_transfer_queue` |
| ロケーション | 確定（`real_stock_id` 固定） | 候補一覧（予約なし） |
| べき等性 | なし | complete API がべき等 |
