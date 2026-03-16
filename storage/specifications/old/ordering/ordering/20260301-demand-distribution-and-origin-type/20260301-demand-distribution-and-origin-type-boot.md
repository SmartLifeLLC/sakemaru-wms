# Work Plan: demand-distribution-and-origin-type

- **ID**: demand-distribution-and-origin-type
- **作成日**: 2026-03-01
- **最終更新**: 2026-03-01
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/ordering/20260301-demand-distribution-and-origin-type/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260301-demand-distribution-and-origin-type-boot.md）
2. 20260301-demand-distribution-and-origin-type-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`OriginType` Enum（AUTO/USER/DIST）を導入し発注・移動候補の生成元を明示する。同時に `DatabaseWithCustomQueue` パターンで `wms:process-queue` を `queue:work` に統合し、`DemandDistributionJobHandler` を実装する。

## 重要な設計制約

- **FK禁止**: 全テーブルで外部キー不使用
- **migrate:fresh/refresh 禁止**: ALTER TABLE のみ許可
- **DB変更は origin_type カラム追加のみ**: custom-queue はコード変更のみ
- **is_manually_modified は残す**: origin_type とは独立した概念
- **wms_queue_jobs テーブル変更なし**: 既存テーブルをそのまま活用
- **移行期間**: ProcessWmsQueueJobsCommand は即削除せずフォールバックとして残す

## 対象ファイル

### 新規作成
- `app/Enums/AutoOrder/OriginType.php`
- `database/migrations/XXXX_add_origin_type_to_order_and_transfer_candidates.php`
- `app/Queue/DatabaseWithCustomQueue.php`
- `app/Queue/DatabaseWithCustomQueueConnector.php`
- `app/Jobs/ProcessWmsQueueJob.php`
- `app/Services/AutoOrder/DemandDistributionJobHandler.php`

### 既存変更
- `app/Models/WmsOrderCandidate.php` — fillable/casts追加
- `app/Models/WmsStockTransferCandidate.php` — fillable/casts追加
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — insert配列にorigin_type追加（2箇所）
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` — create時origin_type追加
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` — 列変更、フィルター、アクション修正
- `app/Filament/Resources/WmsOrderCandidates/Schemas/WmsOrderCandidateForm.php` — 詳細表示変更
- `app/Services/AutoOrder/OrderCreateJobHandler.php` — origin_type追加、handleItems()公開
- `app/Services/AutoOrder/TransferCreateJobHandler.php` — origin_type追加、handleItems()公開
- `app/Console/Commands/ProcessWmsQueueJobsCommand.php` — 非推奨化
- `config/queue.php` — driver変更
- `app/Providers/AppServiceProvider.php` — コネクタ登録
- `app/Jobs/ProcessEarningDeliveryQueue.php` — onConnection削除
- `app/Jobs/ArchiveDepletedLots.php` — onConnection削除

### 参照のみ（変更禁止）
- `app/Enums/AutoOrder/QueueJobType.php`
- `app/Enums/AutoOrder/QueueJobStatus.php`
- `app/Models/WmsQueueJob.php`
- `database/migrations/2025_12_13_165201_create_wms_order_candidates_table.php`
- `database/migrations/2025_12_13_165200_create_wms_stock_transfer_candidates_table.php`
- `database/migrations/2026_01_30_000001_create_wms_queue_jobs_table.php`

## テストデータ

- `php artisan wms:generate-test-data` でテストデータ生成可
- wms_queue_jobs への手動INSERT で Queue 処理テスト可:
  ```sql
  INSERT INTO wms_queue_jobs (job_type, payload, status, source_system, created_at, updated_at)
  VALUES ('transfer_create', '{"items":[...]}', 'pending', 'trade', NOW(), NOW());
  ```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: 基盤（Enum + Migration + Model） | 完了 | 2026-03-01 | OriginType Enum作成、migration実行、Model更新 |
| P1: origin_type を全生成パスにセット | 完了 | 2026-03-01 | 7箇所の生成パスにorigin_type追加 |
| P2: custom-queue 基盤導入 | 完了 | 2026-03-01 | DatabaseWithCustomQueue + Connector + config + Provider |
| P3: Handler リファクタリング + ProcessWmsQueueJob | 完了 | 2026-03-01 | handleItems()公開 + ProcessWmsQueueJob作成 |
| P4: DemandDistributionJobHandler 実装 | 完了 | 2026-03-01 | 需要分配ハンドラ作成 |
| P5: UI変更 + 既存ジョブ修正 + コマンド非推奨化 | 完了 | 2026-03-01 | 生成元列・フィルタ追加、onConnection削除、非推奨警告 |
| P6: 統合検証 | 完了 | 2026-03-01 | pint OK、テスト165 passed（既存10 failed） |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーション（P0完了）
- マイグレーションファイル名: `2026_03_01_073331_add_origin_type_to_order_and_transfer_candidates.php`
- migrate 実行結果: 成功（65.54ms）

### custom-queue動作確認（P2完了）
- DatabaseWithCustomQueue + Connector作成済み
- config/queue.php: driver=custom-queue, retry_after=36000
- AppServiceProvider: コネクタ登録済み

### DemandDistribution テスト（P4完了）
- DemandDistributionJobHandler実装済み
- ProcessWmsQueueJob経由でDEMAND_DISTRIBUTION振り分け完了

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P0: 基盤（Enum + Migration + Model）
- 完了日: 2026-03-01
- 実績:
  - `app/Enums/AutoOrder/OriginType.php` 作成（AUTO/USER/DIST + label/color）
  - `2026_03_01_073331_add_origin_type_to_order_and_transfer_candidates.php` 作成・実行
  - WmsOrderCandidate / WmsStockTransferCandidate に fillable + casts 追加
  - 既存データ補正: is_manually_modified=true → origin_type='USER'

### P1: origin_type を全生成パスにセット
- 完了日: 2026-03-01
- 実績:
  - OrderCandidateCalculationService: INTERNAL insert に AUTO追加
  - OrderCandidateCalculationService: EXTERNAL insert に AUTO追加
  - ListWmsOrderCandidates: 手動追加に USER追加
  - OrderCreateJobHandler: Queue経由に DIST追加
  - TransferCreateJobHandler: Queue経由に DIST追加

### P2: custom-queue 基盤導入
- 完了日: 2026-03-01
- 実績:
  - `app/Queue/DatabaseWithCustomQueue.php` 作成
  - `app/Queue/DatabaseWithCustomQueueConnector.php` 作成
  - `config/queue.php` driver=custom-queue, retry_after=36000
  - `AppServiceProvider` にコネクタ登録

### P3: Handler リファクタリング + ProcessWmsQueueJob
- 完了日: 2026-03-01
- 実績:
  - OrderCreateJobHandler: `handleItems()` 公開メソッド追加
  - TransferCreateJobHandler: `handleItems()` 公開メソッド追加
  - `app/Jobs/ProcessWmsQueueJob.php` 作成（job_type振り分け）

### P4: DemandDistributionJobHandler 実装
- 完了日: 2026-03-01
- 実績:
  - `app/Services/AutoOrder/DemandDistributionJobHandler.php` 作成
  - order/transfer振り分け + 既存Handler委譲方式

### P5: UI変更 + 既存ジョブ修正 + コマンド非推奨化
- 完了日: 2026-03-01
- 実績:
  - WmsOrderCandidatesTable: is_manually_modified列→origin_type列（バッジ表示）
  - WmsOrderCandidatesTable: origin_typeフィルタ追加
  - WmsOrderCandidateForm: origin_type TextEntry追加
  - ProcessEarningDeliveryQueue: onConnection('sakemaru')削除
  - ArchiveDepletedLots: onConnection('sakemaru')削除
  - ProcessWmsQueueJobsCommand: 非推奨警告追加

### P6: 統合検証
- 完了日: 2026-03-01
- 実績:
  - pint: PASS（19ファイル）
  - composer test: 165 passed（既存10 failed は変更前と同一）
  - OriginType Enum動作確認: AUTO→自動、USER→手動、DIST→分配
  - wms:process-queue 非推奨警告確認
  - アプリ起動確認: route:list 正常
