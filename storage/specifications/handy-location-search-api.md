# Handy ロケ検索 API 仕様

## 目的

Android Handy 端末で、選択中の倉庫に対して商品を検索し、商品の基本情報・在庫状況・ロケーション情報を表示する。

単価、税、原価、標準売価など価格系情報は返さない。

## 認証

既存 Handy API と同じ。

```http
X-API-Key: {APIキー}
Authorization: Bearer {POST /api/auth/login で取得したtoken}
Accept: application/json
```

## エンドポイント

`GET /api/master/item-locations`

倉庫選択後に呼び出す。レスポンス内の在庫・ロケーションは `warehouse_id` で指定した倉庫のみを対象にする。

### クエリ

| 名称 | 必須 | 型 | 説明 |
| --- | --- | --- | --- |
| `warehouse_id` | 必須 | integer | 選択中の倉庫ID |
| `search` | 必須 | string | 商品CD、商品名、JAN、社内JAN |
| `limit` | 任意 | integer | 最大商品件数。デフォルト10、最大50 |

### 検索対象

- `items.code`
- `items.name`
- `item_search_information.search_string`
- `item_search_information.search_string` の13桁ゼロ埋め一致
- `item_quantity_information.product_code`
- `item_quantity_information.product_code` の13桁ゼロ埋め一致
- `item_quantity_information.own_code`
- `item_quantity_information.own_code` の13桁ゼロ埋め一致

`item_quantity_information.product_code` / `own_code` は社内JAN系コードとして検索・表示する。

## ロケーション優先順位

`locations.suggested` は次の優先順位で1件返す。

1. `item_incoming_default_locations`: 商品×倉庫の入荷デフォルトロケ
2. `real_stock_lots` + `real_stocks`: 指定倉庫・商品の在庫ロットがあるロケ
3. `locations`: 倉庫デフォルトロケ。`Z-00`、`Z-0-0`、`ZZ-1-100` の順

`locations.stock` は指定倉庫内で在庫ロットがあるロケのみを返す。

## レスポンス

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": [
      {
        "item": {
          "id": 123,
          "code": "10001",
          "name": "商品A 720ml",
          "kana": "ショウヒンエー",
          "volume": "720",
          "volume_unit": "ML",
          "capacity_case": 12,
          "capacity_carton": null,
          "packaging": "瓶",
          "temperature_type": "NORMAL",
          "uses_expiration_date": true,
          "images": ["https://example.test/item.jpg"],
          "search_codes": [
            {
              "code": "4901234567890",
              "code_type": "JAN",
              "quantity_type": "PIECE",
              "priority": 1
            }
          ],
          "jan_codes": ["4901234567890"],
          "item_quantity_codes": [
            {
              "product_code": "100010000",
              "own_code": "10001",
              "quantity_code": "00",
              "quantity": 1,
              "can_order": true
            }
          ]
        },
        "warehouse": {
          "id": 91,
          "code": "91",
          "name": "華むすびの蔵センター",
          "kana_name": "ハナムスビノクラセンター"
        },
        "stock": {
          "status": "IN_STOCK",
          "has_stock": true,
          "lot_count": 2,
          "location_count": 1,
          "current_quantity": 24,
          "reserved_quantity": 4,
          "available_quantity": 20,
          "earliest_expiration_date": "2026-08-31",
          "latest_expiration_date": "2026-09-30"
        },
        "locations": {
          "suggested": {
            "id": 456,
            "warehouse_id": 91,
            "floor_id": 1,
            "code1": "A",
            "code2": "1",
            "code3": "01",
            "code": "A-1-01",
            "display_name": "A-1-01 常温棚A",
            "name": "常温棚A",
            "source": "item_default",
            "temperature_type": "NORMAL",
            "is_restricted_area": false,
            "available_quantity_flags": 3
          },
          "default": {
            "id": 456,
            "warehouse_id": 91,
            "floor_id": 1,
            "code1": "A",
            "code2": "1",
            "code3": "01",
            "code": "A-1-01",
            "display_name": "A-1-01 常温棚A",
            "name": "常温棚A",
            "source": "item_default",
            "temperature_type": "NORMAL",
            "is_restricted_area": false,
            "available_quantity_flags": 3
          },
          "stock": [
            {
              "id": 789,
              "warehouse_id": 91,
              "floor_id": 1,
              "code1": "B",
              "code2": "2",
              "code3": "03",
              "code": "B-2-03",
              "display_name": "B-2-03 冷蔵棚B",
              "name": "冷蔵棚B",
              "source": "stock_lot",
              "temperature_type": "REFRIGERATED",
              "is_restricted_area": false,
              "available_quantity_flags": 3,
              "lot_count": 2,
              "current_quantity": 24,
              "reserved_quantity": 4,
              "available_quantity": 20,
              "earliest_expiration_date": "2026-08-31",
              "latest_expiration_date": "2026-09-30"
            }
          ]
        }
      }
    ],
    "debug_message": null
  }
}
```

該当商品がない場合:

```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": []
  }
}
```

## 在庫ステータス

| `stock.status` | 意味 |
| --- | --- |
| `IN_STOCK` | 指定倉庫で引当可能数がある |
| `RESERVED_ONLY` | 現在庫はあるが、全数引当済み |
| `NO_STOCK` | 指定倉庫に有効在庫ロットがない |

数量はすべて指定倉庫のみの合計。

## Android 側の表示方針

- 倉庫を選択してから呼び出す。`warehouse_id` は選択中倉庫を渡す。
- バーコードスキャン時は読み取った値を `search` にそのまま渡す。
- `data` が0件なら「該当商品なし」。
- `data` が複数件なら `item.code` / `item.name` / `jan_codes` / `item_quantity_codes` で商品選択リストを表示する。
- 商品詳細では `item`、`stock`、`locations` の3ブロックで表示する。
- 推奨ロケは `locations.suggested` を最上段に表示する。
- 在庫ロケ一覧は `locations.stock` を表示し、ロケごとの `available_quantity` を見せる。
- 入荷先ロケとして使う場合は `locations.suggested.id`、または作業者が選んだ `locations.stock[].id` を既存の `PUT /api/incoming/work-items/{id}` の `location_id` に渡す。

## エラー

バリデーションエラーは既存API形式に合わせて `422 VALIDATION_ERROR` を返す。

```json
{
  "is_success": false,
  "code": "VALIDATION_ERROR",
  "result": {
    "data": null,
    "error_message": "Validation failed",
    "errors": {
      "warehouse_id": ["The warehouse id field is required."]
    }
  }
}
```
