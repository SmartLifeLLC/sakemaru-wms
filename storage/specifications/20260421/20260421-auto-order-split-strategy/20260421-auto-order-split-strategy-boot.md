# Work Plan: auto-order-split-strategy

- **ID**: auto-order-split-strategy
- **作成日**: 2026-04-21
- **最終更新**: 2026-04-21 (全Phase完了)
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260421/20260421-auto-order-split-strategy/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260421-auto-order-split-strategy-boot.md）
2. 20260421-auto-order-split-strategy-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

発注計算を安全在庫ベースと実績ベースに分離する。移動候補一覧に「発注OFF」ボタンを追加し、既存計算から実績ベースロジックを除去し、実績ベース発注候補生成を新規機能として追加する。

## 重要な設計制約

- **FK禁止**: `item_contractors` は基幹システム共有テーブル。WMS側からは `update` のみ
- **migrate:fresh/refresh 禁止**: 本番データ保護
- **batch_code共有**: 安全在庫ベースと実績ベースは同一 `batch_code` を使用（同一仕入先への一括送信のため）
- **発注OFF時**: PENDING候補は自動削除、APPROVED候補はそのまま残す
- **実績ベース計算**: `is_auto_order` フラグに関係なく、出荷実績があれば対象

## 対象ファイル

### 新規作成
- `app/Services/AutoOrder/SalesBasedOrderCandidateService.php` — 実績ベース発注候補生成サービス
- `app/Jobs/ProcessSalesBasedOrderCandidateJob.php` — 実績ベース候補生成ジョブ

### 既存変更
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — `safety_stock=0` の実績ベース計算を削除
- `app/Filament/Resources/WmsStockTransferCandidates/Tables/WmsStockTransferCandidatesTable.php` — 「発注OFF」レコードアクション追加
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` — 「実績ベース発注候補生成」アクション追加
- `app/Enums/AutoOrder/JobProcessName.php` — `SALES_BASED_CALC` 追加

### 参照のみ（変更禁止）
- `app/Models/Sakemaru/ItemContractor.php`
- `app/Models/StatsItemWarehouseSalesSummary.php`
- `app/Models/WmsAutoOrderJobControl.php`（process_name 周りの参照。findPendingSettlement 等）
- `app/Models/WmsStockTransferCandidate.php`
- `app/Models/WmsOrderCandidate.php`
- `app/Jobs/ProcessOrderCandidateGenerationJob.php`（新規ジョブの構造参考）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 発注OFFボタン追加 | 完了 | 2026-04-21 | toggleAutoOrderアクション追加済み |
| P2: 既存計算から実績ベース除去 | 完了 | 2026-04-21 | INTERNAL/EXTERNAL両方のsafety_stock=0ブロック除去 |
| P3: JobProcessName enum 追加 | 完了 | 2026-04-21 | SALES_BASED_CALC追加済み |
| P4: 実績ベースサービス新規作成 | 完了 | 2026-04-21 | SalesBasedOrderCandidateService作成済み |
| P5: 実績ベースジョブ新規作成 | 完了 | 2026-04-21 | ProcessSalesBasedOrderCandidateJob作成済み |
| P6: 実績ベース生成UIボタン追加 | 完了 | 2026-04-21 | getSalesBasedGenerateAction追加済み |
| P7: 動作確認 | 完了 | 2026-04-21 | Pint通過・全ファイル構文チェックOK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### コード上の重要な行番号
- `OrderCandidateCalculationService.php`:
  - INTERNAL 実績ベース計算: lines 653-660
  - EXTERNAL 実績ベース計算: lines 879-886
  - salesSummaries3d ロード: lines 554-569
  - is_auto_order フィルタ（INTERNAL）: line 612
  - is_auto_order フィルタ（EXTERNAL）: line 842
- `WmsStockTransferCandidatesTable.php`:
  - recordActions: lines 385-570
  - 利用可能フィールド: item_id, contractor_id, satellite_warehouse_id, hub_warehouse_id
- `WmsAutoOrderJobControl.php`:
  - findPendingSettlement: lines 201-207
  - findPendingSettlementForWarehouse: lines 212-220
- `ProcessOrderCandidateGenerationJob.php`:
  - constructor: lines 29-50, handle: lines 55-218
- `JobProcessName` enum: 既存値 = STOCK_SNAPSHOT, SATELLITE_CALC, HUB_CALC, ORDER_CALC, ORDER_EXECUTION, ORDER_TRANSMISSION, TRANSFER_APPROVAL
- ステータス enum: `App\Enums\AutoOrder\CandidateStatus` (PENDING, APPROVED, EXCLUDED)

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: 発注OFFボタン追加
- 完了日: -
- 実績:
  - (完了後に記入)

### P2: 既存計算から実績ベース除去
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: JobProcessName enum 追加
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: 実績ベースサービス新規作成
- 完了日: -
- 実績:
  - (完了後に記入)

### P5: 実績ベースジョブ新規作成
- 完了日: -
- 実績:
  - (完了後に記入)

### P6: 実績ベース生成UIボタン追加
- 完了日: -
- 実績:
  - (完了後に記入)

### P7: 動作確認
- 完了日: -
- 実績:
  - (完了後に記入)
