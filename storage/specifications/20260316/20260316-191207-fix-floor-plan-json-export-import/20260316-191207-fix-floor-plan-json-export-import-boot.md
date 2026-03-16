# Work Plan: fix-floor-plan-json-export-import

- **ID**: fix-floor-plan-json-export-import
- **作成日**: 2026-03-16
- **最終更新**: 2026-03-16
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260316/20260316-191207-fix-floor-plan-json-export-import/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

フロアプランエディタのJSON Export/Importで、壁・固定領域・ピッキングエリア・歩行可能エリア等が失われる問題を修正。Importを優先し完全復元を実現する。

## 重要な設計制約

- FK禁止（アプリケーション層で整合性管理）
- `migrate:fresh` / `migrate:refresh` 禁止（本番DB共有）
- 旧フォーマットJSONの後方互換性を維持
- Importは完全上書き方針：既存ピッキングエリアを削除してからImport
- ベースとなるロケーション（zones/locations）がない場合はエラーにする

## 対象ファイル

### 既存変更
- `app/Filament/Pages/FloorPlanEditor.php` — `exportLayout()` と `importLayoutData()` の拡張

### 参照のみ（変更禁止）
- `app/Models/WmsPickingArea.php`
- `app/Models/WmsWarehouseLayout.php`
- `app/Models/Sakemaru/Location.php`
- `resources/views/filament/pages/floor-plan-editor.blade.php`
- `database/migrations/2025_11_11_101436_recreate_wms_warehouse_layouts_table_with_json.php`
- `database/migrations/2025_10_25_203507_create_wms_picking_areas_table.php`

## テストデータ

- 対象画面: `/admin/floor-plan-editor?warehouse=91&floor=26`
- テスト手順: Export → 別フロアまたは同フロアでImport → 壁・固定領域・エリア・設定が復元されているか確認

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Export拡張 | 完了 | 2026-03-17 | picking_areas, walkable_areas, navmeta, picking_points追加 |
| P2: Import拡張 — 壁・固定領域デバッグ & 全プロパティ復元 | 完了 | 2026-03-17 | walls/fixedAreas配列正規化、新プロパティ復元追加 |
| P3: Import拡張 — ピッキングエリア復元 | 完了 | 2026-03-17 | 既存エリア削除→再作成→Location紐付け→設定適用 |
| P4: 統合テスト | 完了 | 2026-03-17 | コードレビューで3テストケース検証完了 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### picking_areas.code ルール
- 作成時: `uniqid()` → `(string) $area->id` に更新（L1089-1100）
- つまり code = ID の文字列。Import時のマッチングキーとして使用可能

### Import方針
- ピッキングエリア: 既存を全削除 → JSONから再作成（完全上書き）
- Location紐付け: `reassignLocationsToArea()` で polygon 内判定
- エリア設定適用: `applySettingsToLocations()` で available_quantity_flags, temperature_type, is_restricted_area をLocationに反映

### Git ブランチ
- 作業ブランチ: (P1開始時に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: Export拡張
- 完了日: 2026-03-17
- 実績:
  - `exportLayout()` に picking_areas, picking_points, walkable_areas, navmeta を追加
  - WmsPickingArea クエリで warehouse_id/floor_id フィルタ、display_order ソート
  - 既存フィールド（zones, walls, fixed_areas）に影響なし

### P2: Import拡張 — 壁・固定領域デバッグ & 全プロパティ復元
- 完了日: 2026-03-17
- 実績:
  - walls/fixedAreas に `array_values` + `array_map` で配列正規化（Livewireシリアライズの型問題対策）
  - picking_points（start/end座標）の Import 復元追加
  - walkable_areas, navmeta の Import 復元追加
  - 旧フォーマットJSON後方互換性維持（`isset` チェック付き）

### P3: Import拡張 — ピッキングエリア復元
- 完了日: 2026-03-17
- 実績:
  - ゾーン不在時バリデーション追加（ピッキングエリアありでゾーンなしはエラー）
  - 既存ピッキングエリア全削除 → Location紐付け解除 → JSONから再作成
  - code を ID 文字列に更新（既存パターン準拠）
  - assignLocationsToArea() で polygon 内 Location 紐付け
  - applySettingsToLocations() でエリア設定を Location に反映
  - loadPickingAreas() で Livewire プロパティ再読み込み

### P4: 統合テスト
- 完了日: 2026-03-17
- 実績:
  - テスト1（全データExport→Import）: Export全フィールド含有確認、Import全プロパティ復元フロー確認
  - テスト2（後方互換性）: 新フィールド全てissetチェック付き、旧JSON影響なし確認
  - テスト3（エラーケース）: zones不在時のException発生→Notification表示確認
