# API仕様リファレンス

---

## 1. 認証方式

### ヘッダー構成
```
Authorization: Bearer {sanctum_token}
X-API-Key: {api_key}
Content-Type: application/json
Accept: application/json
```

### API Key
- サーバー側 `config('api.keys')` の最初のキーを使用
- Blade テンプレートから `window.HANDY_CONFIG.apiKey` として注入

### Sanctum Token
- `POST /api/auth/login` で取得
- `localStorage.setItem('handy_token', token)` で保存
- URLパラメータ `auth_key` でも受け取り可能

### 認証エラーハンドリング
```javascript
// 401 レスポンス時
if (response.status === 401) {
    localStorage.removeItem('handy_token');
    localStorage.removeItem('handy_picker');
    window.location.href = '/handy/login';
}
```

---

## 2. レスポンス共通フォーマット

### 成功時
```json
{
    "is_success": true,
    "code": "SUCCESS",
    "result": {
        "data": { ... },
        "message": "...",
        "debug_message": null
    }
}
```

### エラー時
```json
{
    "is_success": false,
    "code": "ERROR_CODE",
    "result": {
        "error_message": "エラーメッセージ",
        "message": "...",
        "data": null
    }
}
```

### エラーメッセージ抽出パターン
```javascript
const errorMsg = json.result?.error_message
    || json.result?.message
    || json.message
    || json.error
    || `API Error (${response.status})`;
```

---

## 3. API エンドポイント一覧

### 3.1 認証系

| # | Method | Path | 説明 | 利用画面 |
|---|--------|------|------|---------|
| 1 | POST | `/api/auth/login` | ログイン | ログイン画面 |
| 2 | POST | `/api/auth/logout` | ログアウト | 全画面 |
| 3 | GET | `/api/me` | 認証済みユーザー情報 | 初期化時 |

#### POST /api/auth/login
```javascript
// Request
{ "code": "TEST001", "password": "password123" }

// Response
{
    "is_success": true,
    "result": {
        "data": {
            "token": "1|abcdef123456...",
            "picker": {
                "id": 2,
                "code": "TEST001",
                "name": "テストピッカー",
                "default_warehouse_id": 991
            }
        }
    }
}
```

#### GET /api/me
```javascript
// Response
{
    "is_success": true,
    "result": {
        "data": {
            "id": 2,
            "code": "TEST001",
            "name": "テストピッカー",
            "default_warehouse_id": 991
        }
    }
}
```

### 3.2 マスタ系

| # | Method | Path | 説明 | 利用画面 |
|---|--------|------|------|---------|
| 4 | GET | `/api/master/warehouses` | 倉庫一覧 | ホーム、倉庫選択 |

#### GET /api/master/warehouses
```javascript
// Response
{
    "is_success": true,
    "result": {
        "data": [
            {
                "id": 991,
                "code": "WH001",
                "name": "メイン倉庫",
                "kana_name": "メインソウコ",
                "out_of_stock_option": "IGNORE_STOCK"
            }
        ]
    }
}
```
- `out_of_stock_option`: `IGNORE_STOCK`（欠品無視）/ `UP_TO_STOCK`（在庫分のみ）

### 3.3 出荷(ピッキング)系 ★出荷アプリのコア

| # | Method | Path | 説明 | 利用画面 |
|---|--------|------|------|---------|
| 5 | GET | `/api/picking/tasks` | タスク一覧 | P20 コース選択 |
| 6 | GET | `/api/picking/tasks/{id}` | タスク詳細 | P21 内部 |
| 7 | GET | `/api/picking/items/{id}` | アイテム詳細 | P21 内部 |
| 8 | POST | `/api/picking/tasks/{id}/start` | タスク開始 | P20→P21遷移時 |
| 9 | POST | `/api/picking/tasks/{itemResultId}/update` | 数量更新 | P21 登録 |
| 10 | POST | `/api/picking/tasks/{id}/complete` | タスク完了 | P22 確定 |
| 11 | POST | `/api/picking/tasks/{itemResultId}/cancel` | アイテムキャンセル | P22 削除 |

#### GET /api/picking/tasks
```javascript
// Request
GET /api/picking/tasks?warehouse_id=991&picker_id=2

// Response 構造（グルーピング）
// 配送コース → ピッキングエリア → 波動 → ピッキングリスト
{
    "is_success": true,
    "result": {
        "data": [
            {
                "course": {
                    "code": "A001",
                    "name": "Aコース（午前便）"
                },
                "picking_area": {
                    "code": "AREA-1F",
                    "name": "1F 冷凍エリア"
                },
                "wave": {
                    "wms_picking_task_id": 123,
                    "wave_id": 456
                },
                "picking_list": [
                    {
                        "wms_picking_item_result_id": 789,
                        "item_id": 100,
                        "item_name": "サッポロ生ビール黒ラベル 500ml缶",
                        "jan_code": "4901777123456",
                        "jan_code_list": ["4901777123456", "4901777123457"],
                        "volume": "500ml",
                        "capacity_case": 24,
                        "packaging": "缶",
                        "temperature_type": "冷蔵",
                        "images": ["https://example.com/img1.jpg"],
                        "planned_qty_type": "CASE",
                        "planned_qty": 10,
                        "picked_qty": 0,
                        "status": "PENDING",
                        "slip_number": 2024031500,
                        "walking_order": 1001,
                        "customer_name": "○○酒店",
                        "destination_warehouse": null
                    }
                ]
            }
        ]
    }
}
```

**picking_list 各アイテムのフィールド:**

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `wms_picking_item_result_id` | int | アイテム結果ID（update/cancelで使用） |
| `item_id` | int | 商品ID |
| `item_name` | string | 商品名 |
| `jan_code` | string | 主JANコード |
| `jan_code_list` | string[] | 全JANコード |
| `volume` | string | 容量（例: "720ml"） |
| `capacity_case` | int | ケース入数 |
| `packaging` | string | 包装形態（瓶, 缶等） |
| `temperature_type` | string | 温度帯（常温, 冷蔵, 冷凍） |
| `images` | string[] | 商品画像URL（最大3件） |
| `planned_qty_type` | enum | CASE / PIECE |
| `planned_qty` | decimal | 引当数量 |
| `picked_qty` | decimal | ピッキング済数量 |
| `status` | enum | PENDING / PICKING / COMPLETED / SHORTAGE |
| `slip_number` | int | 伝票番号 |
| `walking_order` | int | 歩行順序 |
| `customer_name` | string | 得意先名 |

#### POST /api/picking/tasks/{id}/start
```javascript
// Request
POST /api/picking/tasks/123/start
// Body: なし

// Response
// status: PICKING_READY → PICKING に遷移
// べき等: 既にPICKINGの場合も200 SUCCESS
```

#### POST /api/picking/tasks/{itemResultId}/update
```javascript
// Request
POST /api/picking/tasks/789/update
{
    "picked_qty": 10,
    "picked_qty_type": "CASE"  // optional（省略時はplanned_qty_type使用）
}

// Response
// shortage_qty = max(0, planned_qty - picked_qty) 自動計算
// 複数回呼び出し可能（上書き方式）
// picked_qty > planned_qty も許容（過剰ピック）
```

#### POST /api/picking/tasks/{id}/complete
```javascript
// Request
POST /api/picking/tasks/123/complete
// Body: なし

// Response
// planned_qty=0 のアイテムは自動COMPLETED
// planned_qty>0 かつ picked_qty=0 のアイテムがあれば 422エラー
// shortage_qty=0 → COMPLETED / shortage_qty>0 → SHORTAGE
// べき等: 完了済みタスクへの再呼び出しは200 SUCCESS
```

#### POST /api/picking/tasks/{itemResultId}/cancel
```javascript
// Request
POST /api/picking/tasks/789/cancel
// Body: なし

// Response
// PENDING に戻る、picked_qty=0にリセット
```

### 3.4 入荷(入庫)系

| # | Method | Path | 説明 | 利用画面 |
|---|--------|------|------|---------|
| 12 | GET | `/api/incoming/schedules` | 入荷予定一覧 | P11 商品リスト |
| 13 | GET | `/api/incoming/schedules/{id}` | 入荷予定詳細 | P12 内部 |
| 14 | GET | `/api/incoming/locations` | ロケーション検索 | P13 入力 |
| 15 | POST | `/api/incoming/work-items` | 入荷作業開始 | P13 入力 |
| 16 | GET | `/api/incoming/work-items` | 入荷作業一覧 | P14 履歴 |
| 17 | PUT | `/api/incoming/work-items/{id}` | 入荷作業更新 | P13 編集 |
| 18 | DELETE | `/api/incoming/work-items/{id}` | 入荷作業キャンセル | P14 削除 |
| 19 | POST | `/api/incoming/work-items/{id}/complete` | 入荷確定 | P13 確定 |

#### GET /api/incoming/schedules
```javascript
// Request
GET /api/incoming/schedules?warehouse_id=991&search=keyword

// Response: 商品ごとにグルーピングされた入荷予定
```

#### GET /api/incoming/locations
```javascript
// Request
GET /api/incoming/locations?warehouse_id=991&search=A-1

// Response: ロケーション候補（code1/code2/code3 階層）
```

#### POST /api/incoming/work-items
```javascript
// Request
{
    "incoming_schedule_id": 123,
    "picker_id": 2,
    "warehouse_id": 991
}
```

#### PUT /api/incoming/work-items/{id}
```javascript
// Request
{
    "work_quantity": 100,
    "work_arrival_date": "2026-03-15",
    "work_expiration_date": "2027-03-15",
    "location_id": 456
}
```

#### POST /api/incoming/work-items/{id}/complete
```javascript
// Request: Body なし
// Response: real_stocks に在庫追加
```

---

## 4. 数量タイプ表示ルール

| API値 | 日本語表示 |
|-------|-----------|
| `CASE` | ケース |
| `PIECE` | バラ |
| `CARTON` | ボール |

```javascript
function getQtyTypeLabel(qtyType) {
    const labels = { 'CASE': 'ケース', 'PIECE': 'バラ', 'CARTON': 'ボール' };
    return labels[qtyType] || qtyType;
}
```

---

## 5. ステータス遷移

### ピッキングアイテム結果
```
PENDING → PICKING → COMPLETED
                  → SHORTAGE（欠品時）
```

### ピッキングタスク
```
PENDING → PICKING_READY → PICKING → COMPLETED → SHIPPED
```

### Webアプリでのステータス判定ロジック

```javascript
// タスク選択時のナビゲーション判定
if (task.pendingCount > 0) {
    // PENDINGアイテムあり → データ入力画面
    navigate('picking');
} else if (task.pickingCount > 0) {
    // PENDINGなし、PIKINGあり → 履歴画面（編集可能）
    navigate('history');
} else {
    // 全てCOMPLETED/SHORTAGE → 履歴画面（読み取り専用）
    navigate('history');
}
```

---

## 6. APIクライアント実装パターン

既存の入荷・出荷アプリで共通化済みのパターン:

```javascript
const api = {
    baseUrl: window.HANDY_CONFIG?.baseUrl || '/api',
    apiKey: window.HANDY_CONFIG?.apiKey || '',
    token: null,
    onAuthError: null,

    getHeaders() {
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-API-Key': this.apiKey,
        };
        if (this.token) {
            headers['Authorization'] = `Bearer ${this.token}`;
        }
        return headers;
    },

    async request(method, endpoint, data = null) {
        const url = `${this.baseUrl}${endpoint}`;
        const options = { method, headers: this.getHeaders() };
        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }
        const response = await fetch(url, options);
        const json = await response.json();
        if (!response.ok) {
            if (response.status === 401 && this.onAuthError) {
                this.onAuthError();
            }
            const errorMsg = json.result?.error_message
                || json.result?.message
                || json.message
                || `API Error (${response.status})`;
            const error = new Error(errorMsg);
            error.code = json.code;
            error.data = json.result?.data;
            throw error;
        }
        return json;
    },

    get(endpoint) { return this.request('GET', endpoint); },
    post(endpoint, data) { return this.request('POST', endpoint, data); },
    put(endpoint, data) { return this.request('PUT', endpoint, data); },
    delete(endpoint) { return this.request('DELETE', endpoint); },
};
```
