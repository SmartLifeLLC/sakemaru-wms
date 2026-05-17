# Work Plan: bulk-soft-shortage-maintenance

- **ID**: bulk-soft-shortage-maintenance
- **作成日**: 2026-05-17
- **最終更新**: 2026-05-17
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260517/20260517-172043-bulk-soft-shortage-maintenance/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（`20260517-172043-bulk-soft-shortage-maintenance-boot.md`）
2. `20260517-172043-bulk-soft-shortage-maintenance-plan.md` を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

ピッキング調整ページ（`/admin/wms-picking-waitings`）に「統合引当欠品処理」ボタンを追加。モーダル内で全タスクの引当欠品明細（`has_soft_shortage = true`）の引当数を一括編集・保存できるようにする。現在タスクごとに個別ページ遷移して1行ずつ編集する非効率な動線を、1モーダルで完結させる改善。

## 重要な設計制約

- **FK禁止**: データ整合性はアプリケーション層で管理
- **migrate:fresh / refresh 禁止**: 基幹共有DB
- **planned_qty の上限**: `ordered_qty` を超える引当は不可（バラ換算で比較）
- **対象ステータス制限**: `PENDING` / `PICKING_READY` のタスクの明細のみ
- **操作ログ必須**: 全変更を `WmsAdminOperationLog` に記録（`ADJUST_PICKING_QTY`）
- **引当区分（ケース/バラ）の変更は不要**: 引当数のみ編集対象
- **在庫数カラムは不要**: パフォーマンス考慮で省略
- **V2ページへの追加は不要**: V1のみ
- **倉庫フィルター**: `selected_warehouse_id`（ユーザーの選択倉庫）を利用
- **ページネーションなし**: スクロールで全件表示

## ユーザーの回答（確認事項への回答）

1. V2ページ → 不要。全ての欠品商品を一括表示するモーダル（同じ波動内で出荷前のもの）
2. 在庫数カラム → 不要
3. 引当区分の変更 → 不要（伝票修正が必要になるため）
4. 倉庫フィルター → `selected_warehouse_id` を利用
5. ページネーション → スクロールで全件

## 対象ファイル

### 新規作成
- `resources/views/filament/forms/components/bulk-soft-shortage-table.blade.php` — モーダル内テーブル（Alpine.js）

### 既存変更
- `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php` — ヘッダーアクション追加
- `resources/css/filament/admin/theme.css` — 必要に応じて `@source inline()` 更新

### 参照のみ（変更禁止）
- `app/Filament/Resources/WmsPickingTasks/WmsPickingItemEditResource.php` — バリデーション・ログ記録ロジック参考
- `app/Models/WmsPickingItemResult.php` — モデル定義
- `app/Models/WmsPickingTask.php` — ステータス定数
- `~/.claude/design-knowledge/modal-design.md` — モーダルデザイン仕様

## テストデータ

```bash
# ローカル環境
# URL: https://wms.sakemaru.test/admin/wms-picking-waitings
# ログイン: .env の TEST_ADMIN_NAME / TEST_ADMIN_PASS

# 引当欠品ありのタスクを確認
# プリセットビュー「引当欠品あり」で絞り込み可能
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: Blade テンプレート作成 | 完了 | 2026-05-17 | Alpine.js テーブル作成 |
| P2: アクション実装 | 完了 | 2026-05-17 | ヘッダーアクション + 保存ロジック追加 |
| P3: CSS・ビルド・動作確認 | 完了 | 2026-05-17 | safelist追加、npm run build成功 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### バリデーションロジック（参考: WmsPickingItemEditResource）
- `quantityAsPieces()`: `quantity * max(1, item.capacityOfQuantityType(type))` でバラ換算
- `calculateAllocationShortage()`: `max(0, ordered_pieces - planned_pieces)`
- `planned_qty` 更新時: `picked_qty` が新値を超えたらキャップ
- ログ: `WmsAdminOperationLog::log(ADJUST_PICKING_QTY, {...})`

### Git ブランチ
- 作業ブランチ: (実施後に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: Blade テンプレート作成
- 完了日: 2026-05-17
- 成果物: `resources/views/filament/forms/components/bulk-soft-shortage-table.blade.php`
- 実績:
  - Alpine.js テーブルコンポーネント新規作成
  - 12カラム（タスクID〜欠品数）、引当数は number input で編集可能
  - 変更追跡（changes オブジェクト）、欠品数リアクティブ計算
  - 変更行ハイライト（amber）、欠品解消行ハイライト（green）
  - 0件時の空メッセージ表示

### P2: アクション実装
- 完了日: 2026-05-17
- 成果物: `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php`
- 実績:
  - `bulkSoftShortageMaintenance` ヘッダーアクション追加（openVersion2 と assignPickers の間）
  - `getBulkShortageItems()`: 当日・選択倉庫・PENDING/PICKING_READY の欠品明細取得
  - `saveBulkShortageChanges()`: トランザクション内一括更新、バリデーション、操作ログ記録
  - use文 6件追加（QuantityType, EWMSLogOperationType, etc.）
  - php artisan route:list エラーなし

### P3: CSS・ビルド・動作確認
- 完了日: 2026-05-17
- 実績:
  - theme.css @source inline に dark:bg-green-900/20, dark:bg-amber-900/20 等を追加
  - npm run build 成功（2.87s）
  - Laravel Pint 通過
