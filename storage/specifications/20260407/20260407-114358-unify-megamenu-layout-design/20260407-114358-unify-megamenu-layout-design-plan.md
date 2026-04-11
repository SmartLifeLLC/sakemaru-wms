# 酒丸シリーズ MegaMenu・ページレイアウト統一 作業計画

## 前提

- WMS の MegaMenu が標準実装（external→openInSplitView 変更済み）
- Split View 対応（base.blade.php, index.blade.php）は全5システムで完了済み
- Trade/Delivery に `buildSakemaruSeriesTab()` + desc 表示追加済み（ただし amber 色のまま）
- Insights に `buildSakemaruSeriesTab()` あり（indigo 色だが日付バッジなし）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | WMS 標準テンプレート整理 | WMS blade の構造を文書化、各システム共通部分を明確化 | 標準パターンが明文化され、以降の Phase で参照可能 |
| P2 | ClientSetting モデル追加 | Delivery/Insights/Documents に ClientSetting 新規作成 | 3システムで `ClientSetting::systemDate()` が動作 |
| P3 | Documents MegaMenu 刷新 | CSS hover → Alpine mega-menu、NavigationGroup 連動化 | Documents で WMS 同等の mega-menu が表示される |
| P4 | Trade MegaMenu 統一 | amber→indigo、日付バッジ、z-index/テキスト統一 | WMS と同一の見た目・構造 |
| P5 | Delivery MegaMenu 統一 | amber→indigo、日付バッジ、z-index/テキスト統一 | WMS と同一の見た目・構造 |
| P6 | Insights MegaMenu 統一 | 日付バッジ追加、z-index/テキスト/アイコン統一 | WMS と同一の見た目・構造 |
| P7 | Search MegaMenu 統一 | 外部リンク統合、酒丸タブ追加、日付バッジ | WMS と同一の見た目・構造 |
| P8 | スクロールバー CSS 展開 | 全5システムの theme.css に WMS 標準 CSS 追加 | 全システムでスクロールバー非表示 |
| P9 | npm build & 最終確認 | 全5システムで `npm run build` | 全システムビルド成功 |

---

## P1: WMS MegaMenu 標準 Blade テンプレート整理

### 目的

WMS の mega-menu.blade.php を標準リファレンスとして各システムの変更箇所を明確にする。

### 修正方針

WMS blade をそのまま読み、以下の標準パターンを確認・記録:

**ヘッダーバー:**
```
header.bg-slate-800.sticky.top-0.z-[35].shadow-md
  ├─ [ロゴ] hidden sm:flex, bg-white rounded-md
  ├─ [日付バッジ] Alpine dateBadge(), bg-slate-900/60
  ├─ [nav] flex-1, text-base font-medium
  └─ [右] 倉庫セレクタ(WMSのみ), 検索, 通知, ユーザーメニュー
```

**ドロップダウン:**
```
fixed left-0 right-0 top-40px z-40 bg-white
  └─ flex flex-wrap gap-12
     ├─ [group] + divider (w-px bg-slate-200)
     │  ├─ ヘッダー (text-sm font-bold, text-indigo-600 アイコン)
     │  └─ 動的カラム 1/2/3 based on itemCount
     │     └─ [item] hover:bg-indigo-100, group-hover:bg-indigo-600
     │        ├─ openInSplitView → fa-table-columns + @click.prevent
     │        ├─ icon → filament::icon
     │        └─ desc → text-xs text-slate-400
```

**各システムでカスタマイズする部分:**
- ロゴリンク先（`/admin` or パネルパス）
- 日付バッジ（ClientSetting の import パス）
- 倉庫セレクタ（WMS のみ）
- nav 右側の追加ウィジェット

### 完了条件

WMS blade の構造が理解され、P3-P7 で参照できる状態になる（このPhaseは確認のみ、コード変更なし）。

---

## P2: ClientSetting モデル追加（Delivery / Insights / Documents）

### 目的

システム日付バッジ表示に必要な `ClientSetting::systemDate()` を3システムで使えるようにする。

### 修正対象ファイル

- `sakemaru-delivery/app/Models/ClientSetting.php` — 新規作成
- `sakemaru-insights/app/Models/ClientSetting.php` — 新規作成
- `sakemaru-documents/app/Models/ClientSetting.php` — 新規作成

### 修正方針

最小限の ClientSetting モデル。sakemaru DB 接続で `client_settings` テーブルを参照。

```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ClientSetting extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'client_settings';

    public static function systemDate(bool $defaultNow = false): ?Carbon
    {
        $setting = static::first();

        if ($setting?->system_date) {
            return new Carbon($setting->system_date);
        }

        return $defaultNow ? now() : null;
    }
}
```

### 完了条件

- 3ファイルが作成され、`php -l` で構文エラーなし
- 名前空間が各システムに合致（`App\Models\ClientSetting`）

---

## P3: Documents MegaMenu 刷新

### 目的

Documents の CSS hover dropdown を Alpine mega-menu に完全置換し、他システムと同じ仕組みにする。

### 修正対象ファイル

1. `sakemaru-documents/app/Enums/EMenuCategory.php` — label を NavigationGroup 文字列に合わせる
2. `sakemaru-documents/app/Filament/Resources/Documents/DocumentResource.php` — navigationGroup 追加
3. `sakemaru-documents/app/Filament/Resources/Users/UserResource.php` — navigationGroup 追加
4. `sakemaru-documents/app/Livewire/MegaMenu.php` — 完全リファクタ
5. `sakemaru-documents/resources/views/livewire/mega-menu.blade.php` — 完全置換

### 修正方針

**Step 1: EMenuCategory 更新**

現在の NavigationGroup 文字列に合わせる:
```php
enum EMenuCategory: string
{
    case Documents = 'documents';
    case Master = 'master';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Documents => '帳票管理',  // '帳票' → '帳票管理' に変更（既存 BuyerInvoicesResource 等と一致）
            self::Master => 'マスタ',
            self::System => '同期運用',     // 'システム' → '同期運用' に変更（既存 SyncRunItemsResource 等と一致）
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Documents => 'heroicon-o-document-text',
            self::Master => 'heroicon-o-circle-stack',
            self::System => 'heroicon-o-arrow-path',
        };
    }
}
```

**Step 2: Resources に navigationGroup 追加**

`DocumentResource` と `UserResource` に `$navigationGroup` を追加:
- DocumentResource: `'帳票管理'`（EMenuCategory::Documents->label()）
- UserResource: `'マスタ'`（EMenuCategory::Master->label()）

**Step 3: MegaMenu.php リファクタ**

ハードコード URL 方式 → Filament NavigationGroup 連動方式に変更。WMS 標準パターンを採用:
- `buildMenuStructure()`: EMenuCategory → NavigationGroup マッチング
- `buildSakemaruSeriesTab()`: insights 方式（app.url からベースドメイン導出）
- `mount()`: systemDateDisplay + systemDayOfWeek

**Step 4: mega-menu.blade.php 置換**

WMS の mega-menu.blade.php をベースに:
- ロゴ: 酒丸帳場
- 倉庫セレクタ: 削除
- アクセント色: indigo

### 完了条件

- Documents で Alpine mega-menu が正しくレンダリングされる構造になっている
- `php -l` で全ファイル構文エラーなし
- EMenuCategory の label が Filament Resources の navigationGroup と一致

---

## P4: Trade MegaMenu 統一

### 目的

Trade の MegaMenu を WMS 標準に統一（amber→indigo、日付バッジ追加）。

### 修正対象ファイル

1. `sakemaru-trade/app/Livewire/MegaMenu.php` — systemDateDisplay/systemDayOfWeek 追加
2. `sakemaru-trade/resources/views/livewire/mega-menu.blade.php` — amber→indigo、日付バッジ、z-index、テキストサイズ、アイコン、divider、レイアウト統一

### 修正方針

**MegaMenu.php:**
- `mount()` に `systemDateDisplay` / `systemDayOfWeek` プロパティ追加
- ClientSetting は `App\Models\BZCore\ClientSetting` を使用（既存）
- `buildSakemaruSeriesTab()` は追加済み（前タスク）

**mega-menu.blade.php:**

WMS の blade をベースに以下をカスタマイズ:
- ロゴ: `<a href="/{{ filament()->getCurrentPanel()->getPath() }}">`（Trade はマルチパネル）
- 日付バッジ: Alpine `dateBadge()` 追加
- 倉庫セレクタ: なし
- z-index: header `z-[35]`（z-50 から変更）
- nav テキスト: `text-base`（text-lg から変更）
- アクセント色: amber → indigo 全箇所
- グループレイアウト: `grid-cols-4` → `flex flex-wrap gap-12` + divider
- アイコン: `fa-table-columns` 確認

### 完了条件

- `php -l` 構文チェック通過
- amber 色クラスが blade から完全排除
- 日付バッジが表示される構造

---

## P5: Delivery MegaMenu 統一

### 目的

Delivery の MegaMenu を WMS 標準に統一（amber→indigo、日付バッジ追加）。

### 修正対象ファイル

1. `sakemaru-delivery/app/Livewire/MegaMenu.php` — systemDateDisplay/systemDayOfWeek 追加
2. `sakemaru-delivery/resources/views/livewire/mega-menu.blade.php` — amber→indigo、日付バッジ、z-index、テキストサイズ

### 修正方針

**MegaMenu.php:**
- `mount()` に日付プロパティ追加
- ClientSetting は P2 で作成した `App\Models\ClientSetting` を使用
- `buildSakemaruSeriesTab()` は追加済み（前タスク）

**mega-menu.blade.php:**

WMS の blade をベースに:
- ロゴ: `/admin` 固定
- 日付バッジ追加
- 倉庫セレクタ: なし
- z-index: `z-[35]`（z-50 から変更）
- nav テキスト: `text-base`（text-sm から変更）
- アクセント色: amber → indigo
- アイコン: `fa-columns` → `fa-table-columns`

### 完了条件

- `php -l` 構文チェック通過
- amber 色クラス排除、indigo 統一

---

## P6: Insights MegaMenu 統一

### 目的

Insights の MegaMenu を WMS 標準に統一（日付バッジ追加、z-index/テキスト/アイコン統一）。

### 修正対象ファイル

1. `sakemaru-insights/app/Livewire/MegaMenu.php` — systemDateDisplay/systemDayOfWeek 追加
2. `sakemaru-insights/resources/views/livewire/mega-menu.blade.php` — 日付バッジ、z-index、テキストサイズ、アイコン

### 修正方針

**MegaMenu.php:**
- `mount()` に日付プロパティ追加
- ClientSetting は P2 で作成した `App\Models\ClientSetting` を使用
- `buildSakemaruSeriesTab()` は既存（確認のみ）

**mega-menu.blade.php:**
- 日付バッジ追加（ロゴの右）
- z-index: 既に `z-[35]`（変更不要の可能性、確認）
- nav テキスト: `text-base`（text-sm から変更の可能性、確認）
- アイコン: `fa-columns` → `fa-table-columns`
- Insights のカスタム色は既に indigo ベース（確認）

### 完了条件

- `php -l` 構文チェック通過
- 日付バッジが表示される構造

---

## P7: Search MegaMenu 統一

### 目的

Search の外部リンクロジックを酒丸シリーズタブに統合し、WMS 標準に統一。

### 修正対象ファイル

1. `sakemaru-search/app/Livewire/MegaMenu.php` — 外部リンクロジック廃止、buildSakemaruSeriesTab() 追加、日付バッジ追加
2. `sakemaru-search/resources/views/livewire/mega-menu.blade.php` — 日付バッジ、z-index、テキストサイズ、アイコン

### 修正方針

**MegaMenu.php:**

1. 既存の `$externalItems` 集約ロジック（L112-163）を削除
2. `buildSakemaruSeriesTab()` メソッド追加（insights 方式）
3. `mount()` に日付プロパティ追加
4. ClientSetting は `App\Models\Sakemaru\ClientSetting`（既存）

**mega-menu.blade.php:**
- 日付バッジ追加
- z-index: `z-[35]`（z-50 から変更）
- nav テキスト: `text-base`（text-lg から変更）
- アイコン: `fa-columns` → `fa-table-columns`

### 完了条件

- `php -l` 構文チェック通過
- 外部リンクタブが消え、酒丸シリーズタブが表示される構造

---

## P8: ページスクロールバー CSS 全システム展開

### 目的

WMS のスクロールバー非表示 CSS を全5システムの theme.css に展開。

### 修正対象ファイル

- `sakemaru-trade/resources/css/filament/admin/theme.css`
- `sakemaru-delivery/resources/css/filament/admin/theme.css`
- `sakemaru-insights/resources/css/filament/admin/theme.css`
- `sakemaru-search/resources/css/filament/search/theme.css`
- `sakemaru-documents/resources/css/filament/admin/theme.css`

### 修正方針

各システムの theme.css に以下を追加（既存のものがあれば不足分のみ追加）:

```css
/* ページスクロールバー最適化 */
html.fi {
    scrollbar-gutter: auto !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

/* mega-menu(2.5rem)に合わせてメインコンテンツの高さを調整 */
.fi-body.fi-body-has-topbar .fi-main-ctn {
    min-height: calc(100dvh - 2.5rem) !important;
}

/* ページ全体のわずかなはみ出しによるスクロールバーを防止 */
.fi-main {
    height: auto !important;
    padding-bottom: 0 !important;
}

.fi-page {
    padding-bottom: 0 !important;
}

.fi-page-content {
    padding-bottom: 0 !important;
}

@layer components {
    .fi-main {
        overflow: visible !important;
    }

    .fi-layout {
        overflow: visible !important;
    }
}
```

既に一部が存在するシステム（Delivery, Insights, Documents）は不足分のみ追加。

### 完了条件

- 全5システムの theme.css に上記 CSS ルールが存在
- CSS 構文エラーなし

---

## P9: npm build & 最終確認

### 目的

全5システムでアセットビルドし、エラーがないことを確認。

### 手順

```bash
cd /Users/jungsinyu/Projects/sakemaru-trade && npm run build
cd /Users/jungsinyu/Projects/sakemaru-delivery && npm run build
cd /Users/jungsinyu/Projects/sakemaru-insights && npm run build
cd /Users/jungsinyu/Projects/sakemaru-search && npm run build
cd /Users/jungsinyu/Projects/sakemaru-documents && npm run build
```

### 完了条件

- 全5システムで `npm run build` が成功
- ビルドエラーなし

---

## 制約（厳守）

1. WMS のファイルは参照のみ（変更禁止）— 既に標準化済み
2. 各システム固有のメニュータブ定義（EMenuCategory のケース値・タブ構成）は維持
3. WMS 固有の倉庫セレクタ（`@livewire('warehouse-selector')`）は他システムに追加しない
4. Insights のカスタムフォント・カラーパレット（bengara, gold, navy 等）は維持
5. Trade の精算ページ用 CSS（settlement 関連）は維持
6. データベース破壊コマンド（migrate:fresh, migrate:refresh 等）禁止
7. 各システムの Filament Resources のナビゲーション登録（navigationGroup 以外）は変更しない

## 全体完了条件

1. 全6システムで MegaMenu が Alpine mega-menu + indigo + 日付バッジ + 酒丸シリーズタブ
2. 全5システム（WMS 除く）の theme.css にスクロールバー CSS が存在
3. 全5システムで `npm run build` 成功
4. `php -l` で全変更ファイル構文エラーなし
