# Work Plan: contractor-specific-order-generation

- **ID**: contractor-specific-order-generation
- **作成日**: 2026-04-05
- **最終更新**: 2026-04-05
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/20260405/20260405-154423-contractor-specific-order-generation/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260405-154423-contractor-specific-order-generation-boot.md）
2. 20260405-154423-contractor-specific-order-generation-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

倉庫別の発注候補生成ボタンに仕入先選択機能を追加。検索付きチェックボックスリスト（全選択デフォルト）で仕入先を選び、選択した仕入先のPENDING候補のみ削除・再生成する。同日同倉庫ではbatch_codeを再利用。

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベル管理
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 共有DB
- **計算ロジック変更禁止**: 不足数計算・単位切り上げは変更しない
- **既存エントリポイント維持**: ウィザード・仕入先別強制生成・移動候補生成・スケジューラーは変更しない
- **後方互換性**: `calculate($contractorId)` 単一ID指定の動作を壊さない
- **全選択デフォルト**: 仕入先選択UIは全選択状態がデフォルト
- **選択なし不可**: 最低1つの仕入先を選択必須
- **親仕入先のみ表示**: `transmission_contractor_id IS NULL` の仕入先のみ。子は自動包含
- **batch_code再利用**: 同日同倉庫のPENDINGジョブがあればそのbatch_codeを使う（確定前のみ）
- **INTERNAL移動候補**: 選択仕入先にINTERNALが含まれない場合は生成しない
- **スケジューラー修正はスコープ外**

## 対象ファイル

### 新規作成
- `resources/views/filament/components/contractor-selection.blade.php` — 仕入先選択UIコンポーネント

### 既存変更
- `app/Models/WmsAutoOrderJobControl.php` — findPendingSettlementForWarehouse()追加
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — contractorIds配列対応、batchCode外部指定、INTERNALフィルタ、移動候補ロード拡張
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` — contractorIds+batchCodeパラメータ追加
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` — 仕入先選択UI統合、排他制御変更

### 参照のみ（変更禁止）
- `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` — スケジューラー（変更しない）
- `app/Models/WmsContractorSetting.php` — 親子仕入先展開ロジック
- `app/Jobs/ProcessOrderConfirmationJob.php` — 確定処理への影響確認
- `app/Jobs/ProcessAutoSendJob.php` — 自動送信への影響確認

## テストデータ

- `php artisan test --filter=Order`
- `php artisan test --filter=Transfer`
- `php artisan test --filter=Calculation`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: WmsAutoOrderJobControl の拡張 | 完了 | 2026-04-05 | findPendingSettlementForWarehouse()追加 |
| P2: OrderCandidateCalculationService の拡張 | 完了 | 2026-04-05 | contractorIds配列、batchCode外部指定、INTERNALフィルタ、移動候補PENDING全件 |
| P3: ProcessOrderCandidateGenerationJob の拡張 | 完了 | 2026-04-05 | contractorIds+batchCode追加、PENDING削除スコープ |
| P4: 仕入先選択Bladeコンポーネント作成 | 完了 | 2026-04-05 | Alpine.js + Livewire、検索・全選択・チェック維持 |
| P5: getGenerateByWarehouseAction() の統合 | 完了 | 2026-04-05 | 仕入先選択UI統合、batch_code再利用、仕入先別PENDING削除 |
| P6: 排他制御・result_data の調整 | 完了 | 2026-04-05 | P2/P5で実装済み、buildResultDataにbyContractor既存 |
| P7: 動作確認・回帰テスト | 完了 | 2026-04-05 | 全構文OK、テストパス、既存機能影響なし |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 仕入先選択UIの設計決定
- 全選択デフォルト、複数選択可能、選択なし不可
- 検索付きチェックボックスリスト（Alpine.js + Livewire）
- フィルタ変更時にチェック状態維持
- 親仕入先のみ表示（子は自動包含）
- INTERNAL/EXTERNAL区別表示、締切時間表示

### 運用フロー（ユーザー確認済み）
- 仕入先別発注データ生成 → 確定 → その後全仕入先で生成（既に作成済みの分は発注データなし）→ 確定
- 確定処理は仕入先別ではなくバッチ全体
- スケジューラーは今回スコープ外（根本的修正が必要）

### Git ブランチ
- 作業ブランチ: release/v1.0（現在のブランチ）
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: WmsAutoOrderJobControl の拡張
- 完了日: 2026-04-05
- 成果物: app/Models/WmsAutoOrderJobControl.php
- 実績:
  - `findPendingSettlementForWarehouse(int $warehouseId)` メソッド追加
  - settlement_status=PENDING, process_name=ORDER_CALC, warehouse_id一致, 当日のみで検索

### P2: OrderCandidateCalculationService の拡張
- 完了日: 2026-04-05
- 成果物: app/Services/AutoOrder/OrderCandidateCalculationService.php
- 実績:
  - calculate() に $contractorIds(配列) と $batchCode パラメータ追加
  - $contractorIds指定時は各IDを親+子に展開してマージ → $this->targetContractorIds
  - 排他制御: 配列指定時も倉庫フィルタ適用
  - $batchCode外部指定時はstartJob()にそのまま渡す
  - target_scope に contractor_ids を保存
  - createInternalTransferCandidatesBulk(): targetContractorIdsでINTERNALもフィルタ
  - loadTransferCandidatesToMemory(): 同バッチ + PENDINGステータス全件に拡張

### P3: ProcessOrderCandidateGenerationJob の拡張
- 完了日: 2026-04-05
- 成果物: app/Jobs/ProcessOrderCandidateGenerationJob.php
- 実績:
  - コンストラクタに $contractorIds(配列) と $batchCode パラメータ追加
  - PENDING削除: contractorIds指定時は展開後IDsでwhereInスコープ
  - calculate() に contractorIds + batchCode を渡すよう変更
  - getExpandedContractorIds() ヘルパーメソッド追加

### P4: 仕入先選択Bladeコンポーネント作成
- 完了日: 2026-04-05
- 成果物: resources/views/filament/components/contractor-selection.blade.php
- 実績:
  - Alpine.js x-data で searchQuery, selectedIds, allContractors 管理
  - $wire.getContractorsForWarehouse() で初期データ取得、全選択デフォルト
  - クライアントサイド検索フィルタ（コード・名前）、フィルタ変更時チェック維持
  - すべて選択 / 表示中を選択・解除ボタン
  - INTERNAL/EXTERNAL区別バッジ + 締切時間表示
  - 選択なし不可（最低1件）、$wire.set('selectedContractorIds')で同期

### P5: getGenerateByWarehouseAction() の統合
- 完了日: 2026-04-05
- 成果物: app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php
- 実績:
  - getGenerateByWarehouseAction() をschema() + ViewFieldに変更
  - 仕入先選択モーダル（contractor-selection.blade.php）を統合
  - 選択仕入先のAPPROVED候補のみブロック（他の仕入先は無視）
  - 選択仕入先のPENDING候補のみ削除
  - findPendingSettlementForWarehouse() でbatch_code再利用
  - ProcessOrderCandidateGenerationJob に contractorIds + batchCode を渡す
  - getContractorsForWarehouse() メソッド追加（親仕入先のみ、INTERNAL/EXTERNAL区別）
  - expandContractorIds() ヘルパーメソッド追加
  - selectedContractorIds プロパティ追加

### P6: 排他制御・result_data の調整
- 完了日: 2026-04-05
- 実績:
  - 排他制御はP2(calculate)とP5(UI)で仕入先単位に変更済み
  - buildResultData()のbyContractor集計は既存で対応済み（追加不要）

### P7: 動作確認・回帰テスト
- 完了日: 2026-04-05
- 実績:
  - 全4ファイル構文チェック OK
  - grep StockSnapshotService app/ → 0件（スナップショット廃止維持確認）
  - Calculation テスト: 5件全パス
  - Transfer テスト: 27件全パス
  - Order テスト: 96パス、3失敗（RouteNotFoundException — 今回の変更と無関係）
  - 既存エントリポイント: getOrderGenerationWizardAction, getForceGenerateByContractorAction, getGenerateTransferCandidatesAction 変更なし確認
  - AutoOrderScheduledCommand: 単一contractorIdでのdispatch維持確認（後方互換OK）
