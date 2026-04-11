# 出荷・ピッキング対象フィルタ欠落修正 作業計画

## 前提

- trade_items.is_active フィルタが複数箇所で欠落
- trades.trade_direction フィルタが全箇所で未使用
- trade_items.quantity > 0 フィルタが未使用
- 確認済み:
  - SPONSOR（協賛）は出荷対象 → 除外しない
  - RETURN, INVENTORY, ITEM_SET は除外
  - `is_active` で統一（`is_deleted` は使わない）
  - `whereHas` は使わず `trade_id` JOIN で高速化
  - 発注数量(`trade_items.quantity`) > 0 で除外

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | GenerateWavesCommand.php 修正 | 自動波動生成の4箇所を修正 | Pint通過 |
| P1 | ListWaves.php 修正 | 手動波動生成の4箇所を修正 | Pint通過 |
| P2 | 配送コース変更画面修正 | TradeDetailModal + DeliveryCourseChangeResource | Pint通過 |
| P3 | 検証・Pint | 全体テスト | Pint + composer test 通過 |

---

## P0: GenerateWavesCommand.php 修正

### 修正対象ファイル

`app/Console/Commands/GenerateWavesCommand.php`

### 修正内容

**P0-1. earnings カウントクエリ（L84-88）— trade_direction フィルタ追加**

Earning Eloquent クエリに JOIN を追加:

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
    ->join('trades', 'earnings.trade_id', '=', 'trades.id')
    ->whereIn('trades.trade_direction', ['NORMAL', 'SPONSOR'])
    ->count();
```

**P0-2. earnings 取得クエリ（L137-141）— trade_direction フィルタ追加**

```php
// 変更後
$earnings = Earning::where('earnings.delivered_date', $shippingDate)
    ->where('earnings.is_delivered', 0)
    ->where('earnings.picking_status', 'BEFORE')
    ->where('earnings.delivery_course_id', $setting->delivery_course_id)
    ->join('trades', 'earnings.trade_id', '=', 'trades.id')
    ->whereIn('trades.trade_direction', ['NORMAL', 'SPONSOR'])
    ->select('earnings.*')
    ->get();
```

**注意:** JOIN 後は `earnings.*` を select して Earning モデルとして取得。カラム名の曖昧さ回避のため `earnings.` プレフィックスを付与。

**P0-3. earnings用 trade_items 取得（L150-153）— is_active + quantity フィルタ追加**

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

**P0-4. stock_transfers用 trade_items 取得（L415-419）— is_deleted→is_active に変更 + quantity フィルタ追加**

```php
// 変更前
->where('is_deleted', false)

// 変更後
->where('is_active', true)
->where('quantity', '>', 0)
```

### 完了条件

- 4箇所すべて修正済み
- `./vendor/bin/pint app/Console/Commands/GenerateWavesCommand.php` 通過

---

## P1: ListWaves.php 修正

### 修正対象ファイル

`app/Filament/Resources/Waves/Pages/ListWaves.php`

### 修正内容

**P1-1. 配送コース選択肢 earnings カウント（L69-79 付近）— trade_direction フィルタ追加**

DB::table クエリに trades JOIN を追加:

```php
// 追加する JOIN と WHERE
->join('trades', 'earnings.trade_id', '=', 'trades.id')
->whereIn('trades.trade_direction', ['NORMAL', 'SPONSOR'])
```

**P1-2. プレビュー earnings カウント（L145-157 付近）— trade_direction フィルタ追加**

同上パターン。

**P1-3. generateManualWave() earnings 取得（L277-284 付近）— trade_direction フィルタ追加**

Earning Eloquent クエリに JOIN を追加:

```php
$earnings = Earning::query()
    ->whereIn('earnings.warehouse_id', $warehouseIds)
    ->where('earnings.delivered_date', $shippingDate)
    ->where('earnings.is_delivered', 0)
    ->where('earnings.picking_status', 'BEFORE')
    ->whereNotNull('earnings.delivery_course_id')
    ->whereIn('earnings.delivery_course_id', $deliveryCourseIds)
    ->join('trades', 'earnings.trade_id', '=', 'trades.id')
    ->whereIn('trades.trade_direction', ['NORMAL', 'SPONSOR'])
    ->select('earnings.*')
    ->get();
```

**P1-4. earnings用 trade_items 取得（L474-477 付近）— is_active + quantity フィルタ追加**

```php
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->whereIn('trade_id', $tradeIds)
    ->where('is_active', true)
    ->where('quantity', '>', 0)
    ->get();
```

### 完了条件

- 4箇所すべて修正済み
- `./vendor/bin/pint app/Filament/Resources/Waves/Pages/ListWaves.php` 通過

---

## P2: 配送コース変更画面修正

### 修正対象ファイル

- `app/Livewire/TradeDetailModal.php`
- `app/Filament/Resources/DeliveryCourseChangeResource.php`

### 修正内容

**P2-1. TradeDetailModal.php（L81-84 付近）— is_active フィルタ追加**

```php
// 変更後
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->where('trade_id', $this->tradeId)
    ->where('is_active', true)
    ->get();
```

**P2-2. DeliveryCourseChangeResource.php（L78-81 付近）— is_active フィルタ追加**

```php
// 変更後
$tradeItems = DB::connection('sakemaru')
    ->table('trade_items')
    ->where('trade_id', $tradeId)
    ->where('is_active', true)
    ->get();
```

**注意:** これらは表示用なので `quantity > 0` フィルタは不要（表示上は全アクティブアイテムを見せる）。trade_direction フィルタも不要（既に配送コース変更対象として選ばれた伝票の詳細表示）。

### 完了条件

- 2箇所修正済み
- `./vendor/bin/pint` 通過

---

## P3: 検証・Pint

### 手順

1. `./vendor/bin/pint` 実行（全体）
2. `composer test` 実行
3. 修正した4ファイルの差分を `git diff` で確認

### 完了条件

- Pint がエラーなく通過
- テスト通過（既存テスト失敗は許容）
- 全修正箇所が意図通りであること確認

---

## 制約（厳守）

1. **migrate:fresh/refresh/reset 絶対禁止**
2. **FK禁止**
3. **whereHas 使用禁止** — `trade_id` JOIN で高速化
4. **trade_direction フィルタ**: `IN ('NORMAL', 'SPONSOR')` — RETURN/INVENTORY/ITEM_SET を除外
5. **is_active で統一** — `is_deleted` は使わない
6. **quantity > 0** — 発注数量0以下を除外（trade_items クエリのみ）

## 全体完了条件

1. GenerateWavesCommand.php: 4箇所修正
2. ListWaves.php: 4箇所修正
3. TradeDetailModal.php: 1箇所修正
4. DeliveryCourseChangeResource.php: 1箇所修正
5. Pint 通過
6. テスト通過
