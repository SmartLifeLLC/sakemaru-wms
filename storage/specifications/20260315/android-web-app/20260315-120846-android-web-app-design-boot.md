# Work Plan: android-web-app-v2

- **ID**: android-web-app-v2
- **作成日**: 2026-03-15
- **最終更新**: 2026-03-15
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260315/android-web-app/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260315-120846-android-web-app-design-boot.md）
2. 20260315-120846-android-web-app-design-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

Android端末（6.75インチ、HD+ 720x1600px）向けピッキング・入荷Webアプリの新規構築。**Landscapeベース（1600x720px）** で設計。既存BHT-M60専用アプリ（`/handy/*`）を参考に、新SPA（`/handy-v2/*`）をAlpine.js + Tailwind CSS 4で実装。バックエンドAPI変更なし。

## 重要な設計制約

- **バックエンドAPI変更禁止**: 既存19エンドポイントをそのまま使用
- **FK禁止**: DB変更が必要な場合もFK制約は使用しない
- **migrate:fresh/refresh/reset禁止**: DB破壊コマンド禁止
- **既存アプリ共存**: `/handy/*` は既存、`/handy-v2/*` は新アプリ（並行運用）
- **Alpine.js継続**: フレームワーク変更なし、コンポーネント分割を改善
- **PWA対応**: manifest.json + Service Worker（Network First戦略）
- **Landscapeベース**: 画面は横向き（1600x720px）を前提にレイアウト設計
- **ターゲット端末**: 6.75インチ、HD+ (720x1600px)、120Hz

## 対象ファイル

### 新規作成

**コントローラー・ルート:**
- `app/Http/Controllers/Handy/HandyV2Controller.php`

**JavaScript（Alpine.js SPA）:**
- `resources/js/handy-v2/app.js` — エントリポイント
- `resources/js/handy-v2/stores/` — auth, warehouse, incoming, picking, notification
- `resources/js/handy-v2/services/` — api-client, auth, incoming, picking, master
- `resources/js/handy-v2/components/` — login, bottom-nav, incoming-*, picking-*, settings
- `resources/js/handy-v2/components/shared/` — product-card, quantity-input, search-input, barcode-input, loading-overlay, toast
- `resources/js/handy-v2/utils/` — format, storage, constants

**Blade テンプレート:**
- `resources/views/handy-v2/layouts/app.blade.php` — 共通レイアウト
- `resources/views/handy-v2/app.blade.php` — SPAエントリポイント
- `resources/views/handy-v2/partials/` — login, home, incoming/*, picking/*, settings

**CSS:**
- `resources/css/handy-v2/app.css` — レスポンシブスタイル

**PWA:**
- `public/manifest.json`, `public/sw.js`, `public/icons/icon-192.png`, `public/icons/icon-512.png`

### 既存変更
- `routes/web.php` — `/handy-v2/{any?}` ルート追加
- `vite.config.js` — エントリポイント追加

### 参照のみ（変更禁止）
- `app/Http/Controllers/Api/*` — 既存APIコントローラー
- `app/Http/Middleware/ApiKeyAuth.php` — API認証ミドルウェア
- `config/api.php` — API設定
- `resources/js/handy/*` — 既存Handyアプリ（参考）
- `resources/views/handy/*` — 既存Bladeテンプレート（参考）
- `storage/api-docs/api-docs.json` — API仕様書

## テスト環境

### ローカルDB
- Host: `localhost`
- User: `root`
- Password: なし
- Database: `sakemaru_hana_prod`

### テストデータ
- ローカルDBの既存データを利用
- 出荷用の売上データ: Filament管理画面 → TestDataGenerator → 「売上テストデータを生成」で生成
- `php artisan wms:generate-test-data` でWMSテストデータ生成可能

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 基盤・ログイン・ホーム | 完了 | 2026-03-15 | |
| P2: 入荷機能 | 完了 | 2026-03-15 | |
| P3: 出荷（ピッキング）機能 | 完了 | 2026-03-15 | |
| P4: 仕上げ・PWA・テスト | 完了 | 2026-03-15 | |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 既存APIエンドポイント
- 認証: `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/me`
- マスタ: `GET /api/master/warehouses`
- 入荷: `GET /api/incoming/schedules`, `GET /api/incoming/schedules/{id}`, `GET /api/incoming/locations`, `POST /api/incoming/work-items`, `PUT /api/incoming/work-items/{id}`, `POST /api/incoming/work-items/{id}/complete`, `DELETE /api/incoming/work-items/{id}`
- ピッキング: `GET /api/picking/tasks`, `GET /api/picking/tasks/{id}`, `GET /api/picking/items/{id}`, `POST /api/picking/tasks/{id}/start`, `POST /api/picking/tasks/{itemResultId}/update`, `POST /api/picking/tasks/{itemResultId}/cancel`, `POST /api/picking/tasks/{id}/complete`

### 既存アプリの技術パターン（参考）
- APIクライアント: `X-API-Key` + `Authorization: Bearer token`
- レスポンス形式: `{ is_success, code, result: { data, message } }`
- 認証トークン: `localStorage` 保存
- 画面遷移: `currentScreen` state変数で管理
- 無限スクロール: 50件/ページ

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチで作業）
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: 基盤・ログイン・ホーム
- 完了日: 2026-03-15
- 実績:
  - HandyV2Controller.php 作成（SPA用 catch-all）
  - routes/web.php に `/handy-v2/{any?}` ルート追加
  - vite.config.js にエントリポイント追加
  - CSS: Landscape ベースレイアウト（サイドナビ56px + ヘッダー48px + メイン）
  - JS: api-client, auth-service, master-service（サービス層）
  - JS: auth, warehouse, notification（ストア層）
  - JS: app.js エントリポイント（Alpine.js SPA）
  - Blade: layouts/app, app（SPA shell）, login, home, settings パーシャル
  - `npm run build` 成功確認済み

### P2: 入荷機能
- 完了日: 2026-03-15
- 実績:
  - JS: incoming-service.js（8 APIメソッド）
  - JS: incoming store（スケジュール一覧、作業フォーム、履歴、ロケーション検索）
  - JS: app.js に入荷フロー全メソッド統合（検索デバウンス、作業開始、確定、履歴、キャンセル）
  - Blade: incoming/list（検索バー、商品カード、無限スクロール、プログレスバー）
  - Blade: incoming/work（2カラムLandscape: 左=商品情報、右=入力フォーム）
  - Blade: incoming/result（確定結果サマリー）
  - Blade: incoming/history（履歴一覧、編集・キャンセルボタン、確認ダイアログ）
  - app.blade.php に5画面分のルーティング追加
  - `npm run build` 成功確認済み（12.64 kB）

### P3: 出荷（ピッキング）機能
- 完了日: 2026-03-15
- 実績:
  - JS: picking-service.js（7 APIメソッド: getTasks, startTask, updateItem, cancelItem, completeTask等）
  - JS: picking store（タスクグループ、アコーディオン、アイテム遷移、バーコード照合、欠品処理）
  - JS: barcode-input.js（ハードウェアスキャナ対応、30ms閾値、Enterキー検出）
  - JS: app.js にピッキングフロー全メソッド統合（タスク開始、アイテム確定、欠品、スキップ、完了）
  - Blade: picking/tasks（コース別アコーディオン、ステータスバッジ、プログレスバー）
  - Blade: picking/item（2カラムLandscape: 左=商品情報、右=バーコード+数量入力+アクション）
  - Blade: picking/complete（結果サマリー、欠品ハイライト、未処理警告）
  - Blade: picking/result（完了結果）
  - app.blade.php のプレースホルダを4画面に置換
  - `npm run build` 成功確認済み（18.27 kB）

### P4: 仕上げ・PWA・テスト
- 完了日: 2026-03-15
- 実績:
  - PWA: manifest.json（standalone, landscape, テーマカラー #1e293b）
  - PWA: sw.js（Network First戦略、静的アセットキャッシュ、APIパススルー）
  - PWA: アイコン icon-192.png / icon-512.png 生成
  - レイアウト更新: manifest link, apple-touch-icon, SW登録スクリプト追加
  - オフライン検知: online/offline イベント → notification store連携
  - ビルド確認: `npm run build` 成功、既存handy app ハッシュ変更なし（共存OK）
