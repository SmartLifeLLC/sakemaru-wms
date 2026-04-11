# Work Plan: deploy-split-view-all-systems

- **ID**: deploy-split-view-all-systems
- **作成日**: 2026-04-07
- **最終更新**: 2026-04-07
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260407/20260407-073743-deploy-split-view-all-systems/`

## セッション再開手順

1. このファイルを読む
2. `20260407-073743-deploy-split-view-all-systems-plan.md` を読む
3. 下記「進捗」テーブルで現在の Phase を確認
4. 「Phase完了記録」で完了済み Phase の実績を確認
5. 「作業中コンテキスト」で途中データを確認
6. 未完了の最初の Phase から plan.md の該当セクションを読んで作業再開

## 概要

WMS で実装済みの Split View（iframe 画面分割）を酒丸シリーズ全5システムに展開する。各システムの vendor レイアウト・MegaMenu・CSS を変更。

## 重要な設計制約

- Alpine ストアは `@filamentScripts` の **前** に配置する（`alpine:init` タイミング）
- `data-navigate-once` で Livewire SPA ナビゲーション時の重複実行防止
- 同一オリジン iframe では `?splitView=1` でトップメニュー非表示
- 各システムの既存カスタマイズ（パネル分岐等）を壊さない
- trade の store/gift-store パネルは対象外

## 対象システムと状態

| # | システム | base.blade.php | index.blade.php | theme.css | MegaMenu 外部リンク処理 |
|---|---------|---------------|-----------------|-----------|----------------------|
| 1 | delivery | 既存（標準） | 既存（標準） | `admin/theme.css` | なし |
| 2 | insights | 既存（標準） | 既存（標準） | `admin/theme.css` | `external` フラグ + `buildSakemaruSeriesTab()` |
| 3 | documents | **未作成** | 既存（標準+パネル分岐） | `admin/theme.css` | なし |
| 4 | search | **未作成** | 既存（標準） | `search/theme.css` | `openInNewTab` フラグ + 外部システムタブ |
| 5 | trade | **標準形式に統一済み** | **標準形式に統一済み** | `admin/theme.css` | なし |

## 対象ファイル（各システム共通パターン）

### 新規作成
- `resources/views/components/split-link.blade.php`

### 既存変更
- `resources/views/vendor/filament-panels/components/layout/base.blade.php`（なければ新規作成）
- `resources/views/vendor/filament-panels/components/layout/index.blade.php`
- `app/Livewire/MegaMenu.php`
- `resources/views/livewire/mega-menu.blade.php`
- `resources/css/filament/{panel}/theme.css`

### 参照のみ（WMS リファレンス）
- `/Users/jungsinyu/Projects/sakemaru-wms/resources/views/vendor/filament-panels/components/layout/base.blade.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/resources/views/vendor/filament-panels/components/layout/index.blade.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/resources/views/livewire/mega-menu.blade.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/app/Livewire/MegaMenu.php`
- `/Users/jungsinyu/Projects/sakemaru-wms/resources/views/components/split-link.blade.php`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: delivery Split View 導入 | 完了 | 2026-04-07 | 6ファイル変更・構文チェックOK |
| P2: insights Split View 導入 | 完了 | 2026-04-07 | base新規作成、external→openInSplitView、構文チェックOK |
| P3: documents Split View 導入 | 完了 | 2026-04-07 | base新規、index+splitView、パネル分岐維持、MegaMenu変更不要 |
| P4: search Split View 導入 | 完了 | 2026-04-07 | base新規、openInNewTab→openInSplitView、構文チェックOK |
| P5: trade Split View 導入 | 完了 | 2026-04-07 | 6ファイル変更・構文チェックOK |
| P6: npm build & 最終確認 | 完了 | 2026-04-07 | 全5システムビルド成功 |

---

## 作業中コンテキスト

> Phase 作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 各システムの特記事項（事前調査完了）
- **delivery**: MegaMenu に外部リンク処理なし。base.blade.php 既存（標準形式）
- **insights**: MegaMenu に `external` フラグ + `buildSakemaruSeriesTab()` あり。base.blade.php 未作成
- **documents**: MegaMenu に外部リンク処理なし。base.blade.php 未作成。`$panelId === 'admin'` 分岐あり
- **search**: MegaMenu に `openInNewTab` フラグ + 外部システムタブあり。base.blade.php 未作成。theme は `search/theme.css`
- **trade**: MegaMenu に外部リンク処理なし。base.blade.php 標準化済み。index 標準化済み。delivery と同一形式

### Git ブランチ
- 各システムで個別ブランチを作成予定（Phase 実行時に決定）

---

## Phase完了記録

### P1: delivery Split View 導入
- 完了日: 2026-04-07
- 実績:
  - base.blade.php: Alpine ストア + body x-data 追加
  - index.blade.php: Split View コンテナ + splitView パラメータチェック追加
  - MegaMenu.php: openInSplitView フラグ追加
  - mega-menu.blade.php: Split View 起動ハンドラ + fa-columns アイコン追加
  - split-link.blade.php: 新規作成
  - theme.css: safelist + components source 追加
  - 全ファイル構文チェック通過

### P2: insights Split View 導入
- 完了日: 2026-04-07
- 実績:
  - base.blade.php: 新規作成（delivery ベース + Split View ストア）
  - index.blade.php: Split View コンテナ追加
  - MegaMenu.php: external→openInSplitView、buildSakemaruSeriesTab も対応
  - mega-menu.blade.php: external→openInSplitView + fa-columns アイコン
  - split-link.blade.php: 新規作成
  - theme.css: safelist追加

### P3: documents Split View 導入
- 完了日: 2026-04-07
- 実績:
  - base.blade.php: 新規作成（delivery ベース）
  - index.blade.php: Split View コンテナ追加、パネル分岐維持
  - MegaMenu: 独自実装のため変更不要
  - split-link.blade.php: 新規作成
  - theme.css: safelist追加

### P4: search Split View 導入
- 完了日: 2026-04-07
- 実績:
  - base.blade.php: 新規作成（delivery ベース）
  - index.blade.php: Split View コンテナ追加
  - MegaMenu.php: openInNewTab→openInSplitView（3箇所）
  - mega-menu.blade.php: target_blank→Split View ハンドラ + fa-columns
  - split-link.blade.php: 新規作成
  - theme.css (search/): safelist追加

### P5: trade Split View 導入
- 完了日: 2026-04-07
- 実績:
  - base.blade.php: Alpine ストア + body x-data 追加済み（ユーザー手動標準化後）
  - index.blade.php: Split View コンテナ + splitView パラメータチェック追加済み
  - MegaMenu.php: openInSplitView フラグ追加
  - mega-menu.blade.php: fa-table-columns アイコン（既存のSplit Viewハンドラ確認）
  - split-link.blade.php: 新規作成
  - theme.css: components source + safelist 追加

### P6: npm build & 最終確認
- 完了日: 2026-04-07
- 実績:
  - delivery: ビルド成功 (vite 5.4.21, theme 585KB)
  - insights: ビルド成功 (vite 7.3.1, theme 617KB)
  - documents: ビルド成功 (vite 7.2.4, theme 596/600KB)
  - search: ビルド成功 (vite 6.3.5, theme 594KB)
  - trade: ビルド成功 (vite 6.3.4, theme 657/658/668KB)
