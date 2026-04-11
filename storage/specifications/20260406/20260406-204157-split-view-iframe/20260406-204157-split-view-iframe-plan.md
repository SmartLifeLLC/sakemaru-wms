# Split View（iframe）機能 作業計画

## 前提

- Filament 4 + Laravel 12 + Alpine.js + Livewire 3 環境
- レイアウトは vendor override 済み（`resources/views/vendor/filament-panels/components/layout/`）
- topNavigation 使用（サイドバーなし）、mega-menu カスタム実装
- 解説ページ（auto-order-guide.blade.php）に約27箇所のリンクあり
- Tailwind CSS 4（`@source inline()` でsafelist管理）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | Alpine Store + base.blade.php | グローバルストア登録 + ナビゲーション連携 | `$store.splitView` が全ページで利用可能 |
| P2 | レイアウト分割（index.blade.php） | fi-main-ctn を左右分割構造に変更 | Split View 開閉・リサイズが動作する |
| P3 | split-link コンポーネント + CSS | Blade コンポーネント新規作成 + ドラッグCSS | `<x-split-link>` でリンクが Split View で開く |
| P4 | 解説ページリンク変更 | ~~完了済み~~ | — |
| P5 | 動作確認 + 微調整 | ~~完了済み~~ | — |
| P6 | URL変更 + ファイルリネーム | auto-order-guide → order-guide（PHP+Blade） | /admin/order-guide でアクセス可能 |
| P7 | 解説ページデザイン改修 | Nav Panel追加 + レスポンシブ対応 | Nav Panelでセクションジャンプ、Split View時2カラム |
| P8 | 最終動作確認 | ビルド・構文チェック・回帰確認 | エラーなし、既存ページに影響なし |

---

## P1: Alpine Store + base.blade.php

### 目的

Split View の状態管理用グローバルストア `$store.splitView` を Alpine.js に登録する。
ページ遷移（Livewire navigated）時に自動クローズするイベントリスナーも追加。

### 修正方針

`base.blade.php` の `@filamentScripts(withCore: true)`（L136）の後、`@stack('scripts')`（L152）の前にスクリプトブロックを挿入。

Alpine.js は Filament のコアスクリプトに含まれるため、`@filamentScripts` の後であれば `Alpine.store()` が利用可能。

### 修正対象ファイル

- `resources/views/vendor/filament-panels/components/layout/base.blade.php`

### 修正内容

L136 `@filamentScripts(withCore: true)` の直後に以下を追加:

```html
{{-- Split View グローバルストア --}}
<script data-navigate-once>
    document.addEventListener('alpine:init', () => {
        Alpine.store('splitView', {
            url: null,
            title: '',
            ratio: 50,
            dragging: false,

            open(url, title) {
                this.url = url;
                this.title = title || '';
            },
            close() {
                this.url = null;
                this.title = '';
                this.ratio = 50;
            },
            get isOpen() {
                return this.url !== null;
            }
        });
    });

    // ページ遷移時に Split View を閉じる
    document.addEventListener('livewire:navigated', () => {
        if (Alpine.store('splitView')) {
            Alpine.store('splitView').close();
        }
    });
</script>
```

**注意:** `data-navigate-once` 属性で Livewire のSPAナビゲーションで重複登録を防止。

また、`<body>` タグ（L118-L127）に `:class` バインディングを追加してドラッグ中の select-none を適用:

```html
<body
    {{
        $attributes
            ->merge($livewire?->getExtraBodyAttributes() ?? [], escape: false)
            ->class([
                'fi-body',
                'fi-panel-' . filament()->getId(),
            ])
    }}
    x-data
    :class="$store.splitView?.dragging && 'select-none cursor-col-resize'"
>
```

### 完了条件

- ブラウザの DevTools コンソールで `Alpine.store('splitView')` にアクセスできる
- `.open()` / `.close()` / `.isOpen` が正常動作する
- `php -l base.blade.php` で構文エラーなし

---

## P2: レイアウト分割（index.blade.php）

### 目的

`fi-main-ctn` 内の既存コンテンツ領域を左パネルとし、右パネル（iframe + ヘッダー）とリサイズバーを追加する。

### 修正方針

`index.blade.php` L77-L116 の `.fi-main-ctn` div 内部を以下の構造に変更:

```
.fi-main-ctn
└── .flex.flex-1.overflow-hidden  (split view container)
    ├── 左パネル div (:style で幅制御)
    │   ├── CONTENT_BEFORE hook
    │   ├── <main class="fi-main">
    │   │   └── $slot
    │   ├── CONTENT_AFTER hook
    │   └── FOOTER hook
    ├── リサイズバー (x-show で表示制御)
    └── 右パネル div (x-show + iframe)
        ├── ヘッダー (タイトル + 新規タブ + 閉じる)
        └── <iframe :src="$store.splitView.url">
```

### 修正対象ファイル

- `resources/views/vendor/filament-panels/components/layout/index.blade.php`

### 修正内容

L98 `{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_BEFORE ...` の直前に Split View コンテナの開始タグを挿入。

L115 `{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER ...` の直後に左パネルの閉じタグ + リサイズバー + 右パネル + コンテナ閉じタグを挿入。

具体的には:

**L98の前に追加:**
```html
        {{-- Split View コンテナ --}}
        <div class="flex flex-1 overflow-hidden"
             @mousemove.window="if($store.splitView.dragging) {
                 const rect = $el.getBoundingClientRect();
                 $store.splitView.ratio = Math.max(20, Math.min(80,
                     ((event.clientX - rect.left) / rect.width) * 100
                 ));
             }"
             @mouseup.window="$store.splitView.dragging = false">

            {{-- 左パネル: 既存コンテンツ --}}
            <div :style="$store.splitView.isOpen ? 'width:' + $store.splitView.ratio + '%' : 'width:100%'"
                 class="flex flex-col min-w-0 overflow-y-auto transition-[width] duration-200">
```

**L115の後に追加:**
```html
            </div>{{-- /左パネル --}}

            {{-- リサイズバー --}}
            <div x-show="$store.splitView.isOpen" x-cloak
                 @mousedown.prevent="$store.splitView.dragging = true"
                 class="w-1.5 bg-gray-200 dark:bg-gray-700 hover:bg-primary-400 dark:hover:bg-primary-600
                        cursor-col-resize flex-shrink-0 relative group"
                 :class="$store.splitView.dragging && 'bg-primary-500'">
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex flex-col gap-1">
                    <div class="w-1 h-1 rounded-full bg-gray-400 group-hover:bg-white"></div>
                    <div class="w-1 h-1 rounded-full bg-gray-400 group-hover:bg-white"></div>
                    <div class="w-1 h-1 rounded-full bg-gray-400 group-hover:bg-white"></div>
                </div>
            </div>

            {{-- 右パネル: iframe --}}
            <div x-show="$store.splitView.isOpen" x-cloak
                 :style="'width:' + (100 - $store.splitView.ratio) + '%'"
                 class="flex flex-col border-l border-gray-200 dark:border-gray-700 min-w-0">

                {{-- iframe ヘッダー --}}
                <div class="flex items-center justify-between px-3 py-1.5 bg-gray-50 dark:bg-gray-800
                            border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 min-w-0">
                        <x-heroicon-o-globe-alt class="w-4 h-4 flex-shrink-0" />
                        <span class="truncate" x-text="$store.splitView.title"></span>
                    </div>
                    <div class="flex items-center gap-1 flex-shrink-0">
                        <a :href="$store.splitView.url" target="_blank"
                           class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300
                                  rounded hover:bg-gray-200 dark:hover:bg-gray-700"
                           title="新しいタブで開く">
                            <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                        </a>
                        <button @click="$store.splitView.close()"
                                class="p-1 text-gray-400 hover:text-red-500
                                       rounded hover:bg-gray-200 dark:hover:bg-gray-700"
                                title="閉じる">
                            <x-heroicon-o-x-mark class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                {{-- iframe 本体 --}}
                <iframe :src="$store.splitView.url"
                        class="flex-1 w-full border-0"
                        :class="$store.splitView.dragging && 'pointer-events-none'"
                        x-show="$store.splitView.url"></iframe>
            </div>

        </div>{{-- /Split View コンテナ --}}
```

### 完了条件

- DevTools コンソールで `Alpine.store('splitView').open('/admin/waves', 'テスト')` → 右パネルにiframe表示
- リサイズバーをドラッグして左右幅が変わる
- 閉じるボタンで全幅に戻る
- Split View 非表示時に既存ページの見た目に変化なし

---

## P3: split-link コンポーネント + CSS

### 目的

再利用可能な `<x-split-link>` Blade コンポーネントを作成。
ドラッグ中のスタイル（select-none）を theme.css に追加。

### 修正方針

**新規ファイル:** `resources/views/components/split-link.blade.php`

```html
@props(['href', 'title' => null])
<a href="{{ $href }}"
   @click.prevent="
       if (window.innerWidth < 1024) {
           window.location.href = '{{ $href }}';
       } else {
           $store.splitView.open('{{ $href }}', '{{ $title ?? '' }}' || $el.textContent.trim());
       }
   "
   {{ $attributes->merge(['class' => 'text-blue-600 hover:underline cursor-pointer']) }}>
    {{ $slot }}
</a>
```

**CSS追加:** `resources/css/filament/admin/theme.css`

`@source inline()` にドラッグ関連の動的クラスを追加（`select-none cursor-col-resize pointer-events-none`）。

### 修正対象ファイル

- `resources/views/components/split-link.blade.php`（新規）
- `resources/css/filament/admin/theme.css`（既存）

### 完了条件

- `<x-split-link href="/admin/waves">テスト</x-split-link>` が正しくレンダリングされる
- クリックで Split View が開く
- 1024px 未満の画面幅では通常遷移する
- `npm run build` でエラーなし

---

## P6: URL変更 + ファイルリネーム

### 目的

URL を `/admin/auto-order-guide` → `/admin/order-guide` に変更し、ファイル名を統一する。

### 手順

1. PHPクラスのリネーム:
   ```
   app/Filament/Pages/AutoOrderGuide.php → app/Filament/Pages/OrderGuide.php
   ```
   - クラス名: `AutoOrderGuide` → `OrderGuide`
   - slug: `'auto-order-guide'` → `'order-guide'`
   - view: `'filament.pages.auto-order-guide'` → `'filament.pages.order-guide'`

2. Bladeテンプレートのリネーム:
   ```
   resources/views/filament/pages/auto-order-guide.blade.php
   → resources/views/filament/pages/order-guide.blade.php
   ```

3. 他ファイルからの参照を確認:
   ```bash
   grep -rn "auto-order-guide\|AutoOrderGuide" app/ resources/ routes/ --include="*.php" --include="*.blade.php"
   ```

### 修正対象ファイル

- `app/Filament/Pages/AutoOrderGuide.php` → `OrderGuide.php`（リネーム + クラス名変更）
- `resources/views/filament/pages/auto-order-guide.blade.php` → `order-guide.blade.php`（リネーム）

### 完了条件

- `/admin/order-guide` でページにアクセスできる
- `/admin/auto-order-guide` は404になる
- ナビゲーションメニューのリンクが正しく動作

---

## P7: 解説ページデザイン改修

### 目的

左側にNav Panel（ナビゲーションパネル）を追加し、セクションへのジャンプ機能を提供する。Split View で幅が狭い時は2カラム表示（nav:本文）にレスポンシブ対応する。全リンクを `<x-split-link>` に変更する。

### 修正方針

**1. レイアウト構造の変更:**

現在の構造:
```
div (h-[calc(100vh-120px)] flex flex-col)
├── タブナビゲーション（上部水平タブ）
└── タブコンテンツ（flex-1 overflow-y-auto）
```

変更後の構造:
```
div (h-[calc(100vh-120px)] flex)   ← flex-col → flex に変更
├── Nav Panel (w-48, shrink-0, sticky, hidden lg:block)
│   ├── セクション選択（概要/流れ/メニュー/設定）
│   └── サブセクション目次（タブに応じて動的）
├── 本文 (flex-1, overflow-y-auto)
│   └── タブコンテンツ（グリッドカラム数がSplit View連動）
└── モバイル用水平タブ（lg:hidden、画面上部に表示）
```

**2. Nav Panel の実装:**

```html
<div class="w-48 shrink-0 border-r border-gray-200 dark:border-gray-700
            overflow-y-auto h-full hidden lg:block"
     :class="$store.splitView?.isOpen && '!w-40'">
    {{-- タブ選択 --}}
    <div class="p-2 space-y-0.5">
        <div class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold px-2 py-1">セクション</div>
        @foreach([
            ['overview', '概要', 'heroicon-o-information-circle'],
            ['flow', '発注の流れ', 'heroicon-o-clock'],
            ['menus', 'メニュー詳細', 'heroicon-o-squares-2x2'],
            ['settings', '設定', 'heroicon-o-cog-6-tooth'],
        ] as [$key, $label, $icon])
        <button @click="activeTab = '{{ $key }}'"
            :class="activeTab === '{{ $key }}'
                ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 border-l-2 border-primary-500'
                : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 border-l-2 border-transparent'"
            class="w-full text-left px-3 py-1.5 text-xs rounded-r transition-colors flex items-center gap-2">
            <x-dynamic-component :component="$icon" class="w-3.5 h-3.5" />
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- タブ内サブセクション目次 --}}
    <div class="p-2 border-t border-gray-200 dark:border-gray-700">
        {{-- flow タブ --}}
        <div x-show="activeTab === 'flow'" x-cloak class="space-y-0.5">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold px-2 py-1">目次</div>
            @foreach([
                ['flow-schedule', '発注スケジュール'],
                ['flow-warehouse', '倉庫構成'],
                ['flow-satellite', 'サテライト手順'],
                ['flow-hub', 'HUB手順'],
                ['flow-orange', 'オレンジ出荷'],
                ['flow-notes', '注意事項'],
                ['flow-faq', 'FAQ'],
            ] as [$ref, $label])
            <button @click="$refs['{{ $ref }}']?.scrollIntoView({behavior:'smooth', block:'start'})"
                class="w-full text-left px-3 py-1 text-[11px] text-gray-500 hover:text-primary-600 hover:bg-gray-50 dark:hover:bg-gray-800 rounded transition-colors">
                {{ $label }}
            </button>
            @endforeach
        </div>

        {{-- menus タブ --}}
        <div x-show="activeTab === 'menus'" x-cloak class="space-y-0.5">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold px-2 py-1">メニュー</div>
            @foreach([
                ['generation', '候補生成', 'blue'],
                ['transfer', '移動候補', 'cyan'],
                ['order', '発注候補', 'green'],
                ['confirm', '確定待ち', 'orange'],
                ['history', '履歴', 'purple'],
            ] as [$key, $label, $color])
            <button @click="menuTab = '{{ $key }}'"
                :class="menuTab === '{{ $key }}'
                    ? 'bg-{{ $color }}-50 dark:bg-{{ $color }}-900/20 text-{{ $color }}-700 dark:text-{{ $color }}-300'
                    : 'text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800'"
                class="w-full text-left px-3 py-1 text-[11px] rounded transition-colors">
                {{ $label }}
            </button>
            @endforeach
        </div>

        {{-- settings タブ --}}
        <div x-show="activeTab === 'settings'" x-cloak class="space-y-0.5">
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold px-2 py-1">設定項目</div>
            @foreach(['月別発注点','倉庫カレンダー','発注先休日','発注先別ロット','移動配送コース','JX接続設定'] as $label)
            <div class="px-3 py-1 text-[11px] text-gray-500">{{ $label }}</div>
            @endforeach
        </div>
    </div>
</div>
```

**3. 上部タブナビの変更:**

- PC（lg以上）: 上部水平タブを削除（Nav Panel に統合）
- モバイル（lg未満）: 水平タブを維持

```html
{{-- モバイル用タブ（lg未満で表示） --}}
<div class="lg:hidden border-b border-gray-200 dark:border-gray-700 flex-shrink-0 mb-2">
    <nav class="-mb-px flex flex-wrap gap-x-4 overflow-x-auto px-1" aria-label="Tabs">
        @foreach([
            ['overview', '概要'],
            ['flow', '発注の流れ'],
            ['menus', 'メニュー詳細'],
            ['settings', '設定'],
        ] as [$key, $label])
        <button @click="activeTab = '{{ $key }}'" type="button"
            :class="activeTab === '{{ $key }}'
                ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400'"
            class="whitespace-nowrap py-2 px-1 border-b-2 font-medium text-xs">
            {{ $label }}
        </button>
        @endforeach
    </nav>
</div>
```

**4. 本文グリッドのレスポンシブ対応:**

Split View の `$store.splitView.isOpen` に応じてカラム数を動的に調整:

| セクション | 通常 | Split View時 |
|-----------|------|-------------|
| 概要カード | 3カラム | 2カラム |
| 関連リンク | 7カラム | 4カラム |
| 倉庫構成 | 3カラム | 2カラム |
| メニュー詳細 | 2-3カラム | 1-2カラム |
| 設定カード | 3カラム | 2カラム |

実装例:
```html
<div class="grid grid-cols-1 gap-4"
     :class="$store.splitView?.isOpen ? 'lg:grid-cols-2' : 'lg:grid-cols-3'">
```

**5. 本文セクションに x-ref を追加:**

Nav Panel からのスクロールジャンプ用に各セクションに `x-ref` を付与:

```html
<div x-ref="flow-schedule" class="bg-white ...">
    <h3>1日の発注スケジュール</h3>
```

**6. リンクの `<x-split-link>` 変更:**

全リンク（約27箇所）を `<x-split-link>` に変更（既存の概要タブ・設定タブのリンクは変更済み）。

### 修正対象ファイル

- `resources/views/filament/pages/order-guide.blade.php`（P4でリネーム済み）

### 完了条件

- Nav Panel が左側に表示される（lg以上）
- Nav Panel のセクションクリックでタブ切替 + スクロール
- Nav Panel のサブセクションクリックで該当箇所へスムーズスクロール
- Split View 表示時にグリッドカラム数が削減される（3→2等）
- モバイル（lg未満）でNav Panel非表示、水平タブが表示される
- 全リンクが `<x-split-link>` で Split View 対応
- `php -l` で構文エラーなし

---

## P8: 最終動作確認

### 目的

全体の動作確認とビルド確認。

### 確認手順

1. `npm run build` — Vite ビルドがエラーなく完了
2. `php artisan view:cache && php artisan view:clear` — Blade テンプレートの構文確認
3. ブラウザで以下を確認:
   - `/admin/order-guide` でページにアクセスできる
   - `/admin/auto-order-guide` は404
   - ナビゲーションメニューのリンクが正しい
   - Nav Panel: セクションクリックでタブ切替
   - Nav Panel: サブセクション（目次）クリックでスクロール
   - 概要タブのリンクをクリック → Split View で開く
   - Split View 表示時: Nav Panel w-40に縮小、グリッド2カラムに
   - リサイズバーをドラッグ → 左右幅が変わる
   - 閉じるボタン → 全幅に戻る（Nav Panel w-48に復帰）
   - 他ページへの遷移（mega-menu等）→ Split View が閉じる
   - モバイル（lg未満）: Nav Panel非表示、水平タブ表示
   - モバイル: リンクは通常遷移
   - 他の通常ページ → 見た目に変化なし

### 修正対象ファイル

なし（確認のみ。微調整があれば該当ファイル）

### 完了条件

- `npm run build` 成功
- Blade 構文エラーなし
- 上記の全確認項目がパス

---

## 制約（厳守）

1. DB破壊コマンド禁止（migrate:fresh, refresh, reset, db:wipe）
2. FK禁止
3. Split View 非表示時に既存ページの見た目・動作を変化させない
4. Tailwind CSS 4 動的クラスは `@source inline()` で対応
5. モバイル（1024px未満）では通常リンク遷移にフォールバック
6. `data-navigate-once` でスクリプト重複登録を防止

## 全体完了条件

1. 全8 Phase が完了（P1-5完了済み、P6-8新規）
2. `npm run build` 成功
3. `/admin/order-guide` でアクセス可能（旧URLは404）
4. Nav Panel でセクションジャンプが動作
5. Split View 表示時にグリッドカラム数が適切に削減
6. 解説ページの全リンクが Split View で開く
7. リサイズ・閉じる・新規タブが正常動作
8. モバイル: Nav Panel非表示 → 水平タブ表示、リンクは通常遷移
9. 他ページへの影響なし（Split View 非表示時）
