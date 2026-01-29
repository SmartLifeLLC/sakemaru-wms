# Filament 4 仕様ドキュメント

このドキュメントは、Filament 4で実装する際の重要な仕様と変更点をまとめたものです。

## 目次

1. [テーブルクエリのカスタマイズ](#テーブルクエリのカスタマイズ)
2. [アクションの位置制御](#アクションの位置制御)
3. [フォームコンポーネント](#フォームコンポーネント)
4. [重要なENUMクラス](#重要なenumクラス)

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
