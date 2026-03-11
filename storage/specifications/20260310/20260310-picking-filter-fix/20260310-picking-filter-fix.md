# 出荷・ピッキング対象フィルタ欠落修正

- **作成日**: 2026-03-10
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/202503100000/20260310-picking-filter-fix/`

## 背景・目的

`/admin/delivery-course-changes` で伝票を確認すると、非アクティブ（`trade_items.is_active = 0`）のアイテムが出荷リストに含まれている。同様の問題が波動生成・ピッキング処理の複数箇所に存在する。

さらに、以下の条件の伝票もピッキング対象外とすべき:
- **返品伝票**: `trades.trade_direction = 'RETURN'`
- **協賛伝票**: `trades.trade_direction = 'SPONSOR'`
- **マイナス数量**: `trade_items.quantity < 0`

## 現状の実装（問題箇所）

### 問題1: trade_items.is_active フィルタ欠落

| # | ファイル | 行 | 用途 | is_active フィルタ | 深刻度 |
|---|---------|-----|------|-------------------|--------|
| 1 | `GenerateWavesCommand.php` | L150-153 | 自動波動生成（earnings用trade_items取得） | **欠落** | CRITICAL |
| 2 | `GenerateWavesCommand.php` | L415-419 | 自動波動生成（stock_transfers用） | `is_deleted=false`（不整合） | 要統一 |
| 3 | `ListWaves.php` | L474-477 | 手動波動生成（earnings用trade_items取得） | **欠落** | CRITICAL |
| 4 | `ListWaves.php` | L722-726 | 手動波動生成（stock_transfers用） | `is_active=true` ✓ | OK |
| 5 | `TradeDetailModal.php` | L81-84 | 配送コース変更の伝票詳細表示 | **欠落** | CRITICAL |
| 6 | `DeliveryCourseChangeResource.php` | L78-81 | 配送コース変更の伝票詳細 | **欠落** | CRITICAL |
| 7 | `ProxyShipmentService.php` | L69-72 | 横持ち出荷の価格取得（ID検索） | 欠落（低リスク） | LOW |
| 8 | `EarningDeliveryQueueService.php` | L58-61 | 配送キュー登録（ID検索） | 欠落（低リスク） | LOW |

### 問題2: trades.trade_direction フィルタ欠落

波動生成の全クエリで `trades.trade_direction` のフィルタが**一切ない**。

- `GenerateWavesCommand.php`: earnings を `delivery_course_id` + `picking_status` のみでフィルタ。trades テーブルとの JOIN なし
- `ListWaves.php`: 同上

**`ETradeDirection` enum**: `NORMAL`, `RETURN`（返品）, `SPONSOR`（協賛）, `INVENTORY`（在庫調整）, `ITEM_SET`（セット調整）

ピッキング対象は `NORMAL` のみであるべき。

### 問題3: マイナス数量フィルタ欠落

`trade_items.quantity` がマイナスの場合（返品や調整）もピッキング対象に含まれてしまう。

## 変更内容

### 概要

1. trade_items クエリに `is_active = true` フィルタを追加（6箇所）
2. 波動生成の earnings クエリに `trades.trade_direction = 'NORMAL'` フィルタを追加
3. trade_items クエリに `quantity > 0` フィルタを追加

### 詳細設計

#### 修正箇所一覧

##### A. GenerateWavesCommand.php

**A-1. earnings用 trade_items 取得（L150-153）**
```php
// 変更前
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->whereIn('trade_id', $tradeIds)
    ->get();

// 変更後
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->whereIn('trade_id', $tradeIds)
    ->where('is_active', true)
    ->where('quantity', '>', 0)
    ->get();
```

**A-2. stock_transfers用 trade_items 取得（L415-419）— `is_deleted` を `is_active` に統一**
```php
// 変更前
->where('is_deleted', false)

// 変更後
->where('is_active', true)
->where('quantity', '>', 0)
```

**A-3. earnings カウントクエリ（L84-88）— trade_direction フィルタ追加**
```php
// 変更前
$earningsCount = Earning::where('delivered_date', $shippingDate)
    ->where('is_delivered', 0)
    ->where('picking_status', 'BEFORE')
    ->where('delivery_course_id', $setting->delivery_course_id)
    ->count();

// 変更後
$earningsCount = Earning::where('delivered_date', $shippingDate)
    ->where('is_delivered', 0)
    ->where('picking_status', 'BEFORE')
    ->where('delivery_course_id', $setting->delivery_course_id)
    ->whereHas('trade', fn ($q) => $q->where('trade_direction', 'NORMAL'))
    ->count();
```

**A-4. earnings 取得クエリ（L137-141）— trade_direction フィルタ追加**
```php
// 変更後
$earnings = Earning::where('delivered_date', $shippingDate)
    ->where('is_delivered', 0)
    ->where('picking_status', 'BEFORE')
    ->where('delivery_course_id', $setting->delivery_course_id)
    ->whereHas('trade', fn ($q) => $q->where('trade_direction', 'NORMAL'))
    ->get();
```

##### B. ListWaves.php

**B-1. 配送コース選択肢 earnings カウント（L69-79）— trade_direction フィルタ追加**
```php
// earnings テーブルクエリに追加
->join('trades', 'earnings.trade_id', '=', 'trades.id')
->where('trades.trade_direction', 'NORMAL')
```

**B-2. プレビュー earnings カウント（L145-157）— trade_direction フィルタ追加**
同上パターン

**B-3. 手動波動生成 earnings 取得（L277-284）— trade_direction フィルタ追加**
```php
$earnings = Earning::query()
    ->whereIn('warehouse_id', $warehouseIds)
    // ... existing filters ...
    ->whereHas('trade', fn ($q) => $q->where('trade_direction', 'NORMAL'))
    ->get();
```

**B-4. earnings用 trade_items 取得（L474-477）— is_active + quantity フィルタ追加**
```php
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->whereIn('trade_id', $tradeIds)
    ->where('is_active', true)
    ->where('quantity', '>', 0)
    ->get();
```

##### C. TradeDetailModal.php

**C-1. 伝票詳細表示（L81-84）— is_active フィルタ追加**
```php
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->where('trade_id', $this->tradeId)
    ->where('is_active', true)
    ->get();
```

##### D. DeliveryCourseChangeResource.php

**D-1. getTradeDetails()（L78-81）— is_active フィルタ追加**
```php
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->where('trade_id', $tradeId)
    ->where('is_active', true)
    ->get();
```

#### Earning モデルの確認

`Earning` モデルに `trade` リレーションが定義されているか確認が必要:
```php
public function trade(): BelongsTo
{
    return $this->belongsTo(Trade::class);
}
```

### DB変更

なし

### モデル変更

なし（Earning に trade リレーションが既に存在する前提）

### 影響範囲

| 機能 | 影響 |
|------|------|
| 自動波動生成 | 返品・協賛・非アクティブ・マイナス数量が除外される |
| 手動波動生成 | 同上 |
| 配送コース変更画面 | 非アクティブアイテムが表示されなくなる |
| 配送コース選択肢の件数表示 | 返品伝票が除外され件数が減る可能性 |

## 制約

- FK禁止（アプリレベルでリレーション管理）
- `migrate:fresh/refresh/reset` 禁止
- DB変更なし
- `is_active` と `is_deleted` の両方が存在するテーブルでは `is_active` を使用（統一）

## 対象ファイル

### 新規作成
なし

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Console/Commands/GenerateWavesCommand.php` | is_active + quantity>0 + trade_direction フィルタ追加（4箇所） |
| `app/Filament/Resources/Waves/Pages/ListWaves.php` | is_active + quantity>0 + trade_direction フィルタ追加（4箇所） |
| `app/Livewire/TradeDetailModal.php` | is_active フィルタ追加（1箇所） |
| `app/Filament/Resources/DeliveryCourseChangeResource.php` | is_active フィルタ追加（1箇所） |

### 参照のみ

| ファイル | 理由 |
|---------|------|
| `app/Models/Sakemaru/Earning.php` | trade リレーション確認 |
| `app/Enums/Partners/ETradeDirection.php` | enum値の確認 |

## 確認事項

1. **Earning モデルの trade リレーション**: `whereHas('trade', ...)` を使うため、`Earning::trade()` BelongsTo リレーションが必要。存在しなければ追加が必要。=> 高速化のためにearnings.trade_idを利用すること .
2. **SPONSOR（協賛）の扱い**: 協賛も出荷対象外で正しいか？（通常は物品を無償提供するため出荷は発生する可能性がある）出荷対象である。
3. **INVENTORY / ITEM_SET の扱い**: 在庫調整・セット調整も同様に除外すべきか？ 除外
4. **is_active vs is_deleted**: trade_items テーブルに `is_active` と `is_deleted` の両カラムが存在する場合、どちらを使うべきか統一ルールが必要。現状 stock_transfers 用は `is_deleted=false` を使用。 =>伝票から削除される場合は基本 is_activeが正しい。is_deleted が利用される場面でいつis_deletedがtrueになるかを調査。冗長な可能性がある。 
5. **マイナス数量のルール**: `quantity <= 0` を全て除外で正しいか？数量0のアイテムも除外すべきか？ 発注数量が0は除外。出荷数量が0は実際に発注数量の引き当てを倉庫でもう一度行うので除外しない。
