# ピッキング経路計算ロジック修正

- **作成日**: 2026-03-17
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/20260317/20260317-105426-fix-picking-route-calculation/`

## 背景・目的

ピッキング経路可視化画面（`/admin/picking-route-visualization`）で以下の問題が発生:

1. ロケーションが正しく解決されていない（例: 91倉庫2F、2026-03-17、配送コース910331）
2. 食い違う動線が発生している（B08103、L20102 の位置が正しくない）
3. Location の仕様変更（`wms_locations` テーブル廃止 → `locations.wms_picking_area_id` に統一）が原因の可能性

## 現状の実装

### 経路計算フロー

```
1. Wave生成時: StockAllocationService → location_id を wms_picking_item_results に保存
2. 経路最適化: PickRouteService.updateWalkingOrder()
   → Location をDB取得 → A*経路探索 → RouteOptimizer（最近挿入+2-opt）
   → walking_order, distance_from_previous を保存
3. 可視化API: PickingRouteController.getPickingRoute()
   → picking items + location座標 → calculateRoutePaths() → A*経路セグメント
4. フロントエンド: calculateRouteLines()
   → routePaths があればそのまま使用、なければ zone 中心点から構築
```

### ゾーン（Zone）とロケーション（Location）のID体系

| 概念 | ID | 内容 | 用途 |
|------|-----|------|------|
| Zone | 最初のlocation.id | code1+code2 でグループ化 | マップ表示・可視化 |
| Location | 個別 location.id | code1+code2+code3 の個別棚 | 経路計算・在庫引当 |

**根本的な問題**: 経路計算は個別 `location_id` を使うが、可視化はゾーン（code1+code2グループ）を使う。ゾーンの `id` は「グループ内最初のlocation」の ID であり、picking item の `location_id`（個別棚）と一致しない場合がある。

### 関連する最近の変更

- `wms_locations` テーブル廃止 → `locations.wms_picking_area_id` に統一（コミット af6ea4e）
- PickingRouteVisualization の zones() 座標取得ロジックを修正（本日: max() → first non-zero に変更）

## 調査対象

### 1. フロントエンドの Zone-Location マッピング問題

`calculateRouteLines()` で picking item の `location_id` から zone を特定する際:

```javascript
// 現在のロジック（推定）
Find zone with zone.id === location_id
Use (zone.x1 + zone.x2) / 2, (zone.y1 + zone.y2) / 2 as center
```

**問題**: `zone.id` はグループ内の最初の location ID。picking item の `location_id` が code3 違いの別の location なら、一致しない → ゾーンが見つからない → 動線が飛ぶ。

**修正案**: `zone.id` ではなく `zone.location_ids` 配列で検索する。

### 2. API側の Location 座標フィルタ

`PickingRouteController.php` でロケーションを取得する際のフィルタ:

```php
// 座標がない location は除外される
WHERE floor_id = ? AND has coordinates
```

座標がゼロまたは未設定の location が除外され、経路から抜け落ちる可能性。

### 3. PickRouteService の Location 取得

```php
$locations = Location::whereIn('id', $locationIds)
    ->where('floor_id', $floorId)
    ->get()
    ->keyBy('id');
```

`floor_id` が正しく設定されていない location があると取得できない。

### 4. FrontPointCalculator の座標計算

Location の `x1_pos`/`y1_pos`/`x2_pos`/`y2_pos` から接近点を計算するが、同一ゾーン内の複数 location が同じ座標を持つ場合（code3 違い）、全て同一の front point になる。

## 変更内容

### 概要

1. **原因調査**: 91倉庫2F / 910331 の実データで、location_id → zone マッピングの不整合を特定
2. **Zone-Location マッチング修正**: `zone.id` による一致ではなく `zone.location_ids` で検索
3. **経路計算の堅牢化**: 見つからない location のフォールバック処理

### 詳細設計

#### フロントエンド修正（picking-route-visualization.blade.php）

`calculateRouteLines()` で zone を探す際:

```javascript
// 修正前: zone.id === location_id（一致しないケースあり）
// 修正後: zone.location_ids.includes(location_id)
const zone = zones.find(z => z.location_ids && z.location_ids.includes(locationId));
```

#### API修正（PickingRouteController.php）

location が見つからない場合のエラーハンドリング追加。

#### PickingRouteVisualization.php

`loadInitialData()` の dispatch に `walkableAreas`/`navmeta` を追加（FloorPlanEditor と統一）。

### 影響範囲

- `/admin/picking-route-visualization` — 経路表示
- `PickingRouteController` API — 経路データ提供
- `PickRouteService` — 経路最適化（確認のみ、変更は最小限）

## 制約

- FK禁止（アプリケーション層で整合性管理）
- `migrate:fresh` / `migrate:refresh` 禁止（本番DB共有）
- 経路最適化ロジック（A*、RouteOptimizer）は変更しない（マッピング層のみ修正）

## 対象ファイル

### 既存変更
- `resources/views/filament/pages/picking-route-visualization.blade.php` — calculateRouteLines() のzone検索修正
- `app/Http/Controllers/Api/PickingRouteController.php` — location解決ロジック確認・修正
- `app/Filament/Pages/PickingRouteVisualization.php` — loadInitialData()のデータ不足修正

### 参照のみ（変更禁止）
- `app/Services/Picking/PickRouteService.php` — 経路最適化ロジック
- `app/Services/Picking/RouteOptimizer.php` — 最近挿入+2-opt
- `app/Services/Picking/FrontPointCalculator.php` — 接近点計算
- `app/Services/Picking/AStarGrid.php` — A*経路探索
- `app/Models/Sakemaru/Location.php` — Location モデル

## テストデータ

- 対象: 91倉庫 2F、2026-03-17、配送コース 910331
- 確認ロケーション: B08103、L20102
- 期待結果: 全ピッキングアイテムの位置がマップ上で正しく表示され、動線が連続的になること

## 確認事項

1. B08103、L20102 の `location_id` と、zones() で生成される zone の `id` が一致しているか？
2. 同一 code1+code2 で複数 code3 がある場合、picking item の location_id はどの code3 を指しているか？
3. `wms_locations` テーブル廃止後、PickRouteService 内で旧テーブルを参照しているコードが残っていないか？
