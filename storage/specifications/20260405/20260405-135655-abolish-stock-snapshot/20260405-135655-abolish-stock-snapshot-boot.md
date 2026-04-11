# Work Plan: abolish-stock-snapshot

- **ID**: abolish-stock-snapshot
- **作成日**: 2026-04-05
- **最終更新**: 2026-04-05
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/20260405/20260405-135655-abolish-stock-snapshot/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260405-135655-abolish-stock-snapshot-boot.md）
2. 20260405-135655-abolish-stock-snapshot-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

`StockSnapshotService`を廃止し、`OrderCandidateCalculationService`が`wms_v_stock_available` + `wms_order_incoming_schedules`から直接メモリに読み込む。2段階ジョブ→1段階に簡素化。`wms_item_stock_snapshots`テーブル・enumは閲覧用に残す。

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベル管理
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 共有DB
- **`wms_item_stock_snapshots`テーブルは削除しない**: 既存データの参照用に残す
- **`STOCK_SNAPSHOT` enumは削除しない**: 既存ジョブレコードの表示に必要
- **`snapshot_job_id`カラムは削除しない**: 既存データの整合性保持
- **重複排除必須**: `wms_v_stock_available`はロット毎に行が複製 → `real_stock_id`で重複排除してから集計

## 対象ファイル

### 既存変更
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — 直接クエリ化、$snapshotJobId削除
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` — スナップショットステップ削除
- `app/Console/Commands/AutoOrder/AutoOrderCalculateCommand.php` — スナップショット呼び出し削除
- `app/Services/AutoOrder/OrderCreateJobHandler.php` — スナップショット呼び出し削除
- `app/Services/AutoOrder/TransferCreateJobHandler.php` — スナップショット呼び出し削除
- `app/Models/WmsAutoOrderJobControl.php` — findPendingSettlement変更、startJobのsnapshotJobId削除
- `app/Filament/Resources/WmsItemStockSnapshots/Pages/ListWmsItemStockSnapshots.php` — 再生成ボタン削除

### 削除
- `app/Services/AutoOrder/StockSnapshotService.php`
- `app/Console/Commands/AutoOrder/SnapshotStocksCommand.php`

### 参照のみ（変更禁止）
- `app/Models/WmsItemStockSnapshot.php`
- `app/Filament/Resources/WmsItemStockSnapshots/` — 閲覧用に残す
- `app/Enums/AutoOrder/JobProcessName.php` — STOCK_SNAPSHOT残す
- `app/Enums/EMenu.php`

## テストデータ

- 既存の自動発注テストで動作確認
- `php artisan test --filter=Order` / `php artisan test --filter=Transfer`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: OrderCandidateCalculationService 直接クエリ化 | 完了 | 2026-04-05 | snapshotJobId削除、直接クエリ化 |
| P2: 呼び出し元のスナップショット削除 | 完了 | 2026-04-05 | 4ファイルからStockSnapshotService削除 |
| P3: WmsAutoOrderJobControl・管理画面の整理 | 完了 | 2026-04-05 | findPendingSettlement変更、再生成ボタン削除 |
| P4: StockSnapshotService・コマンド削除 | 完了 | 2026-04-05 | 2ファイル削除 |
| P5: 動作確認・回帰テスト | 完了 | 2026-04-05 | grep 0件、テスト全パス |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### スナップショット参照の詳細（調査済み）
- `OrderCandidateCalculationService:274-316` — loadAllDataToMemory内でスナップショットをSELECT
- `OrderCandidateCalculationService:595-597` — 移動候補計算で$this->stockSnapshots参照
- `OrderCandidateCalculationService:790-792` — 発注候補計算で$this->stockSnapshots参照
- `StockSnapshotService`生成SQL: `wms_v_stock_available`(DISTINCT real_stock_id) + `wms_order_incoming_schedules`(PENDING/PARTIAL)

### calculate() 呼び出し箇所（全箇所の$snapshotJobId変更が必要）
- `ProcessOrderCandidateGenerationJob:130` — `$calculationService->calculate($snapshotJob->id, ...)`
- `AutoOrderCalculateCommand:65付近` — `$calculationService->calculate($job->id)`
- `OrderCreateJobHandler:159付近` — 要確認
- `TransferCreateJobHandler:159付近` — 要確認

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチ）
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: OrderCandidateCalculationService 直接クエリ化
- 完了日: 2026-04-05
- 成果物: OrderCandidateCalculationService.php
- 実績:
  - $snapshotJobId プロパティ・パラメータ削除
  - calculate() シグネチャから $snapshotJobId 削除
  - startJob() から snapshotJobId 引数削除
  - loadAllDataToMemory(): wms_v_stock_available(DISTINCT real_stock_id重複排除) + wms_order_incoming_schedules から直接読み込み
  - $this->stockSnapshots のデータ構造 {effective, incoming} は変更なし

### P2: 呼び出し元のスナップショット削除
- 完了日: 2026-04-05
- 成果物: ProcessOrderCandidateGenerationJob.php, AutoOrderCalculateCommand.php, OrderCreateJobHandler.php, TransferCreateJobHandler.php
- 実績:
  - ProcessOrderCandidateGenerationJob: スナップショット生成ステップ削除、進捗ステップ5→4に簡素化、calculate()引数更新
  - AutoOrderCalculateCommand: --skip-snapshotオプション削除、StockSnapshotService DI削除、スナップショットフェーズ削除
  - OrderCreateJobHandler: コンストラクタからStockSnapshotService削除、getOrCreateBatchCode()をstartJob()直接使用に変更
  - TransferCreateJobHandler: 同上

### P3: WmsAutoOrderJobControl・管理画面の整理
- 完了日: 2026-04-05
- 成果物: WmsAutoOrderJobControl.php, ListWmsItemStockSnapshots.php
- 実績:
  - startJob(): $snapshotJobId パラメータ削除、snapshot_job_id のcreate行も削除
  - findPendingSettlement() / hasPendingSettlement(): STOCK_SNAPSHOT除外→ORDER_CALCのみ
  - ListWmsItemStockSnapshots: 再生成ボタン削除、StockSnapshotService import削除
  - snapshotJob()リレーション・$fillableのsnapshot_job_idは残す（既存データ参照用）

### P4: StockSnapshotService・コマンド削除
- 完了日: 2026-04-05
- 実績:
  - StockSnapshotService.php 削除
  - SnapshotStocksCommand.php 削除
  - `php artisan list | grep snapshot` → 0件確認

### P5: 動作確認・回帰テスト
- 完了日: 2026-04-05
- 実績:
  - grep StockSnapshotService app/ → 0件
  - grep snapshotJobId app/ → 0件
  - grep SnapshotStocksCommand app/ → 0件
  - php artisan list | grep snapshot → 0件
  - Transfer テスト: 27件全パス
  - Order テスト: 96パス、3失敗（RouteNotFoundException — 今回の変更と無関係）
  - Calculation テスト: 5件全パス
  - 全7ファイル構文チェック OK
