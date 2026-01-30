# Filament 4 仕様ドキュメント

このドキュメントは、Filament 4で実装する際の重要な仕様と変更点をまとめたものです。

## 目次

### 技術仕様
1. [テーブルクエリのカスタマイズ](#テーブルクエリのカスタマイズ)
2. [アクションの位置制御](#アクションの位置制御)
3. [フォームコンポーネント](#フォームコンポーネント)
4. [重要なENUMクラス](#重要なenumクラス)

### デザイン仕様
5. [メガメニュー](#メガメニュー)
6. [AdminPanelProvider設定](#adminpanelprovider設定)
7. [テーマCSS](#テーマcss)
8. [テーブルデザインパターン](#テーブルデザインパターン)
9. [モーダルコンポーネント](#モーダルコンポーネント)
10. [ログイン画面](#ログイン画面)

### インフラストラクチャ
11. [クロスアプリケーション セッション共有](#クロスアプリケーション-セッション共有)

---

## テーブルクエリのカスタマイズ

### ❌ 非推奨 (古い方法)

```php
protected function getTableQuery(): ?Builder
{
    return parent::getTableQuery()
        ->with(['relation1', 'relation2']);
}
```

### ✅ 推奨 (Filament 4の方法)

```php
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

public function table(Table $table): Table
{
    return parent::table($table)
        ->modifyQueryUsing(fn (Builder $query) => $query
            ->with([
                'relation1',
                'relation2',
                'relation3.nestedRelation',
            ])
        );
}
```

**重要なポイント:**
- `getTableQuery()` → `table(Table $table)`
- `parent::getTableQuery()` → `parent::table($table)`
- `->with([...])` → `->modifyQueryUsing(fn (Builder $query) => $query->with([...]))`
- メソッドは`public`にする必要がある
- `Table`クラスのインポートが必要

**参考URL:** https://filamentphp.com/docs/4.x/tables/filters/query-builder

---

## アクションの位置制御

### recordActionsの位置設定

```php
use Filament\Tables\Enums\RecordActionsPosition;

return $table
    ->recordActions([
        Action::make('action1')
            ->label('アクション1'),
        Action::make('action2')
            ->label('アクション2'),
    ], position: RecordActionsPosition::BeforeColumns)
```

**利用可能なポジション:**
- `RecordActionsPosition::BeforeColumns` - カラムの前（左端/先頭）
- `RecordActionsPosition::AfterColumns` - カラムの後（右端/末尾、デフォルト）
- `RecordActionsPosition::BeforeCells` - 各セルの前
- `RecordActionsPosition::AfterCells` - 各セルの後

**重要なポイント:**
- ❌ `ActionsPosition` (存在しない)
- ✅ `RecordActionsPosition` (正しいクラス)
- ❌ `->sort(1)`, `->sort(2)` (Filament 4では効果なし)
- ✅ `position`パラメータを使用

**参考URL:** https://filamentphp.com/docs/4.x/tables/actions

---

## フォームコンポーネント

### Sectionコンポーネント

```php
use Filament\Schemas\Components\Section; // ✅ 正しい

// ❌ 間違い
use Filament\Forms\Components\Section;
```

**重要なポイント:**
- Filament 4では`Filament\Schemas\Schema`を使用
- フォームセクションは`Filament\Schemas\Components\Section`
- フォーム関連のコンポーネントは基本的に`Filament\Forms\Components\*`から利用可能

### よく使うコンポーネント

```php
use Filament\Infolists\Components\TextEntry; // 表示のみ (Filament 4)
use Filament\Forms\Components\Repeater;      // 繰り返しフィールド
use Filament\Forms\Components\Select;        // セレクトボックス
use Filament\Forms\Components\TextInput;     // テキスト入力
use Filament\Schemas\Components\Section;     // セクション
```

### Placeholder → TextEntry (非推奨)

#### ❌ 非推奨 (Filament 3の方法)

```php
use Filament\Forms\Components\Placeholder;

Placeholder::make('field_name')
    ->label('ラベル')
    ->content(fn ($record) => $record->value)
    ->html() // HTMLとして表示する場合
```

#### ✅ 推奨 (Filament 4の方法)

```php
use Filament\Infolists\Components\TextEntry;

TextEntry::make('field_name')
    ->label('ラベル')
    ->state(fn ($record) => $record->value)
    ->html() // HTMLとして表示する場合
```

**重要なポイント:**
- ❌ `Placeholder::make()` (非推奨)
- ✅ `TextEntry::make()` (推奨)
- ❌ `->content()` メソッド
- ✅ `->state()` メソッド
- `extraAttributes(['class' => 'font-bold'])` → `->weight('bold')`
- import先: `Filament\Infolists\Components\TextEntry`

**参考URL:** https://filamentphp.com/docs/4.x/infolists/text-entry

### Repeaterのデフォルト値設定

```php
Repeater::make('items')
    ->schema([...])
    ->default(function ($record) {
        return $record->relatedItems->map(function ($item) {
            return [
                'id' => $item->id,
                'field1' => $item->field1,
                'field2' => $item->field2,
            ];
        })->toArray();
    })
    ->deleteAction(
        fn ($action, $state) => $action->hidden(!empty($state['id']))
    )
```

**重要なポイント:**
- `default()`で既存データを読み込める
- `deleteAction()`で削除ボタンの表示/非表示を制御
- `dehydrated(false)`で送信時にフィールドを除外

---

## 重要なENUMクラス

### RecordActionsPosition

```php
use Filament\Tables\Enums\RecordActionsPosition;

RecordActionsPosition::BeforeColumns
RecordActionsPosition::AfterColumns
RecordActionsPosition::BeforeCells
RecordActionsPosition::AfterCells
```

**場所:** `vendor/filament/tables/src/Enums/RecordActionsPosition.php`

---

## リソースの構造

### Filament 4でのリソース構成

```
app/Filament/Resources/
├── ModelResource.php           # メインリソースクラス
├── Model/
│   ├── Pages/
│   │   ├── ListModel.php      # 一覧ページ
│   │   ├── CreateModel.php    # 作成ページ
│   │   └── EditModel.php      # 編集ページ
│   ├── Schemas/
│   │   └── ModelForm.php      # フォーム定義
│   └── Tables/
│       └── ModelTable.php     # テーブル定義
```

### リソースクラスでの使用

```php
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ModelResource extends Resource
{
    public static function form(Schema $schema): Schema
    {
        return ModelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ModelTable::configure($table);
    }
}
```

---

## データベース関連

### Eager Loadingの推奨パターン

```php
public function table(Table $table): Table
{
    return parent::table($table)
        ->modifyQueryUsing(fn (Builder $query) => $query
            ->with([
                'relation1',
                'relation2.nestedRelation',
                'relation3.nested1.nested2',
            ])
        );
}
```

**利点:**
- N+1クエリ問題の回避
- パフォーマンス向上
- 複雑なリレーションにも対応

---

## アクション関連

### モーダルアクションの基本構造

#### ❌ 非推奨 (Filament 3の方法)

```php
Action::make('actionName')
    ->form([
        // フォームコンポーネント
    ])
```

#### ✅ 推奨 (Filament 4の方法)

```php
Action::make('actionName')
    ->label('アクション名')
    ->icon('heroicon-o-icon-name')
    ->color('warning')
    ->visible(fn ($record) => /* 条件 */)
    ->schema([  // ✅ form() ではなく schema() を使用
        // フォームコンポーネント
    ])
    ->action(function ($record, array $data, Action $action) {
        // アクション処理
    })
    ->after(function () {
        // アクション完了後の処理
        redirect()->to(request()->url());
    })
```

**重要なポイント:**
- ❌ `->form([...])` (古い方法)
- ✅ `->schema([...])` (Filament 4の方法)
- Actionのフォーム定義は`->schema()`を使用する
- これはモーダル、スライドオーバーなどすべてのアクションに適用

**参考URL:** https://filamentphp.com/docs/4.x/schemas/overview

### アクション内でのデータ更新

```php
->action(function ($record, array $data) {
    // レコード更新
    $record->update(['field' => $data['field']]);

    // リレーション更新
    foreach ($data['items'] as $item) {
        if (!empty($item['id'])) {
            // 既存レコード更新
            $existingItem = Model::find($item['id']);
            $existingItem->update([...]);
        } else {
            // 新規レコード作成
            $record->items()->create([...]);
        }
    }

    // レコード再読み込み
    $record->refresh();
})
```

---

## ベストプラクティス

### 1. コンポーネントの分離

```php
// ✅ 良い例
class ModelTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([...])
            ->filters([...])
            ->recordActions([...]);
    }
}
```

### 2. リレーションの効率的な読み込み

```php
// ✅ Eager Loading使用
->modifyQueryUsing(fn ($query) => $query->with(['relation']))

// ❌ 避けるべき（N+1問題）
TextColumn::make('relation.field') // withなし
```

### 3. アクションの可視性制御

```php
->visible(fn ($record) =>
    in_array($record->status, ['OPEN', 'PENDING']) &&
    $record->quantity > 0
)
```

### 4. フォームバリデーション

```php
TextInput::make('field')
    ->required()
    ->numeric()
    ->minValue(1)
    ->maxValue(100)
```

---

## トラブルシューティング

### よくあるエラーと解決方法

#### 1. "Class not found" エラー

**問題:**
```php
use Filament\Tables\Enums\ActionsPosition; // ❌
```

**解決:**
```php
use Filament\Tables\Enums\RecordActionsPosition; // ✅
```

#### 2. "Method not found" エラー

**問題:**
```php
protected function getTableQuery() // ❌ Filament 3の方法
```

**解決:**
```php
public function table(Table $table): Table // ✅ Filament 4の方法
```

#### 3. Sectionが見つからない

**問題:**
```php
use Filament\Forms\Components\Section; // ❌
```

**解決:**
```php
use Filament\Schemas\Components\Section; // ✅
```

---

## プリセットビュー（倉庫タブ）の実装

### 概要

AdvancedTablesプラグインの`getPresetViews()`を使用して、倉庫別のタブフィルタリングを実装できます。
ただし、`getPresetViews()`は1リクエスト内で**複数回呼び出される**ため、キャッシュが必須です。

### キャッシュなしの問題

```
// クエリログ例（キャッシュなし）
select distinct `warehouse_id` from `wms_order_data_files` where `is_test` = 0  // 1回目
select * from `warehouses` where `id` in (1, 2, 3...) order by `code` asc        // 1回目
select distinct `warehouse_id` from `wms_order_data_files` where `is_test` = 0  // 2回目（重複）
select * from `warehouses` where `id` in (1, 2, 3...) order by `code` asc        // 2回目（重複）
... 10回以上繰り返される場合あり
```

### 方法1: インメモリキャッシュ（推奨：シンプルなケース）

```php
class ListWmsOrderDataFiles extends ListRecords
{
    // リクエスト内キャッシュ用プロパティ
    protected ?Collection $cachedWarehouses = null;

    public function getPresetViews(): array
    {
        $warehouses = $this->getWarehousesForPresetViews();
        // ... プリセットビュー構築
    }

    protected function getWarehousesForPresetViews(): Collection
    {
        if ($this->cachedWarehouses !== null) {
            return $this->cachedWarehouses;
        }

        $warehouseIds = WmsOrderDataFile::where('is_test', $this->isTestTab)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $this->cachedWarehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get();

        return $this->cachedWarehouses;
    }
}
```

**特徴:**
- リクエスト内でのみキャッシュ
- 外部キャッシュの無効化が不要
- シンプルで理解しやすい

### 方法2: Laravelキャッシュ（複数リクエストで共有）

```php
public function getPresetViews(): array
{
    $cacheKey = 'order_warehouses_' . auth()->id();

    $warehouseData = cache()->remember($cacheKey, 30, function () {
        $warehouseIds = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        $warehouses = Warehouse::whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get(['id', 'name']);

        return [
            'ids' => $warehouseIds,
            'warehouses' => $warehouses,
        ];
    });

    // ... プリセットビュー構築
}

// タブ切り替え時にキャッシュをクリア
public function setTab(string $tab): void
{
    cache()->forget('order_warehouses_' . auth()->id());
    // ...
}
```

**特徴:**
- リクエスト間でキャッシュ共有
- TTL（例: 30秒）で自動無効化
- データ変更時は明示的なキャッシュクリアが必要

### フィルタ内でのLivewireコンポーネント参照

テーブルクラスのフィルタからページの状態を参照する場合:

```php
// Tables/WmsOrderDataFilesTable.php
SelectFilter::make('batch_code')
    ->label('実行CD')
    ->options(function ($livewire): array {
        // $livewire でページコンポーネントを参照
        $isTest = $livewire instanceof ListWmsOrderDataFiles
            && $livewire->fileTypeTab === 'test';

        return WmsOrderDataFile::query()
            ->where('is_test', $isTest)
            ->select('batch_code')
            ->distinct()
            ->orderByDesc('batch_code')
            ->limit(50)
            ->pluck('batch_code', 'batch_code')
            ->toArray();
    })
    ->default(function ($livewire): ?string {
        $isTest = $livewire instanceof ListWmsOrderDataFiles
            && $livewire->fileTypeTab === 'test';

        return WmsOrderDataFile::query()
            ->where('is_test', $isTest)
            ->orderByDesc('batch_code')
            ->value('batch_code');
    })
    ->searchable(),
```

---

## 参考リンク

- [Filament 4 公式ドキュメント](https://filamentphp.com/docs/4.x)
- [Tables - Actions](https://filamentphp.com/docs/4.x/tables/actions)
- [Tables - Query Builder](https://filamentphp.com/docs/4.x/tables/filters/query-builder)
- [Forms - Layout](https://filamentphp.com/docs/4.x/forms/layout)
- [Schemas](https://filamentphp.com/docs/4.x/schemas)

---

## 更新履歴

- 2025-11-16: 初版作成
  - テーブルクエリのカスタマイズ方法
  - アクションの位置制御
  - フォームコンポーネント
  - ENUMクラス
  - ベストプラクティス
- 2025-11-16: Actionのschema()に関する情報を追加
  - `->form([])` から `->schema([])` への変更
  - モーダルアクションでの正しい使用方法
- 2025-11-16: PlaceholderからTextEntryへの変更を追加
  - `Placeholder::make()` から `TextEntry::make()` への変更
  - `->content()` から `->state()` への変更
  - スタイリング方法の更新 (`extraAttributes` → `->weight()`)
- 2026-01-29: プリセットビュー（倉庫タブ）のキャッシュに関する注意事項を追加
  - `getPresetViews()`の重複クエリ問題と解決方法
  - インメモリキャッシュとLaravelキャッシュの使い分け
  - フィルタ内でのLivewireコンポーネント参照方法
- 2026-01-29: デザイン仕様を追加
  - メガメニューの実装仕様
  - AdminPanelProvider設定
  - テーマCSS仕様
  - テーブルデザインパターン
  - モーダルコンポーネント
  - ログイン画面デザイン
- 2026-01-30: モーダルフッターボタン配置を追加
  - `modalFooterActionsAlignment(Alignment::End)` で右寄せ
  - Alignment ENUMの使用方法
- 2026-01-30: 在庫関連カラム名の統一を追加
  - 「入庫予定」→「入庫数」
  - 「計算後在庫」→「見込在庫」

---

# デザイン仕様

---

## メガメニュー

### 概要

Filament 4のtopNavigation()と組み合わせて使用するメガメニューコンポーネント。
タブベースの構造で、大量のメニューアイテムを整理して表示します。

### ファイル構成

```
app/
├── Livewire/MegaMenu.php              # Livewireコンポーネント
├── Enums/
│   ├── EMenu.php                      # メニューアイテム定義
│   └── EMenuCategory.php              # メニューカテゴリ定義
resources/views/livewire/mega-menu.blade.php  # Bladeテンプレート
```

### EMenuCategory（カテゴリEnum）

```php
namespace App\Enums;

enum EMenuCategory: string
{
    // 出荷関連
    case PICKING = 'picking';           // ピッキング
    case WAVE = 'wave';                 // ウェーブ
    case SHORTAGE = 'shortage';         // 欠品

    // 入荷関連
    case RECEIVING = 'receiving';       // 入荷
    case INSPECTION = 'inspection';     // 検品

    // 発注関連
    case AUTO_ORDER = 'auto_order';     // 自動発注

    // 在庫関連
    case STOCK = 'stock';               // 在庫
    case LOCATION = 'location';         // ロケーション
    case TRANSFER = 'transfer';         // 移動

    // マスタ管理
    case MASTER_ITEM = 'master_item';   // 商品マスタ
    case MASTER_WH = 'master_wh';       // 倉庫マスタ
    case MASTER_ORDER = 'master_order'; // 発注マスタ

    // システム
    case LOGS = 'logs';                 // ログ（sort: 80）
    case SETTINGS = 'settings';         // 設定（sort: 90）
    case TEST_DATA = 'test_data';       // テストデータ（sort: 99）

    // ラベル取得
    public function label(): string
    {
        return match ($this) {
            self::PICKING => 'ピッキング',
            self::WAVE => 'ウェーブ',
            // ...
        };
    }

    // アイコン取得（Heroicons）
    public function icon(): string
    {
        return match ($this) {
            self::PICKING => 'heroicon-o-clipboard-document-list',
            self::WAVE => 'heroicon-o-queue-list',
            // ...
        };
    }

    // ソート順（数値が小さいほど先に表示）
    public function sort(): int
    {
        return match ($this) {
            self::PICKING => 1,
            self::WAVE => 2,
            // ...
            self::LOGS => 80,
            self::SETTINGS => 90,
            self::TEST_DATA => 99,
        };
    }
}
```

### EMenu（メニューアイテムEnum）

```php
namespace App\Enums;

enum EMenu: string
{
    // ピッキング
    case PICKING_TASKS = 'picking_tasks';
    case PICKING_ROUTES = 'picking_routes';
    case PICKING_LOGS = 'picking_logs';

    // ウェーブ
    case WAVES = 'waves';
    case WAVE_GENERATION = 'wave_generation';

    // 欠品
    case SHORTAGES = 'shortages';
    case SHORTAGE_ALLOCATIONS = 'shortage_allocations';

    // ... 81項目

    // カテゴリ取得
    public function category(): EMenuCategory
    {
        return match ($this) {
            self::PICKING_TASKS,
            self::PICKING_ROUTES,
            self::PICKING_LOGS => EMenuCategory::PICKING,

            self::WAVES,
            self::WAVE_GENERATION => EMenuCategory::WAVE,
            // ...
        };
    }

    // ラベル取得
    public function label(): string
    {
        return match ($this) {
            self::PICKING_TASKS => 'ピッキングタスク',
            self::WAVES => 'ウェーブ一覧',
            // ...
        };
    }

    // アイコン取得
    public function icon(): string
    {
        return match ($this) {
            self::PICKING_TASKS => 'heroicon-o-clipboard-document-list',
            // ...
        };
    }

    // カテゴリ内ソート順
    public function sort(): int
    {
        return match ($this) {
            self::PICKING_TASKS => 1,
            self::PICKING_ROUTES => 2,
            // ...
        };
    }
}
```

### MegaMenu Livewireコンポーネント

```php
namespace App\Livewire;

use App\Enums\EMenuCategory;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Livewire\Component;

class MegaMenu extends Component
{
    // タブ定義（カテゴリをグループ化）
    protected array $tabs = [
        '出荷' => [
            EMenuCategory::PICKING,
            EMenuCategory::WAVE,
            EMenuCategory::SHORTAGE,
        ],
        '入荷' => [
            EMenuCategory::RECEIVING,
            EMenuCategory::INSPECTION,
        ],
        '発注' => [
            EMenuCategory::AUTO_ORDER,
        ],
        '在庫' => [
            EMenuCategory::STOCK,
            EMenuCategory::LOCATION,
            EMenuCategory::TRANSFER,
        ],
        'マスタ管理' => [
            EMenuCategory::MASTER_ITEM,
            EMenuCategory::MASTER_WH,
            EMenuCategory::MASTER_ORDER,
        ],
        '分析・レポート' => [
            EMenuCategory::LOGS,
        ],
        'システム設定' => [
            EMenuCategory::SETTINGS,
            EMenuCategory::TEST_DATA,
        ],
    ];

    // タブデータ取得
    public function getTabs(): array
    {
        return $this->tabs;
    }

    // カテゴリ別メニューアイテム取得
    public function getMenuItems(array $categories): Collection
    {
        $navigation = collect(Filament::getNavigation());

        return $navigation
            ->filter(fn ($group) => in_array(
                $this->getCategoryFromLabel($group->getLabel()),
                $categories
            ))
            ->sortBy(fn ($group) => $this->getCategoryFromLabel($group->getLabel())?->sort() ?? 999);
    }

    public function render()
    {
        return view('livewire.mega-menu');
    }
}
```

### mega-menu.blade.php（テンプレート）

```blade
<div x-data="{ openTab: null }" @click.outside="openTab = null"
    class="fixed top-0 left-0 right-0 z-50 bg-slate-800 h-10 px-4 flex items-center shadow-md">

    {{-- ロゴ --}}
    <a href="{{ route('filament.admin.pages.dashboard') }}" class="flex items-center mr-6">
        <div class="bg-white rounded-md px-2 py-1">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-6">
        </div>
    </a>

    {{-- ナビゲーションタブ --}}
    <nav class="flex items-center space-x-1">
        @foreach($this->getTabs() as $tabName => $categories)
            <div class="relative">
                <button @click="openTab = openTab === '{{ $tabName }}' ? null : '{{ $tabName }}'"
                    :class="openTab === '{{ $tabName }}' ? 'text-white bg-slate-700' : 'text-slate-200 hover:text-white hover:bg-slate-700'"
                    class="px-3 py-2 rounded-md text-sm font-medium transition-colors duration-150 flex items-center gap-1">
                    <i class="fa-solid fa-{{ $this->getTabIcon($tabName) }} text-xs"></i>
                    <span>{{ $tabName }}</span>
                    <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200"
                       :class="openTab === '{{ $tabName }}' ? 'rotate-180' : ''"></i>
                </button>

                {{-- ドロップダウン --}}
                <div x-show="openTab === '{{ $tabName }}'" x-cloak
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="transform opacity-0 scale-95"
                    x-transition:enter-end="transform opacity-100 scale-100"
                    class="absolute left-0 mt-1 bg-white rounded-lg shadow-lg border border-slate-200 py-4 z-50">

                    @php
                        $menuItems = $this->getMenuItems($categories);
                        $totalItems = $menuItems->sum(fn ($g) => count($g->getItems()));
                        $columns = $totalItems > 10 ? 3 : ($totalItems > 5 ? 2 : 1);
                        $minWidth = $columns === 3 ? '600px' : '400px';
                    @endphp

                    <div class="grid gap-4 px-4" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr)); min-width: {{ $minWidth }}">
                        @foreach($menuItems as $group)
                            <div class="space-y-1">
                                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider px-2 mb-2">
                                    {{ $group->getLabel() }}
                                </div>
                                @foreach($group->getItems() as $item)
                                    <a href="{{ $item->getUrl() }}"
                                       @class([
                                           'group flex items-center gap-2 px-2 py-1.5 rounded-md transition-colors',
                                           'text-indigo-600 bg-indigo-50' => $item->isActive(),
                                           'text-slate-700 hover:bg-slate-50 hover:text-indigo-700' => !$item->isActive(),
                                       ])>
                                        <span class="p-1.5 rounded-md bg-white border border-slate-200 shadow-sm group-hover:bg-indigo-600 group-hover:border-indigo-600 group-hover:text-white transition-colors">
                                            @svg($item->getIcon(), 'w-4 h-4')
                                        </span>
                                        <span class="text-sm font-medium">{{ $item->getLabel() }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </nav>

    {{-- ユーザーメニュー（右端） --}}
    <div class="ml-auto flex items-center gap-4">
        {{-- グローバル検索 --}}
        <button class="text-slate-300 hover:text-white">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>

        {{-- 通知 --}}
        <button class="text-slate-300 hover:text-white relative">
            <i class="fa-solid fa-bell"></i>
        </button>

        {{-- アカウントメニュー --}}
        <x-filament::dropdown>
            <x-slot name="trigger">
                <button class="flex items-center gap-2 text-slate-200 hover:text-white">
                    <x-filament-panels::avatar.user :user="auth()->user()" class="w-8 h-8" />
                    <span class="text-sm">{{ auth()->user()->name }}</span>
                </button>
            </x-slot>
            <x-filament::dropdown.list>
                <x-filament::dropdown.list.item wire:click="logout" icon="heroicon-o-arrow-right-on-rectangle">
                    ログアウト
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>
</div>

{{-- Font Awesome CDN --}}
@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/js/all.min.js" defer></script>
@endpush
```

### スタイリング仕様

| 要素 | クラス/スタイル |
|------|----------------|
| ヘッダー背景 | `bg-slate-800` |
| ヘッダー高さ | `h-10`（40px） |
| タブボタン（通常） | `text-slate-200 hover:text-white hover:bg-slate-700` |
| タブボタン（アクティブ） | `text-white bg-slate-700` |
| ドロップダウン背景 | `bg-white rounded-lg shadow-lg border border-slate-200` |
| メニューアイテムアイコン | `p-1.5 rounded-md bg-white border border-slate-200 shadow-sm` |
| メニューアイテムホバー | `group-hover:bg-indigo-600 group-hover:border-indigo-600` |
| アクティブアイテム | `text-indigo-600 bg-indigo-50` |

---

## AdminPanelProvider設定

### 基本設定

```php
// app/Providers/Filament/AdminPanelProvider.php

use Filament\Support\Colors\Color;
use Archilex\AdvancedTables\AdvancedTablesPlugin;
use Archilex\ToggleIconColumn\ToggleIconColumnPlugin;
use FilamentPro\StickyTableHeader\StickyTableHeaderPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->id('admin')
        ->path('admin')
        ->topNavigation()                              // トップナビゲーション有効化
        ->maxContentWidth('full')                      // フル幅レイアウト
        ->breadcrumbs(false)                           // パンくずリスト無効化
        ->viteTheme('resources/css/filament/admin/theme.css')  // カスタムテーマ
        ->colors(['primary' => Color::Amber])          // プライマリカラー
        ->navigationGroups($this->getNavigationGroups())
        ->plugins([
            StickyTableHeaderPlugin::make(),           // テーブルヘッダー固定
            AdvancedTablesPlugin::make()               // 高度なテーブル機能
                ->resourceNavigation('システム設定', sort: 1000)
                ->enableUserViews()
                ->enablePublicUserViews(),
        ]);
}
```

### ナビゲーショングループの動的生成

```php
protected function getNavigationGroups(): array
{
    return collect(EMenuCategory::cases())
        ->sortBy(fn ($category) => $category->sort())
        ->map(fn ($category) => NavigationGroup::make($category->label()))
        ->values()
        ->toArray();
}
```

### カラーパレット

| 用途 | カラー | 値 |
|------|--------|-----|
| Primary | Amber | `Color::Amber` |
| Success | Green | デフォルト |
| Danger | Red | デフォルト |
| Warning | Orange | デフォルト |
| Info | Blue | デフォルト |

---

## テーマCSS

### ファイル構成

```
resources/css/
├── app.css                          # Tailwind設定
└── filament/admin/theme.css         # Filamentテーマ
```

### theme.css - テーブルコンパクト化

```css
/* テーブル行の高さ削減 */
.fi-ta-row {
    min-height: 0 !important;
    height: auto !important;
}

/* セルのパディング削減 */
.fi-ta-cell {
    padding: 1px 2px !important;
    margin: 0 !important;
}

/* ストライプカラー */
.fi-ta-row:nth-child(odd) {
    background-color: rgba(59, 130, 246, 0.05);  /* 薄い青 */
}
.fi-ta-row:nth-child(even) {
    background-color: white;
}

/* ダークモード */
.dark .fi-ta-row:nth-child(odd) {
    background-color: rgba(59, 130, 246, 0.1);
}
.dark .fi-ta-row:nth-child(even) {
    background-color: rgb(31, 41, 55);
}
```

### theme.css - スティッキーアクション

```css
/* アクションカラムを右端に固定 */
.sticky-actions .fi-ta-actions-cell,
.sticky-actions th:last-child {
    position: sticky;
    right: 0;
    z-index: 10;
    box-shadow: -2px 0 4px rgba(0, 0, 0, 0.1);
}

/* ヘッダーのz-index調整 */
.sticky-actions thead th:last-child {
    z-index: 11;
}

/* ストライプ行の背景色維持 */
.sticky-actions .fi-ta-row:nth-child(odd) .fi-ta-actions-cell {
    background-color: rgba(59, 130, 246, 0.05);
}
.sticky-actions .fi-ta-row:nth-child(even) .fi-ta-actions-cell {
    background-color: white;
}
```

### theme.css - トップバーカスタマイズ

```css
/* トップバー高さ削減 */
.fi-topbar {
    height: 2.5rem;
    padding-top: 0.25rem;
    padding-bottom: 0.25rem;
}

.fi-topbar .fi-logo {
    max-height: 1.5rem;
}

.fi-topbar .fi-topbar-item {
    font-size: 0.75rem;
}

.fi-topbar .fi-icon {
    width: 1rem;
    height: 1rem;
}
```

### theme.css - モーダルユーティリティ

```css
/* モーダル分割レイアウト */
.modal-split-left {
    @apply bg-gray-50 dark:bg-gray-800 rounded-lg p-4;
}
.modal-split-right {
    @apply space-y-4;
}

/* 情報カード */
.modal-info-card {
    @apply bg-white dark:bg-gray-900 rounded-lg p-3 border border-gray-200 dark:border-gray-700;
}
.modal-label {
    @apply text-xs text-gray-500 dark:text-gray-400 mb-1;
}
.modal-value {
    @apply text-sm font-medium text-gray-900 dark:text-gray-100;
}
.modal-value-large {
    @apply text-lg font-bold text-gray-900 dark:text-gray-100;
}
.modal-value-primary {
    @apply text-lg font-bold text-primary-600 dark:text-primary-400;
}
.modal-value-danger {
    @apply text-lg font-bold text-danger-600 dark:text-danger-400;
}

/* 計算セクション */
.modal-calc-section {
    @apply bg-gray-50 dark:bg-gray-800 rounded-lg p-4 space-y-3;
}

/* 警告ボックス */
.modal-warning-box {
    @apply p-3 bg-warning-50 dark:bg-warning-900/20 border border-warning-200 dark:border-warning-800 rounded-lg;
}
```

### テーブルクラス（リソース別カラム幅）

```css
/* 発注候補テーブル */
.order-candidates-table col:nth-child(3) { width: 170px; }

/* 移動候補テーブル */
.transfer-candidates-table col:nth-child(3),
.transfer-candidates-table col:nth-child(4) { width: 140px; }
```

---

## テーブルデザインパターン

### 基本パターン

```php
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use App\Enums\PaginationOptions;

public static function configure(Table $table): Table
{
    return $table
        ->striped()                                           // ストライプ
        ->defaultPaginationPageOption(PaginationOptions::DEFAULT)  // デフォルト25件
        ->paginationPageOptions(PaginationOptions::all())     // 10,25,50,100,all
        ->extraAttributes(['class' => 'my-table sticky-actions'])  // CSSクラス
        ->defaultSort('created_at', 'desc')                   // デフォルトソート
        ->columns([...])
        ->filters([...])
        ->recordActions([...], position: RecordActionsPosition::AfterColumns)
        ->toolbarActions([...]);
}
```

### カラム幅の指定

```php
TextColumn::make('code')
    ->label('コード')
    ->width('80px')           // 固定幅
    ->alignCenter(),          // 中央揃え

TextColumn::make('name')
    ->label('名称')
    ->grow(),                 // 可変幅（残りスペースを使用）

TextColumn::make('quantity')
    ->label('数量')
    ->numeric()
    ->alignEnd()              // 右揃え
    ->width('70px'),
```

### カラムラベル標準

| データ種別 | ラベル | 例 |
|-----------|--------|-----|
| コード系 | 〜CD | 倉庫CD、商品CD、発注先CD |
| 名称系 | 〜名 | 倉庫名、商品名 |
| 数量系 | 〜数 | 発注数、在庫数、入庫数 |
| 日時系 | 〜日時、〜日 | 作成日時、入荷予定日 |

### 在庫関連カラム名の統一

| カラム | ラベル | 説明 |
|--------|--------|------|
| current_stock / current_effective_stock | 現在庫 | 現在の有効在庫数 |
| incoming_quantity | 入庫数 | 入庫予定数量（※「入庫予定」ではなく「入庫数」） |
| calculated_available | 見込在庫 | 計算後の利用可能在庫（※「計算後在庫」ではなく「見込在庫」） |
| safety_stock | 発注点 | 安全在庫/発注点 |
| shortage_qty | 不足分 | 不足数量 |
| suggested_quantity | 算出数 | システム算出の推奨数量 |
| order_quantity | 発注数 | 実際の発注数量 |
| transfer_quantity | 移動数 | 移動数量 |

### インライン編集

```php
use Filament\Tables\Columns\TextInputColumn;

TextInputColumn::make('order_quantity')
    ->label('発注数')
    ->type('number')
    ->rules(['required', 'integer', 'min:0'])
    ->disabled(fn ($record) => $record->status !== CandidateStatus::PENDING)
    ->afterStateUpdated(function ($record, $state) {
        $record->update([
            'order_quantity' => $state,
            'is_manually_modified' => true,
        ]);
    }),
```

### フィルタパターン

```php
SelectFilter::make('batch_code')
    ->label('実行CD')
    ->options(fn () => Model::query()
        ->select('batch_code')
        ->distinct()
        ->orderByDesc('batch_code')
        ->limit(50)
        ->pluck('batch_code', 'batch_code')
        ->toArray())
    ->default(fn () => Model::query()
        ->orderByDesc('batch_code')
        ->value('batch_code'))  // 最新を初期値に
    ->searchable(),

SelectFilter::make('warehouse_id')
    ->label('倉庫')
    ->options(fn () => Warehouse::query()
        ->where('is_active', true)
        ->orderBy('code')
        ->get()
        ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))  // [CD]名称形式
    ->searchable()
    ->getSearchResultsUsing(function (string $search): array {
        $search = mb_convert_kana($search, 'as');  // 全角→半角変換
        return Warehouse::query()
            ->where(fn ($q) => $q
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"))
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
            ->toArray();
    }),
```

---

## モーダルコンポーネント

### 詳細表示モーダル（読み取り専用）

```php
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;

Action::make('viewDetail')
    ->label('詳細')
    ->icon('heroicon-o-eye')
    ->color('gray')
    ->modalHeading('発注候補詳細')
    ->modalWidth('6xl')
    ->modalSubmitAction(false)           // 送信ボタン非表示
    ->modalCancelActionLabel('閉じる')   // キャンセルを「閉じる」に
    ->infolist(function ($record): array {
        return [
            Grid::make(3)
                ->schema([
                    // 左パネル（1/3）
                    View::make('filament.components.detail-left-panel')
                        ->viewData([
                            'item' => $record->item,
                            'warehouse' => $record->warehouse,
                        ])
                        ->columnSpan(1),

                    // 右パネル（2/3）
                    Section::make('詳細情報')
                        ->schema([
                            View::make('filament.components.detail-right-panel')
                                ->viewData([...]),
                        ])
                        ->columnSpan(2),
                ]),
        ];
    }),
```

### 編集モーダル

```php
use Filament\Support\Enums\Alignment;

Action::make('edit')
    ->label('編集')
    ->icon('heroicon-o-pencil')
    ->color('warning')
    ->modalHeading('発注数量編集')
    ->modalWidth('md')
    ->modalFooterActionsAlignment(Alignment::End)  // ボタンを右寄せ
    ->schema([
        TextInput::make('order_quantity')
            ->label('発注数')
            ->numeric()
            ->required()
            ->default(fn ($record) => $record->order_quantity),

        DatePicker::make('expected_arrival_date')
            ->label('入荷予定日')
            ->default(fn ($record) => $record->expected_arrival_date),
    ])
    ->action(function ($record, array $data) {
        $record->update([
            'order_quantity' => $data['order_quantity'],
            'expected_arrival_date' => $data['expected_arrival_date'],
            'is_manually_modified' => true,
        ]);
    }),
```

### モーダルフッターボタンの配置

```php
use Filament\Support\Enums\Alignment;

// ボタンを右寄せ（推奨）
->modalFooterActionsAlignment(Alignment::End)

// ボタンを左寄せ
->modalFooterActionsAlignment(Alignment::Start)

// ボタンを中央寄せ
->modalFooterActionsAlignment(Alignment::Center)
```

**利用可能なAlignment:**
- `Alignment::Start` - 左寄せ
- `Alignment::Center` - 中央寄せ
- `Alignment::End` - 右寄せ（推奨）

**重要:** デフォルトは左寄せ。業務アプリでは右寄せ（`Alignment::End`）が標準的。

### Bladeコンポーネント例

```blade
{{-- filament/components/detail-left-panel.blade.php --}}
<div class="modal-split-left space-y-3">
    <div class="modal-info-card">
        <dt class="modal-label">倉庫</dt>
        <dd class="modal-value">[{{ $warehouse->code }}] {{ $warehouse->name }}</dd>
    </div>

    <div class="modal-info-card">
        <dt class="modal-label">商品</dt>
        <dd class="modal-value-large">[{{ $item->code }}] {{ $item->name }}</dd>
        <dd class="text-xs text-gray-500 mt-1">{{ $item->packaging }}</dd>
    </div>

    @if($orderQuantity > 0)
        <div class="modal-info-card">
            <dt class="modal-label">発注数</dt>
            <dd class="modal-value-primary">{{ number_format($orderQuantity) }}</dd>
        </div>
    @endif
</div>
```

---

## ログイン画面

### デザイン特徴

```blade
{{-- filament/pages/auth/login.blade.php --}}
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-100 to-slate-200">
    <div class="w-full max-w-md p-8 bg-white/90 backdrop-blur-sm rounded-xl shadow-2xl border border-gray-200">

        {{-- ロゴ --}}
        <div class="text-center mb-8">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-16 mx-auto mb-4">
            <h1 class="text-2xl font-serif tracking-widest text-gray-800">
                おかえりなさい
            </h1>
            <p class="text-sm text-gray-500 mt-1">倉庫管理システム</p>
        </div>

        {{-- フォーム --}}
        <form wire:submit="authenticate" class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">メールアドレス</label>
                <input type="email" wire:model="data.email"
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg
                           focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400
                           transition-colors">
                @error('data.email')
                    <p class="text-red-700 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">パスワード</label>
                <input type="password" wire:model="data.password"
                    class="w-full px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg
                           focus:ring-2 focus:ring-indigo-200 focus:border-indigo-400
                           transition-colors">
            </div>

            <button type="submit"
                class="w-full py-3 bg-slate-800 text-white rounded-lg font-medium
                       hover:-translate-y-0.5 transform transition-all duration-200
                       hover:bg-slate-700 focus:ring-2 focus:ring-slate-400">
                <span wire:loading.remove>ログイン</span>
                <span wire:loading class="flex items-center justify-center gap-2">
                    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24">...</svg>
                    認証中...
                </span>
            </button>
        </form>
    </div>
</div>
```

### スタイリング仕様

| 要素 | スタイル |
|------|---------|
| 背景 | グラデーション `from-slate-100 to-slate-200` |
| カード | `bg-white/90 backdrop-blur-sm rounded-xl shadow-2xl` |
| 入力フィールド | `bg-gray-50 border-gray-300 rounded-lg` |
| フォーカス | `ring-2 ring-indigo-200 border-indigo-400` |
| ボタン | `bg-slate-800 hover:bg-slate-700 hover:-translate-y-0.5` |
| エラー | `text-red-700 text-xs` |

---

## デザイン原則

### 1. コンパクトで高密度なテーブル

- テーブル行の高さを最小限に
- パディング/マージンを削減
- データ中心のUI設計

### 2. スティッキー要素の活用

- テーブルヘッダーを固定（StickyTableHeaderPlugin）
- アクションカラムを右端に固定（sticky-actions）
- 長いリストでも操作性を維持

### 3. 日本語UX

- すべてのラベル・メッセージを日本語化
- 業務用語に合わせた表現（ケース、バラ、ボール等）
- コード+名称の併記形式（[CD]名称）

### 4. ダークモード対応

- すべてのコンポーネントにダークモードスタイルを定義
- `dark:` プレフィックスで切り替え

### 5. モーダル中心のワークフロー

- 詳細表示・編集はモーダルで完結
- ページ遷移を最小限に
- `infolist()` で読み取り専用、`schema()` で編集可能

### 6. アイコンの一貫性

- UI全般: Heroicons（`heroicon-o-*`）
- メガメニュー: Font Awesome 6（`fa-solid fa-*`）

---

## クロスアプリケーション セッション共有

複数のLaravel/Filamentアプリケーション間でセッションを共有し、シングルサインオン（SSO）的な動作を実現するための設定。

### 前提条件

- 全アプリケーションが同じデータベースの`sessions`テーブルを使用
- 全アプリケーションが同じドメイン配下（例: `*.sakemaru.test`）
- 全アプリケーションが同じ`users`テーブルを参照

### 必要な設定

#### 1. .env 設定（全アプリ共通）

```env
# セッション設定
SESSION_DRIVER=database
SESSION_DOMAIN=.sakemaru.test          # 先頭にドットでサブドメイン共有
SESSION_COOKIE=sakemaru_session        # 全アプリで同じCookie名
SESSION_LIFETIME=3600
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
SESSION_PATH=/
SESSION_CONNECTION=sakemaru            # セッションを保存するDB接続名
```

#### 2. config/session.php

```php
'connection' => env('SESSION_CONNECTION', 'sakemaru'),
```

#### 3. User モデルのクラスパス統一

異なるアプリケーションで異なるUserモデルパスを使用している場合、相互にエイリアスクラスを作成する必要がある。

**例: WMSが `App\Models\Sakemaru\User` を使用、Tradeが `App\Models\User` を使用する場合**

Trade側に作成:
```php
// app/Models/Sakemaru/User.php
namespace App\Models\Sakemaru;

use App\Models\User as BaseUser;

class User extends BaseUser
{
    // WMSセッション互換性のためのエイリアス
}
```

WMS側に作成:
```php
// app/Models/User.php
namespace App\Models;

use App\Models\Sakemaru\User as SakemaruUser;

class User extends SakemaruUser
{
    // Tradeセッション互換性のためのエイリアス
}
```

#### 4. auth.php の設定

全アプリで同じUserモデルパスを参照するか、上記のエイリアスを設定した上で統一する。

```php
'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\Sakemaru\User::class,  // 推奨: 全アプリで統一
    ],
],
```

### 重要: AuthenticateSession ミドルウェアの無効化

**クロスアプリケーションセッション共有では `AuthenticateSession` ミドルウェアを無効化する必要がある。**

#### 問題の原因

`AuthenticateSession` ミドルウェアは、セッションに保存された `password_hash_web` と現在のユーザーの `getAuthPassword()` を比較する。異なるアプリケーション間でこの比較が失敗すると、セッションがクリアされログアウトが発生する。

#### 解決策

AdminPanelProvider.php でミドルウェアリストから `AuthenticateSession` をコメントアウト:

```php
->middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    // AuthenticateSession::class, // Disabled for cross-app session sharing
    ShareErrorsFromSession::class,
    VerifyCsrfToken::class,
    SubstituteBindings::class,
    DisableBladeIconComponents::class,
    DispatchServingFilamentEvent::class,
])
```

#### セキュリティ上の注意

`AuthenticateSession` を無効化すると、以下のセキュリティ機能が失われる:

1. **パスワード変更時の自動ログアウト**: ユーザーがパスワードを変更しても、既存のセッションは無効化されない
2. **セッションハイジャック検出**: パスワードハッシュによるセッション検証が行われない

**対策案:**
- パスワード変更時に明示的にセッションを無効化する処理を実装
- 重要な操作時に再認証を要求する

### トラブルシューティング

| 症状 | 原因 | 解決策 |
|------|------|--------|
| アプリAでログイン後、アプリBでログアウトされる | `AuthenticateSession`のパスワードハッシュ検証失敗 | `AuthenticateSession`を無効化 |
| セッションが共有されない | `SESSION_DOMAIN`が不一致 | 全アプリで`.domain.test`形式に統一 |
| セッションが共有されない | `SESSION_CONNECTION`が未設定 | 全アプリで同じDB接続を指定 |
| Userモデルがデシリアライズできない | クラスパスが異なる | エイリアスクラスを作成 |
| セッションのuser_idがnullになる | 認証プロバイダのモデル設定が異なる | auth.phpのmodelを統一 |

### 動作確認チェックリスト

- [ ] 全アプリで同じ`SESSION_DOMAIN`を設定
- [ ] 全アプリで同じ`SESSION_COOKIE`名を設定
- [ ] 全アプリで同じ`SESSION_CONNECTION`を設定
- [ ] Userモデルのエイリアスを相互に作成
- [ ] auth.phpのmodelパスを統一または互換性確保
- [ ] `AuthenticateSession`ミドルウェアを無効化
- [ ] 双方向のログイン/移動テストを実施
