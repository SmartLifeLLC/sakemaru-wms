# Split View 全システム展開

- **作成日**: 2026-04-07
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260407/20260407-073743-deploy-split-view-all-systems/`

## 背景・目的

sakemaru-wms で実装した Split View（iframe ベースの画面分割表示）機能を、酒丸シリーズの全システムに展開する。これにより、どのシステムからでも他システムのページを右パネルに並列表示でき、業務効率が向上する。

## 現状の実装（WMS リファレンス）

### Split View の構成要素

WMS での実装は以下の 4 ファイルで構成される:

| # | ファイル | 役割 |
|---|---------|------|
| 1 | `resources/views/vendor/filament-panels/components/layout/base.blade.php` | Alpine.js グローバルストア登録（`$store.splitView`）、body の drag 状態クラス |
| 2 | `resources/views/vendor/filament-panels/components/layout/index.blade.php` | Split View コンテナ（左パネル + リサイズバー + 右 iframe パネル）、`?splitView=1` でメガメニュー非表示 |
| 3 | `resources/views/components/split-link.blade.php` | `<x-split-link>` Blade コンポーネント（モバイル対応付き） |
| 4 | `app/Livewire/MegaMenu.php` + `mega-menu.blade.php` | メニューから `openInSplitView` アイテムをクリックで Split View 起動 |

### Alpine.js ストア仕様

```javascript
Alpine.store('splitView', {
    url: null,           // iframe URL
    title: '',           // タイトル表示
    breadcrumb: '',      // パンくず（メニューグループ名）
    ratio: 50,           // 左:右 の比率（左のパーセント）
    dragging: false,     // ドラッグ中フラグ
    isSameOrigin: false, // 同一オリジン判定

    open(url, title, breadcrumb) { ... },  // 同一オリジン時 ?splitView=1 付与
    close() { ... },
    setRatio(r) { ... },
    get isOpen() { return this.url !== null; }
});
```

### 重要な実装ポイント

1. **Alpine ストアの登録タイミング**: `alpine:init` イベントリスナーは `@filamentScripts(withCore: true)` の **前** に配置する必要がある（Alpine 読み込み前に登録）
2. **`data-navigate-once`**: Livewire SPA ナビゲーションで重複実行を防止
3. **`?splitView=1`**: 同一オリジンの iframe 内でトップメニューを非表示にするパラメータ
4. **ドラッグ中の transition 無効化**: `:class="$store.splitView.dragging ? '' : 'transition-[width] duration-200'"` でストレッチ防止
5. **body の drag 状態**: `x-data :class="$store.splitView?.dragging && 'select-none cursor-col-resize'"` で全体に適用

## 対象システム

| # | プロジェクト | ディレクトリ | base.blade.php | index.blade.php | 特記事項 |
|---|-------------|-------------|----------------|-----------------|---------|
| 1 | sakemaru-trade | `/Users/jungsinyu/Projects/sakemaru-trade` | 既存（旧形式） | **標準形式に統一済み** | Filament 4 標準形式に書き換え完了。admin パネルのみ MegaMenu、store/gift-store は標準 topbar（今回対象外） |
| 2 | sakemaru-documents | `/Users/jungsinyu/Projects/sakemaru-documents` | **未作成** | 既存（標準形式） | admin パネルのみ MegaMenu。partner パネルは標準 topbar |
| 3 | sakemaru-search | `/Users/jungsinyu/Projects/sakemaru-search` | **未作成** | 既存（標準形式） | search パネル + stats パネル |
| 4 | sakemaru-insights | `/Users/jungsinyu/Projects/sakemaru-insights` | **未作成** | 既存（標準形式） | admin パネル1つのみ |
| 5 | sakemaru-delivery | `/Users/jungsinyu/Projects/sakemaru-delivery` | 既存（標準形式） | 既存（標準形式） | admin パネル1つ |

## 変更内容

### 概要

各システムの Filament vendor レイアウトに Split View のコード（Alpine ストア + Split View コンテナ + CSS）を追加し、MegaMenu から Split View を起動できるようにする。

### 詳細設計

#### 各システム共通の変更（5 システム共通）

##### 1. `base.blade.php` — Alpine ストア追加

**変更箇所**: `<body>` タグに `x-data` と drag 状態クラスを追加。`@filamentScripts` の **前** に Alpine ストア登録スクリプトを挿入。

```blade
{{-- body タグに追加 --}}
<body ... x-data :class="$store.splitView?.dragging && 'select-none cursor-col-resize'">

{{-- @filamentScripts の前に挿入 --}}
<script data-navigate-once>
    document.addEventListener('alpine:init', () => {
        Alpine.store('splitView', { ... });  {{-- WMS と同じストア定義 --}}
    });
    document.addEventListener('livewire:navigated', () => {
        if (Alpine.store('splitView')) Alpine.store('splitView').close();
    });
</script>
@filamentScripts(withCore: true)
```

- **base.blade.php が未作成のシステム（documents, search, insights）**: Filament デフォルトの base.blade.php を `php artisan vendor:publish` で生成し、そこに Split View コードを追加

##### 2. `index.blade.php` — Split View コンテナ追加

**変更箇所**: `.fi-main-ctn` div の中身を Split View コンテナで包む。topbar 条件に `splitView` パラメータチェックを追加。

主要な変更:
- `@if ($hasTopbar)` → `@if ($hasTopbar && ! request()->has('splitView'))`
- `.fi-main-ctn` 内に Split View の `flex` コンテナ追加（左パネル + リサイズバー + 右 iframe パネル）
- `x-data` を Split View コンテナ div に直接付与（topNavigation モードで `x-data` が不足する問題を回避）

##### 3. `split-link.blade.php` — Blade コンポーネント新規作成

```
resources/views/components/split-link.blade.php
```

WMS と同一の内容。モバイル（1024px 未満）では通常リンクとしてフォールバック。

##### 4. `MegaMenu.php` — `openInSplitView` フラグ追加

`buildMenuStructure()` メソッドの NavigationItem マッピングに `'openInSplitView' => $item->shouldOpenUrlInNewTab()` を追加。

##### 5. `mega-menu.blade.php` — Split View 起動ハンドラ追加

`openInSplitView` が true のアイテムに `@click.prevent` で `$store.splitView.open()` を呼び出すハンドラを追加。breadcrumb（メニューグループ名）も渡す。

##### 6. `theme.css` — Tailwind safelist 追加

```css
@source inline("select-none cursor-col-resize pointer-events-none");
```

#### システム固有の対応

##### sakemaru-trade（特殊）

- `index.blade.php` が独自構造（旧 Filament 形式のサイドバースクロール処理あり）。WMS と同様のパターンに書き換えるか、既存構造を維持しつつ Split View コンテナだけ差し込む
- 複数パネル（admin, store, gift-store）があるが、Split View は admin パネルのみに適用（MegaMenu が admin のみで使用されているため）
- `base.blade.php` は既存だが旧形式 → Alpine ストアの差し込み位置に注意

##### sakemaru-documents

- `base.blade.php` が未作成 → vendor:publish で生成してから変更
- admin パネルのみ MegaMenu、partner パネルは標準 topbar → partner パネルでは Split View 非活性

##### sakemaru-search

- `base.blade.php` が未作成 → vendor:publish で生成してから変更
- search パネル + stats パネル → search パネルのみに Split View 適用

### 影響範囲

- 各システムのレイアウト表示（Split View が閉じている時は影響なし）
- MegaMenu の外部リンククリック動作（新規タブ → Split View に変更）
- iframe 内でのトップメニュー非表示（`?splitView=1` パラメータ）

## 制約

- 各システムの既存レイアウトカスタマイズ（特に trade の独自サイドバー処理）を壊さない
- Split View は admin パネル（MegaMenu 使用パネル）のみに適用
- 各システムの npm run build が必要（Tailwind CSS safelist 追加のため）
- クロスオリジン iframe ではメニュー非表示制御が効かない（これは仕様通り）

## 対象ファイル

### 新規作成（各システム共通）

- `resources/views/components/split-link.blade.php` — 全5システム

### 新規作成（base.blade.php 未作成のシステム）

- `resources/views/vendor/filament-panels/components/layout/base.blade.php` — documents, search, insights

### 既存変更（全5システム）

- `resources/views/vendor/filament-panels/components/layout/index.blade.php` — Split View コンテナ追加
- `resources/views/vendor/filament-panels/components/layout/base.blade.php` — Alpine ストア追加（trade, delivery は既存変更、他3つは新規作成後変更）
- `app/Livewire/MegaMenu.php` — `openInSplitView` フラグ追加
- `resources/views/livewire/mega-menu.blade.php` — Split View 起動ハンドラ追加
- `resources/css/filament/admin/theme.css`（または該当テーマCSS） — safelist 追加

### 参照のみ（変更禁止）

- `/Users/jungsinyu/Projects/sakemaru-wms/resources/views/vendor/filament-panels/components/layout/base.blade.php` — リファレンス実装
- `/Users/jungsinyu/Projects/sakemaru-wms/resources/views/vendor/filament-panels/components/layout/index.blade.php` — リファレンス実装
- `/Users/jungsinyu/Projects/sakemaru-wms/resources/views/components/split-link.blade.php` — リファレンス実装

## 確認事項

1. **sakemaru-trade の index.blade.php**: 独自構造が大きく異なるため、Split View コンテナをどのレベルに差し込むか慎重に検討が必要。既存のサイドバースクロール処理と競合しないか確認
2. **複数パネルのシステム（trade: 4パネル, documents: 2パネル, search: 2パネル）**: admin パネル以外のパネルでは Split View のストアが空で動作するか、または副作用が発生しないか確認
3. **各システムの npm run build**: Vite + Tailwind CSS 4 の設定が正しく動作するか（safelist の追加）
4. **Filament バージョン**: 全システムが Filament 4 であることは確認済みだが、マイナーバージョンの差異で vendor ファイルの構造が異なる可能性
