# Split View 全システム展開 作業計画

## 前提

- WMS で Split View 実装済み（リファレンス）
- trade の index.blade.php は Filament 4 標準形式に統一済み
- 全5システムが Filament 4 + MegaMenu を使用
- 各システムの base.blade.php / index.blade.php / MegaMenu の状態は事前調査済み（boot.md 参照）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | delivery Split View 導入 | base 既存・index 標準。最もシンプルなケース | Split View が動作し `php artisan view:cache` エラーなし |
| P2 | insights Split View 導入 | base 未作成。external フラグ既存 | 同上 |
| P3 | documents Split View 導入 | base 未作成。パネル分岐あり | 同上。partner パネルに影響なし |
| P4 | search Split View 導入 | base 未作成。openInNewTab 既存 | 同上 |
| P5 | trade Split View 導入 | base/index 標準化済み。Split View 追加のみ | 同上。store/gift-store に影響なし |
| P6 | npm build & 最終確認 | 全5システムの build + 構文チェック | 全システム build 成功 |

---

## P1: delivery Split View 導入

### 目的

最もシンプルな構成（base 既存・index 標準・単一パネル）の delivery から着手し、導入パターンを確立する。

### 修正対象ファイル

| ファイル | 操作 | 内容 |
|---------|------|------|
| `/Users/jungsinyu/Projects/sakemaru-delivery/resources/views/vendor/filament-panels/components/layout/base.blade.php` | 変更 | Alpine ストア追加 + body に x-data |
| `/Users/jungsinyu/Projects/sakemaru-delivery/resources/views/vendor/filament-panels/components/layout/index.blade.php` | 変更 | Split View コンテナ追加 + splitView パラメータチェック |
| `/Users/jungsinyu/Projects/sakemaru-delivery/app/Livewire/MegaMenu.php` | 変更 | `openInSplitView` フラグ追加 |
| `/Users/jungsinyu/Projects/sakemaru-delivery/resources/views/livewire/mega-menu.blade.php` | 変更 | Split View 起動ハンドラ追加 |
| `/Users/jungsinyu/Projects/sakemaru-delivery/resources/views/components/split-link.blade.php` | 新規 | Blade コンポーネント |
| `/Users/jungsinyu/Projects/sakemaru-delivery/resources/css/filament/admin/theme.css` | 変更 | safelist 追加 |

### 修正方針

#### 1. base.blade.php

WMS の base.blade.php をリファレンスに以下を追加:

**body タグ** に `x-data` と drag 状態クラス:
```blade
<body ... x-data :class="$store.splitView?.dragging && 'select-none cursor-col-resize'">
```

**`@filamentScripts(withCore: true)` の直前** に Alpine ストア登録:
```blade
<script data-navigate-once>
    document.addEventListener('alpine:init', () => {
        Alpine.store('splitView', {
            url: null, title: '', breadcrumb: '', ratio: 50,
            dragging: false, isSameOrigin: false,
            open(url, title, breadcrumb) { ... },
            close() { ... },
            setRatio(r) { this.ratio = r; },
            get isOpen() { return this.url !== null; }
        });
    });
    document.addEventListener('livewire:navigated', () => {
        if (Alpine.store('splitView')) Alpine.store('splitView').close();
    });
</script>
```

#### 2. index.blade.php

WMS の index.blade.php をリファレンスに:

- topbar 条件に `&& ! request()->has('splitView')` 追加
- `.fi-main-ctn` 内を Split View コンテナ（`x-data` 付き flex div）で包む
- 左パネル（既存コンテンツ）+ リサイズバー + 右パネル（iframe + ヘッダー）

#### 3. MegaMenu.php

`buildMenuStructure()` の NavigationItem マッピングに追加:
```php
'openInSplitView' => $item->shouldOpenUrlInNewTab(),
```

#### 4. mega-menu.blade.php

`openInSplitView` が true のアイテムに `@click.prevent` ハンドラ追加:
```blade
@if(!empty($item['openInSplitView']))
    @click.prevent="$store.splitView.open('{{ $item['url'] }}', '{{ $item['label'] }}', '{{ $group['label'] }}'); openTab = null"
@endif
```

Split View アイテム用のアイコンを `fa-columns` に変更。

#### 5. split-link.blade.php

WMS と同一内容をコピー。

#### 6. theme.css

`@source inline()` に `select-none cursor-col-resize pointer-events-none` を追加。

### 完了条件

- `php artisan view:cache` がエラーなし
- `php -l` で全変更ファイルの構文チェック通過

---

## P2: insights Split View 導入

### 目的

base.blade.php が未作成のケース。また `external` フラグと `buildSakemaruSeriesTab()` が既存のため、Split View との統合が必要。

### 修正対象ファイル

| ファイル | 操作 | 内容 |
|---------|------|------|
| `/Users/jungsinyu/Projects/sakemaru-insights/resources/views/vendor/filament-panels/components/layout/base.blade.php` | **新規作成** | Filament 4 デフォルト + Alpine ストア + body x-data |
| `/Users/jungsinyu/Projects/sakemaru-insights/resources/views/vendor/filament-panels/components/layout/index.blade.php` | 変更 | Split View コンテナ + splitView パラメータチェック |
| `/Users/jungsinyu/Projects/sakemaru-insights/app/Livewire/MegaMenu.php` | 変更 | `external` → `openInSplitView` に変更（外部リンクを Split View で開く） |
| `/Users/jungsinyu/Projects/sakemaru-insights/resources/views/livewire/mega-menu.blade.php` | 変更 | Split View 起動ハンドラ追加 |
| `/Users/jungsinyu/Projects/sakemaru-insights/resources/views/components/split-link.blade.php` | 新規 | Blade コンポーネント |
| `/Users/jungsinyu/Projects/sakemaru-insights/resources/css/filament/admin/theme.css` | 変更 | safelist 追加 |

### 修正方針

P1 と同じパターン。追加で:

- **base.blade.php 新規作成**: delivery の base.blade.php（P1 完了後）をベースにコピー。insights 固有の差異（font 設定等）は既存の base がないため Filament デフォルトベースで作成
- **MegaMenu.php**: 既存の `external` フラグを `openInSplitView` に統合。`buildSakemaruSeriesTab()` の items に `openInSplitView => true` を追加
- **mega-menu.blade.php**: 既存の `external` + `target="_blank"` を `openInSplitView` + `$store.splitView.open()` に変更。外部リンク（クロスオリジン）は引き続き Split View で開くが、メニュー非表示は効かない（仕様通り）

### 完了条件

- `php artisan view:cache` がエラーなし
- `php -l` で全変更ファイルの構文チェック通過

---

## P3: documents Split View 導入

### 目的

base.blade.php 未作成 + パネル分岐（`$panelId === 'admin'`）があるケース。partner パネルに影響を与えない。

### 修正対象ファイル

| ファイル | 操作 | 内容 |
|---------|------|------|
| `/Users/jungsinyu/Projects/sakemaru-documents/resources/views/vendor/filament-panels/components/layout/base.blade.php` | **新規作成** | Alpine ストア + body x-data |
| `/Users/jungsinyu/Projects/sakemaru-documents/resources/views/vendor/filament-panels/components/layout/index.blade.php` | 変更 | Split View コンテナ + splitView パラメータチェック |
| `/Users/jungsinyu/Projects/sakemaru-documents/app/Livewire/MegaMenu.php` | 変更 | `openInSplitView` フラグ追加 |
| `/Users/jungsinyu/Projects/sakemaru-documents/resources/views/livewire/mega-menu.blade.php` | 変更 | Split View 起動ハンドラ追加 |
| `/Users/jungsinyu/Projects/sakemaru-documents/resources/views/components/split-link.blade.php` | 新規 | Blade コンポーネント |
| `/Users/jungsinyu/Projects/sakemaru-documents/resources/css/filament/admin/theme.css` | 変更 | safelist 追加 |

### 修正方針

P1 と同じパターン。追加で:

- **index.blade.php**: 既存のパネル分岐（`$panelId === 'admin'` → mega-menu）を維持。splitView パラメータチェックはパネル分岐の外側に追加:
  ```blade
  @if ($hasTopbar && ! request()->has('splitView'))
      @if ($panelId === 'admin')
          @livewire('mega-menu')
      @else
          @livewire(filament()->getTopbarLivewireComponent())
      @endif
  @endif
  ```
- Split View コンテナは admin/partner 両方で動作するが、Split View を起動する UI（mega-menu の openInSplitView）は admin のみ

### 完了条件

- `php artisan view:cache` がエラーなし
- partner パネルの topbar 表示に影響なし

---

## P4: search Split View 導入

### 目的

base.blade.php 未作成。`openInNewTab` フラグが既存で外部システムタブもあるため、Split View への移行が必要。theme が `search/theme.css`。

### 修正対象ファイル

| ファイル | 操作 | 内容 |
|---------|------|------|
| `/Users/jungsinyu/Projects/sakemaru-search/resources/views/vendor/filament-panels/components/layout/base.blade.php` | **新規作成** | Alpine ストア + body x-data |
| `/Users/jungsinyu/Projects/sakemaru-search/resources/views/vendor/filament-panels/components/layout/index.blade.php` | 変更 | Split View コンテナ + splitView パラメータチェック |
| `/Users/jungsinyu/Projects/sakemaru-search/app/Livewire/MegaMenu.php` | 変更 | `openInNewTab` → `openInSplitView` に変更 |
| `/Users/jungsinyu/Projects/sakemaru-search/resources/views/livewire/mega-menu.blade.php` | 変更 | `target="_blank"` → Split View 起動ハンドラ |
| `/Users/jungsinyu/Projects/sakemaru-search/resources/views/components/split-link.blade.php` | 新規 | Blade コンポーネント |
| `/Users/jungsinyu/Projects/sakemaru-search/resources/css/filament/search/theme.css` | 変更 | safelist 追加 |

### 修正方針

P1 と同じパターン。追加で:

- **MegaMenu.php**: 既存の `openInNewTab` を `openInSplitView` にリネーム。外部システムタブの items にも `openInSplitView => true` を設定
- **mega-menu.blade.php**: `target="_blank"` 判定を `openInSplitView` + `$store.splitView.open()` に変更
- **theme.css**: `search/theme.css` に safelist 追加（`admin/theme.css` ではない）

### 完了条件

- `php artisan view:cache` がエラーなし
- `php -l` で全変更ファイルの構文チェック通過

---

## P5: trade Split View 導入

### 目的

base.blade.php / index.blade.php 共に Filament 4 標準形式に統一済み（delivery と同一）。Split View の追加のみ。store/gift-store パネルに影響を与えない。

### 修正対象ファイル

| ファイル | 操作 | 内容 |
|---------|------|------|
| `/Users/jungsinyu/Projects/sakemaru-trade/resources/views/vendor/filament-panels/components/layout/base.blade.php` | 変更 | Alpine ストア追加 + body x-data |
| `/Users/jungsinyu/Projects/sakemaru-trade/resources/views/vendor/filament-panels/components/layout/index.blade.php` | 変更 | Split View コンテナ + splitView パラメータチェック |
| `/Users/jungsinyu/Projects/sakemaru-trade/app/Livewire/MegaMenu.php` | 変更 | `openInSplitView` フラグ追加 |
| `/Users/jungsinyu/Projects/sakemaru-trade/resources/views/livewire/mega-menu.blade.php` | 変更 | Split View 起動ハンドラ追加 |
| `/Users/jungsinyu/Projects/sakemaru-trade/resources/views/components/split-link.blade.php` | 新規 | Blade コンポーネント |
| `/Users/jungsinyu/Projects/sakemaru-trade/resources/css/filament/admin/theme.css` | 変更 | safelist 追加 |

### 修正方針

P1 と同じパターン。追加で:

- **base.blade.php**: 標準化済み（delivery と同一）。P1 と同じパターンで Alpine ストア + body x-data を追加するのみ
- **index.blade.php**: パネル分岐（`$panelId === 'admin'`）が既にある。splitView チェックを追加
- MegaMenu に外部リンク処理がないため、`openInSplitView` フラグ追加のみ

### 完了条件

- `php artisan view:cache` がエラーなし
- store/gift-store パネルに影響なし（Split View ストアは空で副作用なし）

---

## P6: npm build & 最終確認

### 目的

全5システムで npm build を実行し、Tailwind CSS の safelist が正しく反映されることを確認。

### 手順

各システムのプロジェクトディレクトリで:
```bash
cd /Users/jungsinyu/Projects/{system} && npm run build
```

### 完了条件

- 全5システムで `npm run build` が正常終了
- ビルドエラーなし

---

## 制約（厳守）

1. 各システムの既存パネル分岐・カスタマイズを壊さない
2. trade の store/gift-store パネルは対象外
3. Alpine ストアは `@filamentScripts` の **前** に配置（`alpine:init` タイミング）
4. `data-navigate-once` を必ず付与（Livewire SPA ナビゲーション対応）
5. 各システムのデータベースには触れない

## 全体完了条件

- 全5システムで `php artisan view:cache` エラーなし
- 全5システムで `npm run build` 成功
- Split View の Alpine ストアが正しく登録される（base.blade.php）
- index.blade.php の Split View コンテナが正しく描画される
- MegaMenu から外部リンク／酒丸シリーズリンクが Split View で開ける
