# Work Plan: warehouse-order-generation

- **ID**: warehouse-order-generation
- **作成日**: 2026-04-01
- **最終更新**: 2026-04-01
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260401/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（warehouse-order-generation-boot.md）
2. warehouse-order-generation-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから warehouse-order-generation-plan.md の該当セクションを読んで作業再開

## 概要

`admin/wms-auto-order-job-controls` に**倉庫別**発注・移動候補生成ボタンを追加。既存の全倉庫一括生成とは別に、選択した倉庫のみの発注・移動候補を生成する。HUB倉庫を選択してもサテライト倉庫の候補は生成しない。

## 重要な設計制約

- `migrate:fresh` / `migrate:refresh` / `db:wipe` 禁止（共有DB）
- FK禁止
- 既存の「発注・移動候補生成」「移動候補生成」「仕入先別発注候補生成」ボタンの動作は変更しない
- HUB倉庫を選択した場合でもサテライト倉庫の候補は生成しない（倉庫単独生成）
- `OrderCandidateCalculationService` の既存ロジックを壊さない

## 対象ファイル

### 新規作成
- なし（既存ファイルへの追加で対応）

### 既存変更
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` — 倉庫別生成ボタン追加
- `resources/views/filament/components/order-generation-wizard.blade.php` — 倉庫選択UI追加（または別モーダル）
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` — `warehouseId` パラメータ追加
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — 倉庫フィルタ対応
- `app/Services/AutoOrder/StockSnapshotService.php` — 倉庫フィルタ対応

### 参照のみ（変更禁止）
- `app/Models/WmsAutoOrderJobControl.php`
- `app/Models/WmsQueueProgress.php`
- `app/Models/WmsOrderCandidate.php`
- `app/Models/WmsStockTransferCandidate.php`

## テストデータ

- 既存のテストデータ生成コマンド: `php artisan wms:generate-test-data`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 調査・設計確認 | 完了 | 2026-04-01 | フィルタ挿入ポイント特定済み |
| P2: バックエンド（Job/Service倉庫フィルタ対応） | 完了 | 2026-04-01 | Job/Snapshot/Calculation全てにwarehouseId対応 |
| P3: フロントエンド（倉庫別生成ボタン・モーダル） | 完了 | 2026-04-01 | 「倉庫別候補生成」ボタン追加、倉庫Select+確認モーダル |
| P4: 動作確認 | 完了 | 2026-04-01 | 構文チェック全パス、ルート正常 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。
> 各Phase完了時や重要な中間成果物が出た時に更新する。

### 既存パラメータ構成（ProcessOrderCandidateGenerationJob）
- `$jobId` (UUID): WmsQueueProgress用
- `$deletePending` (bool): PENDING候補を先に削除するか
- `$contractorId` (?int): 特定仕入先のみ
- `$executionLogId` (?int): スケジューラ用
- `$transferOnly` (bool): 移動候補のみ
- **追加予定**: `$warehouseId` (?int): 特定倉庫のみ

### OrderCandidateCalculationService の倉庫フィルタ
- 現状: `WmsWarehouseAutoOrderSetting::enabled()` で有効倉庫を取得
- 変更: `$warehouseId` が指定された場合、その倉庫のみに絞り込む
- INTERNAL移動候補: `satellite_warehouse_id` = 指定倉庫のもののみ生成
- EXTERNAL発注候補: `warehouse_id` = 指定倉庫のもののみ生成

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

### P1: 調査・設計確認
- 完了日: 2026-04-01
- 実績:
  - フィルタ挿入ポイント特定: StockSnapshotService(warehouseIds), OrderCandidateCalculationService(realWarehouseIds), Job(PENDING削除)
  - 既存3ボタンの動作フロー把握完了

### P2: バックエンド（Job/Service倉庫フィルタ対応）
- 完了日: 2026-04-01
- 実績:
  - `StockSnapshotService::generateAll(?int $warehouseId)` — warehouseId指定時はその倉庫のみスナップショット
  - `OrderCandidateCalculationService::calculate(..., ?int $warehouseId)` — targetWarehouseIdでrealWarehouseIdsを絞り込み
  - `ProcessOrderCandidateGenerationJob` — コンストラクタに`$warehouseId`追加、PENDING削除・スナップショット・計算に倉庫フィルタ適用

### P3: フロントエンド（倉庫別生成ボタン・モーダル）
- 完了日: 2026-04-01
- 実績:
  - 「倉庫別候補生成」ボタン追加（warning色、building-storefront アイコン）
  - 有効倉庫のみSelectで選択可能
  - 該当倉庫のAPPROVEDチェック → PENDINGのみ削除 → Job dispatch(warehouseId指定)

### P4: 動作確認
- 完了日: 2026-04-01
- 実績:
  - 全4ファイル構文チェックパス
  - ルート・ビューキャッシュクリア正常
  - adminルート正常ロード
