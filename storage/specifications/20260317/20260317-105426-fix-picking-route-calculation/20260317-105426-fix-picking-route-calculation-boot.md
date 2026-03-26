# Work Plan: fix-picking-route-calculation

- **ID**: fix-picking-route-calculation
- **作成日**: 2026-03-17
- **最終更新**: 2026-03-17
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260317/20260317-105426-fix-picking-route-calculation/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

ピッキング経路可視化画面でロケーションが正しくマッチせず、動線が食い違う問題を修正。根本原因はフロントエンドで `zone.id === item.location_id` で検索しているが、zone.id はグループ内最初の location ID であり、picking item の location_id（個別棚）と一致しないケースがある。

## 重要な設計制約

- FK禁止（アプリケーション層で整合性管理）
- `migrate:fresh` / `migrate:refresh` 禁止（本番DB共有）
- 経路最適化ロジック（A*、RouteOptimizer）は変更しない
- `PickRouteService.php`, `RouteOptimizer.php`, `FrontPointCalculator.php`, `AStarGrid.php` は参照のみ

## 対象ファイル

### 既存変更
- `resources/views/filament/pages/picking-route-visualization.blade.php` — zone検索ロジック全箇所修正
- `app/Filament/Pages/PickingRouteVisualization.php` — zones()のlocation_ids確認、loadInitialData修正

### 参照のみ（変更禁止）
- `app/Http/Controllers/Api/PickingRouteController.php` — API側は個別location_idで直接Location取得しており正常
- `app/Services/Picking/PickRouteService.php`
- `app/Services/Picking/RouteOptimizer.php`
- `app/Services/Picking/FrontPointCalculator.php`
- `app/Services/Picking/AStarGrid.php`
- `app/Models/Sakemaru/Location.php`

## テストデータ

- 対象: 91倉庫 2F、2026-03-17、配送コース 910331
- 確認ロケーション: B08103、L20102
- 期待結果: 全ピッキングアイテムのゾーンがハイライトされ、動線が連続的になること

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: フロントエンド zone-location マッチング修正 | 完了 | 2026-03-17 | findZoneByLocationId追加、hasPickingItems/getZoneWalkingOrders/calculateRouteLines修正 |
| P2: Livewire loadInitialData 補完 | 完了 | 2026-03-17 | zones()にlocation_ids既存確認、変更不要 |
| P3: 動作確認 | 完了 | 2026-03-17 | 構文チェックOK、手動動作確認はユーザーが実施 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 修正対象箇所（Blade）
- L779: `this.zones.find(z => z.id === item.location_id)` — calculateRouteLines()
- L897: `item.location_id === zoneId` — hasPickingItems()
- L902: `item.location_id === zoneId` — getZoneWalkingOrders()
- L908-910: getZoneColor() — hasPickingItems() 経由

### 修正方針
- zone 検索を `zone.id === locationId` から `zone.location_ids && zone.location_ids.includes(locationId)` に変更
- zone からの逆引き（zoneId → items）は `zone.location_ids` を展開して `item.location_id` と比較

### Git ブランチ
- 作業ブランチ: release/v1.0（既存）
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: フロントエンド zone-location マッチング修正
- 完了日: 2026-03-17
- 実績:
  - `findZoneByLocationId()` ヘルパー関数を追加
  - `hasPickingItems()`: zone.location_ids.includes() で検索するよう修正
  - `getZoneWalkingOrders()`: 同上
  - `calculateRouteLines()`: `findZoneByLocationId()` を使用するよう修正

### P2: Livewire loadInitialData 補完
- 完了日: 2026-03-17
- 実績:
  - zones() の location_ids は L337 で既に含まれていることを確認
  - loadInitialData() の dispatch は zones を含んでおり変更不要

### P3: 動作確認
- 完了日: 2026-03-17
- 実績:
  - PHP/Blade 構文チェック OK
  - 手動動作確認はユーザーが実施
