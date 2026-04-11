# Work Plan: split-view-iframe

- **ID**: split-view-iframe
- **作成日**: 2026-04-06
- **最終更新**: 2026-04-06
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/20260406/20260406-204157-split-view-iframe/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260406-204157-split-view-iframe-boot.md）
2. 20260406-204157-split-view-iframe-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから 20260406-204157-split-view-iframe-plan.md の該当セクションを読んで作業再開

## 概要

全ページ共通の Split View（iframe）機能を実装する。リンクをクリックすると画面右半分に iframe で対象ページを表示し、ドラッグバーで左右幅を調整可能。解説ページのリンクを先行適用。

## 重要な設計制約

- FK禁止（CLAUDE.md準拠）
- DB破壊コマンド禁止
- 既存ページの見た目・動作は Split View 非表示時に変化させないこと
- Tailwind CSS 4 動的クラスは `@source inline()` で対応
- iframe は同一オリジンのみ
- モバイル（lg未満=1024px未満）では通常リンク遷移にフォールバック
- ページ遷移時（livewire:navigated）に Split View を自動クローズ

## 対象ファイル

### 新規作成
- `resources/views/components/split-link.blade.php` — Split View 用リンクコンポーネント

### 既存変更
- `resources/views/vendor/filament-panels/components/layout/base.blade.php` — Alpine store 登録 + livewire:navigated イベント
- `resources/views/vendor/filament-panels/components/layout/index.blade.php` — fi-main-ctn 内を左右分割構造に変更
- `resources/views/filament/pages/auto-order-guide.blade.php` — リンクを `<x-split-link>` に変更
- `resources/css/filament/admin/theme.css` — ドラッグ中スタイル追加

### 参照のみ（変更禁止）
- `app/Filament/Pages/AutoOrderGuide.php` — ページクラス
- `app/Providers/Filament/AdminPanelProvider.php` — パネル設定

## テストデータ

なし（UIのみの変更）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Alpine Store + base.blade.php | 完了 | 2026-04-06 | Alpine store + body :class + livewire:navigated |
| P2: レイアウト分割（index.blade.php） | 完了 | 2026-04-06 | fi-main-ctn 内を左右分割、リサイズバー + iframe パネル |
| P3: split-link コンポーネント + CSS | 完了 | 2026-04-06 | split-link.blade.php 新規作成 + @source inline 追加 |
| P4: 解説ページリンク変更 | 完了 | 2026-04-06 | 全18箇所のリンクを <x-split-link> に変更 |
| P5: 動作確認 + 微調整 | 完了 | 2026-04-06 | npm run build 成功、view:cache 成功 |
| P6: URL変更 + ファイルリネーム | 未着手 | - | auto-order-guide → order-guide |
| P7: 解説ページデザイン改修 | 未着手 | - | Nav Panel追加 + レスポンシブ |
| P8: 最終動作確認 | 未着手 | - | |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### レイアウト構造（参照用）

**base.blade.php のスクリプト挿入ポイント:**
- L136: `@filamentScripts(withCore: true)` の後にAlpine store登録スクリプトを配置
- L111: 既存の `livewire:navigated` イベントリスナー（darkMode用）あり → 同様のパターンで追加

**index.blade.php の分割対象:**
- L77-L116: `.fi-main-ctn` div 内に `CONTENT_BEFORE` → `<main>` → `CONTENT_AFTER` → `FOOTER` 
- この内部を flex コンテナで左右分割する

**body タグ:**
- L118-L127: `<body>` に `:class` バインディングを追加する必要あり（ドラッグ中の select-none）

### Git ブランチ
- 作業ブランチ: (実施後に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: Alpine Store + base.blade.php
- 完了日: 2026-04-06
- 実績:
  - `base.blade.php` に `$store.splitView` Alpine store 登録（alpine:init イベント内）
  - `data-navigate-once` で重複登録防止
  - `livewire:navigated` でページ遷移時に自動クローズ
  - `<body>` に `x-data` + `:class` でドラッグ中 select-none 適用

### P2: レイアウト分割（index.blade.php）
- 完了日: 2026-04-06
- 実績:
  - `fi-main-ctn` 内に Split View コンテナ（flex）を追加
  - 左パネル（既存コンテンツ）+ リサイズバー + 右パネル（iframe）の3分割構造
  - リサイズバー: mousemove/mouseup で ratio 制御（20%〜80%）
  - iframe ヘッダー: タイトル表示 + 新規タブ + 閉じるボタン
  - ドラッグ中の iframe に pointer-events-none 適用

### P3: split-link コンポーネント + CSS
- 完了日: 2026-04-06
- 実績:
  - `resources/views/components/split-link.blade.php` 新規作成
  - モバイル（1024px未満）で通常遷移にフォールバック
  - `$attributes->merge()` でカスタムクラスをサポート
  - theme.css の `@source inline()` に `select-none cursor-col-resize pointer-events-none` 追加
  - `@source` に `components/**/*.blade.php` パス追加

### P4: 解説ページリンク変更
- 完了日: 2026-04-06
- 実績:
  - 概要タブ: カードリンク7箇所 → `<x-split-link>`
  - 発注の流れタブ: Step内リンク6箇所 → `<x-split-link>`
  - メニュー詳細タブ: 「画面を開く →」4箇所 + 「→」3箇所 → `<x-split-link>`
  - 設定タブ: 設定カードリンク（foreach内）→ `<x-split-link>`
  - 残存 `<a href="{{ route(` : 0件（全変換完了）

### P5: 動作確認 + 微調整
- 完了日: 2026-04-06
- 実績:
  - `npm run build` 成功（エラーなし）
  - `php artisan view:cache` 成功（Blade構文エラーなし）

### P6: URL変更 + ファイルリネーム
- 完了日: -
- 実績:
  - (完了後に記入)

### P7: 解説ページデザイン改修
- 完了日: -
- 実績:
  - (完了後に記入)

### P8: 最終動作確認
- 完了日: -
- 実績:
  - (完了後に記入)
