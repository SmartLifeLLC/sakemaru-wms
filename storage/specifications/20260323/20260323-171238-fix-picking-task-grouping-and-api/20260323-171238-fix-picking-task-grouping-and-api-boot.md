# Work Plan: fix-picking-task-grouping-and-api

- **ID**: fix-picking-task-grouping-and-api
- **作成日**: 2026-03-23
- **最終更新**: 2026-03-23
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260323/20260323-171238-fix-picking-task-grouping-and-api/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260323-171238-fix-picking-task-grouping-and-api-boot.md）
2. 20260323-171238-fix-picking-task-grouping-and-api-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

波動生成時のピッキングタスクグルーピングを `floor_id` → `floor_id × picking_area_id` に変更し、API `GET /api/picking/tasks` から不要な `picking_area_id` パラメータを削除する。

## 重要な設計制約

- `php artisan migrate:fresh` / `migrate:refresh` は絶対禁止（共有DB）
- 外部キーは使用しない
- 既存データは考慮不要（再生成で対応）
- Androidアプリから `picking_area_id` が送信されても無視する（バリデーションエラーにしない）

## 対象ファイル

### 既存変更
- `app/Console/Commands/GenerateWavesCommand.php` — グルーピングキー変更（Earning + Stock Transfer）
- `app/Http/Controllers/Api/PickingTaskController.php` — `picking_area_id` パラメータ削除 + Swagger更新

### 参照のみ（変更禁止）
- `app/Models/WmsPickingArea.php` — エリアモデル構造の確認
- `app/Models/WmsPickingTask.php` — タスクモデル構造の確認
- 仕様書: `20260323-171238-fix-picking-task-grouping-and-api.md`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 波動生成 Earning グルーピング修正 | 完了 | 2026-03-23 | groupKey を floor_id × picking_area_id に変更 |
| P2: 波動生成 Stock Transfer グルーピング修正 | 完了 | 2026-03-23 | groupKey + 既存タスク検索条件に picking_area_id 追加 |
| P3: API picking_area_id パラメータ削除 | 完了 | 2026-03-23 | バリデーション・フィルタ・Swagger から削除 |
| P4: 動作確認 | 完了 | 2026-03-23 | Pint PASS |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 既に完了済みの変更（本セッションで実施済み）
- `PickingTaskController.php`: index / show に `started_at`, `completed_at` を追加済み（レスポンス + Swagger）

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチ）
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: 波動生成 Earning グルーピング修正
- 完了日: 2026-03-23
- 実績:
  - `GenerateWavesCommand.php` 258行目: groupKey を `($floorId ?? 'null').'_'.($pickingAreaId ?? 'null')` に変更
  - コメントを「Group by floor_id × picking_area_id」に更新

### P2: 波動生成 Stock Transfer グルーピング修正
- 完了日: 2026-03-23
- 実績:
  - `GenerateWavesCommand.php` 489行目: groupKey を `'ST_'.($floorId ?? 'null').'_'.($pickingAreaId ?? 'null')` に変更
  - 既存タスク検索条件に `->where('wms_picking_area_id', $pickingAreaId)` を追加

### P3: API picking_area_id パラメータ削除
- 完了日: 2026-03-23
- 実績:
  - バリデーションから `picking_area_id` 削除
  - `$pickingAreaId` 変数とクエリフィルタ削除
  - Swagger `@OA\Parameter` ブロック削除
  - コメントの `picking_area_id` 記述削除

### P4: 動作確認
- 完了日: 2026-03-23
- 実績:
  - `./vendor/bin/pint --dirty` PASS（2 files）
