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
