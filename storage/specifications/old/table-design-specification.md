# テーブルデザイン仕様書

このドキュメントはWMSシステムのFilamentテーブルのデザイン標準を定義します。

## 1. カラムラベルの命名規則

### コード系カラムは「CD」表記で統一

| 変更前 | 変更後 |
|--------|--------|
| 商品コード | 商品CD |
| 検索コード | 検索CD |
| 倉庫コード | 倉庫CD |
| 発注先コード | 発注先CD |

```php
// 例
TextColumn::make('item.code')
    ->label('商品CD')  // ✅ 正しい
    // ->label('商品コード')  // ❌ 使用しない
```

### コードと名前は別カラムに分離

倉庫・発注先などは「[コード]名前」形式ではなく、別々のカラムとして表示する:

```php
// ✅ 正しい: 別カラムに分離
TextColumn::make('warehouse.code')
    ->label('倉庫CD')
    ->width('50px'),

TextColumn::make('warehouse.name')
    ->label('倉庫名')
    ->width('120px'),

// ❌ 使用しない: 結合表示
TextColumn::make('warehouse.name')
    ->state(fn ($record) => "[{$record->warehouse->code}]{$record->warehouse->name}")
```

## 2. 日付・時刻の表示形式

### 24時間形式で統一

すべての時刻表示は24時間形式を使用する。

```php
// ✅ 正しい: 24時間形式
TextColumn::make('created_at')
    ->label('作成日時')
    ->dateTime('m/d H:i'),  // 例: 12/27 23:05

TextColumn::make('order_date')
    ->label('発注日')
    ->date('m/d'),  // 例: 12/27

// ❌ 使用しない: 12時間形式
->dateTime('m/d h:i A')  // AM/PM形式は使用しない
```

### 主な日付フォーマット

| 用途 | フォーマット | 表示例 |
|------|-------------|--------|
| 日付のみ | `m/d` | 12/27 |
| 日時 | `m/d H:i` | 12/27 23:05 |
| 年付き日付 | `Y/m/d` | 2025/12/27 |
| 年付き日時 | `Y/m/d H:i` | 2025/12/27 23:05 |

### 実行CDの表示

実行CD（batch_code）はそのまま表示する（日付形式に変換しない）。
ラベルは「実行CD」で統一。

```php
// ✅ 正しい: そのまま表示
TextColumn::make('batch_code')
    ->label('実行CD')
    ->sortable()
    ->searchable(),

// ❌ 使用しない: 日付形式に変換
TextColumn::make('batch_code')
    ->state(fn ($record) => Carbon::createFromFormat('YmdHis', $record->batch_code)->format('m/d H:i'))

// ❌ 使用しない: 古いラベル
->label('バッチコード')
```

## 4. 商品名の表示

### wrap禁止 - 全体表示

商品名は折り返し（wrap）せず、全体を表示する。`->grow()`を使用してカラム幅を可変にする。

```php
// ✅ 正しい
TextColumn::make('item.name')
    ->label('商品名')
    ->searchable()
    ->sortable()
    ->grow(),  // 可変幅

// ❌ 使用しない
TextColumn::make('item.name')
    ->wrap()  // 折り返し禁止
    ->grow(),
```

## 5. アクションボタンの右固定配置

### CSSクラス `sticky-actions` の使用

テーブルに`sticky-actions`クラスを追加することで、アクションカラムを右側に固定できる。

```php
return $table
    ->striped()
    ->extraAttributes(['class' => 'sticky-actions'])  // これを追加
    ->columns([...])
    ->recordActions([...]);
```

### 背景色の設定（theme.css）

`sticky-actions`クラスを使用すると、以下のスタイルが適用される:

- アクションカラムが`position: sticky`で右端に固定
- 横スクロール時も常に表示
- stripe行の背景色を不透明色で設定（文字の透け防止）
- 影付きで固定カラムを視覚的に区別

```css
/* resources/css/filament/admin/theme.css */

/* アクションカラム固定 */
.sticky-actions .fi-ta-row td:last-child {
    position: sticky !important;
    right: 0 !important;
    z-index: 10 !important;
    box-shadow: -2px 0 4px rgba(0, 0, 0, 0.1) !important;
}

/* 背景色（不透明） */
.sticky-actions .fi-ta-table tbody tr:nth-child(odd) td:last-child {
    background-color: #f5f9ff !important;
}

.sticky-actions .fi-ta-table tbody tr:nth-child(even) td:last-child {
    background-color: #ffffff !important;
}
```

## 6. テーブルのヘッダー固定・タブ表示

### ヘッダー固定: Sticky Table Header プラグイン

テーブルヘッダーの固定（縦スクロール時にヘッダーが常に表示）は `watheq-alshowaiter/sticky-table-header` プラグインで実現。

- プラグインURL: https://filamentphp.com/plugins/watheq-alshowaiter-sticky-table-header
- インストール済みで自動適用される

### タブ表示: Advanced Filter Sets プラグイン

倉庫別タブなどのプリセットビュー表示は `archilex/advanced-tables` プラグインで実現。

ListRecordsページで以下のtraitを使用:

```php
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;

class ListWmsOrderIncomingSchedules extends ListRecords
{
    use AdvancedTables;

    public function getPresetViews(): array
    {
        return [
            'default' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(true),

            'default_1' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', 1))
                ->favorite()
                ->label('倉庫A'),
        ];
    }
}
```

**重要: プリセットビューのキー命名規則**

すべてのプリセットビューのキーは `default` プレフィックスで統一する必要がある。
そうしないと「default」と「全て」が両方表示されてしまう。

```php
// ✅ 正しいキー命名
'default' => PresetView::make()->label('全て'),           // 「全て」タブ
'default_1' => PresetView::make()->label('倉庫A'),        // 倉庫タブ
'default_2' => PresetView::make()->label('倉庫B'),        // 倉庫タブ

// ❌ 使用しない
'all' => PresetView::make()->label('全て'),               // NG: defaultプレフィックスなし
'warehouse_1' => PresetView::make()->label('倉庫A'),      // NG: defaultプレフィックスなし
```

**データがなくても「全て」タブを常に表示する**

```php
$views = [
    'default' => PresetView::make()
        ->favorite()
        ->label('全て')
        ->default(! $hasDefaultWarehouse || empty($warehouses)),  // データがない場合もデフォルト選択
];

// 倉庫タブを追加（データがある場合のみ）
foreach ($warehouses as $warehouse) {
    $views["default_{$warehouse->id}"] = PresetView::make()
        ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
        ->favorite()
        ->label($warehouse->name)
        ->default($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId);
}
```

## 7. フィルターの設計

### [コード]名前 形式での表示

フィルターのオプションは`[コード]名前`形式で表示し、コードでも検索可能にする:

```php
SelectFilter::make('warehouse_id')
    ->label('倉庫')
    ->options(fn () => Warehouse::query()
        ->where('is_active', true)
        ->orderBy('code')
        ->get()
        ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
    ->searchable()
    ->getSearchResultsUsing(function (string $search): array {
        $search = mb_convert_kana($search, 'as');  // 全角→半角変換

        return Warehouse::query()
            ->where('is_active', true)
            ->where(function ($query) use ($search) {
                $query->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            })
            ->orderBy('code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
            ->toArray();
    }),
```

### 複数選択が必要な場合

```php
SelectFilter::make('contractor_id')
    ->label('発注先')
    ->multiple()  // 複数選択可能
    ->options(...)
    ->searchable()
    ->getSearchResultsUsing(...),
```

## 8. 実装例

### 完全な実装例（WmsOrderIncomingSchedulesTable）

```php
public static function configure(Table $table): Table
{
    return $table
        ->striped()
        ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
        ->paginationPageOptions(PaginationOptions::all())
        ->extraAttributes(['class' => 'incoming-schedules-table sticky-actions'])
        ->columns([
            TextColumn::make('id')
                ->label('ID')
                ->sortable()
                ->width('60px'),

            TextColumn::make('warehouse.code')
                ->label('倉庫CD')
                ->searchable()
                ->alignCenter()
                ->width('50px'),

            TextColumn::make('warehouse.name')
                ->label('倉庫名')
                ->searchable()
                ->width('120px'),

            TextColumn::make('item.code')
                ->label('商品CD')
                ->searchable()
                ->sortable()
                ->alignCenter()
                ->width('70px'),

            TextColumn::make('item.name')
                ->label('商品名')
                ->searchable()
                ->sortable()
                ->grow(),  // wrapしない、可変幅

            // ... 他のカラム
        ])
        ->filters([...])
        ->recordActions([...]);
}
```

## 9. チェックリスト

新しいテーブルを作成する際は以下を確認:

- [ ] コード系カラムのラベルは「CD」表記になっているか
- [ ] コードと名前は別カラムに分離しているか
- [ ] 商品名に`->wrap()`が付いていないか
- [ ] 商品名に`->grow()`が付いているか
- [ ] `sticky-actions`クラスがテーブルに追加されているか
- [ ] フィルターで`[コード]名前`形式を使用しているか
- [ ] フィルターでコード検索が可能か
