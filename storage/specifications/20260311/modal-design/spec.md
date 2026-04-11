# WMS モーダルデザイン統一仕様書

> 作成日: 2026-03-11
> 参考元: sakemaru-ai-core `/earnings` 画面のモーダルデザイン
> 適用先: sakemaru-wms 全モーダル（段階的移行）

---

## 1. 概要

### 目的
WMS側のモーダルデザインを統一し、sakemaru-ai-core `/earnings` 画面で採用されている
洗練されたデザインパターンに合わせる。共通Bladeコンポーネントとして実装し、
既存モーダルは段階的に移行する。

### 現状の課題
- WMS側モーダルのデザインが不統一（Navy header `#1e3a5f`、直接Tailwindクラス、Filament標準等が混在）
- フォーム要素のスタイルがモーダルごとに異なる
- アニメーション・トランジションが不揃い

### 目標デザイン
sakemaru-ai-core の以下3モーダルをベースとする:
1. **フィルタ条件モーダル** — 複数フォーム要素のグリッドレイアウト
2. **カラムモーダル** — チェックボックスグリッド + ライブ更新
3. **配送業務連絡モーダル** — タブ切替 + フォーム入力 + リスト表示

---

## 2. 共通デザイン基盤

### 2.1 モーダルコンテナ

```html
<!-- Backdrop -->
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40"
     x-show="open" x-cloak
     @click.self="open = false"
     @keydown.escape.window="open = false">

    <!-- Modal Box -->
    <div class="bg-white rounded-lg shadow-xl w-full {SIZE_CLASS} mx-4 max-h-[80vh] flex flex-col pointer-events-auto"
         @click.stop
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95">

        <!-- Header -->
        <!-- Content -->
        <!-- Footer -->
    </div>
</div>
```

### 2.2 サイズバリエーション

| サイズ名  | Tailwind Class | 用途                        |
|-----------|----------------|-----------------------------|
| `sm`      | `max-w-sm`     | 確認ダイアログ、簡易入力     |
| `md`      | `max-w-md`     | 単一フォーム、詳細表示       |
| `lg`      | `max-w-lg`     | 中規模フォーム               |
| `xl`      | `max-w-xl`     | リスト表示                   |
| `2xl`     | `max-w-2xl`    | テーブル付きモーダル         |
| `3xl`     | `max-w-3xl`    | カラム選択（4列グリッド）    |
| `6xl`     | `max-w-6xl`    | フィルタ条件（4列グリッド）  |

### 2.3 ヘッダー

```html
<div class="flex items-center justify-between px-4 py-3 border-b bg-slate-50 rounded-t-lg">
    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700">
        <i class="fa fa-{ICON}"></i>
        {TITLE}
    </h3>
    <button @click="open = false" class="text-slate-400 hover:text-slate-600">
        <i class="fa fa-times"></i>
    </button>
</div>
```

**ポイント:**
- 背景: `bg-slate-50` + `border-b`（Navy背景は使わない）
- テキスト: `text-sm font-bold text-slate-700`
- アイコン: FontAwesome 使用
- 閉じるボタン: `text-slate-400 hover:text-slate-600`

### 2.4 コンテンツエリア

```html
<div class="p-4 overflow-y-auto flex-1">
    <!-- コンテンツ -->
</div>
```

- `overflow-y-auto` + `flex-1` でスクロール可能
- パディング: `p-4`（コンパクト）または `p-6`（ゆったり）

### 2.5 フッター

```html
<div class="flex items-center justify-end px-4 py-3 border-t bg-slate-50 rounded-b-lg">
    <!-- ボタン群 -->
</div>
```

- 背景: `bg-slate-50` + `border-t`
- ボタン配置: `justify-end`（右寄せ）
- 左右にボタンを配置する場合: `justify-between`

### 2.6 Z-Index 戦略

| レイヤー           | Z-Index    |
|--------------------|------------|
| 通常モーダル       | `z-[100]`  |
| モーダル上のモーダル | `z-[120]`  |
| ドロップダウン（モーダル内） | `z-10` |

### 2.7 トランジション

全モーダル共通:
```
enter:  ease-out 200ms → opacity-0 scale-95 → opacity-100 scale-100
leave:  ease-in  150ms → opacity-100 scale-100 → opacity-0 scale-95
```

---

## 3. カラーパレット

### 基本カラー
| 用途           | クラス                          |
|----------------|---------------------------------|
| 背景（白）     | `bg-white`                      |
| 背景（薄灰）   | `bg-slate-50`                   |
| ボーダー       | `border-slate-200`              |
| テキスト（主）  | `text-slate-700`                |
| テキスト（副）  | `text-slate-500`                |
| テキスト（淡）  | `text-slate-400`                |
| バックドロップ  | `bg-black/40`                   |

### アクセントカラー
| 用途            | クラス                                     |
|-----------------|--------------------------------------------|
| プライマリ      | `bg-blue-600 hover:bg-blue-700 text-white` |
| アクティブ状態  | `border-blue-400 bg-blue-50`               |
| リンク          | `text-blue-600`                            |
| 警告バッジ      | `bg-orange-100 text-orange-700`            |
| 危険            | `text-red-500 hover:text-red-700`          |

---

## 4. フォーム要素デザイン

### 4.1 テキスト入力 / 日付入力

```html
<label class="block text-xs font-medium text-slate-600 mb-1">{LABEL}</label>
<input type="text"
       class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm
              focus:ring-2 focus:ring-blue-500 focus:border-blue-500
              placeholder:text-slate-400"
       placeholder="{PLACEHOLDER}">
```

### 4.2 セレクトボックス

```html
<label class="block text-xs font-medium text-slate-600 mb-1">{LABEL}</label>
<select class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm
               focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    <option value="">{PLACEHOLDER}</option>
</select>
```

### 4.3 検索付きドロップダウン（カスタム）

```html
<div x-data="{ search: '', isOpen: false }">
    <!-- トリガーボタン -->
    <button @click="isOpen = !isOpen"
            class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm
                   text-left flex items-center justify-between">
        <span>{SELECTED_LABEL}</span>
        <i class="fa fa-chevron-down text-xs text-slate-400"></i>
    </button>

    <!-- ドロップダウン -->
    <div x-show="isOpen" x-transition
         class="absolute z-10 mt-1 w-full bg-white border border-slate-200
                rounded-xl shadow-xl max-h-60 overflow-y-auto">
        <!-- 検索入力 -->
        <div class="p-2 border-b">
            <input type="text" x-model="search"
                   class="w-full bg-slate-50 rounded-lg px-3 py-2 text-sm
                          border-none focus:ring-0"
                   placeholder="検索...">
        </div>
        <!-- オプション -->
        <div class="py-1">
            <button class="w-full px-3 py-2 text-sm text-left
                           hover:bg-blue-50 transition-colors"
                    :class="selected ? 'text-blue-600 font-bold bg-blue-50/50' : ''">
                {OPTION_LABEL}
            </button>
        </div>
    </div>
</div>
```

### 4.4 テキストエリア

```html
<label class="block text-xs font-medium text-slate-600 mb-1">{LABEL}</label>
<textarea rows="6"
          class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm
                 resize-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          placeholder="{PLACEHOLDER}"></textarea>
```

### 4.5 チェックボックス（グリッド配置）

```html
<div class="grid grid-cols-4 gap-2">
    <label class="flex items-center p-2 rounded border cursor-pointer
                  transition-colors hover:bg-slate-50"
           :class="checked ? 'border-blue-400 bg-blue-50' : 'border-slate-200'">
        <input type="checkbox"
               class="w-4 h-4 rounded text-blue-600 border-slate-300">
        <span class="ml-2 text-xs text-slate-700">{LABEL}</span>
    </label>
</div>
```

### 4.6 ボタン

```html
<!-- プライマリ（実行/保存） -->
<button class="px-3 py-1.5 text-xs font-medium text-white
               bg-blue-600 rounded hover:bg-blue-700">
    <i class="fa fa-check mr-1"></i> {LABEL}
</button>

<!-- セカンダリ（キャンセル） -->
<button class="px-3 py-1.5 text-xs font-medium text-slate-600
               bg-slate-100 rounded hover:bg-slate-200">
    {LABEL}
</button>

<!-- フル幅（送信ボタン） -->
<button class="w-full py-3.5 rounded-xl text-sm font-medium text-white
               bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-200
               active:scale-95 transition-all">
    {LABEL}
</button>

<!-- リセット/クリア -->
<button class="px-3 py-1.5 text-xs font-medium text-slate-500
               hover:text-slate-700">
    <i class="fa fa-eraser mr-1"></i> {LABEL}
</button>
```

---

## 5. モーダルタイプ別テンプレート

### 5.1 フィルタモーダル（Type: filter）

**用途**: テーブルの絞り込み条件入力
**サイズ**: `max-w-6xl`
**レイアウト**: 4列グリッド

```html
<!-- Header -->
<div class="flex items-center justify-between px-4 py-3 border-b bg-slate-50 rounded-t-lg">
    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700">
        <i class="fa fa-filter"></i> フィルタ条件
    </h3>
    <button @click="open = false" class="text-slate-400 hover:text-slate-600">
        <i class="fa fa-times"></i>
    </button>
</div>

<!-- Content -->
<div class="p-4 overflow-y-auto flex-1">
    <div class="grid grid-cols-4 gap-2">
        <!-- 各フィルタ項目: input / select / date を配置 -->
        <div class="p-2 rounded bg-slate-50">
            <label class="block text-xs font-medium text-slate-600 mb-1">項目名</label>
            <input type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm">
        </div>
    </div>
</div>

<!-- Footer -->
<div class="flex items-center justify-between px-4 py-3 border-t bg-slate-50 rounded-b-lg">
    <button class="px-3 py-1.5 text-xs font-medium text-slate-500 hover:text-slate-700">
        <i class="fa fa-eraser mr-1"></i> クリア
    </button>
    <div class="flex gap-2">
        <button @click="open = false"
                class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 rounded hover:bg-slate-200">
            キャンセル
        </button>
        <button class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
            <i class="fa fa-search mr-1"></i> 適用
        </button>
    </div>
</div>
```

### 5.2 カラム選択モーダル（Type: column-select）

**用途**: テーブルの表示カラム切替
**サイズ**: `max-w-3xl`
**レイアウト**: 4列チェックボックスグリッド

```html
<!-- Header -->
<div class="flex items-center justify-between px-4 py-3 border-b bg-slate-50 rounded-t-lg">
    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700">
        <i class="fa fa-columns"></i> カラム
    </h3>
    <button @click="open = false" class="text-slate-400 hover:text-slate-600">
        <i class="fa fa-times"></i>
    </button>
</div>

<!-- Content -->
<div class="p-4 overflow-y-auto flex-1">
    <div class="grid grid-cols-4 gap-2">
        <label class="flex items-center p-2 rounded border cursor-pointer
                      transition-colors hover:bg-slate-50"
               :class="checked ? 'border-blue-400 bg-blue-50' : 'border-slate-200'">
            <input type="checkbox" class="w-4 h-4 rounded text-blue-600 border-slate-300">
            <span class="ml-2 text-xs text-slate-700">カラム名</span>
        </label>
    </div>
</div>

<!-- Footer -->
<div class="flex items-center justify-end px-4 py-3 border-t bg-slate-50 rounded-b-lg">
    <button @click="open = false"
            class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
        <i class="fa fa-check mr-1"></i> 完了
    </button>
</div>
```

### 5.3 フォーム入力モーダル（Type: form）

**用途**: データ登録/編集
**サイズ**: `max-w-lg` ～ `max-w-2xl`

```html
<!-- Header -->
<div class="flex items-center justify-between px-4 py-3 border-b bg-slate-50 rounded-t-lg">
    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700">
        <i class="fa fa-edit"></i> {TITLE}
    </h3>
    <button @click="open = false" class="text-slate-400 hover:text-slate-600">
        <i class="fa fa-times"></i>
    </button>
</div>

<!-- Content -->
<div class="p-6 overflow-y-auto flex-1 space-y-4">
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">項目名</label>
        <input type="text"
               class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm
                      focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">備考</label>
        <textarea rows="4"
                  class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm
                         resize-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  placeholder="入力してください..."></textarea>
    </div>
</div>

<!-- Footer -->
<div class="flex items-center justify-end gap-2 px-4 py-3 border-t bg-slate-50 rounded-b-lg">
    <button @click="open = false"
            class="px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 rounded hover:bg-slate-200">
        キャンセル
    </button>
    <button class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
        <i class="fa fa-save mr-1"></i> 保存
    </button>
</div>
```

### 5.4 詳細表示モーダル（Type: detail）

**用途**: データ詳細閲覧（読み取り専用）
**サイズ**: `max-w-2xl` ～ `max-w-6xl`

```html
<!-- Header -->
<div class="flex items-center justify-between px-4 py-3 border-b bg-slate-50 rounded-t-lg">
    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700">
        <i class="fa fa-info-circle"></i> {TITLE}
    </h3>
    <button @click="open = false" class="text-slate-400 hover:text-slate-600">
        <i class="fa fa-times"></i>
    </button>
</div>

<!-- Content -->
<div class="p-4 overflow-y-auto flex-1 space-y-4">
    <!-- 情報カード -->
    <div class="grid grid-cols-3 gap-3">
        <div class="p-3 rounded-lg bg-slate-50 border border-slate-200">
            <dt class="text-xs font-medium text-slate-500 mb-1">項目名</dt>
            <dd class="text-sm font-bold text-slate-800">{VALUE}</dd>
        </div>
    </div>

    <!-- テーブル -->
    <div class="border border-slate-200 rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-3 py-2 text-xs font-medium text-slate-600 text-left">見出し</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-3 py-2 text-sm text-slate-700">{DATA}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Footer -->
<div class="flex items-center justify-end px-4 py-3 border-t bg-slate-50 rounded-b-lg">
    <button @click="open = false"
            class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
        <i class="fa fa-check mr-1"></i> 閉じる
    </button>
</div>
```

### 5.5 タブ付きモーダル（Type: tabbed）

**用途**: 複数ビュー切替（リスト + 新規作成 等）
**サイズ**: `max-w-2xl`

```html
<!-- Header -->
<div class="flex items-center justify-between px-4 py-3 border-b bg-slate-50 rounded-t-lg">
    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700">
        <i class="fa fa-{ICON}"></i> {TITLE}
    </h3>
    <button @click="open = false" class="text-slate-400 hover:text-slate-600">
        <i class="fa fa-times"></i>
    </button>
</div>

<!-- Tabs -->
<div class="flex border-b px-4" x-data="{ activeTab: 'list' }">
    <button @click="activeTab = 'list'"
            class="px-4 py-2 text-sm font-medium transition-colors"
            :class="activeTab === 'list'
                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30'
                : 'text-slate-500 hover:text-slate-700'">
        一覧
    </button>
    <button @click="activeTab = 'create'"
            class="px-4 py-2 text-sm font-medium transition-colors"
            :class="activeTab === 'create'
                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30'
                : 'text-slate-500 hover:text-slate-700'">
        新規作成
    </button>
</div>

<!-- Tab Content -->
<div class="p-4 overflow-y-auto flex-1">
    <!-- List Tab -->
    <div x-show="activeTab === 'list'">
        <!-- リスト内容 -->
    </div>

    <!-- Create Tab -->
    <div x-show="activeTab === 'create'">
        <!-- フォーム内容 -->
    </div>
</div>
```

### 5.6 確認ダイアログ（Type: confirm）

**用途**: 削除確認、実行確認
**サイズ**: `max-w-sm`

```html
<!-- Content -->
<div class="p-6 text-center">
    <div class="mx-auto w-12 h-12 rounded-full bg-red-50 flex items-center justify-center mb-4">
        <i class="fa fa-exclamation-triangle text-red-500 text-xl"></i>
    </div>
    <h3 class="text-sm font-bold text-slate-800 mb-2">{TITLE}</h3>
    <p class="text-xs text-slate-500">{MESSAGE}</p>
</div>

<!-- Footer -->
<div class="flex items-center justify-center gap-2 px-4 py-3 border-t bg-slate-50 rounded-b-lg">
    <button @click="open = false"
            class="px-4 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 rounded hover:bg-slate-200">
        キャンセル
    </button>
    <button class="px-4 py-1.5 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700">
        <i class="fa fa-trash mr-1"></i> 削除
    </button>
</div>
```

---

## 6. バッジ・ステータス表示

```html
<!-- 情報バッジ -->
<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full
             bg-blue-50 text-blue-700">
    {LABEL}
</span>

<!-- 全体向けバッジ -->
<span class="inline-flex items-center px-2 py-0.5 text-xs font-black uppercase tracking-tighter rounded-full
             bg-orange-100 text-orange-700">
    全体
</span>

<!-- ユーザーバッジ -->
<span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full
             bg-slate-100 text-slate-500">
    <i class="fa fa-user text-[10px]"></i> {USER_NAME}
</span>

<!-- ステータスバッジ -->
<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full
             bg-green-100 text-green-700">完了</span>
<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full
             bg-yellow-100 text-yellow-700">処理中</span>
<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full
             bg-red-100 text-red-700">エラー</span>
```

---

## 7. リストアイテム（モーダル内）

```html
<!-- メッセージ/通知アイテム -->
<div class="border border-slate-200 rounded-xl p-4 bg-white hover:shadow-md transition-shadow">
    <!-- ヘッダー行 -->
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
            <span class="badge">{CATEGORY}</span>
            <span class="text-xs text-slate-400">{TIMESTAMP}</span>
        </div>
        <div class="flex items-center gap-2">
            <span class="user-badge">{USER}</span>
            <button class="text-slate-300 hover:text-red-500 transition-colors">
                <i class="fa fa-times text-xs"></i>
            </button>
        </div>
    </div>
    <!-- コンテンツ -->
    <p class="text-sm text-slate-700 whitespace-pre-wrap">{CONTENT}</p>
</div>
```

---

## 8. 空状態表示

```html
<div class="flex flex-col items-center justify-center py-12 text-slate-400">
    <i class="fa fa-{ICON} text-3xl mb-3"></i>
    <p class="text-sm">{MESSAGE}</p>
</div>
```

---

## 9. Dark Mode 対応ガイドライン

WMS側はダークモード対応済みのため、以下のクラスを追加で適用する:

| Light              | Dark                        |
|--------------------|-----------------------------|
| `bg-white`         | `dark:bg-gray-800`          |
| `bg-slate-50`      | `dark:bg-gray-900`          |
| `border-slate-200` | `dark:border-gray-700`      |
| `text-slate-700`   | `dark:text-gray-200`        |
| `text-slate-500`   | `dark:text-gray-400`        |
| `text-slate-400`   | `dark:text-gray-500`        |
| `bg-blue-50`       | `dark:bg-blue-900/30`       |
| `bg-black/40`      | `dark:bg-black/60`          |
| `hover:bg-slate-50`| `dark:hover:bg-gray-700`    |

---

## 10. 実装ガイドライン

### 10.1 Bladeコンポーネント化

以下のBladeコンポーネントを`resources/views/components/modal/`に作成:

```
components/modal/
├── container.blade.php    ← モーダルコンテナ（backdrop + box + transition）
├── header.blade.php       ← ヘッダー（タイトル + 閉じるボタン）
├── content.blade.php      ← コンテンツエリア（スクロール対応）
├── footer.blade.php       ← フッター（ボタン配置）
├── form-group.blade.php   ← フォームグループ（label + input ラッパー）
└── confirm.blade.php      ← 確認ダイアログ（ショートカット）
```

### 10.2 使用例

```blade
<x-modal.container size="lg" alpine-var="showEditModal">
    <x-modal.header icon="edit" title="商品編集" alpine-var="showEditModal" />
    <x-modal.content>
        <div class="space-y-4">
            <x-modal.form-group label="商品名">
                <input type="text" wire:model="name" class="...">
            </x-modal.form-group>
        </div>
    </x-modal.content>
    <x-modal.footer>
        <button @click="showEditModal = false" class="...">キャンセル</button>
        <button wire:click="save" class="...">保存</button>
    </x-modal.footer>
</x-modal.container>
```

### 10.3 移行計画

**既存コードは修正しない。** 新規モーダルから段階的に適用:

1. **Phase 1**: 共通コンポーネント作成（`components/modal/*`）
2. **Phase 2**: 新規開発モーダルで採用
3. **Phase 3**: 既存モーダルの段階的置き換え（優先度: 使用頻度の高いものから）

### 10.4 WMS 既存モーダル一覧（移行対象）

| ファイル | 種別 | 優先度 |
|----------|------|--------|
| `floor-plan-editor/zone-edit-modal.blade.php` | form | 中 |
| `floor-plan-editor/add-zone-modal.blade.php` | form | 中 |
| `filament/modals/trade-detail.blade.php` | detail | 高 |
| `real-stocks/modal/stock-detail.blade.php` | detail | 高 |
| `wms-picking-logs/view-modal.blade.php` | detail | 中 |
| `wms-auto-order-job-controls/result-modal.blade.php` | tabbed | 低 |
| `components/transmission-detail-modal.blade.php` | detail | 低 |
| `livewire/trade-detail-modal.blade.php` | detail/form | 高 |
