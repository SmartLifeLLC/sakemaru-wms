# 酒丸シリーズ MegaMenu・ページレイアウト統一

- **作成日**: 2026-04-07
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260407/20260407-114358-unify-megamenu-layout-design/`

## 背景・目的

酒丸シリーズ全6システム（WMS, Trade, Delivery, Insights, Search, Documents）で MegaMenu の構造・デザイン・機能が不統一。Documents は簡易ホバードロップダウン、他5システムは Alpine ベースのメガメニューだがレイアウト・カラム数・アイコン・配色が異なる。

また、WMS で実施したページスクロールバー非表示最適化（`html.fi` の overflow 制御、`fi-main` / `fi-page` の padding リセット）が他システムに展開されていない。

全システムで **WMS を標準** として統一する。

## 決定事項

1. **Documents MegaMenu**: Filament NavigationGroup 連動方式に統一（他5システムと同じ仕組み）
2. **アクセント色**: **indigo に全システム統一**（Trade/Delivery の amber を indigo に変更）
3. **システム日付バッジ**: **全システムに実装**
   - WMS/Trade/Search: ClientSetting::systemDate() 既存
   - Delivery/Insights/Documents: ClientSetting モデル新規追加（sakemaru DB 接続あり）
4. **Search 外部リンク**: 既存の外部リンク集約ロジックを廃止し、**酒丸シリーズタブに統合**

## 現状の実装（システム別差分）

### MegaMenu 構造比較

| 項目 | WMS (標準) | Trade | Delivery | Insights | Search | Documents |
|------|-----------|-------|----------|----------|--------|-----------|
| メニュー方式 | Alpine mega-menu | Alpine mega-menu | Alpine mega-menu | Alpine mega-menu | Alpine mega-menu | **CSS hover dropdown** |
| ヘッダー高さ | h-10 | h-10 | h-10 | h-10 | h-10 | h-10 |
| ロゴ | `sm:flex` 非表示対応 | パネルパス動的 | 固定 | 固定 | 固定 | 固定 |
| システム日付 | **あり** (Alpine dateBadge) | **なし** | **なし** | **なし** | **なし** | **なし** |
| 倉庫セレクタ | **あり** | なし | なし | なし | なし | なし |
| ドロップダウン位置 | fixed top:40px | fixed top:40px | fixed top:40px | fixed top:40px | fixed top:40px | absolute top-full |
| グループ内カラム | 動的 1/2/3 | grid-cols-4 外枠 | 動的 1/2/3 | 動的 1/2/3 + divider | 動的 1/2/3 + divider | 単一列 |
| グループ区切り | gap-12 flex | grid-cols-4 | gap-12 flex | gap-12 flex + divider | gap-12 flex + divider | なし |
| z-index (header) | z-[35] | z-50 | z-50 | z-[35] | z-50 | z-50 |
| z-index (dropdown) | z-40 | z-40 | z-40 | z-40 | z-40 | z-50 |
| 酒丸シリーズタブ | **あり** (openInSplitView) | **あり** | **あり** | **あり** | **なし** | **なし** |
| desc 表示 | **あり** | **あり** | **あり** | **あり** | なし | なし |
| Split View アイコン | fa-table-columns | fa-table-columns | fa-columns | fa-columns | fa-columns | N/A |
| アクセント色 | indigo | amber | amber | indigo (custom) | indigo | indigo |
| nav テキストサイズ | text-base | text-lg | text-sm | text-sm | text-lg | text-sm |

### システム日付バッジ対応状況

| システム | ClientSetting モデル | systemDate() | DB接続 |
|---------|---------------------|-------------|--------|
| WMS | `App\Models\Sakemaru\ClientSetting` | あり | sakemaru |
| Trade | `App\Models\BZCore\ClientSetting` | あり | sakemaru (default) |
| Search | `App\Models\Sakemaru\ClientSetting` | あり | sakemaru |
| Delivery | **なし** | なし | sakemaru 接続あり |
| Insights | **なし** | なし | sakemaru 接続あり |
| Documents | **なし** | なし | sakemaru 接続あり |

### ページレイアウト CSS 比較

| CSS ルール | WMS (標準) | Trade | Delivery | Insights | Search | Documents |
|-----------|-----------|-------|----------|----------|--------|-----------|
| `html.fi { overflow-x: hidden; overflow-y: auto; }` | **あり** | なし | あり | あり | なし | あり |
| `html.fi { scrollbar-gutter: auto; }` | **あり** | なし | あり | あり | なし | あり |
| `.fi-main { overflow: visible; }` | **あり** | なし | なし | なし | なし | なし |
| `.fi-layout { overflow: visible; }` | **あり** | なし | なし | なし | なし | なし |
| `.fi-main { height: auto; padding-bottom: 0; }` | **あり** | なし | なし | なし | なし | なし |
| `.fi-page { padding-bottom: 0; }` | **あり** | なし | なし | なし | なし | なし |
| `.fi-page-content { padding-bottom: 0; }` | **あり** | なし | なし | なし | なし | なし |
| sticky-actions CSS | **あり** | なし | あり | あり | なし | なし |

## 変更内容

### 概要

1. MegaMenu の PHP・Blade を WMS 標準に統一（Documents 含む全システム Alpine mega-menu 化）
2. アクセント色を全システム indigo に統一
3. システム日付バッジを全システムに追加（未対応3システムに ClientSetting モデル新規作成）
4. 酒丸シリーズタブを全システムに追加（Split View 対応）
5. Search の外部リンクロジックを酒丸シリーズタブに統合
6. ページスクロールバー非表示 CSS を全システムの theme.css に展開

### 詳細設計

#### A. MegaMenu 標準仕様（WMS ベース）

**ヘッダーバー構成:**
```
[ロゴ] [システム日付バッジ] | [メニュータブ1] [タブ2] ... [酒丸] | [倉庫セレクタ?] [検索] [通知] [ユーザーメニュー]
```

- ヘッダー: `bg-slate-800 sticky top-0 z-[35] shadow-md h-10`
- ロゴ: `<a href="/admin" class="hidden sm:flex items-center bg-white rounded-md px-2 py-1">`
- システム日付バッジ: **全システム共通**。Alpine `dateBadge()` で `mm.dd 曜日` 表示
- 倉庫セレクタ: WMS のみ（`@livewire('warehouse-selector')`）
- メニュータブ: `text-base font-medium` 統一
- アクセント色: **indigo-600 / indigo-100 / indigo-700** で全システム統一
- ドロップダウン: `fixed left-0 right-0 top-40px z-40`
- グループレイアウト: `flex flex-wrap gap-12` + 動的カラム（1/2/3）+ divider あり

**PHP MegaMenu 標準構造:**
```php
class MegaMenu extends Component
{
    public array $menuStructure = [];
    public string $systemDateDisplay = '';
    public string $systemDayOfWeek = '';

    public function mount()
    {
        $this->menuStructure = $this->buildMenuStructure();

        // システム日付バッジ（全システム共通）
        $systemDate = ClientSetting::systemDate();
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        $this->systemDateDisplay = $systemDate->format('m.d');
        $this->systemDayOfWeek = $weekdays[$systemDate->dayOfWeek];
    }

    protected function buildMenuStructure(): array
    {
        $navigation = Filament::getNavigation();

        // 各システム固有のタブ定義
        $tabs = [ ... ];

        $structure = [];
        foreach ($tabs as $key => $tab) {
            // EMenuCategory → NavigationGroup マッチングでメニュー構築
        }

        // 酒丸シリーズタブを追加（全システム共通）
        $sakemaruTab = $this->buildSakemaruSeriesTab();
        if ($sakemaruTab) {
            $structure[] = $sakemaruTab;
        }

        return $structure;
    }

    protected function buildSakemaruSeriesTab(): ?array
    {
        // config('app.url') からベースドメイン導出
        // 自システムを除外した酒丸シリーズリスト
        // openInSplitView: true + desc フィールド
    }
}
```

**Blade 標準パターン:**
- Split View: `@if(!empty($item['openInSplitView'])) @click.prevent="$store.splitView.open(...)"`
- アイコン: `fa-table-columns`（統一）
- desc: `@if(!empty($item['desc'])) <span class="text-xs text-slate-400">`
- divider: グループ間に `<div class="w-px bg-slate-200 self-stretch">`
- アクセント色: `hover:bg-indigo-100`, `group-hover:bg-indigo-600`, `text-indigo-600`

#### B. ClientSetting モデル追加（Delivery / Insights / Documents）

3システムに `ClientSetting` モデルを新規作成。共有 `sakemaru` DB の `client_settings` テーブルを参照。

```php
// 最小限の実装（systemDate + authSetting のみ）
namespace App\Models; // 各システムのモデル名前空間

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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

    public static function authSetting(): ?self
    {
        $user = auth()->user();
        return $user?->client?->setting;
    }
}
```

#### C. Documents MegaMenu 刷新

現在の CSS hover dropdown を Alpine mega-menu に完全置換:
- `MegaMenu.php`: `getMenus()` → `buildMenuStructure()` + `buildSakemaruSeriesTab()` へリファクタ
  - 既存 EMenuCategory (Documents/Master/System) の分類は維持
  - Filament NavigationGroup 連動方式に変更（ハードコード URL 廃止）
- `mega-menu.blade.php`: WMS 標準テンプレートに置換
- ClientSetting モデル新規作成（systemDate 対応）

#### D. Search 外部リンク統合

- 既存の `$externalItems` 集約ロジック（Filament navigation の `shouldOpenUrlInNewTab()` を収集）を廃止
- `buildSakemaruSeriesTab()` で酒丸シリーズタブに統合
- Filament 側の外部リンク NavigationItem 登録は維持（他で使われる可能性）

#### E. アクセント色 indigo 統一

全システムの mega-menu.blade.php で以下を統一:

| 要素 | クラス |
|-----|-------|
| メニューリンク hover | `hover:bg-indigo-100` |
| アクティブ背景 | `bg-indigo-50` |
| アイコン hover | `group-hover:bg-indigo-600 group-hover:border-indigo-600` |
| アクティブアイコン | `text-indigo-600 border-indigo-200` |
| ラベル hover | `group-hover:text-indigo-700` |
| アクティブラベル | `text-indigo-700 font-semibold` |
| グループヘッダーアイコン | `text-indigo-600` |

Trade/Delivery: amber → indigo に変更

#### F. ページスクロールバー CSS 統一

以下を全システムの theme.css に追加（WMS からコピー）:

```css
/* ページスクロールバー最適化 */
html.fi {
    scrollbar-gutter: auto !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

.fi-main {
    height: auto !important;
    padding-bottom: 0 !important;
    overflow: visible !important;
}

.fi-layout {
    overflow: visible !important;
}

.fi-page {
    padding-bottom: 0 !important;
}

.fi-page-content {
    padding-bottom: 0 !important;
}
```

#### G. アイコン・z-index 統一

- Split View アイコン: `fa-columns` → `fa-table-columns` に全システム統一
- ヘッダー z-index: `z-[35]` に統一（Filament モーダル z-40 との干渉回避）
- ドロップダウン z-index: `z-40` 維持
- nav テキストサイズ: `text-base` に統一

### 影響範囲

- 全6システムの MegaMenu コンポーネント（PHP + Blade）
- 全6システムの theme.css
- Documents: MegaMenu 完全刷新 + ClientSetting モデル新規追加
- Delivery / Insights: ClientSetting モデル新規追加
- Trade / Delivery: amber → indigo 色変更
- Search: 外部リンクロジック廃止

## 制約

- 各システム固有のメニュータブ定義（EMenuCategory 分類）は維持する
- WMS 固有の倉庫セレクタは他システムに移植しない
- Insights のカスタムフォント・カラーパレット（bengara, gold 等）は維持（MegaMenu のアクセント色のみ indigo 化）
- Trade の精算ページ用 CSS は維持
- Split View 対応は前タスクで完了済み（base.blade.php, index.blade.php）
- Documents の EMenuCategory enum の case 値は維持（label のみ変更がある場合は別途対応）

## 対象ファイル

### 新規作成

- `sakemaru-delivery/app/Models/ClientSetting.php` — systemDate() 対応
- `sakemaru-insights/app/Models/ClientSetting.php` — systemDate() 対応
- `sakemaru-documents/app/Models/ClientSetting.php` — systemDate() 対応

### 既存変更

**MegaMenu PHP:**
- `sakemaru-wms/app/Livewire/MegaMenu.php` — 参照標準（既に変更済み: external→openInSplitView）
- `sakemaru-trade/app/Livewire/MegaMenu.php` — 酒丸シリーズタブ追加済み、システム日付バッジ追加
- `sakemaru-delivery/app/Livewire/MegaMenu.php` — 酒丸シリーズタブ追加済み、システム日付バッジ追加
- `sakemaru-insights/app/Livewire/MegaMenu.php` — 酒丸シリーズ確認・微調整、システム日付バッジ追加
- `sakemaru-search/app/Livewire/MegaMenu.php` — 外部リンクロジック廃止、酒丸シリーズタブ追加、システム日付バッジ追加
- `sakemaru-documents/app/Livewire/MegaMenu.php` — 完全リファクタ（NavigationGroup 連動化 + 酒丸シリーズ + 日付バッジ）

**MegaMenu Blade:**
- `sakemaru-trade/resources/views/livewire/mega-menu.blade.php` — amber→indigo、z-index・テキストサイズ統一、日付バッジ追加
- `sakemaru-delivery/resources/views/livewire/mega-menu.blade.php` — amber→indigo、z-index・テキストサイズ統一、日付バッジ追加
- `sakemaru-insights/resources/views/livewire/mega-menu.blade.php` — z-index・テキストサイズ統一、アイコン統一、日付バッジ追加
- `sakemaru-search/resources/views/livewire/mega-menu.blade.php` — z-index・テキストサイズ統一、アイコン統一、日付バッジ追加
- `sakemaru-documents/resources/views/livewire/mega-menu.blade.php` — 完全置換（WMS 標準テンプレート）

**theme.css:**
- `sakemaru-trade/resources/css/filament/admin/theme.css` — スクロールバー CSS 追加
- `sakemaru-delivery/resources/css/filament/admin/theme.css` — fi-main/fi-layout overflow 追加
- `sakemaru-insights/resources/css/filament/admin/theme.css` — fi-main/fi-layout overflow 追加
- `sakemaru-search/resources/css/filament/search/theme.css` — スクロールバー CSS 追加
- `sakemaru-documents/resources/css/filament/admin/theme.css` — スクロールバー CSS 追加

### 参照のみ
- `sakemaru-wms/resources/views/livewire/mega-menu.blade.php` — 標準テンプレート
- `sakemaru-wms/app/Livewire/MegaMenu.php` — 標準実装
- `sakemaru-wms/resources/css/filament/admin/theme.css` — 標準 CSS
- `sakemaru-wms/app/Models/Sakemaru/ClientSetting.php` — systemDate() 参照実装
