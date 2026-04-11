# ピッキング経路計算ロジック修正 作業計画

## 前提

- 仕様書: `20260317-105426-fix-picking-route-calculation.md`
- 変更対象: `picking-route-visualization.blade.php` と `PickingRouteVisualization.php`
- API側（`PickingRouteController.php`）は個別 location_id で直接 Location テーブルを引いており正常動作
- 問題はフロントエンドの zone-location マッチングのみ

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | フロントエンド zone-location マッチング修正 | 4箇所の zone.id 比較を location_ids 検索に変更 | 全ピッキングアイテムのゾーンが正しくハイライトされる |
| P2 | Livewire loadInitialData 補完 | zones()のlocation_ids確認、dispatch補完 | layout-loaded イベントに必要データが全て含まれる |
| P3 | 動作確認 | 91倉庫2F 910331 で経路表示確認 | 動線が連続的で食い違いがない |

---

## P1: フロントエンド zone-location マッチング修正

### 目的

フロントエンドで picking item の `location_id` から zone を検索する際、`zone.id` ではなく `zone.location_ids` 配列を使って検索するよう修正する。

### 修正対象ファイル

- `resources/views/filament/pages/picking-route-visualization.blade.php`

### 修正内容

#### 1. ヘルパー関数追加

zone を location_id から検索するヘルパーを追加:

```javascript
findZoneByLocationId(locationId) {
    return this.zones.find(z =>
        z.location_ids && z.location_ids.includes(locationId)
    );
},

isLocationInZone(zoneId, locationId) {
    const zone = this.zones.find(z => z.id === zoneId);
    return zone && zone.location_ids && zone.location_ids.includes(locationId);
},
```

#### 2. hasPickingItems() 修正（L896-898）

```javascript
// 修正前
hasPickingItems(zoneId) {
    return this.pickingItems.some(item => item.location_id === zoneId);
},

// 修正後
hasPickingItems(zoneId) {
    const zone = this.zones.find(z => z.id === zoneId);
    if (!zone || !zone.location_ids) return false;
    return this.pickingItems.some(item => zone.location_ids.includes(item.location_id));
},
```

#### 3. getZoneWalkingOrders() 修正（L900-905）

```javascript
// 修正前
getZoneWalkingOrders(zoneId) {
    return this.pickingItems
        .filter(item => item.location_id === zoneId)
        ...
},

// 修正後
getZoneWalkingOrders(zoneId) {
    const zone = this.zones.find(z => z.id === zoneId);
    if (!zone || !zone.location_ids) return [];
    return this.pickingItems
        .filter(item => zone.location_ids.includes(item.location_id))
        ...
},
```

#### 4. calculateRouteLines() 修正（L779）

```javascript
// 修正前
const zone = this.zones.find(z => z.id === item.location_id);

// 修正後
const zone = this.findZoneByLocationId(item.location_id);
```

### 完了条件

- `hasPickingItems()` が全ピッキングアイテムのゾーンを正しくハイライトする
- `getZoneWalkingOrders()` が正しい歩行順序を表示する
- `calculateRouteLines()` がフォールバック時にも正しい座標を返す
- `php -l` でPHP構文エラーなし（Blade/PHPファイル）

---

## P2: Livewire loadInitialData 補完

### 目的

PickingRouteVisualization.php の zones() computed property に `location_ids` が含まれていることを確認し、`loadInitialData()` の dispatch を FloorPlanEditor と整合させる。

### 修正対象ファイル

- `app/Filament/Pages/PickingRouteVisualization.php`

### 確認・修正内容

#### 1. zones() の location_ids 確認

zones() computed property（L326-341）で `location_ids` が既に含まれていることを確認:
```php
'location_ids' => $locationIds,  // L340 — 既に存在
```

#### 2. loadInitialData() の dispatch 補完

FloorPlanEditor と同様に `walkableAreas`/`navmeta` を含める（省略されていても可視化には影響しないが整合性のため）:

```php
// 修正前
$this->dispatch('layout-loaded',
    zones: $zones,
    walls: $this->walls,
    fixedAreas: $this->fixedAreas,
    pickingAreas: $this->pickingAreas,
    canvasWidth: $this->canvasWidth,
    canvasHeight: $this->canvasHeight
);

// 修正後（必要に応じて）
// walkableAreas/navmeta は loadLayout() で読み込む必要がある
```

loadLayout() に walkableAreas/navmeta 読み込みを追加（Blade側で歩行領域表示に使用）。

### 完了条件

- zones() が `location_ids` を含んでいる（既存確認）
- `php -l` でPHP構文エラーなし

---

## P3: 動作確認

### 目的

実際のデータで経路表示が正しいことを確認する。

### 確認手順

1. `/admin/picking-route-visualization` を開く
2. 91倉庫 2F、2026-03-17、配送コース 910331 を選択
3. 確認項目:
   - B08103 のゾーンがハイライトされているか
   - L20102 のゾーンがハイライトされているか
   - 歩行順序番号が正しく表示されているか
   - 動線（ルートライン）が連続的で食い違いがないか
   - routePaths（A*経路）が正常に表示されるか

### 完了条件

- 全ピッキングアイテムのゾーンがハイライトされている
- 動線が連続的で画面外への飛びがない
- 歩行順序番号がゾーン内に正しく表示されている

---

## 制約（厳守）

1. **FK禁止** — 外部キー制約は使用しない
2. **DB破壊コマンド禁止** — `migrate:fresh`, `migrate:refresh`, `db:wipe` は絶対に実行しない
3. **経路最適化ロジック変更禁止** — `PickRouteService`, `RouteOptimizer`, `AStarGrid`, `FrontPointCalculator` は変更しない
4. **API変更なし** — `PickingRouteController.php` は正常動作しているため変更しない

## 全体完了条件

- フロントエンドの zone-location マッチングが `location_ids` ベースになっている
- 91倉庫2F 910331 の経路が正しく表示される
- 食い違う動線が解消されている
