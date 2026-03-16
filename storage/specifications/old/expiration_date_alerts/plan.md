# 賞味期限管理ページ 作業計画

## 前提

- フロアプランエディタのゾーン色表示（P0）は完了済み
- `real_stock_lots` テーブルに `expiration_date`, `alert_date`, `status`, `current_quantity` カラムが存在
- 倉庫タブは `AdvancedTables` + `PresetView` パターンで実装（WmsOrderCandidatesと同様）
- メニューは `EMenu` / `EMenuCategory` enum で管理

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | メニュー項目追加 | EMenu/EMenuCategoryにメニュー登録 | メニュー項目が表示される |
| P2 | リソース・テーブル・リストページ作成 | Filamentリソース一式を新規作成 | ページが表示されアラート在庫が一覧できる |
| P3 | 倉庫別タブ実装 | AdvancedTables PresetViewで倉庫タブ追加 | 倉庫別にフィルタリングできる |
| P4 | 動作確認・コード品質 | Pint実行、構文チェック、ブラウザ確認 | エラーなし |

---

## P1: メニュー項目追加

### 目的

「在庫 → 賞味期限管理」メニューをFilamentナビゲーションに追加する。

### 修正方針

EMenuCategory::INVENTORYは既に「在庫管理」として存在する。新しいメニュー項目をこのカテゴリに追加する。

#### `app/Enums/EMenu.php`

```php
// 在庫管理セクションに追加
case EXPIRATION_ALERTS = 'inventory.expiration_alerts';
```

以下のmatchブロックに追加:
- `category()`: `self::EXPIRATION_ALERTS => EMenuCategory::INVENTORY`
- `label()`: `self::EXPIRATION_ALERTS => '賞味期限管理'`
- `icon()`: `self::EXPIRATION_ALERTS => 'heroicon-o-clock'` (期限のイメージ)
- `sort()`: `self::EXPIRATION_ALERTS => 2` (在庫管理=1の次)

### 完了条件

- `php -l app/Enums/EMenu.php` でエラーなし
- Filamentナビゲーションに「在庫管理 > 賞味期限管理」が表示

---

## P2: Filamentリソース・テーブル・リストページ作成

### 目的

`real_stock_lots` を元に、賞味期限アラート・期限切れの在庫を一覧表示するページを作成する。

### 修正方針

Filament 4のリソース構造に従って3ファイルを新規作成する。

#### ファイル構造

```
app/Filament/Resources/ExpirationAlerts/
├── ExpirationAlertResource.php
├── Pages/
│   └── ListExpirationAlerts.php
└── Tables/
    └── ExpirationAlertsTable.php
```

#### ExpirationAlertResource.php

```php
- model: RealStockLot::class
- navigationGroup: EMenu::EXPIRATION_ALERTS->category()->label()
- navigationLabel: EMenu::EXPIRATION_ALERTS->label()
- navigationIcon: EMenu::EXPIRATION_ALERTS->icon()
- navigationSort: EMenu::EXPIRATION_ALERTS->sort()
- slug: 'expiration-alerts'
- pages: ListExpirationAlerts のみ（Create/Edit不要 = 読み取り専用）
```

#### ExpirationAlertsTable.php

テーブルカラム（table-design-specification.mdに準拠）:

| カラム | ソース | 表示名 | 備考 |
|--------|--------|--------|------|
| ステータス | computed | ステータス | Badge: 期限切れ(danger)/アラート(warning) |
| 倉庫CD | warehouses.code via real_stocks | 倉庫CD | |
| 倉庫名 | warehouses.name via real_stocks | 倉庫名 | |
| 商品CD | items.code via real_stocks | 商品CD | |
| 商品名 | items.name via real_stocks | 商品名 | grow()使用、wrap()禁止 |
| ロケーション | locations (code1+code2+code3) | ロケーション | |
| 規格 | items.volume + items.volume_unit | 規格 | EVolumeUnit使用 |
| 入荷日 | real_stock_lots.created_at | 入荷日 | date表示 |
| 賞味期限 | real_stock_lots.expiration_date | 賞味期限 | 期限切れは赤、アラートはオレンジ |
| アラート日 | real_stock_lots.alert_date | アラート日 | |
| 在庫数 | real_stock_lots.current_quantity | 在庫数 | |

クエリ:
```php
// テーブルクエリ（modifyQueryUsing）
$query->where('status', 'ACTIVE')
    ->where('current_quantity', '>', 0)
    ->whereNotNull('expiration_date')
    ->where(function ($q) {
        $q->where('expiration_date', '<', now()->toDateString())  // 期限切れ
          ->orWhere(function ($q2) {
              $q2->whereNotNull('alert_date')
                 ->where('alert_date', '<=', now()->toDateString())
                 ->where('expiration_date', '>=', now()->toDateString()); // アラート中
          });
    })
    ->with(['realStock.item', 'location']);
```

ソート: デフォルトは `expiration_date` ASC（期限が近いものが上）

フィルター:
- ステータスフィルター（期限切れ / アラート中）
- 倉庫フィルター（SelectFilter）
- 商品名検索

#### ListExpirationAlerts.php

```php
- extends ListRecords
- use AdvancedTables, HasWmsUserViews traits
- getPresetViews() で倉庫タブを生成（P3で実装）
```

### 完了条件

- 3ファイルが作成され、`php -l` でエラーなし
- ページにアクセスしてテーブルが表示される
- 期限切れ・アラート中のロットのみが表示される

---

## P3: 倉庫別タブ実装

### 目的

WmsOrderCandidatesと同じパターンで、アラート在庫が存在する倉庫のタブを動的生成する。

### 修正方針

`ListExpirationAlerts.php` に `getPresetViews()` を実装:

```php
public function getPresetViews(): array
{
    $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

    // アラート在庫が存在する倉庫を取得
    $warehouseIds = cache()->remember(
        'expiration_alerts_warehouses_' . auth()->id(),
        30,
        fn () => RealStockLot::query()
            ->join('real_stocks', 'real_stocks.id', '=', 'real_stock_lots.real_stock_id')
            ->where('real_stock_lots.status', 'ACTIVE')
            ->where('real_stock_lots.current_quantity', '>', 0)
            ->whereNotNull('real_stock_lots.expiration_date')
            ->where(function ($q) {
                $q->where('real_stock_lots.expiration_date', '<', now()->toDateString())
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('real_stock_lots.alert_date')
                         ->where('real_stock_lots.alert_date', '<=', now()->toDateString());
                  });
            })
            ->distinct()
            ->pluck('real_stocks.warehouse_id')
            ->toArray()
    );

    $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('code')->get();
    $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);

    $views = [
        'default' => PresetView::make()
            ->favorite()
            ->label('全て')
            ->default(!$hasDefaultWarehouse || empty($warehouses)),
    ];

    foreach ($warehouses as $warehouse) {
        $isDefault = $hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId;
        $views["default_{$warehouse->id}"] = PresetView::make()
            ->modifyQueryUsing(fn (Builder $query) =>
                $query->whereHas('realStock', fn ($q) => $q->where('warehouse_id', $warehouse->id))
            )
            ->favorite()
            ->label($warehouse->name)
            ->default($isDefault);
    }

    return $views;
}
```

### 完了条件

- 倉庫タブが表示される
- タブ切り替えで当該倉庫のデータのみ表示される
- ユーザーのデフォルト倉庫タブが自動選択される

---

## P4: 動作確認・コード品質

### 手順

1. `php -l` で全新規ファイルの構文チェック
2. `./vendor/bin/pint --dirty` でフォーマット修正
3. ブラウザで `/admin/expiration-alerts` にアクセス
4. 以下を確認:
   - メガメニュー「在庫」→「賞味期限管理」が表示される
   - テーブルにアラート在庫が表示される
   - 倉庫タブが機能する
   - ステータスバッジが正しく色分けされる
   - ソート・フィルターが機能する

### 完了条件

- Pint でエラーなし
- 構文エラーなし
- ブラウザで正常表示

---

## 制約（厳守）

1. `migrate:fresh`, `migrate:refresh` 等の破壊的DBコマンド禁止
2. FK使用禁止
3. Filament 4のインポートパスに準拠（`Filament\Schemas\Components\Section` 等）
4. テーブルデザイン仕様に準拠（CDラベル、grow()、sticky-actions等）
5. `QuantityType` / `EVolumeUnit` enum を表示に使用
6. 読み取り専用ページ（Create/Edit不要）

## 全体完了条件

1. メニュー「在庫 → 賞味期限管理」が表示される
2. 期限切れ・アラート中の在庫が倉庫タブ付きで一覧表示される
3. コード品質チェック（Pint、構文）がパス
