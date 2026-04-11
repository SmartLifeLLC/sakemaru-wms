# ページ構成と画面遷移

---

## 1. Android 画面 → Web ページ マッピング

### 共通画面

| Android | Web URL | Blade | JS | 状態 |
|---------|---------|-------|----|------|
| P01 ログイン | `/handy/login` | `handy/login.blade.php` | `handy/login-app.js` | 実装済 |
| P02 設定 | N/A（Webではブラウザ設定で代替） | — | — | 不要 |
| P03 メイン | `/handy/home` | `handy/home.blade.php` | `handy/home-app.js` | 実装済 |

### 入荷(入庫)画面

| Android | Web URL | Blade | JS | 状態 |
|---------|---------|-------|----|------|
| P10 倉庫選択 | `/handy/incoming` (SPA内) | `handy/incoming.blade.php` | `handy/incoming-app.js` | 実装済 |
| P11 商品リスト | 同上 (screen='list') | partials/product-list | 同上 | 実装済 |
| P12 スケジュール一覧 | 同上 (screen='process') | partials/process | 同上 | 実装済 |
| P13 入庫入力 | 同上 (screen='input') | partials/process (input section) | 同上 | 実装済 |
| P14 入庫履歴 | 同上 (screen='history') | partials/history | 同上 | 実装済 |

### 出荷(出庫)画面

| Android | Web URL | Blade | JS | 状態 |
|---------|---------|-------|----|------|
| P20 コース選択 | `/handy/outgoing` (SPA内) | `handy/outgoing.blade.php` | `handy/outgoing-app.js` | **要拡張** |
| P21 データ入力 | 同上 (screen='picking') | 同上 (inline) | 同上 | **要拡張** |
| P22 出庫履歴 | 同上 (screen='history') | — | — | **未実装** |

---

## 2. 画面遷移フロー

### Web全体フロー
```
/handy/login (P01)
    │  POST /api/auth/login → token取得
    │  → localStorage保存
    ▼
/handy/home (P03) ?auth_key={token}
    │  GET /api/master/warehouses → 倉庫選択
    │
    ├─→ /handy/incoming?auth_key={token}&warehouse_id={id}
    │       └─ SPA内遷移: login → warehouse → list → process → input → result → history
    │
    └─→ /handy/outgoing?auth_key={token}&warehouse_id={id}
            └─ SPA内遷移: task-list → picking → history → complete → result
```

### 出荷(出庫) SPA内遷移（Android P20-P22 相当）

```
task-list (P20: コース選択)
    │  GET /api/picking/tasks?warehouse_id={id}
    │  タスクカードをタップ
    │  POST /api/picking/tasks/{id}/start
    │
    ├──→ picking (P21: データ入力)  ← PENDINGアイテムあり
    │       │  商品情報表示（名前、JAN、容量、入数、画像）
    │       │  ロケーション、伝票番号表示
    │       │  数量入力（ケース/バラ）
    │       │  POST /api/picking/tasks/{itemResultId}/update
    │       │
    │       ├── 次のPENDINGアイテムへ自動遷移
    │       │
    │       ├──→ history (P22: 出庫履歴) ← 履歴ボタン
    │       │       │  登録済みアイテム一覧
    │       │       │  削除（キャンセル）可能
    │       │       │  POST /api/picking/tasks/{itemResultId}/cancel
    │       │       └──→ picking に戻る
    │       │
    │       └── 全PENDING完了 → complete画面
    │               │  確定ボタン
    │               │  POST /api/picking/tasks/{id}/complete
    │               └──→ result → task-list
    │
    └──→ history (P22: 出庫履歴)  ← PENDINGなし、PIKINGアイテムのみ
            │  編集可能状態
            │  確定ボタン表示
            └──→ task-list（確定後）
```

### 入荷(入庫) SPA内遷移（Android P10-P14 相当）

```
login → warehouse (P10: 倉庫選択)
    │  GET /api/master/warehouses
    ▼
list (P11: 商品リスト)
    │  GET /api/incoming/schedules?warehouse_id={id}
    │  GET /api/incoming/work-items（作業中判定）
    │  検索バー（バーコードスキャン対応）
    │
    ├──→ process (P12: スケジュールリスト)
    │       │  商品サマリー表示
    │       │  スケジュール一覧（倉庫別、予定日、ロケーション）
    │       │
    │       └──→ input (P13: 入庫入力)
    │               │  数量入力
    │               │  賞味期限入力
    │               │  ロケーション入力（オートコンプリート）
    │               │  POST /api/incoming/work-items（新規）
    │               │  PUT /api/incoming/work-items/{id}（更新）
    │               │  POST /api/incoming/work-items/{id}/complete（確定）
    │               └──→ process / result に戻る
    │
    └──→ history (P14: 入庫履歴)
            │  GET /api/incoming/work-items?status=all&from_date=today
            │  履歴一覧（ステータス別カラー）
            └──→ input（編集時）
```

---

## 3. 画面状態管理（Alpine.js x-data）

### 出荷アプリ `handyOutgoingApp()`

```javascript
{
    // Screen Management
    currentScreen: 'task-list', // task-list | picking | history | complete | result

    // Authentication
    picker: null,           // { id, code, name, default_warehouse_id }

    // Data
    selectedWarehouse: null, // { id, name, code }
    tasks: [],              // ピッキングタスク一覧
    currentTask: null,      // 選択中のタスク
    currentItemIndex: 0,    // 現在のアイテムインデックス
    pickedQty: 0,           // 入力中のピッキング数量
    pickedItems: [],        // ピッキング済みアイテム追跡
    historyItems: [],       // 履歴表示用（PICKING/COMPLETED/SHORTAGE）

    // UI State
    selectedTaskIndex: 0,   // キーボードナビゲーション用
    isLoading: false,
    loadingMessage: '',
    notification: { show: false, message: '', type: 'info' },
}
```

### 入荷アプリ `handyIncomingApp()`

```javascript
{
    // Screen Management
    currentScreen: 'login', // login | warehouse | list | process | input | result | history

    // Authentication
    isAuthenticated: false,
    loginForm: { code: '', password: '' },
    picker: null,

    // Data
    warehouses: [],
    selectedWarehouse: null,
    allProducts: [],
    currentItem: null,
    history: [],
    schedulesToProcess: [],
    currentScheduleIndex: 0,

    // Input Form
    inputForm: {
        schedule_id: null,
        qty: 0,
        location_search: '',
        location_id: null,
        expiration_date: '',
        arrival_date: '',
    },

    // UI State
    isLoading: false,
    loadingMessage: '',
    notification: { show: false, message: '', type: 'info' },
}
```

---

## 4. URL パラメータ

| パラメータ | 用途 | 例 |
|-----------|------|-----|
| `auth_key` | Sanctum token（ログインスキップ） | `?auth_key=1\|abc123...` |
| `warehouse_id` | 倉庫ID（倉庫選択スキップ） | `?warehouse_id=991` |

### 組み合わせパターン

```
/handy/outgoing?auth_key={token}&warehouse_id={id}
  → 認証済み + 倉庫選択済み → 直接タスク一覧表示

/handy/outgoing?auth_key={token}
  → 認証済み + 倉庫未選択 → ホーム画面へリダイレクト

/handy/outgoing
  → 未認証 → ログイン画面へリダイレクト
```
