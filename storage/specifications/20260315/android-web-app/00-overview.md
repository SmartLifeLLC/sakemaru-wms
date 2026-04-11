# Android Handy ウェブアプリ化 仕様書

> 作成日: 2026-03-15
> 目的: Android ハンディアプリの出荷(出庫)・入荷(入庫)機能をWebアプリ(SPA)として再実装するための仕様書

---

## 1. 目標

Denso BHT-M60 ハンディターミナル向けの Android ネイティブアプリ（Jetpack Compose）を、
**Laravel Blade + Alpine.js SPA** としてウェブで同等の機能を提供する。

### 既存Web実装状況

| 機能 | Web実装 | 状態 |
|------|---------|------|
| ログイン | `/handy/login` | 実装済 |
| ホーム（メニュー） | `/handy/home` | 実装済 |
| 入荷（入庫） | `/handy/incoming` | 実装済（SPA完成） |
| **出荷（出庫）** | `/handy/outgoing` | **簡易版のみ（Android機能の半分以下）** |

### 今回の作業対象

1. **出荷(出庫) Web SPA の Android機能完全移植**
   - 現在の簡易版 `outgoing.blade.php` + `outgoing-app.js` を拡張
   - Android版 P20/P21/P22 の全機能をWeb化
2. **入荷(入庫) Web SPA のテスト・検証**
   - 既存実装の動作確認

---

## 2. ドキュメント構成

| ファイル | 内容 |
|---------|------|
| `00-overview.md` | 本ファイル（全体概要） |
| `01-page-structure.md` | ページ構成と画面遷移 |
| `02-api-reference.md` | API仕様（利用方法・エンドポイント・認証） |
| `03-outgoing-spec.md` | 出荷(出庫)機能の詳細仕様 |
| `04-incoming-spec.md` | 入荷(入庫)機能の詳細仕様 |
| `05-design-html-mock.md` | UI/UXデザイン・HTMLモック |
| `06-implementation-guide.md` | 実装ガイド（技術スタック・共通パターン） |

---

## 3. 技術スタック

### フロントエンド
- **Alpine.js** — リアクティブUI（x-data, x-show, template等）
- **Tailwind CSS** (via Vite) — ユーティリティファーストCSS
- **Phosphor Icons** — アイコン
- **Vanilla JS fetch** — API通信

### バックエンド
- **Laravel Blade** — テンプレートエンジン（レイアウト + パーシャル）
- **Laravel Sanctum** — Bearer Token認証
- **X-API-Key** — API認証ヘッダー
- **Vite** — アセットバンドル

### デバイス最適化
- 画面幅: **480px** 固定（BHT-M60 WVGA最適化）
- タッチ操作: 最小ボタンサイズ 44px
- フォントサイズ: CSS変数 `--font-xs`(13px) 〜 `--font-3xl`(31px)
