# 横持ち出荷 API JSON 仕様調整 作業指示 PROMPT

## 目的

横持ち出荷 API（`/api/proxy-shipments/*`）のレスポンス JSON を、既存 Android アプリが前提としている共通 Envelope 仕様に合わせて調整してください。

今回の主目的は、**Android 側で endpoint ごとの特殊 Envelope を増やさず、既存の共通 `ApiEnvelope<T>` のまま実装可能にすること**です。

## 背景

Android 側の共通レスポンス Envelope は次の形を前提にしています。

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": { "...": "endpoint-specific payload" },
    "message": "...",
    "debug_message": null
  }
}
```

エラー時は次の形を前提にしています。

```json
{
  "is_success": false,
  "code": "VALIDATION_ERROR",
  "result": {
    "data": null,
    "error_message": "...",
    "errors": {
      "field": ["..."]
    },
    "debug_message": null
  }
}
```

しかし、現在の横持ち出荷 API 仕様では以下の不整合があります。

1. 一覧 API が `result.data` の外に `summary` と `meta` を持っている
2. 完了 API が `result.data` の外に `stock_transfer_queue_id` を持っている
3. エラー例が `error_message` ではなく `result.message` になっている

このままだと Android 側で endpoint 専用 Envelope が必要になり、既存の共通通信基盤とずれます。

## 結論

**WMS 側の API JSON を既存 Envelope に合わせる方針で調整してください。**

Android 側で共通 Envelope を汎用拡張するより、WMS 側で `result.data` に payload を閉じる方が、既存 API 群との整合性・保守性ともに高いです。

## 変更方針

### 共通ルール

成功レスポンスはすべて:

- `is_success`
- `code`
- `result.data`
- `result.message`
- `result.debug_message`

に統一する。

エラーレスポンスはすべて:

- `is_success`
- `code`
- `result.data`
- `result.error_message`
- `result.errors`
- `result.debug_message`

に統一する。

**endpoint 固有の追加フィールドを `result` 直下に増やさず、必ず `result.data` 配下に収める**こと。

## 具体的な調整内容

### 1. GET `/api/proxy-shipments`

現状イメージ:

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": [ ... ],
    "summary": { ... },
    "meta": { ... }
  }
}
```

これを以下へ変更してください。

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
            "id": 992,
            "code": "2",
            "name": "酒丸蔵 第2倉庫"
          },
          "destination_warehouse": {
            "id": 991,
            "code": "1",
            "name": "酒丸蔵 本社"
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

ポイント:

- `data` は配列ではなく object にする
- 一覧本体は `data.items`
- `summary` と `meta` は `data` の中へ入れる

### 2. GET `/api/proxy-shipments/{id}`

現仕様の方向性で問題ありません。

ただし共通ルールとして、必ず以下の形に揃えてください。

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {
      "...": "allocation detail"
    },
    "message": "横持ち出荷詳細を取得しました",
    "debug_message": null
  }
}
```

### 3. POST `/api/proxy-shipments/{id}/start`

現仕様の方向性で問題ありません。

ただし `result.data` に allocation object、`result.message` にメッセージ、`result.debug_message` に null を返してください。

### 4. POST `/api/proxy-shipments/{id}/update`

現仕様の方向性で問題ありません。

ただし `result.data` に allocation object、`result.message` にメッセージ、`result.debug_message` に null を返してください。

### 5. POST `/api/proxy-shipments/{id}/complete`

現状イメージ:

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {
      "allocation_id": 123,
      "status": "FULFILLED",
      "picked_qty": 10,
      "is_editable": false
    },
    "stock_transfer_queue_id": 456,
    "message": "横持ち出荷を完了しました"
  }
}
```

これを以下へ変更してください。

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

ポイント:

- `stock_transfer_queue_id` を `result.data` の中へ移動する

## エラー仕様の調整

エラーは横持ち出荷 API だけ例外形式にせず、既存 WMS API と同じく `error_message` を返してください。

期待形:

```json
{
  "is_success": false,
  "code": "VALIDATION_ERROR",
  "result": {
    "data": null,
    "error_message": "倉庫IDが不正です",
    "errors": {
      "warehouse_id": [
        "倉庫IDが不正です"
      ]
    },
    "debug_message": null
  }
}
```

## API 仕様書も合わせて修正してほしい点

以下を同時に更新してください。

1. `result.summary` / `result.meta` ではなく `result.data.summary` / `result.data.meta`
2. `result.stock_transfer_queue_id` ではなく `result.data.stock_transfer_queue_id`
3. エラー例を `result.message` ではなく `result.error_message`
4. `debug_message` の扱いを既存 API と同一に統一

## 実装時の受け入れ条件

以下を満たしたら完了です。

1. 横持ち出荷 5 API が既存共通 Envelope に準拠している
2. 一覧 API の `summary` / `meta` が `result.data` 配下に入っている
3. 完了 API の `stock_transfer_queue_id` が `result.data` 配下に入っている
4. 422 / 404 / 500 のエラーが `error_message` 形式で返る
5. OpenAPI / Swagger のレスポンス例も更新されている
6. 既存 Android 側の共通 `ApiEnvelope<T>` で追加の特殊処理なしに読める

## 非推奨案

以下の対応は今回は避けたいです。

1. 横持ち出荷 API だけ `result` 直下に独自フィールドを追加し続ける
2. Android 側で横持ち出荷専用 Envelope を増やす
3. Android 側の共通 `ApiEnvelope` に endpoint 固有フィールドを混ぜる

## 最後に

今回の依頼は payload の中身を変えることではなく、**JSON の包み方を既存 WMS API と揃えること**が主目的です。

WMS 側で共通規約に合わせてもらえれば、Android 側は通常出荷と同じ通信基盤の上で横持ち出荷を安全に実装できます。
