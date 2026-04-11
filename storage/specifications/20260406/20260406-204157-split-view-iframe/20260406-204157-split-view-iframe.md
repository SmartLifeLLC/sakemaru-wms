# Split View（iframe）機能

- **作成日**: 2026-04-06
- **ステータス**: ドラフト
- **ディレクトリ**: storage/specifications/20260406/20260406-204157-split-view-iframe/

## 背景・目的

各画面にリンクをクリックすると完全にページ遷移してしまい、元の画面を見ながら別画面を確認したいケースに対応できない。特に解説ページでは操作手順を見ながら対象画面をプレビューしたいニーズが強い。

YouTubeのような Split View（左に現在のページ・右にiframeで別ページ）を**全ページ共通機能**として実装し、任意のリンクから右パネルに対象ページを表示可能にする。

## 現状の実装

### レイアウト構造

```
resources/views/vendor/filament-panels/components/layout/
├── base.blade.php       — HTML基本構造（<html>, <head>, <body>）
├── index.blade.php      — パネルレイアウト（topbar + sidebar + main content）
└── simple.blade.php     — シンプルレイアウト
```

**index.blade.php の構造:**
```
<layout.base>
  ├── mega-menu (topbar)
  └── .fi-layout
      ├── sidebar (非表示: topNavigation使用)
      └── .fi-main-ctn          ← ★ここを分割対象にする
          ├── CONTENT_BEFORE hook
          ├── <main class="fi-main">
          │   └── $slot (ページコンテンツ)
          ├── CONTENT_AFTER hook
          └── FOOTER hook
```

### 現在の解説ページリンク

- `auto-order-guide.blade.php` 内に約27箇所のリンク
- 全て `<a href="{{ route(...) }}">` 形式で通常ページ遷移

## 変更内容

### 概要

Filament パネルレイアウト（`index.blade.php`）に Split View 機能をグローバルに組み込む。Alpine.js の `$store.splitView` で全ページから利用可能にし、解説ページのリンクは自動的に Split View で開くようにする。

### 詳細設計

#### アーキテクチャ

```
┌─────────────────────────────────────────────────────────┐
│ Mega Menu (topbar)                                      │
├─────────────────────────┬──┬────────────────────────────┤
│                         │▐ │ [↗] [×]  タイトル          │
│  .fi-main-ctn           │▐ │                            │
│  （現在のページ）        │▐ │  iframe                    │
│                         │▐ │  （Split View パネル）      │
│                         │  │                            │
└─────────────────────────┴──┴────────────────────────────┘
       左パネル            バー      右パネル
```

#### 1. Alpine.js グローバルストア

`base.blade.php` に `$store.splitView` を登録。全ページから利用可能。

```javascript
Alpine.store('splitView', {
    url: null,           // null = 非表示、URL = iframe表示
    title: '',           // ヘッダー表示用タイトル
    ratio: 50,           // 左パネル幅（%）
    dragging: false,     // リサイズ中フラグ

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
```

#### 2. レイアウト変更（index.blade.php）

`fi-main-ctn` の内部を左右分割構造に変更:

```html
<div class="fi-main-ctn">
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
             class="flex flex-col min-w-0 overflow-hidden transition-[width] duration-200">
            {{ renderHook(CONTENT_BEFORE) }}
            <main class="fi-main ...">
                {{ $slot }}
            </main>
            {{ renderHook(CONTENT_AFTER) }}
            {{ renderHook(FOOTER) }}
        </div>

        {{-- リサイズバー --}}
        <div x-show="$store.splitView.isOpen" x-cloak
             @mousedown.prevent="$store.splitView.dragging = true"
             class="w-1.5 bg-gray-200 dark:bg-gray-700 hover:bg-primary-400 
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
            
            {{-- ヘッダー --}}
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
            
            {{-- iframe --}}
            <iframe :src="$store.splitView.url"
                    class="flex-1 w-full border-0"
                    :class="$store.splitView.dragging && 'pointer-events-none'">
            </iframe>
        </div>
    </div>
</div>
```

#### 3. 利用方法（任意のページから）

**方法A: リンクに直接指定**
```html
<a href="/admin/some-page" 
   @click.prevent="$store.splitView.open($el.href, 'ページ名')">
   リンクテキスト
</a>
```

**方法B: Blade コンポーネント（推奨）**

`resources/views/components/split-link.blade.php` を新規作成:

```html
@props(['href', 'title' => null])
<a href="{{ $href }}" 
   @click.prevent="$store.splitView.open('{{ $href }}', '{{ $title ?? '' }}' || $el.textContent.trim())"
   {{ $attributes->merge(['class' => 'text-blue-600 hover:underline cursor-pointer']) }}>
    {{ $slot }}
</a>
```

使用例:
```html
<x-split-link href="{{ route('filament.admin.resources.wms-order-candidates.index') }}">
    発注候補一覧
</x-split-link>
```

#### 4. 解説ページのリンク変更

`auto-order-guide.blade.php` 内の全リンク（約27箇所）を `<x-split-link>` に変更。

**変更前:**
```html
<a href="{{ route('filament.admin.resources.wms-auto-order-job-controls.index') }}" 
   class="text-blue-600 hover:underline">発注・移動候補生成</a>
```

**変更後:**
```html
<x-split-link href="{{ route('filament.admin.resources.wms-auto-order-job-controls.index') }}">
    発注・移動候補生成
</x-split-link>
```

#### 5. モバイル対応

画面幅が狭い場合（`lg` 未満）は Split View を無効にし、通常のページ遷移にフォールバック:

```html
{{-- split-link.blade.php --}}
@props(['href', 'title' => null])
<a href="{{ $href }}"
   @click.prevent.self="
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

#### 6. Livewire ナビゲーション連携

Filament は Livewire の `wire:navigate` でSPAライクなナビゲーションを行う。ページ遷移時に Split View を閉じる:

```javascript
// base.blade.php に追加
document.addEventListener('livewire:navigated', () => {
    // ページ遷移時にSplit Viewを閉じる
    if (Alpine.store('splitView')) {
        Alpine.store('splitView').close();
    }
});
```

#### 7. CSS（theme.css）

```css
/* Split View — ドラッグ中のテキスト選択防止 */
body:has([x-data] .split-view-dragging) {
    user-select: none;
    cursor: col-resize;
}
```

ドラッグ中は `$store.splitView.dragging` を利用して body レベルで `user-select: none` を適用:

```html
<body :class="$store.splitView?.dragging && 'select-none cursor-col-resize'">
```

#### 8. URL変更: auto-order-guide → order-guide

**ページクラス変更（AutoOrderGuide.php → OrderGuide.php）:**

```php
// ファイルリネーム: AutoOrderGuide.php → OrderGuide.php
class OrderGuide extends Page
{
    protected static ?string $slug = 'order-guide';  // 変更前: auto-order-guide
    protected static ?string $navigationLabel = '発注解説';
    // ...他は同じ
}
```

**Bladeテンプレートリネーム:**
- `auto-order-guide.blade.php` → `order-guide.blade.php`
- ページクラスの `$view` を `'filament.pages.order-guide'` に変更

#### 9. 解説ページデザイン改修 — Nav Panel 追加

**目的**: 左側にナビゲーションパネルを追加し、セクションへのジャンプ機能を提供。Split View で幅が狭い時は2カラム（nav(1):本文(2)）にレスポンシブ対応。

**レイアウト構造:**

```
通常表示（幅広い時）: 3カラム
┌──────┬──────────────────────────────┐
│ Nav  │         本文               │
│Panel │  ┌────────────────────────┐ │
│      │  │    タブコンテンツ       │ │
│ 概要 │  │    3カラムgrid等       │ │
│ 流れ │  │                        │ │
│ メニ │  │                        │ │
│ 設定 │  └────────────────────────┘ │
│      │                            │
│ ── │                            │
│ セク │                            │
│ ション│                            │
│ 一覧 │                            │
└──────┴──────────────────────────────┘
 w-48     flex-1

Split View 表示時 or 幅狭い時: 2カラム
┌──────┬──────────────┐
│ Nav  │    本文     │
│Panel │  2カラム    │
│      │  grid等    │
└──────┴──────────────┘
 w-40     flex-1
```

**Alpine.js 状態管理:**

```javascript
x-data="{
    activeTab: 'overview',
    menuTab: 'generation',
    activeSection: null,    // 現在のセクション（スクロール連動）
}"
```

**Nav Panel 構造:**

```html
{{-- Nav Panel --}}
<div class="w-48 shrink-0 border-r border-gray-200 dark:border-gray-700
            overflow-y-auto sticky top-0 h-[calc(100vh-120px)]
            hidden lg:block"
     :class="$store.splitView?.isOpen && 'lg:w-40'">

    {{-- タブ選択（縦ナビ） --}}
    <div class="p-2 space-y-0.5">
        <div class="text-[10px] uppercase text-gray-400 font-semibold px-2 py-1">セクション</div>
        <template x-for="tab in [
            {key:'overview', label:'概要', icon:'information-circle'},
            {key:'flow', label:'発注の流れ', icon:'clock'},
            {key:'menus', label:'メニュー詳細', icon:'squares-2x2'},
            {key:'settings', label:'設定', icon:'cog-6-tooth'},
        ]">
            <button @click="activeTab = tab.key; $nextTick(() => $refs[tab.key]?.scrollIntoView({behavior:'smooth'}))"
                :class="activeTab === tab.key
                    ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-300 border-l-2 border-primary-500'
                    : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800'"
                class="w-full text-left px-3 py-1.5 text-xs rounded-r transition-colors flex items-center gap-2">
                <span x-text="tab.label"></span>
            </button>
        </template>
    </div>

    {{-- タブ内サブセクション（activeTabに応じて表示） --}}
    <div class="p-2 border-t border-gray-200 dark:border-gray-700">
        {{-- flow タブのサブセクション --}}
        <template x-if="activeTab === 'flow'">
            <div class="space-y-0.5">
                <div class="text-[10px] uppercase text-gray-400 font-semibold px-2 py-1">目次</div>
                <button @click="$refs['flow-schedule']?.scrollIntoView({behavior:'smooth'})"
                    class="w-full text-left px-3 py-1 text-[11px] text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 rounded">
                    1日の発注スケジュール
                </button>
                <button @click="$refs['flow-warehouse']?.scrollIntoView({behavior:'smooth'})"
                    class="w-full text-left px-3 py-1 text-[11px] text-gray-500 hover:text-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 rounded">
                    倉庫構成
                </button>
                {{-- ... 他のセクション --}}
            </div>
        </template>

        {{-- menus タブのサブタブ --}}
        <template x-if="activeTab === 'menus'">
            <div class="space-y-0.5">
                <div class="text-[10px] uppercase text-gray-400 font-semibold px-2 py-1">メニュー</div>
                <button @click="menuTab = 'generation'" ...>候補生成</button>
                <button @click="menuTab = 'transfer'" ...>移動候補</button>
                <button @click="menuTab = 'order'" ...>発注候補</button>
                <button @click="menuTab = 'confirm'" ...>確定待ち</button>
                <button @click="menuTab = 'history'" ...>履歴</button>
            </div>
        </template>
    </div>
</div>
```

**本文のレスポンシブ対応:**

Split View 表示時にグリッドカラム数を減らす:

```html
{{-- 概要タブ: 通常3カラム → Split View時2カラム --}}
<div class="grid grid-cols-1 gap-4"
     :class="$store.splitView?.isOpen ? 'lg:grid-cols-2' : 'lg:grid-cols-3'">

{{-- 関連画面リンク: 通常7カラム → Split View時4カラム --}}
<div class="grid grid-cols-2 gap-2"
     :class="$store.splitView?.isOpen ? 'md:grid-cols-4' : 'md:grid-cols-4 lg:grid-cols-7'">

{{-- 設定タブ: 通常3カラム → Split View時2カラム --}}
<div class="grid grid-cols-1 gap-3"
     :class="$store.splitView?.isOpen ? 'md:grid-cols-2' : 'md:grid-cols-2 lg:grid-cols-3'">
```

**上部タブナビを削除:**

Nav Panel にタブ機能が統合されるため、上部の水平タブナビゲーション（`<nav class="-mb-px flex flex-wrap gap-x-6">` の概要/発注の流れ/メニュー詳細/設定ボタン）は削除する。

**モバイル（lg未満）対応:**

Nav Panel は `hidden lg:block` で非表示。代わりにモバイル用のドロワーまたはトップの水平タブを表示:

```html
{{-- モバイル用タブ（lg未満で表示） --}}
<div class="lg:hidden border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
    <nav class="-mb-px flex flex-wrap gap-x-4 overflow-x-auto px-2" aria-label="Tabs">
        {{-- 既存のタブボタン（概要/流れ/メニュー/設定）をここに配置 --}}
    </nav>
</div>
```

### 影響範囲

| ファイル | 変更内容 |
|---------|---------|
| `vendor/.../layout/index.blade.php` | fi-main-ctn 内を左右分割構造に変更 |
| `vendor/.../layout/base.blade.php` | Alpine store 登録 + Livewire navigated イベント |
| `AutoOrderGuide.php` → `OrderGuide.php` | クラスリネーム + slug変更 |
| `auto-order-guide.blade.php` → `order-guide.blade.php` | リネーム + Nav Panel追加 + レスポンシブ対応 |
| `theme.css` | ドラッグ中のスタイル |
| `components/split-link.blade.php` | 新規 Blade コンポーネント |

- 他の全ページ: レイアウト変更の影響を受けるが、Split View が閉じている状態（デフォルト）では見た目・動作に変化なし

## 制約

- FK禁止（CLAUDE.md準拠）
- DB破壊コマンド禁止
- 既存ページの見た目・動作はSplit View非表示時に変化させない
- Tailwind CSS 4 の動的クラスは `@source inline()` で対応
- iframe は同一オリジンのみ対象（セキュリティ上、外部URLは開かない）
- モバイル（lg未満）では通常リンク遷移にフォールバック
- Nav Panel: `lg:block`（1024px以上で表示）、モバイルでは水平タブにフォールバック

## 対象ファイル

### 新規作成
- `resources/views/components/split-link.blade.php` — Split View 用リンクコンポーネント

### リネーム
- `app/Filament/Pages/AutoOrderGuide.php` → `app/Filament/Pages/OrderGuide.php` — slug: `order-guide`
- `resources/views/filament/pages/auto-order-guide.blade.php` → `resources/views/filament/pages/order-guide.blade.php`

### 既存変更
- `resources/views/vendor/filament-panels/components/layout/index.blade.php` — fi-main-ctn を分割構造に
- `resources/views/vendor/filament-panels/components/layout/base.blade.php` — Alpine store 登録
- `resources/views/filament/pages/order-guide.blade.php` — Nav Panel追加 + レスポンシブ対応 + リンクを `<x-split-link>` に変更
- `resources/css/filament/admin/theme.css` — ドラッグ中スタイル追加

### 参照のみ
- `app/Providers/Filament/AdminPanelProvider.php` — パネル設定確認

## 確認事項

1. iframe内のFilamentサイドバー: 一旦表示のまま
2. 初期比率: 50:50
3. モバイル: Nav Panel非表示 → 水平タブにフォールバック、リンクは通常遷移
4. タブ切替時: Split View を閉じない（同ページ内Alpine制御）
5. 全ページで利用可能（`<x-split-link>` コンポーネント経由）
6. Split View 表示時: グリッドカラム数を自動削減（3→2, 7→4等）
7. Nav Panel 幅: 通常 w-48、Split View時 w-40
