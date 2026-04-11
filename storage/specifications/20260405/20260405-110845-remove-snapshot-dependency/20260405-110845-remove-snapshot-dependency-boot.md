# Work Plan: remove-snapshot-dependency

- **ID**: remove-snapshot-dependency
- **作成日**: 2026-04-05
- **最終更新**: 2026-04-05
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/20260405/20260405-110845-remove-snapshot-dependency/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260405-110845-remove-snapshot-dependency-boot.md）
2. 20260405-110845-remove-snapshot-dependency-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

発注候補・移動候補の画面から`wms_item_stock_snapshots`への参照を完全排除し、在庫データを候補レコード自体に保存する。`batch_code`を17文字に拡張（末尾に倉庫ID 3桁）。手動作成時に入荷予定数を集計・表示・保存。

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベル管理
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 共有DB
- **既存データ破壊禁止**: batch_code拡張は既存14文字に影響しない
- **`wms_item_stock_snapshots`テーブル自体は削除しない**: 自動発注計算サービスで引き続き使用
- **`origin_type`は`USER`を使用**: 既存enumは `AUTO/USER/DIST`（`MANUAL`ではない）
- **倉庫ID（倉庫コードではない）の3桁ゼロ埋めを`batch_code`末尾に付与**
- **HUB倉庫在庫はリアルタイム表示ではなく候補レコードに保存したものを表示**

## 対象ファイル

### 新規作成
- `database/migrations/XXXX_expand_batch_code_to_17_chars.php` — batch_code char(14)→char(17)

### 既存変更
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` — スナップショット参照→wms_v_stock_available、在庫保存追加、入荷予定数集計・表示
- `app/Filament/Resources/WmsStockTransferCandidates/Pages/ListWmsStockTransferCandidates.php` — 同上 + HUB倉庫在庫を候補レコードから表示
- `app/Models/WmsAutoOrderJobControl.php` — `generateBatchCode()`に倉庫ID 3桁追加
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — batch_code生成の変更反映
- `app/Services/AutoOrder/StockSnapshotService.php` — batch_code生成の変更反映（必要であれば）

### 参照のみ（変更禁止）
- `app/Models/WmsOrderCandidate.php`
- `app/Models/WmsStockTransferCandidate.php`
- `app/Models/WmsItemStockSnapshot.php`
- `app/Models/WmsOrderIncomingSchedule.php`
- `app/Enums/AutoOrder/OriginType.php`
- `resources/views/filament/components/order-candidate-create-items.blade.php`
- `resources/views/filament/components/transfer-order-create-items.blade.php`

## テストデータ

- 既存の発注候補・移動候補レコードで動作確認
- `php artisan wms:generate-test-data` でテストデータ生成可能
- `wms_v_stock_available`ビューでリアルタイム在庫確認

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: batch_code拡張（DB + モデル） | 完了 | 2026-04-05 | 4テーブルchar(17)化、generateBatchCode変更 |
| P2: 発注候補のスナップショット排除 | 完了 | 2026-04-05 | 在庫→wms_v_stock_available、入荷予定数追加、在庫保存 |
| P3: 移動候補のスナップショット排除 | 完了 | 2026-04-05 | 在庫→wms_v_stock_available、HUB在庫→候補レコード、入荷予定数追加 |
| P4: 自動発注サービスのbatch_code対応 | 完了 | 2026-04-05 | startJob内でwarehouseId付きgenerateBatchCode |
| P5: 動作確認・回帰テスト | 完了 | 2026-04-05 | grep確認OK、テスト27パス |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マイグレーション（P1完了）
- マイグレーションファイル名: 2026_04_05_112321_expand_batch_code_to_17_chars.php
- 実行結果: 成功（266ms）、4テーブルともchar(17) NOT NULLに変更

### スナップショット参照箇所（調査済み）
- `ListWmsOrderCandidates.php:88-93` — 手動作成モーダルの現在庫表示
- `ListWmsStockTransferCandidates.php:75-79` — 手動作成モーダルの現在庫表示
- `ListWmsStockTransferCandidates.php:367-373` — リストHUB倉庫在庫列
- `origin_type`は `OriginType::USER`（`MANUAL`ではない）

### 入荷予定数の集計SQL（StockSnapshotService.php:123-157 参照）
```sql
SELECT warehouse_id, item_id,
       SUM(expected_quantity - received_quantity) as total_incoming
FROM wms_order_incoming_schedules
WHERE warehouse_id IN (...)
  AND status IN ('PENDING', 'PARTIAL')
GROUP BY warehouse_id, item_id
```

### Git ブランチ
- 作業ブランチ: (P1開始時に作成)
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: batch_code拡張（DB + モデル）
- 完了日: 2026-04-05
- 実績:
  - マイグレーション: 2026_04_05_112321_expand_batch_code_to_17_chars.php（4テーブル char(14)→char(17)）
  - generateBatchCode(?int $warehouseId = null) に変更、末尾に倉庫ID 3桁 or 000
  - 既存データ（14文字）は破壊なし確認済み

### P2: 発注候補のスナップショット排除
- 完了日: 2026-04-05
- 成果物: ListWmsOrderCandidates.php, order-candidate-create-items.blade.php
- 実績:
  - getItemStockForOrderCreate: wms_item_stock_snapshots → wms_v_stock_available に変更
  - getItemIncomingQuantityForOrderCreate: 入荷予定数取得メソッド新規追加
  - Blade: 入荷予定列追加、Promise.allで在庫・入荷予定数を並列取得
  - create action: current_effective_stock, incoming_quantity を候補レコードに保存
  - batch_code: 同日同倉庫PENDINGジョブ再利用、なければ新規作成（倉庫ID付き）
  - StockSnapshotService import 削除

### P3: 移動候補のスナップショット排除
- 完了日: 2026-04-05
- 成果物: ListWmsStockTransferCandidates.php, transfer-order-create-items.blade.php
- 実績:
  - getItemStockForCreate: wms_item_stock_snapshots → wms_v_stock_available に変更
  - getItemIncomingQuantityForCreate: 入荷予定数取得メソッド新規追加
  - hub_effective_stock カラム追加（マイグレーション: 2026_04_05_132840）
  - WmsStockTransferCandidate モデルの fillable に hub_effective_stock 追加
  - paginateTableQuery: スナップショットクエリ削除、候補レコードのhub_effective_stockから表示
  - create action: current_effective_stock, incoming_quantity, hub_effective_stock を保存
  - Blade: 入荷予定列追加、Promise.allで並列取得
  - StockSnapshotService import 削除

### P4: 自動発注サービスのbatch_code対応
- 完了日: 2026-04-05
- 成果物: WmsAutoOrderJobControl.php
- 実績:
  - startJob() 内の generateBatchCode() に $warehouseId を渡すよう変更
  - OrderCandidateCalculationService, StockSnapshotService は startJob(warehouseId:) 経由で自動対応
  - OrderTransmissionService, TransferCandidateApprovalService は batch_code を後から上書きするため影響なし

### P5: 動作確認・回帰テスト
- 完了日: 2026-04-05
- 実績:
  - grep: WmsOrderCandidates/, WmsStockTransferCandidates/ に wms_item_stock_snapshots 参照 0件
  - Transfer テスト: 27件全パス
  - Order テスト: 96パス、3失敗（RouteNotFoundException — 今回の変更と無関係）
  - 全PHPファイル構文チェック: OK
