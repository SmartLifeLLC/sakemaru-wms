# Filament 4 UI カスタマイズガイド

SAKEMARU-WMSで実装したメガメニューとテーブルスクロールのカスタマイズ方法をまとめたドキュメントです。
SAKEMARU-TRADEなど他プロジェクトへの移植時に参照してください。

---

## 目次

1. [メガメニュー実装](#1-メガメニュー実装)
2. [テーブルスクロール固定](#2-テーブルスクロール固定)
3. [必要ファイル一覧](#3-必要ファイル一覧)

---

## 1. メガメニュー実装

### 概要

- トップナビゲーションにメガメニュー（ドロップダウン式の大型メニュー）を実装
- Filamentの標準ナビゲーショングループをタブ形式で表示
- Alpine.jsでドロップダウンの開閉を制御

### 必要ファイル

| ファイル | 役割 |
|---------|------|
| `app/Livewire/MegaMenu.php` | Livewireコンポーネント（メニュー構造のロジック） |
| `resources/views/livewire/mega-menu.blade.php` | ビュー（HTML/デザイン） |
| `app/Enums/EMenuCategory.php` | メニューカテゴリ定義（Enum） |
| `resources/views/vendor/filament-panels/components/layout/index.blade.php` | レイアウト（メガメニュー呼び出し） |

---

### 1.1 Livewireコンポーネント

**ファイル: `app/Livewire/MegaMenu.php`**

```php
<?php

namespace App\Livewire;

use App\Enums\EMenuCategory;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Livewire\Component;

class MegaMenu extends Component
{
    public array $menuStructure = [];

    public function mount()
    {
        $this->menuStructure = $this->buildMenuStructure();
    }

    public function render()
    {
        return view('livewire.mega-menu');
    }

    public function getUserMenuItems(): array
    {
        return Filament::getUserMenuItems();
    }

    protected function buildMenuStructure(): array
    {
        $navigation = Filament::getNavigation();

        // タブ定義: 各タブにどのカテゴリを含めるか設定
        $tabs = [
            'outbound' => [
                'label' => '出荷',
                'icon' => 'fa-arrow-up-from-bracket',
                'categories' => [
                    EMenuCategory::OUTBOUND,
                    EMenuCategory::SHORTAGE,
                    EMenuCategory::HORIZONTAL_SHIPMENT,
                ],
            ],
            'inbound' => [
                'label' => '入荷',
                'icon' => 'fa-arrow-down-to-bracket',
                'categories' => [
                    EMenuCategory::INBOUND,
                ],
            ],
            // ... 他のタブ定義
        ];

        $structure = [];

        foreach ($tabs as $key => $tab) {
            $groupsForTab = [];

            foreach ($tab['categories'] as $category) {
                $categoryLabel = $category->label();

                foreach ($navigation as $navGroup) {
                    if ($navGroup instanceof NavigationGroup && $navGroup->getLabel() === $categoryLabel) {
                        $items = $navGroup->getItems();
                        if (count($items) > 0) {
                            $groupsForTab[] = [
                                'label' => $categoryLabel,
                                'icon' => $category->icon(),
                                'items' => collect($items)->map(fn (NavigationItem $item) => [
                                    'label' => $item->getLabel(),
                                    'url' => $item->getUrl(),
                                    'isActive' => $item->isActive(),
                                    'icon' => $this->getIconString($item->getIcon()),
                                ])->toArray(),
                            ];
                        }
                    }
                }
            }

            if (count($groupsForTab) > 0) {
                $structure[] = [
                    'id' => $key,
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'groups' => $groupsForTab,
                ];
            }
        }

        return $structure;
    }

    protected function getIconString($icon): ?string
    {
        $iconClass = null;

        if ($icon instanceof \BackedEnum) {
            $iconClass = $icon->value;
        } elseif (is_string($icon)) {
            $iconClass = $icon;
        }

        if ($iconClass && (str_starts_with($iconClass, 'o-') || str_starts_with($iconClass, 's-')) && ! str_starts_with($iconClass, 'heroicon-')) {
            return 'heroicon-'.$iconClass;
        }

        return $iconClass;
    }
}
```

---

### 1.2 ビューテンプレート

**ファイル: `resources/views/livewire/mega-menu.blade.php`**

```html
<div x-data="{ openTab: null }" class="relative" @click.outside="openTab = null">
    <!-- FontAwesome読み込み -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <!-- メインナビゲーションバー -->
    <header class="bg-slate-800 sticky top-0 z-50 shadow-md">
        <div class="w-full px-4 md:px-6">
            <div class="flex items-center justify-between h-10">

                <!-- ロゴ & ナビ (左側) -->
                <div class="flex items-center gap-8">
                    <!-- ロゴ -->
                    <a href="/admin" class="flex items-center bg-white rounded-md px-2 py-1">
                        <img src="/images/logo.png" alt="ロゴ" class="h-6 w-auto object-contain">
                    </a>

                    <!-- デスクトップナビ -->
                    <nav class="flex items-center gap-1">
                        @forelse($menuStructure as $tab)
                            <button
                                type="button"
                                class="flex items-center gap-2 px-4 py-1 text-lg font-medium transition-colors duration-200 rounded-md"
                                :class="openTab === '{{ $tab['id'] }}' ? 'text-white bg-slate-700' : 'text-slate-200 hover:text-white hover:bg-slate-700'"
                                @click="openTab = openTab === '{{ $tab['id'] }}' ? null : '{{ $tab['id'] }}'"
                            >
                                <i class="fa-solid {{ $tab['icon'] }}"></i>
                                <span>{{ $tab['label'] }}</span>
                                <i class="fa-solid fa-chevron-down text-[12px] transition-transform duration-200"
                                   :class="openTab === '{{ $tab['id'] }}' ? 'rotate-180' : ''"></i>
                            </button>
                        @empty
                            <span class="text-red-500 text-sm">メニューが空です</span>
                        @endforelse
                    </nav>
                </div>

                <!-- 右側: ユーザーメニュー -->
                <div class="flex items-center gap-3">
                    @if (filament()->isGlobalSearchEnabled())
                        <div class="mr-2">
                            @livewire(filament()->getGlobalSearchLivewireComponent())
                        </div>
                    @endif

                    @if (filament()->hasDatabaseNotifications())
                        @livewire(filament()->getDatabaseNotificationsLivewireComponent())
                    @endif

                    <x-filament-panels::user-menu />
                </div>
            </div>
        </div>

        <!-- メガメニュードロップダウン -->
        @foreach($menuStructure as $tab)
            <div
                x-show="openTab === '{{ $tab['id'] }}'"
                class="fixed left-0 right-0 bg-white border-b border-slate-200 shadow-xl z-40"
                style="top: 40px;"
                x-cloak
            >
                <div class="w-full px-16 py-6">
                    <div class="flex flex-wrap gap-12 justify-start">
                        @foreach($tab['groups'] as $index => $group)
                            @php
                                $itemCount = count($group['items']);
                                $columns = match(true) {
                                    $itemCount > 10 => 3,
                                    $itemCount > 5 => 2,
                                    default => 1,
                                };
                                $minWidth = match($columns) {
                                    3 => 'min-w-[600px]',
                                    default => 'min-w-[400px]',
                                };
                            @endphp
                            <div class="flex gap-12">
                                @if($index > 0)
                                    <div class="w-px bg-slate-200 self-stretch"></div>
                                @endif
                                <div class="{{ $minWidth }}">
                                <div class="flex flex-col gap-3">
                                    <!-- グループヘッダー -->
                                    <div class="flex items-center gap-2 pb-2 border-b border-slate-100">
                                        @if(isset($group['icon']) && $group['icon'])
                                            <x-filament::icon
                                                :icon="$group['icon']"
                                                class="w-5 h-5 text-indigo-600"
                                            />
                                        @endif
                                        <h3 class="font-bold text-slate-800 text-base">{{ $group['label'] }}</h3>
                                    </div>

                                    <!-- メニューアイテム -->
                                    <ul class="@if($columns === 3) grid grid-cols-3 gap-1 @elseif($columns === 2) grid grid-cols-2 gap-1 @else flex flex-col gap-1 @endif">
                                        @foreach($group['items'] as $item)
                                            <li>
                                                <a href="{{ $item['url'] }}"
                                                   class="group flex items-center gap-3 p-2 rounded-lg transition-all duration-150 hover:bg-indigo-100 {{ $item['isActive'] ? 'bg-indigo-50' : '' }}">
                                                    <div class="flex-shrink-0 p-1.5 rounded-md bg-white border border-slate-200 text-slate-500 transition-colors shadow-sm group-hover:bg-indigo-600 group-hover:border-indigo-600 group-hover:text-white {{ $item['isActive'] ? 'text-indigo-600 border-indigo-200' : '' }}">
                                                        @if(isset($item['icon']) && $item['icon'])
                                                            <x-filament::icon
                                                                :icon="$item['icon']"
                                                                class="w-4 h-4"
                                                            />
                                                        @else
                                                            <i class="fa-solid fa-circle text-[6px] w-4 h-4 flex items-center justify-center"></i>
                                                        @endif
                                                    </div>
                                                    <span class="text-base font-medium text-slate-700 transition-colors group-hover:text-indigo-700 group-hover:font-semibold {{ $item['isActive'] ? 'text-indigo-700 font-semibold' : '' }}">
                                                        {{ $item['label'] }}
                                                    </span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </header>
</div>
```

---

### 1.3 メニューカテゴリEnum

**ファイル: `app/Enums/EMenuCategory.php`**

```php
<?php

namespace App\Enums;

enum EMenuCategory: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';
    case SHORTAGE = 'shortage';
    // ... 他のカテゴリ

    public function label(): string
    {
        return match ($this) {
            self::INBOUND => '入荷管理',
            self::OUTBOUND => '出荷管理',
            self::SHORTAGE => '欠品管理',
            // ...
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::INBOUND => 'heroicon-o-arrow-down-tray',
            self::OUTBOUND => 'heroicon-o-arrow-up-tray',
            self::SHORTAGE => 'heroicon-o-exclamation-triangle',
            // ...
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::INBOUND => 1,
            self::OUTBOUND => 2,
            self::SHORTAGE => 3,
            // ...
        };
    }
}
```

---

### 1.4 レイアウトファイルの修正

**ファイル: `resources/views/vendor/filament-panels/components/layout/index.blade.php`**

Filamentの標準topbarをメガメニューに置き換える:

```php
@if ($hasTopbar)
    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_BEFORE, scopes: $renderHookScopes) }}

    {{-- @livewire(filament()->getTopbarLivewireComponent()) --}}
    <livewire:mega-menu />

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_AFTER, scopes: $renderHookScopes) }}
```

**publish方法:**
```bash
php artisan vendor:publish --tag=filament-panels-views
```

---

### 1.5 AdminPanelProvider設定

**ファイル: `app/Providers/Filament/AdminPanelProvider.php`**

```php
return $panel
    ->default()
    ->id('admin')
    ->path('admin')
    ->topNavigation()           // トップナビゲーションを有効化
    ->maxContentWidth('full')   // コンテンツ幅を最大化
    ->breadcrumbs(false)        // パンくずリストを無効化
    ->navigationGroups(
        collect(EMenuCategory::cases())
            ->sortBy(fn (EMenuCategory $category) => $category->sort())
            ->map(fn (EMenuCategory $category) => NavigationGroup::make($category->label()))
            ->values()
            ->toArray()
    )
    // ...
```

---

## 2. テーブルスクロール固定

### 概要

- テーブルの高さを画面に固定
- 縦スクロールバーをテーブル内に表示
- ヘッダー（thead）を固定して常に表示
- フッター（ページネーション）も固定

### 実装方法

各リストページのBladeファイルにCSSを追加:

**ファイル例: `resources/views/filament/resources/{resource}/pages/list-{resource}.blade.php`**

```html
<x-filament-panels::page>
    <style>
        /* テーブル全体のコンテナ */
        .fi-ta {
            position: relative;
            display: flex;
            flex-direction: column;
            height: calc(100vh - 10rem);  /* 画面高さ - ヘッダー等の高さ */
        }

        /* テーブルコンテナ - スクロール可能 */
        .fi-ta-ctn {
            flex: 1;
            overflow-y: auto;
            overflow-x: auto;
        }

        /* theadを上部固定 */
        .fi-ta thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: white;
        }

        /* ダークモード対応 */
        .dark .fi-ta thead {
            background-color: rgb(17 24 39);
        }

        /* 各thの背景色を継承 */
        .fi-ta thead th {
            background-color: inherit;
        }

        /* フッター（ページネーション）を下部固定 */
        .fi-ta-footer {
            flex-shrink: 0;
            background-color: white;
            border-top: 1px solid rgb(229 231 235);
        }

        /* ダークモード対応 */
        .dark .fi-ta-footer {
            background-color: rgb(17 24 39);
            border-top-color: rgb(55 65 81);
        }
    </style>

    {{ $this->table }}
</x-filament-panels::page>
```

### 高さの調整

`height: calc(100vh - 10rem)` の `10rem` は以下を考慮して調整:

| 要素 | 高さの目安 |
|-----|----------|
| メガメニュー | 2.5rem (40px) |
| ページヘッダー | 3rem |
| フィルター・ツールバー | 3rem |
| マージン・パディング | 1.5rem |
| **合計** | **約10rem** |

画面構成に応じて調整してください。

---

## 3. 必要ファイル一覧

### メガメニュー関連

```
app/
├── Livewire/
│   └── MegaMenu.php                    # Livewireコンポーネント
├── Enums/
│   └── EMenuCategory.php               # メニューカテゴリEnum
└── Providers/Filament/
    └── AdminPanelProvider.php          # パネル設定

resources/
├── views/
│   ├── livewire/
│   │   └── mega-menu.blade.php         # メガメニュービュー
│   └── vendor/filament-panels/
│       └── components/layout/
│           └── index.blade.php         # レイアウト（メガメニュー呼び出し）
└── css/filament/admin/
    └── theme.css                       # CSSテーマ
```

### テーブルスクロール関連

```
resources/views/filament/resources/{リソース名}/pages/
└── list-{リソース名}.blade.php          # 各リストページにCSSを追加
```

---

## 4. セットアップ手順

### 4.1 メガメニューのセットアップ

1. **Livewireコンポーネント作成**
   ```bash
   php artisan make:livewire MegaMenu
   ```

2. **ファイルコピー**
   - `app/Livewire/MegaMenu.php`
   - `resources/views/livewire/mega-menu.blade.php`
   - `app/Enums/EMenuCategory.php`

3. **Filamentビューのpublish**
   ```bash
   php artisan vendor:publish --tag=filament-panels-views
   ```

4. **レイアウトファイル修正**
   - `resources/views/vendor/filament-panels/components/layout/index.blade.php`
   - 標準topbarをメガメニューに置き換え

5. **AdminPanelProvider設定**
   - `->topNavigation()` を有効化
   - `->navigationGroups()` でカテゴリ設定

6. **アセットビルド**
   ```bash
   npm run build
   ```

### 4.2 テーブルスクロールのセットアップ

1. **リストページのカスタムビュー作成**
   ```
   resources/views/filament/resources/{リソース}/pages/list-{リソース}.blade.php
   ```

2. **CSSスタイル追加**
   - 上記のCSS例を参照

3. **Resourceクラスで指定** (任意)
   ```php
   public function getPages(): array
   {
       return [
           'index' => Pages\ListXxx::route('/'),
       ];
   }
   ```

---

## 5. カスタマイズポイント

### メニューの色変更

`mega-menu.blade.php` の以下を変更:

```html
<!-- ヘッダー背景色 -->
<header class="bg-slate-800 ...">

<!-- ホバー時の色 -->
hover:bg-indigo-100
group-hover:bg-indigo-600
```

### メニュー高さ変更

```html
<!-- ヘッダー高さ -->
<div class="flex items-center justify-between h-10">

<!-- ドロップダウン位置 -->
style="top: 40px;"
```

### テーブル高さ変更

```css
.fi-ta {
    height: calc(100vh - 10rem);  /* この値を調整 */
}
```

---

## 6. 依存パッケージ

- **Filament 4.x**
- **Livewire 3.x**
- **Alpine.js** (Filamentに含まれる)
- **FontAwesome 6.x** (CDN経由)
- **Tailwind CSS 4.x**

---

## 7. 注意事項

1. **Filamentバージョン**: このガイドはFilament 4を対象としています。バージョンが異なる場合は調整が必要です。

2. **レイアウトのpublish**: `vendor:publish` でpublishしたビューは、Filamentのアップデート時に手動でマージが必要になる場合があります。

3. **レスポンシブ対応**: メガメニューはデスクトップ向けです。モバイル対応が必要な場合は別途実装が必要です。

4. **パフォーマンス**: 大量のメニュー項目がある場合、初期表示時のパフォーマンスに影響する可能性があります。

---

*作成日: 2026-01-17*
*SAKEMARU-WMS プロジェクト*
