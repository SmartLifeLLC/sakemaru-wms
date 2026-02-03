# 入庫処理 API - Android Native Implementation Guide

## 概要

このドキュメントは、現在Web View（Alpine.js）で実装されている入庫処理画面を、Android Native アプリとして再実装するための API 仕様と実装ガイドです。

## 目次

1. [認証](#1-認証)
2. [API 一覧](#2-api-一覧)
3. [API 詳細仕様](#3-api-詳細仕様)
4. [画面フロー](#4-画面フロー)
5. [データモデル](#5-データモデル)
6. [エラーハンドリング](#6-エラーハンドリング)
7. [実装上の注意点](#7-実装上の注意点)

---

## 1. 認証

### 認証方式

全ての API リクエストには以下の認証が必要です：

1. **API Key** (必須): `X-API-Key` ヘッダー
2. **Bearer Token** (ログイン後): `Authorization: Bearer {token}` ヘッダー

### ヘッダー例

```http
X-API-Key: your-api-key
Authorization: Bearer 1|abcdef123456...
Content-Type: application/json
Accept: application/json
```

### ログイン

```http
POST /api/auth/login
```

**リクエスト:**
```json
{
  "code": "PICKER001",
  "password": "password123",
  "device_id": "ANDROID-12345"  // オプション
}
```

**レスポンス:**
```json
{
  "is_success": true,
  "code": "LOGIN_SUCCESS",
  "result": {
    "data": {
      "token": "1|abcdef123456...",
      "picker": {
        "id": 1,
        "code": "PICKER001",
        "name": "田中太郎",
        "default_warehouse_id": 991
      }
    },
    "message": "Login successful"
  }
}
```

**トークン保存:**
- Android SharedPreferences または EncryptedSharedPreferences に保存
- セッション管理用に picker 情報も保存

---

## 2. API 一覧

| メソッド | エンドポイント | 説明 |
|---------|--------------|------|
| POST | `/api/auth/login` | ログイン |
| POST | `/api/auth/logout` | ログアウト |
| GET | `/api/me` | 認証ユーザー情報取得 |
| GET | `/api/master/warehouses` | 倉庫一覧取得 |
| GET | `/api/incoming/schedules` | 入庫予定一覧取得 |
| GET | `/api/incoming/schedules/{id}` | 入庫予定詳細取得 |
| GET | `/api/incoming/work-items` | 作業データ一覧取得 |
| POST | `/api/incoming/work-items` | 作業開始 |
| PUT | `/api/incoming/work-items/{id}` | 作業データ更新 |
| POST | `/api/incoming/work-items/{id}/complete` | 作業完了 |
| DELETE | `/api/incoming/work-items/{id}` | 作業キャンセル |
| GET | `/api/incoming/locations` | ロケーション検索 |

---

## 3. API 詳細仕様

### 3.1 倉庫一覧取得

```http
GET /api/master/warehouses
```

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": [
      {
        "id": 991,
        "code": "991",
        "name": "華むすびの蔵センター",
        "kana_name": "ハナムスビノクラセンター",
        "out_of_stock_option": "IGNORE_STOCK"
      }
    ]
  }
}
```

### 3.2 入庫予定一覧取得

```http
GET /api/incoming/schedules?warehouse_id={warehouse_id}&search={search_query}
```

**パラメータ:**
| 名前 | 型 | 必須 | 説明 |
|-----|---|-----|------|
| warehouse_id | integer | Yes | 作業倉庫ID |
| search | string | No | 検索キーワード（JANコード、商品コード、商品名） |

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": [
      {
        "item_id": 123,
        "item_code": "10001",
        "item_name": "純米大吟醸 雪月花 720ml",
        "search_code": "4901234567890,4901234567891",
        "jan_codes": ["4901234567890", "4901234567891"],
        "volume": "720",
        "volume_unit": "ml",
        "capacity_case": 12,
        "temperature_type": "常温",
        "images": ["https://example.com/image1.jpg"],
        "default_location": {
          "id": 100,
          "code1": "A",
          "code2": "01",
          "code3": "001",
          "display_name": "A-01-001"
        },
        "total_expected_quantity": 100,
        "total_received_quantity": 20,
        "total_remaining_quantity": 80,
        "warehouses": [
          {
            "warehouse_id": 991,
            "warehouse_code": "991",
            "warehouse_name": "華むすびの蔵センター",
            "expected_quantity": 100,
            "received_quantity": 20,
            "remaining_quantity": 80
          }
        ],
        "schedules": [
          {
            "id": 456,
            "warehouse_id": 991,
            "warehouse_name": "華むすびの蔵センター",
            "expected_quantity": 50,
            "received_quantity": 10,
            "remaining_quantity": 40,
            "quantity_type": "PIECE",
            "expected_arrival_date": "2026-01-20",
            "expiration_date": "2027-01-20",
            "status": "PARTIAL",
            "location": {
              "id": 100,
              "code1": "A",
              "code2": "01",
              "code3": "001",
              "display_name": "A-01-001"
            }
          }
        ]
      }
    ]
  }
}
```

### 3.3 作業データ一覧取得（履歴）

```http
GET /api/incoming/work-items?warehouse_id={id}&picker_id={id}&status={status}&from_date={date}&limit={limit}
```

**パラメータ:**
| 名前 | 型 | 必須 | 説明 |
|-----|---|-----|------|
| warehouse_id | integer | Yes | 倉庫ID |
| picker_id | integer | No | 作業者ID |
| status | string | No | WORKING, COMPLETED, CANCELLED, all（デフォルト: WORKING） |
| from_date | string | No | 開始日（YYYY-MM-DD） |
| to_date | string | No | 終了日（YYYY-MM-DD） |
| limit | integer | No | 取得件数（デフォルト: 100） |

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": [
      {
        "id": 789,
        "incoming_schedule_id": 456,
        "picker_id": 1,
        "warehouse_id": 991,
        "location_id": 100,
        "location": {
          "id": 100,
          "code1": "A",
          "code2": "01",
          "code3": "001",
          "name": "棚A-01-001",
          "display_name": "A 01 001"
        },
        "work_quantity": 30,
        "work_arrival_date": "2026-01-20",
        "work_expiration_date": "2027-01-20",
        "status": "COMPLETED",
        "started_at": "2026-01-20T10:30:00+09:00",
        "schedule": {
          "id": 456,
          "item_id": 123,
          "item_code": "10001",
          "item_name": "純米大吟醸 雪月花 720ml",
          "jan_codes": ["4901234567890"],
          "warehouse_id": 991,
          "warehouse_name": "華むすびの蔵センター",
          "expected_quantity": 50,
          "received_quantity": 30,
          "remaining_quantity": 20,
          "quantity_type": "PIECE",
          "expected_arrival_date": "2026-01-20",
          "status": "PARTIAL"
        }
      }
    ]
  }
}
```

### 3.4 作業開始

```http
POST /api/incoming/work-items
```

**リクエスト:**
```json
{
  "incoming_schedule_id": 456,
  "picker_id": 1,
  "warehouse_id": 991
}
```

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": {
      "id": 789,
      "incoming_schedule_id": 456,
      "picker_id": 1,
      "warehouse_id": 991,
      "location_id": 100,
      "location": {
        "id": 100,
        "code1": "A",
        "code2": "01",
        "code3": "001",
        "display_name": "A 01 001"
      },
      "work_quantity": 40,
      "work_arrival_date": "2026-01-20",
      "work_expiration_date": "2027-01-20",
      "status": "WORKING",
      "started_at": "2026-01-20T10:30:00+09:00",
      "schedule": { ... }
    },
    "message": "作業を開始しました"
  }
}
```

**エラーケース:**
- `ALREADY_WORKING`: 既に作業中の場合（既存の作業データが返される）
- 400: 入庫予定が作業不可状態（CONFIRMED, TRANSMITTED, CANCELLED）

### 3.5 作業データ更新

```http
PUT /api/incoming/work-items/{id}
```

**リクエスト:**
```json
{
  "work_quantity": 35,
  "work_arrival_date": "2026-01-20",
  "work_expiration_date": "2027-01-20",
  "location_id": 100
}
```

**レスポンス:**
作業データ一覧と同じ形式

### 3.6 作業完了

```http
POST /api/incoming/work-items/{id}/complete
```

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": null,
    "message": "入庫を確定しました"
  }
}
```

### 3.7 作業キャンセル

```http
DELETE /api/incoming/work-items/{id}
```

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": null,
    "message": "キャンセルしました"
  }
}
```

### 3.8 ロケーション検索

```http
GET /api/incoming/locations?warehouse_id={id}&search={query}&limit={limit}
```

**パラメータ:**
| 名前 | 型 | 必須 | 説明 |
|-----|---|-----|------|
| warehouse_id | integer | Yes | 倉庫ID |
| search | string | No | 検索キーワード（code1, code2, code3, name） |
| limit | integer | No | 取得件数（デフォルト: 50） |

**レスポンス:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": [
      {
        "id": 100,
        "code1": "A",
        "code2": "01",
        "code3": "001",
        "name": "棚A-01-001",
        "display_name": "A 01 001"
      }
    ]
  }
}
```

---

## 4. 画面フロー

```
┌─────────────┐
│   ログイン   │
└──────┬──────┘
       ↓
┌─────────────┐
│  倉庫選択   │
└──────┬──────┘
       ↓
┌─────────────┐     F2:履歴
│ 商品リスト  │ ←──────────→ ┌─────────────┐
│  （検索）   │              │    履歴     │
└──────┬──────┘              └──────┬──────┘
       ↓ 商品選択                    │ 編集選択
┌─────────────┐                     │
│スケジュール │←────────────────────┘
│    一覧     │
└──────┬──────┘
       ↓ スケジュール選択
┌─────────────┐
│  入力画面   │
│（数量/日付）│
└──────┬──────┘
       ↓ F2:登録
┌─────────────┐
│   完了/     │
│ スケジュール│
│    一覧     │
└─────────────┘
```

---

## 5. データモデル

### スケジュールステータス

| ステータス | 説明 | 作業可否 |
|-----------|------|---------|
| PENDING | 未入庫 | 可 |
| PARTIAL | 一部入庫済み | 可 |
| CONFIRMED | 入庫完了（確定済み） | 履歴から編集可 |
| TRANSMITTED | 連携済み | 不可 |
| CANCELLED | キャンセル | 不可 |

### 作業データステータス

| ステータス | 説明 |
|-----------|------|
| WORKING | 作業中 |
| COMPLETED | 完了 |
| CANCELLED | キャンセル |

### 数量タイプ

| タイプ | 説明 |
|-------|------|
| PIECE | バラ |
| CASE | ケース |

---

## 6. エラーハンドリング

### レスポンス形式

**成功:**
```json
{
  "is_success": true,
  "code": "SUCCESS",
  "result": {
    "data": { ... },
    "message": "操作メッセージ"
  }
}
```

**エラー:**
```json
{
  "is_success": false,
  "code": "ERROR_CODE",
  "result": {
    "data": null,
    "error_message": "エラーメッセージ",
    "errors": { ... }  // バリデーションエラー時
  }
}
```

### エラーコード一覧

| HTTPステータス | コード | 説明 |
|---------------|-------|------|
| 401 | UNAUTHORIZED | 認証エラー（トークン無効/期限切れ） |
| 404 | NOT_FOUND | リソースが見つからない |
| 422 | VALIDATION_ERROR | バリデーションエラー |
| 400 | ERROR | 業務エラー |
| 400 | ALREADY_WORKING | 既に作業中（data に作業データが含まれる） |
| 500 | ERROR | サーバーエラー |

### 認証エラー時の処理

401 エラー時は以下の処理を行う：
1. 保存しているトークンをクリア
2. ログイン画面に遷移
3. 「セッションが切れました。再ログインしてください。」を表示

---

## 7. 実装上の注意点

### バーコードスキャン

1. **JANコード検索**
   - スキャンしたJANコードを `search` パラメータに渡して検索
   - 検索結果が1件の場合は自動選択も可

2. **ロケーションスキャン**
   - バーコードリーダーでロケーションバーコードをスキャン
   - スキャン値で `/api/incoming/locations` を検索

### オフライン対応（推奨）

1. **倉庫マスタ**: キャッシュ推奨
2. **入庫予定**: オンライン必須（リアルタイム性重要）
3. **作業データ**: オンライン必須

### UI/UX ポイント

1. **大きなボタン**: ハンディ端末での操作を考慮
2. **ファンクションキー対応**: F1〜F4 の物理キー対応
3. **キーボードナビゲーション**: 上下キーでリスト移動、Enterで選択
4. **視認性**: 文字サイズ大きめ、コントラスト確保

### データリフレッシュ

1. **商品リスト画面に戻る際**: `loadWorkingScheduleIds()` で作業中状態を更新
2. **入庫完了後**: `searchProducts()` で商品リストを再取得
3. **履歴表示時**: `loadHistory()` で当日の履歴を取得

### 同時作業制御

- 同一スケジュールに対して作業開始すると、既存の作業データが返される
- エラーコード `ALREADY_WORKING` 時は `data` に作業データが含まれる
- フロントエンドは自動的に既存作業を再開する

---

## Swagger ドキュメント

詳細な API ドキュメントは Swagger UI で確認できます：

```
https://{server}/api/documentation
```

※ Filament 認証が必要です。管理画面にログイン後にアクセスしてください。
