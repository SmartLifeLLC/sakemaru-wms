# Android端末向け ピッキング・入荷 Webアプリ 全体設計・実装指示書

- **作成日**: 2026-03-15
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260315/android-web-app/`

---

## 1. 背景・目的

### 現状
BHT-M60ハンディ端末向けに Alpine.js ベースのWebアプリが稼働中。入荷（incoming）・出荷（outgoing）の2つのSPAが存在する。

### 課題
- BHT-M60専用の480x800px固定レイアウトで、一般的なAndroid端末（スマートフォン・タブレット）では使いにくい
- オフライン対応なし（ネットワーク切断時に操作不可）
- 出荷アプリの機能が限定的（バーコードスキャン連携、欠品表示等が未対応）
- 入荷アプリの履歴編集・エラーハンドリングが不十分

### 目的
- 一般的なAndroid端末（5-7インチ）で快適に動作するレスポンシブWebアプリを構築
- 既存APIをそのまま活用し、バックエンド変更なしで実装
- PWA対応でホーム画面追加・基本的なオフライン耐性を提供

---

## 2. 現状の実装

### 2.1 技術スタック

| レイヤー | 技術 | バージョン |
|---------|------|-----------|
| UI Framework | Alpine.js | 3.15.4 |
| CSS | Tailwind CSS | 4.1.16 |
| Bundler | Vite | 7.0.7 |
| HTTP Client | Fetch API (vanilla) | - |
| Icons | Phosphor Icons (CDN) | - |
| 認証 | Laravel Sanctum + API Key | - |

### 2.2 既存ファイル構成

```
app/Http/Controllers/Handy/
├── HandyController.php           # login, home, outgoing ビュー
└── HandyIncomingController.php   # incoming ビュー

resources/js/handy/
├── login-app.js                  # ログインSPA (Alpine.js)
├── home-app.js                   # 倉庫選択+メニューSPA
├── incoming-app.js               # 入荷SPA (最も複雑、~800行)
└── outgoing-app.js               # 出荷SPA (~400行)

resources/views/handy/
├── layouts/app.blade.php         # 共通レイアウト (BHT-M60最適化)
├── login.blade.php               # ログイン画面
├── home.blade.php                # ホーム画面
├── incoming.blade.php            # 入荷メイン
├── incoming/partials/            # 入荷パーツ (11ファイル)
│   ├── header.blade.php
│   ├── footer.blade.php
│   ├── product-list.blade.php
│   ├── process.blade.php
│   ├── result.blade.php
│   ├── history.blade.php
│   └── ...
└── outgoing.blade.php            # 出荷メイン (スタンドアロン)
```

### 2.3 既存API（全19エンドポイント）

**認証**: `X-API-Key` ヘッダー + `Authorization: Bearer {token}`

| カテゴリ | エンドポイント数 | 状態 |
|---------|----------------|------|
| 認証 (auth) | 3 | 実装済み |
| マスタ (master) | 1 | 実装済み |
| 入荷 (incoming) | 7 | 実装済み |
| ピッキング (picking) | 7 | 実装済み |

→ **バックエンドAPI変更なし**で新アプリを構築可能

### 2.4 既存画面フロー

```
[ログイン] → [倉庫選択] → [メニュー]
                              ├→ [入荷] → 商品検索 → スケジュール選択 → 数量/ロケ入力 → 確定
                              └→ [出荷] → タスク一覧 → アイテムピッキング → 完了
```

---

## 3. 新アプリ設計

### 3.1 設計方針

| 項目 | 方針 |
|------|------|
| アーキテクチャ | SPA（Alpine.js継続、コンポーネント分割を改善） |
| レスポンシブ | 360px〜768px対応（主ターゲット: 5-7インチ Android） |
| PWA | manifest.json + Service Worker（キャッシュ戦略: Network First） |
| オフライン | 静的アセットキャッシュ + オフライン検知UI（データ操作はオンライン必須） |
| バーコード | HTML5 `<input>` でハードウェアスキャナ入力受付（カメラスキャンは将来対応） |
| ナビゲーション | ボトムナビゲーション（モバイルUX標準） |
| 状態管理 | Alpine.js `$store` でグローバル状態管理 |
| エラー処理 | 統一的なエラーハンドリング + リトライ機構 |

### 3.2 画面構成

```
[ログイン]
  │
  ▼
[ホーム] ── ボトムナビ ──┬── [入荷タブ]
                         ├── [出荷タブ]
                         └── [設定タブ]

■ 入荷タブ
  ├── 入荷予定一覧（検索・フィルタ付き）
  ├── 入荷作業画面（商品情報 + スケジュール + 入力フォーム）
  ├── 入荷確定画面（確認 → 完了）
  └── 入荷履歴（編集・キャンセル可能）

■ 出荷タブ
  ├── タスク一覧（コース別グルーピング）
  ├── タスク詳細（アイテムリスト + 進捗）
  ├── ピッキング画面（1アイテムずつ、バーコードスキャン対応）
  ├── 欠品確認画面（shortage表示）
  └── タスク完了画面（サマリー + 結果）

■ 設定タブ
  ├── 倉庫切替
  ├── ピッカー情報表示
  └── ログアウト
```

### 3.3 画面詳細設計

#### 3.3.1 ログイン画面

```
┌─────────────────────────┐
│        WMS ロゴ          │
│                         │
│  ┌───────────────────┐  │
│  │ ピッカーコード      │  │
│  └───────────────────┘  │
│  ┌───────────────────┐  │
│  │ パスワード          │  │
│  └───────────────────┘  │
│                         │
│  [      ログイン      ]  │
│                         │
│  端末ID: ANDROID-XXXX   │
└─────────────────────────┘
```

- API: `POST /api/auth/login`
- トークンは `localStorage` に保存
- 自動ログイン: トークン存在時に `GET /api/me` で検証

#### 3.3.2 ホーム（ボトムナビ付き）

```
┌─────────────────────────┐
│ 🏭 酒丸本社    ピッカー名 │  ← ヘッダー（倉庫名 + ユーザー名）
├─────────────────────────┤
│                         │
│   [タブ別コンテンツ]      │  ← メインコンテンツ
│                         │
│                         │
├─────────────────────────┤
│  📥入荷  📦出荷  ⚙設定  │  ← ボトムナビゲーション
└─────────────────────────┘
```

- 初回アクセス時に倉庫選択モーダル表示
- API: `GET /api/master/warehouses`

#### 3.3.3 入荷 - 予定一覧

```
┌─────────────────────────┐
│ 📥 入荷予定              │
├─────────────────────────┤
│ 🔍 [商品検索/JANスキャン] │  ← バーコードスキャン対応
├─────────────────────────┤
│ ┌─────────────────────┐ │
│ │ 商品A   4901234...  │ │  ← 商品カード
│ │ 720ml 常温          │ │
│ │ 残: 80 / 予定: 100  │ │  ← 残数量 / 予定数量
│ │ ██████████░░ 20%    │ │  ← プログレスバー
│ └─────────────────────┘ │
│ ┌─────────────────────┐ │
│ │ 商品B   4901235...  │ │
│ │ 500ml 冷蔵          │ │
│ │ 残: 50 / 予定: 50   │ │
│ │ ░░░░░░░░░░░░ 0%     │ │
│ └─────────────────────┘ │
│        ↕ スクロール       │
├─────────────────────────┤
│  📥入荷  📦出荷  ⚙設定  │
└─────────────────────────┘
```

- API: `GET /api/incoming/schedules?warehouse_id=X&search=Y`
- 無限スクロール（50件/ページ）
- 検索: 商品コード、JANコード、商品名
- タップで入荷作業画面へ遷移

#### 3.3.4 入荷 - 作業画面

```
┌─────────────────────────┐
│ ← 入荷作業               │
├─────────────────────────┤
│ 🖼 [商品画像]            │
│ 商品A (10001)            │
│ JAN: 4901234567890       │
│ 720ml / 常温 / 瓶        │
├─────────────────────────┤
│ スケジュール 1/3          │
│ 倉庫: 酒丸本社           │
│ 予定: 100  入荷済: 20    │
├─────────────────────────┤
│ 賞味期限                 │
│ ┌───────────────────┐   │
│ │ 2026/06/20        │   │
│ └───────────────────┘   │
│ ロケーション             │
│ ┌───────────────────┐   │
│ │ 🔍 A-1-01         │   │  ← 検索ドロップダウン
│ └───────────────────┘   │
│ 数量                    │
│ ┌──┐ ┌─────────┐ ┌──┐  │
│ │ − │ │   80    │ │ + │  │  ← 大きなタッチターゲット
│ └──┘ └─────────┘ └──┘  │
│                         │
│ [  入荷確定  ]           │  ← プライマリボタン
├─────────────────────────┤
│  📥入荷  📦出荷  ⚙設定  │
└─────────────────────────┘
```

- API:
  - `POST /api/incoming/work-items` (作業開始)
  - `PUT /api/incoming/work-items/{id}` (更新)
  - `POST /api/incoming/work-items/{id}/complete` (確定)
  - `GET /api/incoming/locations?warehouse_id=X&search=Y` (ロケーション検索)
- ロケーション: インクリメンタル検索 + ドロップダウン選択
- 数量: +/- ボタン + 直接入力、デフォルト = remaining_quantity

#### 3.3.5 出荷 - タスク一覧

```
┌─────────────────────────┐
│ 📦 ピッキングタスク       │
├─────────────────────────┤
│ ▼ 佐藤 尚紀 (910072)    │  ← コース別アコーディオン
│ ┌─────────────────────┐ │
│ │ エリアB（バラ）       │ │
│ │ Wave#5  3品  PENDING │ │
│ │ [開始]               │ │
│ └─────────────────────┘ │
│ ┌─────────────────────┐ │
│ │ エリアA（ケース）     │ │
│ │ Wave#5  5品 PICKING  │ │
│ │ ██████░░ 60%         │ │  ← 進捗表示
│ └─────────────────────┘ │
│                         │
│ ▶ 田中 太郎 (910081)    │  ← 折りたたみ
├─────────────────────────┤
│  📥入荷  📦出荷  ⚙設定  │
└─────────────────────────┘
```

- API: `GET /api/picking/tasks?warehouse_id=X&picker_id=Y`
- コース → エリア → Wave のグルーピング表示
- ステータスバッジ: PENDING(灰), PICKING_READY(青), PICKING(橙), COMPLETED(緑), SHORTAGE(赤)
- タップでピッキング画面へ

#### 3.3.6 出荷 - ピッキング画面

```
┌─────────────────────────┐
│ ← ピッキング  2/8品      │  ← 進捗カウンター
├─────────────────────────┤
│ 🖼 [商品画像]            │
│ 白鶴特撰 本醸造生貯蔵酒   │
│ 720ml                   │
│ JAN: 4901681115008       │
├─────────────────────────┤
│ 予定数量:  2 ケース       │
│ ケース入数: 12本          │
├─────────────────────────┤
│ ピッキング数量            │
│ ┌──┐ ┌─────────┐ ┌──┐  │
│ │ − │ │    2    │ │ + │  │
│ └──┘ └─────────┘ └──┘  │
│ 単位: [ケース ▼]         │
├─────────────────────────┤
│ 🔍 バーコードスキャン     │  ← スキャン入力フィールド
│ ┌───────────────────┐   │
│ │                   │   │  ← ハードウェアスキャナ対応
│ └───────────────────┘   │
├─────────────────────────┤
│ 伝票No: 12345            │
│ 配送先: 佐藤 尚紀        │
├─────────────────────────┤
│ [欠品] [スキップ] [確定]  │  ← アクションボタン
├─────────────────────────┤
│  📥入荷  📦出荷  ⚙設定  │
└─────────────────────────┘
```

- API:
  - `POST /api/picking/tasks/{id}/start` (タスク開始)
  - `POST /api/picking/tasks/{itemResultId}/update` (数量更新)
  - `POST /api/picking/tasks/{itemResultId}/cancel` (キャンセル)
- バーコードスキャン: `jan_code_list` と照合、不一致時は警告
- 欠品ボタン: picked_qty=0 で更新、次アイテムへ
- 確定: picked_qty を送信、次アイテムへ自動遷移

#### 3.3.7 出荷 - タスク完了

```
┌─────────────────────────┐
│ ← タスク完了確認          │
├─────────────────────────┤
│ 📊 ピッキング結果         │
│                         │
│ 完了: 6品  欠品: 2品     │
│                         │
│ ⚠ 欠品アイテム           │
│ ┌─────────────────────┐ │
│ │ 商品X  予定:3 実績:1 │ │
│ │ 商品Y  予定:2 実績:0 │ │
│ └─────────────────────┘ │
│                         │
│ ✅ 完了アイテム           │
│ ┌─────────────────────┐ │
│ │ 商品A  2ケース ✓    │ │
│ │ 商品B  5バラ   ✓    │ │
│ │ ...                 │ │
│ └─────────────────────┘ │
│                         │
│ [タスク完了]              │
├─────────────────────────┤
│  📥入荷  📦出荷  ⚙設定  │
└─────────────────────────┘
```

- API: `POST /api/picking/tasks/{id}/complete`
- 欠品アイテムをハイライト表示
- 完了後はタスク一覧に戻る

---

## 4. 技術設計

### 4.1 ファイル構成（新規）

```
resources/js/handy-v2/
├── app.js                        # エントリポイント、Alpine.js初期化
├── stores/
│   ├── auth.js                   # 認証状態管理 ($store.auth)
│   ├── warehouse.js              # 倉庫状態管理 ($store.warehouse)
│   ├── incoming.js               # 入荷状態管理 ($store.incoming)
│   ├── picking.js                # 出荷状態管理 ($store.picking)
│   └── notification.js           # 通知管理 ($store.notification)
├── services/
│   ├── api-client.js             # 共通APIクライアント（認証・エラー・リトライ）
│   ├── auth-service.js           # 認証API呼び出し
│   ├── incoming-service.js       # 入荷API呼び出し
│   ├── picking-service.js        # 出荷API呼び出し
│   └── master-service.js         # マスタAPI呼び出し
├── components/
│   ├── login.js                  # ログインコンポーネント
│   ├── bottom-nav.js             # ボトムナビゲーション
│   ├── incoming-list.js          # 入荷予定一覧
│   ├── incoming-work.js          # 入荷作業
│   ├── incoming-history.js       # 入荷履歴
│   ├── picking-tasks.js          # タスク一覧
│   ├── picking-item.js           # ピッキング画面
│   ├── picking-complete.js       # 完了確認画面
│   ├── settings.js               # 設定画面
│   └── shared/
│       ├── product-card.js       # 商品カード共通
│       ├── quantity-input.js     # 数量入力共通
│       ├── search-input.js       # 検索入力共通
│       ├── barcode-input.js      # バーコード入力共通
│       ├── loading-overlay.js    # ローディング共通
│       └── toast.js              # トースト通知共通
└── utils/
    ├── format.js                 # 日付・数量フォーマット
    ├── storage.js                # localStorage ラッパー
    └── constants.js              # 定数定義

resources/views/handy-v2/
├── layouts/
│   └── app.blade.php             # 共通レイアウト（レスポンシブ対応）
├── app.blade.php                 # SPAエントリポイント
└── partials/
    ├── login.blade.php
    ├── home.blade.php
    ├── incoming/
    │   ├── list.blade.php
    │   ├── work.blade.php
    │   ├── result.blade.php
    │   └── history.blade.php
    ├── picking/
    │   ├── tasks.blade.php
    │   ├── item.blade.php
    │   ├── complete.blade.php
    │   └── result.blade.php
    └── settings.blade.php

public/
├── manifest.json                 # PWA マニフェスト
├── sw.js                         # Service Worker
└── icons/
    ├── icon-192.png
    └── icon-512.png

app/Http/Controllers/Handy/
└── HandyV2Controller.php         # 新アプリコントローラー
```

### 4.2 API クライアント設計

```javascript
// services/api-client.js
class ApiClient {
    constructor() {
        this.baseUrl = '/api';
        this.apiKey = document.querySelector('meta[name="api-key"]')?.content;
    }

    async request(method, path, data = null, options = {}) {
        const token = localStorage.getItem('wms_token');
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-API-Key': this.apiKey,
        };
        if (token) headers['Authorization'] = `Bearer ${token}`;

        const config = { method, headers };
        if (data && ['POST', 'PUT', 'PATCH'].includes(method)) {
            config.body = JSON.stringify(data);
        }

        const response = await fetch(`${this.baseUrl}${path}`, config);

        // 401 → トークン無効、ログイン画面へ
        if (response.status === 401) {
            localStorage.removeItem('wms_token');
            Alpine.store('auth').logout();
            throw new ApiError('UNAUTHORIZED', '認証エラー。再ログインしてください。');
        }

        const json = await response.json();

        if (!json.is_success) {
            throw new ApiError(json.code, json.result?.error_message || 'エラーが発生しました');
        }

        return json.result;
    }

    get(path, params = {}) {
        const query = new URLSearchParams(params).toString();
        return this.request('GET', query ? `${path}?${query}` : path);
    }

    post(path, data) { return this.request('POST', path, data); }
    put(path, data) { return this.request('PUT', path, data); }
    delete(path) { return this.request('DELETE', path); }
}
```

### 4.3 Alpine.js Store 設計

```javascript
// stores/auth.js
Alpine.store('auth', {
    token: localStorage.getItem('wms_token'),
    picker: null,
    isAuthenticated: false,

    async login(code, password) { ... },
    async checkAuth() { ... },
    logout() { ... },
});

// stores/warehouse.js
Alpine.store('warehouse', {
    warehouses: [],
    selected: null,          // { id, code, name, out_of_stock_option }

    async loadWarehouses() { ... },
    select(warehouse) { ... },
});

// stores/picking.js
Alpine.store('picking', {
    tasks: [],               // グルーピング済みタスクリスト
    currentTask: null,       // 実行中タスク
    currentItemIndex: 0,     // 現在のアイテムインデックス
    pickedItems: [],         // ピッキング済みアイテム

    async loadTasks() { ... },
    async startTask(taskId) { ... },
    async updateItem(itemResultId, pickedQty, pickedQtyType) { ... },
    async cancelItem(itemResultId) { ... },
    async completeTask(taskId) { ... },
});

// stores/incoming.js
Alpine.store('incoming', {
    schedules: [],
    currentItem: null,
    workItem: null,
    history: [],

    async loadSchedules(search) { ... },
    async startWork(scheduleId) { ... },
    async updateWork(id, data) { ... },
    async completeWork(id) { ... },
    async cancelWork(id) { ... },
    async loadHistory() { ... },
});
```

### 4.4 PWA設定

```json
// public/manifest.json
{
    "name": "酒丸WMS",
    "short_name": "WMS",
    "start_url": "/handy-v2",
    "display": "standalone",
    "orientation": "portrait",
    "background_color": "#ffffff",
    "theme_color": "#1e60b6",
    "icons": [
        { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png" },
        { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png" }
    ]
}
```

```javascript
// public/sw.js
const CACHE_NAME = 'wms-v1';
const STATIC_ASSETS = [
    '/handy-v2',
    '/build/assets/handy-v2-app.js',
    '/build/assets/handy-v2-app.css',
];

// Network First戦略（APIはキャッシュしない、静的アセットのみキャッシュ）
self.addEventListener('fetch', (event) => {
    if (event.request.url.includes('/api/')) return; // APIはパススルー
    event.respondWith(
        fetch(event.request)
            .then(response => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});
```

### 4.5 レスポンシブデザイン

```css
/* Tailwind v4 テーマ拡張 */
@theme {
    /* カラーパレット */
    --color-wms-primary: #1e60b6;
    --color-wms-incoming: #2563eb;
    --color-wms-picking: #ea580c;
    --color-wms-success: #16a34a;
    --color-wms-danger: #dc2626;
    --color-wms-warning: #d97706;

    /* レスポンシブ */
    --spacing-safe-bottom: env(safe-area-inset-bottom, 0px);
}

/* ベースレイアウト */
.wms-app {
    @apply min-h-screen flex flex-col bg-gray-50;
    padding-bottom: calc(56px + var(--spacing-safe-bottom));
}

/* ボトムナビ */
.wms-bottom-nav {
    @apply fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200
           flex justify-around items-center h-14 z-50;
    padding-bottom: var(--spacing-safe-bottom);
}

/* タッチターゲット最小サイズ */
.wms-touch-target {
    @apply min-h-[44px] min-w-[44px];
}
```

### 4.6 バーコードスキャン対応

```javascript
// components/shared/barcode-input.js
// ハードウェアスキャナはキーボードとして動作
// 入力速度で手入力とスキャンを判別
Alpine.data('barcodeInput', () => ({
    value: '',
    lastKeyTime: 0,
    buffer: '',

    handleInput(e) {
        const now = Date.now();
        const timeDiff = now - this.lastKeyTime;
        this.lastKeyTime = now;

        // スキャナは30ms以内に連続入力
        if (timeDiff < 30) {
            this.buffer += e.data || '';
        } else {
            this.buffer = e.data || '';
        }
    },

    handleKeydown(e) {
        if (e.key === 'Enter' && this.buffer.length >= 8) {
            // バーコードスキャン完了
            this.value = this.buffer;
            this.$dispatch('barcode-scanned', { code: this.buffer });
            this.buffer = '';
        }
    },

    // JANコード照合
    validateBarcode(janCodeList) {
        return janCodeList.includes(this.value);
    }
}));
```

---

## 5. ルーティング

### 5.1 Webルート（追加）

```php
// routes/web.php に追加
Route::get('/handy-v2/{any?}', [HandyV2Controller::class, 'index'])
    ->where('any', '.*')
    ->name('handy-v2');
```

### 5.2 コントローラー

```php
// app/Http/Controllers/Handy/HandyV2Controller.php
class HandyV2Controller extends Controller
{
    public function index(Request $request)
    {
        return view('handy-v2.app', [
            'apiKey' => config('api.keys.0', ''),
        ]);
    }
}
```

### 5.3 Viteエントリポイント追加

```javascript
// vite.config.js に追加
input: [
    // ... 既存エントリ
    'resources/js/handy-v2/app.js',
    'resources/css/handy-v2/app.css',
],
```

---

## 6. 実装フェーズ

### Phase 1: 基盤（1-2日）
- [ ] ディレクトリ構成作成
- [ ] HandyV2Controller + ルーティング
- [ ] 共通レイアウト (Blade + CSS)
- [ ] API クライアント (api-client.js)
- [ ] Alpine.js Store (auth, warehouse, notification)
- [ ] ログイン画面
- [ ] ホーム画面 + ボトムナビ + 倉庫選択
- [ ] Vite エントリポイント追加
- [ ] PWA manifest.json + Service Worker

### Phase 2: 入荷機能（1-2日）
- [ ] Alpine.js Store (incoming)
- [ ] 入荷サービス (incoming-service.js)
- [ ] 入荷予定一覧（検索 + 無限スクロール）
- [ ] 入荷作業画面（商品情報 + フォーム）
- [ ] ロケーション検索ドロップダウン
- [ ] 入荷確定 + 結果表示
- [ ] 入荷履歴（一覧 + 編集 + キャンセル）
- [ ] 共通コンポーネント（product-card, quantity-input, search-input）

### Phase 3: 出荷機能（1-2日）
- [ ] Alpine.js Store (picking)
- [ ] ピッキングサービス (picking-service.js)
- [ ] タスク一覧（コース別アコーディオン）
- [ ] ピッキング画面（アイテム別、数量入力）
- [ ] バーコードスキャン入力 + JAN照合
- [ ] 欠品表示・確認
- [ ] タスク完了画面（サマリー + 結果）

### Phase 4: 仕上げ（1日）
- [ ] エラーハンドリング統一
- [ ] ローディング状態の統一
- [ ] トースト通知の調整
- [ ] レスポンシブテスト（360px〜768px）
- [ ] PWA動作確認
- [ ] 既存Handyアプリとの共存確認

---

## 7. APIエンドポイント対応表

### 認証

| 画面 | API | Method | 用途 |
|------|-----|--------|------|
| ログイン | `/api/auth/login` | POST | ピッカー認証 |
| 設定 | `/api/auth/logout` | POST | ログアウト |
| 共通 | `/api/me` | GET | 認証状態確認 |

### マスタ

| 画面 | API | Method | 用途 |
|------|-----|--------|------|
| 倉庫選択 | `/api/master/warehouses` | GET | 倉庫一覧取得 |

### 入荷

| 画面 | API | Method | 用途 |
|------|-----|--------|------|
| 予定一覧 | `/api/incoming/schedules` | GET | 入荷予定検索 |
| 作業画面 | `/api/incoming/schedules/{id}` | GET | 予定詳細 |
| 作業画面 | `/api/incoming/locations` | GET | ロケーション検索 |
| 作業画面 | `/api/incoming/work-items` | POST | 作業開始 |
| 作業画面 | `/api/incoming/work-items/{id}` | PUT | 作業更新 |
| 作業画面 | `/api/incoming/work-items/{id}/complete` | POST | 入荷確定 |
| 履歴 | `/api/incoming/work-items` | GET | 履歴取得 |
| 履歴 | `/api/incoming/work-items/{id}` | DELETE | 作業キャンセル |

### ピッキング

| 画面 | API | Method | 用途 |
|------|-----|--------|------|
| タスク一覧 | `/api/picking/tasks` | GET | タスク取得（グルーピング済み） |
| タスク詳細 | `/api/picking/tasks/{id}` | GET | タスク詳細 |
| ピッキング | `/api/picking/items/{id}` | GET | アイテム詳細 |
| ピッキング | `/api/picking/tasks/{id}/start` | POST | タスク開始 |
| ピッキング | `/api/picking/tasks/{itemResultId}/update` | POST | 数量更新 |
| ピッキング | `/api/picking/tasks/{itemResultId}/cancel` | POST | アイテムキャンセル |
| 完了 | `/api/picking/tasks/{id}/complete` | POST | タスク完了 |

---

## 8. 制約

1. **バックエンド変更なし**: 既存API 19エンドポイントをそのまま使用
2. **FK禁止**: DB変更が必要な場合もFK制約は使用しない
3. **migrate:fresh/refresh/reset 禁止**: DB破壊コマンド禁止
4. **既存アプリとの共存**: `/handy/*` は既存アプリ、`/handy-v2/*` は新アプリ（並行運用）
5. **Filament 4 パターン準拠**: 管理画面側の変更がある場合はFilament 4仕様に従う

---

## 9. 対象ファイル

### 新規作成

```
app/Http/Controllers/Handy/HandyV2Controller.php
resources/js/handy-v2/app.js
resources/js/handy-v2/stores/auth.js
resources/js/handy-v2/stores/warehouse.js
resources/js/handy-v2/stores/incoming.js
resources/js/handy-v2/stores/picking.js
resources/js/handy-v2/stores/notification.js
resources/js/handy-v2/services/api-client.js
resources/js/handy-v2/services/auth-service.js
resources/js/handy-v2/services/incoming-service.js
resources/js/handy-v2/services/picking-service.js
resources/js/handy-v2/services/master-service.js
resources/js/handy-v2/components/login.js
resources/js/handy-v2/components/bottom-nav.js
resources/js/handy-v2/components/incoming-list.js
resources/js/handy-v2/components/incoming-work.js
resources/js/handy-v2/components/incoming-history.js
resources/js/handy-v2/components/picking-tasks.js
resources/js/handy-v2/components/picking-item.js
resources/js/handy-v2/components/picking-complete.js
resources/js/handy-v2/components/settings.js
resources/js/handy-v2/components/shared/product-card.js
resources/js/handy-v2/components/shared/quantity-input.js
resources/js/handy-v2/components/shared/search-input.js
resources/js/handy-v2/components/shared/barcode-input.js
resources/js/handy-v2/components/shared/loading-overlay.js
resources/js/handy-v2/components/shared/toast.js
resources/js/handy-v2/utils/format.js
resources/js/handy-v2/utils/storage.js
resources/js/handy-v2/utils/constants.js
resources/css/handy-v2/app.css
resources/views/handy-v2/layouts/app.blade.php
resources/views/handy-v2/app.blade.php
resources/views/handy-v2/partials/login.blade.php
resources/views/handy-v2/partials/home.blade.php
resources/views/handy-v2/partials/incoming/list.blade.php
resources/views/handy-v2/partials/incoming/work.blade.php
resources/views/handy-v2/partials/incoming/result.blade.php
resources/views/handy-v2/partials/incoming/history.blade.php
resources/views/handy-v2/partials/picking/tasks.blade.php
resources/views/handy-v2/partials/picking/item.blade.php
resources/views/handy-v2/partials/picking/complete.blade.php
resources/views/handy-v2/partials/picking/result.blade.php
resources/views/handy-v2/partials/settings.blade.php
public/manifest.json
public/sw.js
public/icons/icon-192.png
public/icons/icon-512.png
```

### 既存変更

```
routes/web.php                    # ルート追加
vite.config.js                    # エントリポイント追加
```

### 参照のみ

```
app/Http/Controllers/Api/*        # 既存APIコントローラー
app/Http/Middleware/ApiKeyAuth.php # API認証ミドルウェア
config/api.php                    # API設定
resources/js/handy/*              # 既存Handyアプリ（参考）
resources/views/handy/*           # 既存Bladeテンプレート（参考）
storage/api-docs/api-docs.json    # API仕様書
```

---

## 10. 確認事項

1. **デザイン方針**: 既存BHT-M60アプリのカラースキーム（入荷=青、出荷=橙）を踏襲するか、統一デザインにするか？
2. **バーコードスキャン**: ハードウェアスキャナ（キーボード入力）のみ対応で良いか？カメラスキャン（WebRTC）も必要か？
3. **オフライン要件**: 静的アセットキャッシュのみか、データのオフラインキャッシュ（IndexedDB）も必要か？
4. **既存アプリの廃止時期**: `/handy-v2` と `/handy` の並行運用期間は？
5. **ピッカーフィルタ**: 出荷タスク一覧で自分のタスクのみ表示するか、全タスク表示するか？
6. **PWAアイコン**: ロゴ画像の提供は可能か？
7. **テスト端末**: 動作確認用のAndroid端末のスペック（画面サイズ、OSバージョン）は？
