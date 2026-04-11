# Android端末向け Webアプリ v2 作業計画

## 前提

- BHT-M60専用ハンディWebアプリ（`/handy/*`）が稼働中（Alpine.js + Tailwind CSS）
- 入荷（incoming-app.js ~1040行）、出荷（outgoing-app.js ~400行）の2つのSPA
- 既存API 19エンドポイントは変更なしで再利用
- 新アプリは `/handy-v2/*` に配置し、既存アプリと並行運用
- **Landscapeベース**: 画面は横向き（1600x720px）を前提にレイアウト設計
- **ターゲット端末**: 6.75インチ、HD+ (720x1600px)、120Hz

### テスト環境
- ローカルDB: `localhost` / `root` / パスワードなし / `sakemaru_hana_prod`
- 出荷用売上データ: Filament管理画面 → TestDataGenerator → 「売上テストデータを生成」で生成

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | 基盤・ログイン・ホーム | コントローラー、ルート、Landscapeレイアウト、APIクライアント、認証Store、ログイン画面、ホーム+サイドナビ+倉庫選択、Vite設定 | `/handy-v2` アクセスでログイン→倉庫選択→ホーム画面が動作 |
| P2 | 入荷機能 | incoming Store/Service、予定一覧（検索+無限スクロール）、作業画面（ロケーション検索+数量入力）、確定+結果、履歴（編集+キャンセル） | 入荷フロー全体が動作（予定選択→作業→確定→履歴） |
| P3 | 出荷（ピッキング）機能 | picking Store/Service、タスク一覧（コース別）、ピッキング画面（バーコードスキャン+数量入力）、欠品確認、タスク完了 | ピッキングフロー全体が動作（タスク選択→ピッキング→完了） |
| P4 | 仕上げ・PWA・テスト | PWA設定、エラーハンドリング統一、ローディング/トースト、Landscape動作確認、既存アプリ共存確認 | PWA動作、1600x720px Landscape対応、既存 `/handy/*` に影響なし |

---

## P1: 基盤・ログイン・ホーム

### 目的

新アプリの基盤を構築し、認証→倉庫選択→ホーム画面（サイドナビ付き）までを動作させる。

### Landscape レイアウト設計方針

ターゲット端末は 6.75インチ HD+ (720x1600px) を横向き（Landscape）で使用。
有効表示領域: **1600x720px**（CSS論理ピクセルではおよそ **800x360dp** 相当）。

```
┌──────────────────────────────────────────────────┐
│ ヘッダー: 倉庫名 + ピッカー名                  48px │
├────────┬─────────────────────────────────────────┤
│        │                                         │
│ サイド  │         メインコンテンツ                  │
│ ナビ    │                                         │
│        │                                         │
│ 📥入荷  │                                         │
│ 📦出荷  │                                         │
│ ⚙設定  │                                         │
│        │                                         │
│  56px  │                                         │
├────────┴─────────────────────────────────────────┤
└──────────────────────────────────────────────────┘
```

- **サイドナビ（左）**: 56px幅、アイコン+ラベル縦並び、入荷/出荷/設定の3タブ
- **メインコンテンツ**: 残り幅全体（約744px）を使用
- **ヘッダー**: 48px高、倉庫名とピッカー名を左右に配置
- Landscape の横幅を活かし、作業画面では左右2カラムレイアウトが可能

### 実装対象ファイル

**新規作成:**

1. `app/Http/Controllers/Handy/HandyV2Controller.php`
   - `index()` メソッドのみ。`handy-v2.app` ビューを返す
   - API Keyをビューのmetaタグへ渡す

2. `resources/views/handy-v2/layouts/app.blade.php`
   - Landscape最適化レイアウト（1600x720px ベース）
   - `<meta name="api-key">` でAPI Keyを埋め込み
   - Viteでhandy-v2のJS/CSSを読み込み
   - `<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">`
   - `<meta name="screen-orientation" content="landscape">`

3. `resources/views/handy-v2/app.blade.php`
   - SPAエントリポイント。layouts/app.blade.phpを継承
   - Alpine.jsで `currentScreen` による画面切り替え
   - 全パーシャルを `@include` で読み込み
   - サイドナビ（左56px: 入荷/出荷/設定タブ、アイコン+ラベル縦並び）

4. `resources/views/handy-v2/partials/login.blade.php`
   - ピッカーコード + パスワード入力
   - ログインボタン
   - 端末ID表示

5. `resources/views/handy-v2/partials/home.blade.php`
   - ヘッダー（倉庫名 + ピッカー名）
   - 倉庫選択モーダル（初回アクセス時表示）
   - タブ別コンテンツエリア

6. `resources/views/handy-v2/partials/settings.blade.php`
   - 倉庫切替ボタン
   - ピッカー情報表示
   - ログアウトボタン

7. `resources/css/handy-v2/app.css`
   - Tailwind v4 テーマ拡張（カラーパレット、safe-area）
   - `.wms-app`, `.wms-side-nav`, `.wms-touch-target` ユーティリティ
   - Landscapeベースレイアウト（サイドナビ + ヘッダー + メインコンテンツ）
   - PWA manifest で `orientation: landscape` 指定

8. `resources/js/handy-v2/app.js`
   - Alpine.js エントリポイント
   - 全Store/Component の登録
   - 自動ログインチェック（トークン存在時 → `/api/me` 検証）

9. `resources/js/handy-v2/services/api-client.js`
   - 共通APIクライアント（fetch wrapper）
   - 認証ヘッダー自動付与（`X-API-Key` + `Authorization: Bearer`）
   - レスポンス形式: `{ is_success, code, result }` のパース
   - 401処理（トークンクリア → ログイン画面へ）
   - ApiError クラス

10. `resources/js/handy-v2/services/auth-service.js`
    - `login(code, password)`, `logout()`, `getMe()`

11. `resources/js/handy-v2/services/master-service.js`
    - `getWarehouses()`

12. `resources/js/handy-v2/stores/auth.js`
    - `token`, `picker`, `isAuthenticated`
    - `login()`, `checkAuth()`, `logout()`

13. `resources/js/handy-v2/stores/warehouse.js`
    - `warehouses`, `selected`
    - `loadWarehouses()`, `select(warehouse)`
    - `localStorage` で選択倉庫を永続化

14. `resources/js/handy-v2/stores/notification.js`
    - `show`, `message`, `type`, `duration`
    - `success()`, `error()`, `warning()`, `info()`
    - 自動消去タイマー

15. `resources/js/handy-v2/components/shared/toast.js`
    - トースト通知UIコンポーネント
    - notification Store と連携

16. `resources/js/handy-v2/components/shared/loading-overlay.js`
    - 全画面ローディングオーバーレイ

17. `resources/js/handy-v2/utils/storage.js`
    - localStorage ラッパー（get/set/remove、JSONパース）

18. `resources/js/handy-v2/utils/constants.js`
    - 定数定義（画面名、ステータスラベル、カラー等）

19. `resources/js/handy-v2/utils/format.js`
    - 日付フォーマット、数量フォーマット

**既存変更:**

20. `routes/web.php`
    - `Route::get('/handy-v2/{any?}', [HandyV2Controller::class, 'index'])->where('any', '.*')->name('handy-v2');` 追加

21. `vite.config.js`
    - `resources/js/handy-v2/app.js` と `resources/css/handy-v2/app.css` をinputに追加

### 実装の参考

- 既存 `resources/js/handy/login-app.js` — ログインフローの参考
- 既存 `resources/js/handy/home-app.js` — 倉庫選択フローの参考
- 既存 `resources/views/handy/layouts/app.blade.php` — レイアウトの参考（ただしBHT-M60固定幅は使わない）
- 既存 `app/Http/Controllers/Handy/HandyController.php` — コントローラーの参考

### 完了条件

1. `npm run build` が成功する
2. `/handy-v2` にアクセスするとログイン画面が表示される
3. ログイン → 倉庫選択モーダル → ホーム画面（サイドナビ付き）が動作する
4. 設定タブからログアウト・倉庫切替ができる
5. 既存 `/handy/*` が影響を受けない

---

## P2: 入荷機能

### 目的

入荷予定の検索・選択 → 入荷作業（ロケーション選択、数量入力、賞味期限入力） → 確定 → 履歴閲覧・編集・キャンセルの全フローを実装。

### 実装対象ファイル

**新規作成:**

1. `resources/js/handy-v2/services/incoming-service.js`
   - `getSchedules(warehouseId, search, page)` — 入荷予定一覧取得
   - `getScheduleDetail(scheduleId)` — 予定詳細取得
   - `getLocations(warehouseId, search)` — ロケーション検索
   - `createWorkItem(data)` — 作業開始
   - `updateWorkItem(id, data)` — 作業更新
   - `completeWorkItem(id)` — 入荷確定
   - `getWorkItems(warehouseId)` — 履歴取得
   - `deleteWorkItem(id)` — 作業キャンセル

2. `resources/js/handy-v2/stores/incoming.js`
   - `schedules`, `currentSchedule`, `workItem`, `history`
   - `searchQuery`, `page`, `hasMore`（無限スクロール用）
   - `loadSchedules()`, `loadMore()`, `startWork()`, `updateWork()`, `completeWork()`, `cancelWork()`, `loadHistory()`

3. `resources/js/handy-v2/components/incoming-list.js`
   - 入荷予定一覧（商品カード表示）
   - バーコード/商品コード/商品名検索
   - 無限スクロール（IntersectionObserver）
   - 残数量/予定数量 + プログレスバー表示
   - タップで作業画面へ遷移

4. `resources/js/handy-v2/components/incoming-work.js`
   - 商品情報表示（画像、コード、JAN、容量等）
   - スケジュール情報（倉庫、予定数、入荷済数）
   - 賞味期限入力（date input）
   - ロケーション検索ドロップダウン（インクリメンタル検索）
   - 数量入力（+/- ボタン + 直接入力）
   - 入荷確定ボタン

5. `resources/js/handy-v2/components/incoming-history.js`
   - 入荷履歴一覧
   - 編集（タップで作業画面へ）
   - キャンセル（確認ダイアログ付き）

6. `resources/views/handy-v2/partials/incoming/list.blade.php`
   - 検索バー + 商品カードリスト

7. `resources/views/handy-v2/partials/incoming/work.blade.php`
   - 商品情報 + 入力フォーム

8. `resources/views/handy-v2/partials/incoming/result.blade.php`
   - 入荷確定結果表示

9. `resources/views/handy-v2/partials/incoming/history.blade.php`
   - 履歴リスト

10. `resources/js/handy-v2/components/shared/product-card.js`
    - 商品カード共通コンポーネント（画像、名前、コード、残数表示）

11. `resources/js/handy-v2/components/shared/quantity-input.js`
    - 数量入力共通（+/- ボタン、直接入力、min/max制約）

12. `resources/js/handy-v2/components/shared/search-input.js`
    - 検索入力共通（デバウンス付き、クリアボタン）

### 実装の参考

- 既存 `resources/js/handy/incoming-app.js` — 入荷フロー全体の参考（画面遷移、API呼び出し、バリデーション）
- 既存 `resources/views/handy/incoming/partials/*` — UI構成の参考

### 完了条件

1. 入荷タブで予定一覧が表示される（検索・無限スクロール動作）
2. 商品タップ → 作業画面でロケーション選択・数量入力ができる
3. 入荷確定 → 結果表示 → 一覧に戻れる
4. 履歴タブで過去の作業が閲覧・キャンセルできる
5. 既存入荷APIとの通信が正常に動作する

---

## P3: 出荷（ピッキング）機能

### 目的

ピッキングタスク一覧（コース別グルーピング） → ピッキング画面（アイテム別、バーコードスキャン対応） → 欠品確認 → タスク完了の全フローを実装。

### 実装対象ファイル

**新規作成:**

1. `resources/js/handy-v2/services/picking-service.js`
   - `getTasks(warehouseId, pickerId)` — タスク一覧取得
   - `getTaskDetail(taskId)` — タスク詳細取得
   - `getItemDetail(itemId)` — アイテム詳細取得
   - `startTask(taskId)` — タスク開始
   - `updateItem(itemResultId, data)` — 数量更新
   - `cancelItem(itemResultId)` — アイテムキャンセル
   - `completeTask(taskId)` — タスク完了

2. `resources/js/handy-v2/stores/picking.js`
   - `tasks` — グルーピング済みタスクリスト
   - `currentTask`, `currentItemIndex`, `pickedItems`
   - `loadTasks()`, `startTask()`, `updateItem()`, `cancelItem()`, `completeTask()`
   - 次アイテム自動遷移ロジック

3. `resources/js/handy-v2/components/picking-tasks.js`
   - タスク一覧（コース別アコーディオン表示）
   - ステータスバッジ（PENDING灰/PICKING_READY青/PICKING橙/COMPLETED緑/SHORTAGE赤）
   - プログレスバー（ピッキング進捗）
   - 開始/再開ボタン

4. `resources/js/handy-v2/components/picking-item.js`
   - 商品情報表示（画像、名前、JAN、容量）
   - 予定数量・ケース入数表示
   - 数量入力（+/- ボタン + 直接入力）
   - 単位選択（ケース/バラ）
   - バーコードスキャン入力 + JAN照合（一致/不一致表示）
   - 伝票No・配送先表示
   - アクションボタン: 欠品（picked_qty=0）、スキップ、確定
   - 進捗カウンター（n/m品）

5. `resources/js/handy-v2/components/picking-complete.js`
   - ピッキング結果サマリー
   - 完了アイテム一覧（数量+単位）
   - 欠品アイテムハイライト（予定 vs 実績）
   - タスク完了ボタン

6. `resources/js/handy-v2/components/shared/barcode-input.js`
   - ハードウェアスキャナ対応（キーボード入力として受付）
   - 入力速度判別（30ms以内 → スキャン）
   - Enter キーでスキャン完了
   - `barcode-scanned` イベント発火
   - JANコードリスト照合機能

7. `resources/views/handy-v2/partials/picking/tasks.blade.php`
   - タスクカードリスト + アコーディオン

8. `resources/views/handy-v2/partials/picking/item.blade.php`
   - ピッキング作業画面

9. `resources/views/handy-v2/partials/picking/complete.blade.php`
   - タスク完了確認画面

10. `resources/views/handy-v2/partials/picking/result.blade.php`
    - ピッキング結果表示

### 実装の参考

- 既存 `resources/js/handy/outgoing-app.js` — ピッキングフローの参考
- 既存 `resources/views/handy/outgoing.blade.php` — 出荷UIの参考
- 仕様書の `3.3.5` ~ `3.3.7` — 画面詳細設計
- 仕様書の `4.6` — バーコードスキャン対応仕様

### 完了条件

1. 出荷タブでタスク一覧がコース別に表示される
2. タスク開始 → アイテム別ピッキング画面が動作する
3. バーコードスキャン入力でJAN照合が動作する
4. 数量入力 → 確定で次アイテムへ自動遷移する
5. 欠品ボタンで picked_qty=0 送信される
6. 全アイテム処理後 → 完了確認画面 → タスク完了が動作する
7. 既存ピッキングAPIとの通信が正常に動作する

---

## P4: 仕上げ・PWA・テスト

### 目的

PWA対応、エラーハンドリング統一、レスポンシブテスト、既存アプリ共存確認。

### 実装対象ファイル

**新規作成:**

1. `public/manifest.json`
   - PWAマニフェスト（name, short_name, start_url, display, icons等）

2. `public/sw.js`
   - Service Worker（Network First戦略）
   - 静的アセットキャッシュ（APIはパススルー）
   - オフライン時のフォールバック

3. `public/icons/icon-192.png`, `public/icons/icon-512.png`
   - PWAアイコン（シンプルなWMSロゴ）

**既存変更:**

4. `resources/views/handy-v2/layouts/app.blade.php`
   - `<link rel="manifest" href="/manifest.json">` 追加
   - Service Worker登録スクリプト追加
   - `<meta name="theme-color">` 追加

### 実装内容

1. **PWA設定**
   - manifest.json 作成（standalone, **landscape**）
   - Service Worker 登録・キャッシュ戦略設定
   - オフライン検知UI（`navigator.onLine` 監視、トースト通知）

2. **エラーハンドリング統一**
   - APIエラーの統一表示（notification Store経由）
   - ネットワークエラー時のリトライUI
   - 入力バリデーションエラーの表示

3. **ローディング状態統一**
   - API呼び出し中のローディングオーバーレイ
   - ボタンのdisabled + ローディングスピナー

4. **Landscape動作確認**
   - ターゲット: 1600x720px（6.75インチ HD+ Landscape）
   - Chrome DevToolsでカスタムデバイス設定して確認
   - サイドナビ + ヘッダー + メインコンテンツの配置確認
   - 入荷作業画面・ピッキング画面の2カラムレイアウト確認

5. **既存アプリ共存確認**
   - `/handy/*` の動作に影響がないこと
   - Vite ビルドが既存エントリに影響しないこと

### 完了条件

1. `npm run build` が成功し、既存アプリのビルドにも影響しない
2. PWA: Chromeで「ホーム画面に追加」が利用可能（Landscape）
3. オフライン: ネットワーク切断時にオフライン警告が表示される
4. Landscape: 1600x720px で全画面が正しく表示される
5. `/handy/*` の既存アプリが正常に動作する

---

## 制約（厳守）

1. **バックエンド変更禁止**: 既存API 19エンドポイントの修正・追加不可
2. **FK禁止**: DB変更が必要な場合もFK制約は使用しない
3. **migrate:fresh/refresh/reset禁止**: DB破壊コマンド禁止
4. **既存アプリ共存**: `/handy/*` のルート・ビュー・JSファイルを変更しない
5. **Alpine.js + Tailwind CSS 4**: フレームワーク変更なし
6. **API認証方式維持**: `X-API-Key` + `Bearer token` のヘッダー方式

## 全体完了条件

1. `/handy-v2` で全機能（ログイン→入荷→出荷→設定）が動作する
2. 6.75インチ HD+ Android端末（Landscape 1600x720px）で快適に操作できる
3. PWA対応（ホーム画面追加、Landscape固定、基本的なオフライン耐性）
4. 既存 `/handy/*` アプリに影響がない
5. `npm run build` が成功する
