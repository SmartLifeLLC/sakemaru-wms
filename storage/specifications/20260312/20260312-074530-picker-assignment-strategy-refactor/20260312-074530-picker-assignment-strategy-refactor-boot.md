# Work Plan: picker-assignment-strategy-refactor

- **ID**: picker-assignment-strategy-refactor
- **作成日**: 2026-03-12
- **最終更新**: 2026-03-12
- **ステータス**: 完了
- **ディレクトリ**: `/Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260312/20260312-074530-picker-assignment-strategy-refactor/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイル（boot.md）を読む
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

ピッカー割り当てサービスのリファクタリング。倉庫991ハードコード削除、戦略パラメータ実装（EQUAL=商品数均等・SKILL_BASED=スキル比率）、配送コース単位の割り当て、割り当て解除機能追加、InitSystemSeeder登録。

## 重要な設計制約

- **FK制約禁止**: アプリレベルでのデータ整合性管理
- **`migrate:fresh` / `migrate:refresh` 禁止**: 本番DB共有のため
- **配送コース分割禁止**: 同一 `delivery_course_id` のタスクは必ず同一ピッカーに割り当て
- **`canPickerHandleTask()` の既存ロジック維持**: 制限エリア＋ピッキングエリアチェックは変更しない
- **トランザクション安全性**: `sakemaru` コネクションでのトランザクションを維持

## 対象ファイル

### 既存変更
- `app/Enums/PickingStrategyType.php` — ZONE_PRIORITY削除、ラベル更新
- `app/Services/Picking/AssignPickersToTasksService.php` — 991ハードコード削除、戦略パターン実装
- `database/seeders/WmsPickingAssignmentStrategySeeder.php` — 全倉庫対応に変更
- `database/seeders/InitSystemSeeder.php` — シーダー呼び出し追加
- `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php` — プレビュー商品数追加、割り当て解除アクション追加

### 参照のみ（変更禁止）
- `app/Models/WmsPickingAssignmentStrategy.php`
- `app/Models/WmsPickingTask.php`
- `app/Models/WmsPicker.php`
- `app/Models/WmsPickingArea.php`
- `app/Enums/PickerSkillLevel.php`

## テストデータ

```bash
php artisan wms:generate-test-data
php artisan wms:generate-waves
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Enum整理・戦略パラメータスキーマ定義 | 完了 | 2026-03-12 | ZONE_PRIORITY削除、ラベル更新、defaultSkillRates追加 |
| P2: AssignPickersToTasksService リファクタリング | 完了 | 2026-03-12 | 991削除、配送コースグルーピング+商品数均等+SKILL_BASED実装 |
| P3: 割り当て解除機能 | 完了 | 2026-03-12 | unassign()メソッド追加（P2に含む） |
| P4: モーダルUI更新（プレビュー商品数・解除ボタン） | 完了 | 2026-03-12 | 商品数表示追加、解除アクション追加 |
| P5: Seeder更新・InitSystemSeeder登録 | 完了 | 2026-03-12 | 全倉庫対応、InitSystemSeeder登録 |
| P6: 動作確認 | 完了 | 2026-03-12 | route:list確認OK、syntax check全ファイルOK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### ユーザー回答（確認事項）
- `delivery_course_id` NULL → `warehouse_id` で1グループにまとめる
- プレビューに商品数を追加表示する
- 割り当て済み解除機能を追加（再割り当ては不要。解除→再計算の運用）
- `ZONE_PRIORITY` を削除。`SKILL_BASED` を実装（スキルレベルで商品割り当て比率を調整）

### SKILL_BASED 比率設計
- TRAINEE(1): 0.5倍
- JUNIOR(2): 0.8倍
- SENIOR(3): 1.0倍（基準）
- EXPERT(4): 1.2倍
- MASTER(5): 1.5倍
- ※ parameters で倍率を上書き可能

### Git ブランチ
- 作業ブランチ: (作業開始時に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: Enum整理・戦略パラメータスキーマ定義
- 完了日: 2026-03-12
- 実績:
  - `ZONE_PRIORITY` case を削除
  - `EQUAL` → '商品数均等割り当て'、`SKILL_BASED` → 'スキルレベル考慮割り当て' にラベル更新
  - `defaultSkillRates()` 静的メソッド追加（TRAINEE:0.5, JUNIOR:0.8, SENIOR:1.0, EXPERT:1.2, MASTER:1.5）

### P2: AssignPickersToTasksService リファクタリング
- 完了日: 2026-03-12
- 実績:
  - 倉庫991ハードコード完全削除（`assignWithFloorPriority()` メソッド削除）
  - `strategy_key` による分岐実装（EQUAL / SKILL_BASED / default エラー）
  - 配送コースグルーピング（`groupByDeliveryCourse()`）: delivery_course_id NULL → warehouse_id で1グループ
  - EQUAL: 商品数均等（`withCount('pickingItemResults as item_count')`）+ First Fit Decreasing
  - SKILL_BASED: 重み付き累計商品数（actual / skill_rate）で最少ピッカー選択
  - `canPickerHandleTask()` は変更なし

### P3: 割り当て解除機能
- 完了日: 2026-03-12
- 実績:
  - `unassign(int $warehouseId)` メソッド追加
  - PICKING_READY のみ解除（PICKING は不可）
  - picker_id → NULL、status → PENDING に更新

### P4: モーダルUI更新
- 完了日: 2026-03-12
- 実績:
  - プレビューに商品数（`number_format($totalItemCount)`）追加表示
  - 「約XX商品/人」表示に変更
  - 割り当て解除アクション追加（warning色、倉庫選択 + 解除対象件数プレビュー）

### P5: Seeder更新・InitSystemSeeder登録
- 完了日: 2026-03-12
- 実績:
  - 倉庫991固定→全アクティブ倉庫対応
  - 各倉庫に EQUAL（デフォルト）+ SKILL_BASED の2戦略を生成
  - 既存 ZONE_PRIORITY レコードを EQUAL に変更＋無効化
  - InitSystemSeeder に `WmsPickingAssignmentStrategySeeder` 登録

### P6: 動作確認
- 完了日: 2026-03-12
- 実績:
  - 全PHPファイルの syntax check OK
  - route:list で wms-picking-waitings ルート確認 OK
  - ZONE_PRIORITY の Enum 参照が完全に除去されていることを確認
  - 倉庫991のハードコードが完全に除去されていることを確認
